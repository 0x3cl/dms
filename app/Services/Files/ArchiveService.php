<?php

namespace App\Services\Files;

use App\Enums\ActivityAction;
use App\Enums\FileAbility;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Zipping a selection, and extracting an archive.
 *
 * Extraction is the highest-severity operation in this application, so the
 * design is worth stating plainly:
 *
 * **`ZipArchive::extractTo()` is never called.** Every entry is streamed out by
 * index and written to a path *this application* generates, through
 * FileStorage and FilePathFactory, from a fresh `public_id`. Nothing from the
 * archive — no name, no directory component, no symlink target — ever reaches
 * a filesystem path.
 *
 * That is what makes zip-slip impossible here rather than filtered against:
 * there is no attacker-controlled path to sanitise, because no path comes from
 * the archive at all. The entry-name checks below are a second line, not the
 * defence.
 *
 * The limits are the administrator's, from `upload_settings`.
 */
class ArchiveService
{
    /**
     * Refuse any entry whose uncompressed size is more than this multiple of
     * its compressed size.
     *
     * A zip bomb is a few kilobytes that expands to gigabytes; legitimate
     * office documents do not compress anywhere near this well. Checked per
     * entry *as well as* in total, because a thousand entries each just under
     * the total cap is the same attack spread out.
     */
    private const MAX_COMPRESSION_RATIO = 120;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly FileStorage $storage,
        private readonly FilePathFactory $paths,
        private readonly FolderService $folders,
        private readonly FileEventRecorder $events,
        private readonly UploadPolicy $policy,
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    // ------------------------------------------------------------------ zipping

    /**
     * Zip a selection of files for download.
     *
     * Every file is re-resolved: a selection posted from a stale page may name
     * something the user could see when the page rendered and cannot now.
     *
     * @param  list<File>  $files
     */
    public function zip(User $actor, array $files, ?Request $request = null): BinaryFileResponse
    {
        if ($files === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one file to download.']);
        }

        $setting = $this->policy->for('files');
        $scratch = $this->scratchPath('zip');
        $zip = new ZipArchive;

        if ($zip->open($scratch, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages(['items' => 'Could not prepare the archive.']);
        }

        $total = 0;
        $used = [];
        // The files that actually went in, so the audit rows describe what was
        // released rather than what was asked for. A selection posted from a
        // stale page may name something the user could see when it rendered and
        // cannot now.
        $included = [];

        foreach ($files as $file) {
            if (! $this->access->allows($actor, $file, FileAbility::Download)) {
                continue;
            }

            if (! $this->storage->exists($file)) {
                continue;
            }

            $total += $file->size_bytes;

            if ($total > $setting->maxArchiveBytes()) {
                $zip->close();
                @unlink($scratch);

                throw ValidationException::withMessages([
                    'items' => 'That selection is larger than the archive limit of '.$setting->max_archive_size_kb.' KB.',
                ]);
            }

            $zip->addFromString(
                $this->uniqueEntryName($file->downloadName(), $used),
                (string) $this->storage->disk()->get($file->storage_path),
            );
            $included[] = $file;
        }

        $zip->close();

        if ($included === []) {
            @unlink($scratch);

            throw ValidationException::withMessages(['items' => 'None of those files can be downloaded.']);
        }

        foreach ($included as $file) {
            $this->events->record(ActivityAction::Zipped, $file, $actor, $request, [
                'entries' => count($included),
            ]);
        }

        return response()
            ->download($scratch, 'files-'.now()->format('Ymd-His').'.zip')
            ->deleteFileAfterSend();
    }

    // --------------------------------------------------------------- extracting

    /**
     * Extract an archive already in storage into a folder.
     *
     * Ordering is deliberate and is the whole of "a failed operation leaves no
     * incomplete records": **validate the entire archive first**, then open a
     * transaction, then create rows and write bytes while collecting every path
     * written. Disk writes are not transactional, so on any exception the
     * collector unlinks what was written and the transaction rolls the rows
     * back. Without the collector, a rollback leaves orphaned bytes that
     * nothing points at.
     *
     * @return list<File>
     */
    public function extract(User $actor, File $archive, Folder $target, ?Request $request = null): array
    {
        $this->assert($actor, $archive, FileAbility::Download, 'You cannot extract that.');
        $this->assert($actor, $target, FileAbility::Rename, 'You cannot extract into that folder.');

        $setting = $this->policy->for('files');

        if ($setting->max_archive_entries <= 0) {
            throw ValidationException::withMessages(['item' => 'Extracting archives is not permitted.']);
        }

        if (! $setting->isArchiveExtension($archive->extension)) {
            throw ValidationException::withMessages(['item' => 'That file is not an archive.']);
        }

        if ($archive->size_bytes > $setting->maxArchiveBytes()) {
            throw ValidationException::withMessages([
                'item' => 'That archive is larger than the limit of '.$setting->max_archive_size_kb.' KB.',
            ]);
        }

        // ZipArchive needs a real local path, and the bytes live on a disk that
        // may not be local. Copied to scratch and removed in the finally.
        $scratch = $this->scratchPath('extract');
        file_put_contents($scratch, (string) $this->storage->disk()->get($archive->storage_path));

        $zip = new ZipArchive;
        $written = [];

        try {
            if ($zip->open($scratch, ZipArchive::RDONLY) !== true) {
                throw ValidationException::withMessages(['item' => 'That archive could not be read.']);
            }

            $plan = $this->validateEntries($zip, $setting);

            if ($plan === []) {
                throw ValidationException::withMessages(['item' => 'That archive holds nothing that can be extracted.']);
            }

            $created = DB::transaction(function () use ($zip, $plan, $actor, $target, $archive, $request, &$written): array {
                $folders = [];
                $files = [];

                foreach ($plan as $entry) {
                    $folder = $this->folderForEntry($actor, $target, $entry['directory'], $folders);

                    $stream = $zip->getStream($entry['name']);

                    if ($stream === false) {
                        throw ValidationException::withMessages(['item' => 'An entry in that archive could not be read.']);
                    }

                    $file = File::create([
                        'user_id' => $actor->id,
                        'owner_name' => $actor->name,
                        'folder_id' => $folder->id,
                        'name' => $entry['basename'],
                        'original_name' => $entry['basename'],
                        'extension' => $entry['extension'],
                        'mime_type' => 'application/octet-stream',
                        'size_bytes' => $entry['size'],
                        'disk' => FileStorage::DISK,
                        'storage_path' => '',
                        'visibility' => $target->visibility?->value ?? 'private',
                        'source' => 'extract',
                    ]);

                    // The path is ours, built from the row's own public id. The
                    // archive had no say in it.
                    $path = $this->paths->for($file);
                    $this->storage->disk()->writeStream($path, $stream);
                    $written[] = $path;
                    fclose($stream);

                    $file->update(['storage_path' => $path]);
                    $this->thumbnails->generate($file);

                    $files[] = $file;
                }

                $this->events->record(ActivityAction::Extracted, $archive, $actor, $request, [
                    'entries' => count($files),
                    'into' => $target->is_root ? 'storage root' : $target->name,
                ]);

                return $files;
            });

            return $created;
        } catch (\Throwable $e) {
            // The rows are gone with the transaction; the bytes are not.
            foreach ($written as $path) {
                $this->storage->disk()->delete($path);
            }

            throw $e;
        } finally {
            $zip->close();
            @unlink($scratch);
        }
    }

    /**
     * Check every entry before anything is written, and return what to extract.
     *
     * @return list<array{name: string, basename: string, directory: list<string>, extension: string, size: int}>
     */
    private function validateEntries(ZipArchive $zip, \App\Models\UploadSetting $setting): array
    {
        if ($zip->numFiles > $setting->max_archive_entries) {
            throw ValidationException::withMessages([
                'item' => 'That archive holds more than '.$setting->max_archive_entries.' entries.',
            ]);
        }

        $plan = [];
        $totalUncompressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw ValidationException::withMessages(['item' => 'That archive could not be read.']);
            }

            $name = $stat['name'];

            /*
             * An encrypted entry cannot be read, and silently skipping it means
             * an extraction that reports success while dropping files.
             *
             * Read from `statIndex()` rather than `isEncryptedIndex()`: that
             * method is absent in some libzip builds, including the one these
             * servers have, so calling it is a fatal error rather than a
             * refused archive.
             */
            if ((int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                throw ValidationException::withMessages(['item' => 'That archive is encrypted.']);
            }

            // A symlink entry whose target is followed on extraction is how an
            // archive writes outside its own tree. Nothing here follows one,
            // but an archive containing them is not an archive of documents.
            if ($this->isSymlink($zip, $index)) {
                throw ValidationException::withMessages(['item' => 'That archive contains a symbolic link.']);
            }

            // Directory entries carry no bytes; the folders are created from
            // the file entries' own paths instead.
            if (str_ends_with($name, '/')) {
                continue;
            }

            $this->assertSafeEntryName($name);

            $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $name)), fn ($s) => $s !== ''));
            $basename = array_pop($segments);

            if (count($segments) >= $setting->max_archive_depth) {
                throw ValidationException::withMessages([
                    'item' => 'That archive nests folders deeper than '.$setting->max_archive_depth.' levels.',
                ]);
            }

            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

            if ($extension === '' || ! $setting->permitsExtension($extension)) {
                throw ValidationException::withMessages([
                    'item' => "That archive contains “{$basename}”, which is not a permitted file type.",
                ]);
            }

            if (! $setting->allow_nested_archives && $setting->isArchiveExtension($extension)) {
                throw ValidationException::withMessages([
                    'item' => 'That archive contains another archive, which is not permitted.',
                ]);
            }

            $size = (int) $stat['size'];
            $compressed = max((int) $stat['comp_size'], 1);

            // Per entry: a single entry with an implausible ratio is a bomb
            // even if the archive's total is within the cap.
            if ($size > 0 && ($size / $compressed) > self::MAX_COMPRESSION_RATIO) {
                throw ValidationException::withMessages([
                    'item' => "That archive contains “{$basename}”, which expands far more than a document should.",
                ]);
            }

            $totalUncompressed += $size;

            // In total, read from the central directory before a byte is
            // written. The streaming side re-counts, because this header is
            // the archive's own claim about itself.
            if ($totalUncompressed > $setting->maxExtractedBytes()) {
                throw ValidationException::withMessages([
                    'item' => 'That archive expands to more than the limit of '.$setting->max_extracted_kb.' KB.',
                ]);
            }

            $plan[] = [
                'name' => $name,
                'basename' => $this->sanitizeName($basename),
                'directory' => array_map(fn (string $s) => $this->sanitizeName($s), $segments),
                'extension' => $extension,
                'size' => $size,
            ];
        }

        return $plan;
    }

    /**
     * Refuse an entry name that is trying to escape.
     *
     * Nothing here is used to build a path, so this cannot be the defence — it
     * is a refusal to process an archive that is evidently hostile, which is
     * more useful to the person than silently flattening it.
     */
    private function assertSafeEntryName(string $name): void
    {
        $suspect = str_contains($name, "\0")
            || str_contains($name, '..')
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || (bool) preg_match('/^[A-Za-z]:/', $name);

        if ($suspect) {
            throw ValidationException::withMessages([
                'item' => 'That archive contains an entry with an unsafe path and was not extracted.',
            ]);
        }
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr);

        if ($attributes === false || $opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        // The unix mode sits in the high 16 bits; S_IFLNK is 0xA000.
        return (($attr >> 16) & 0xF000) === 0xA000;
    }

    /**
     * A name safe to show and to store in the `name` column.
     *
     * Not a path component — FilePathFactory builds those — so this only has to
     * be free of separators and control characters.
     */
    private function sanitizeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', $name) ?? '';
        $clean = trim(preg_replace('/_{2,}/', '_', $clean) ?? '', ' _');

        return $clean === '' ? Str::random(8) : Str::limit($clean, 200, '');
    }

    /**
     * The folder an entry's directory path maps to, created as needed.
     *
     * @param  list<string>  $segments
     * @param  array<string, Folder>  $cache
     */
    private function folderForEntry(User $actor, Folder $target, array $segments, array &$cache): Folder
    {
        $folder = $target;
        $key = '';

        foreach ($segments as $segment) {
            $key .= '/'.$segment;

            if (isset($cache[$key])) {
                $folder = $cache[$key];

                continue;
            }

            $folder = Folder::query()
                ->where('parent_id', $folder->id)
                ->where('name', $segment)
                ->first()
                ?? $this->folders->create($actor, $folder, $segment);

            $cache[$key] = $folder;
        }

        return $folder;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function uniqueEntryName(string $name, array &$used): string
    {
        $clean = $this->sanitizeName($name);
        $candidate = $clean;
        $n = 2;

        while (isset($used[$candidate])) {
            $extension = pathinfo($clean, PATHINFO_EXTENSION);
            $stem = pathinfo($clean, PATHINFO_FILENAME);
            $candidate = $stem." ({$n})".($extension === '' ? '' : ".{$extension}");
            $n++;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    /** A private scratch file, outside anything the web server serves. */
    private function scratchPath(string $prefix): string
    {
        $directory = storage_path('app/private/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        return $directory.'/'.$prefix.'-'.Str::random(32).'.zip';
    }

    private function assert(User $actor, File|Folder $item, FileAbility $ability, string $message): void
    {
        if (! $this->access->allows($actor, $item, $ability)) {
            throw ValidationException::withMessages(['item' => $message]);
        }
    }
}
