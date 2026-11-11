<?php

namespace App\Services\Files;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything that reads or writes a file's bytes.
 *
 * Always through the Storage facade, never `storage_path()` interpolation.
 * The old document paths were built by hand, which hard-coupled them to the
 * local public disk and made them untestable under `Storage::fake()` — the
 * reason the download test could only ever assert a negative.
 *
 * Inline responses are hardened here rather than at each call site, because
 * "serve this with the browser's own viewer" is the one place an upload can
 * turn into script execution on our own origin.
 */
class FileStorage
{
    /**
     * Content types the browser may render in place.
     *
     * An allow-list, not a block-list. Anything else is sent as an attachment,
     * because an uploaded SVG or HTML file rendered inline executes its own
     * script against this origin — which, on an artifact that is also reachable
     * by a share-link holder, is a cross-account disclosure.
     *
     * @var list<string>
     */
    public const INLINE_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
        /*
         * Video and audio, for the preview's `<video>` and `<audio>` elements.
         * They are on the list for the same reason the image types are — a
         * player cannot play an attachment — and they are safe for the same
         * reason: a media container is decoded by the browser's media stack,
         * not executed as a document against this origin. `image/svg+xml` is
         * still deliberately absent, because an SVG *is* a document that can
         * carry script.
         */
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
    ];

    public const DISK = 'documents';

    public function __construct(private readonly FilePathFactory $paths) {}

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Store an upload and create its row, in one transaction.
     *
     * The row is written first so the path is derived from a committed
     * `public_id`; if the byte write then fails, the transaction rolls the row
     * back and nothing is left pointing at a file that does not exist. The
     * reverse order leaves orphaned bytes on a failed insert.
     */
    public function store(UploadedFile $upload, Folder $folder, User $owner, string $source = 'upload'): File
    {
        return DB::transaction(function () use ($upload, $folder, $owner, $source): File {
            $extension = strtolower($upload->getClientOriginalExtension());
            $originalName = $upload->getClientOriginalName();

            $file = File::create([
                'user_id' => $owner->id,
                'owner_name' => $owner->name,
                'folder_id' => $folder->id,
                'name' => $this->uniqueNameIn($folder, $originalName),
                'original_name' => $originalName,
                'extension' => $extension,
                'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $upload->getSize() ?: 0,
                'checksum' => hash_file('sha256', $upload->getRealPath()) ?: null,
                'disk' => self::DISK,
                'storage_path' => '',
                'visibility' => $folder->visibility->value,
                'source' => $source,
            ]);

            $path = $this->paths->for($file);
            $this->disk()->put($path, file_get_contents($upload->getRealPath()));
            $file->update(['storage_path' => $path]);

            return $file;
        });
    }

    /**
     * Copy a file into a folder, as a new row with its own bytes.
     *
     * A copy is never a reference. Revoking the original's share, or deleting
     * the original outright, must not reach into the storage of whoever took a
     * copy — the copy is theirs.
     */
    public function copy(File $file, Folder $target, User $owner): File
    {
        return DB::transaction(function () use ($file, $target, $owner): File {
            $copy = File::create([
                'user_id' => $owner->id,
                'owner_name' => $owner->name,
                'folder_id' => $target->id,
                'name' => $this->uniqueNameIn($target, $file->name),
                'original_name' => $file->original_name,
                'extension' => $file->extension,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'checksum' => $file->checksum,
                'disk' => self::DISK,
                'storage_path' => '',
                // The copy takes the destination's visibility, not the
                // source's: a public file copied into a private folder must not
                // drag its openness across.
                'visibility' => $target->visibility->value,
                'source' => 'copy',
            ]);

            $path = $this->paths->for($copy);
            $this->disk()->writeStream($path, $this->disk()->readStream($file->storage_path));
            $copy->update(['storage_path' => $path]);

            return $copy;
        });
    }

    public function exists(File $file): bool
    {
        return $file->storage_path !== '' && $this->disk()->exists($file->storage_path);
    }

    /** A download, named after what the user originally uploaded. */
    public function download(File $file): StreamedResponse|BinaryFileResponse
    {
        return $this->disk()->download($file->storage_path, $file->downloadName(), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * An inline response, for the preview iframe.
     *
     * Anything outside INLINE_TYPES is handed back as a download instead, so a
     * caller cannot accidentally render an arbitrary upload in place.
     */
    public function inline(File $file): StreamedResponse|BinaryFileResponse
    {
        if (! in_array($file->mime_type, self::INLINE_TYPES, true)) {
            return $this->download($file);
        }

        $headers = [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($file->downloadName()).'"',
            'X-Content-Type-Options' => 'nosniff',
            // Belt and braces for the types that *are* rendered: a PDF can
            // carry JavaScript, and a sandboxed document has no origin of its
            // own to use it against.
            'Content-Security-Policy' => 'sandbox; default-src \'none\'; img-src data: blob:; media-src \'self\' data: blob:; object-src \'none\'',
        ];

        /*
         * Media is served as a real file response, not a stream.
         *
         * A `<video>` element seeks with a Range request, and a streamed
         * response answers one with the whole file and a 200 — so the player
         * either cannot seek at all or re-downloads from the start on every
         * scrub. A BinaryFileResponse handles ranges itself. It needs a path on
         * the local filesystem, which the private disk has; anything else falls
         * through to the streamed response, which still plays, just without
         * seeking.
         */
        if ($this->isMedia($file) && method_exists($this->disk(), 'path')) {
            $absolute = $this->disk()->path($file->storage_path);

            if (is_file($absolute)) {
                return response()->file($absolute, $headers);
            }
        }

        return $this->disk()->response($file->storage_path, $file->downloadName(), $headers);
    }

    /**
     * A file's thumbnail, or a 404 if it has none.
     *
     * Served inline as webp from the same private disk, through the same gate
     * as the file itself.
     */
    public function thumbnail(File $file): StreamedResponse|BinaryFileResponse
    {
        $path = $this->paths->thumbnailFor($file);

        abort_unless($file->has_thumbnail && $this->disk()->exists($path), 404);

        return $this->disk()->response($path, null, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            // Private: a thumbnail of a confidential file must not be cached by
            // a proxy on the way to one viewer and handed to the next.
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function delete(File $file): void
    {
        if ($file->storage_path !== '' && $this->disk()->exists($file->storage_path)) {
            $this->disk()->delete($file->storage_path);
        }

        if ($file->has_thumbnail) {
            $this->disk()->delete($this->paths->thumbnailFor($file));
        }
    }

    /**
     * A name that does not collide inside the folder.
     *
     * `(folder_id, name)` is unique, so without this a second upload of the
     * same filename is a failed insert rather than "report (2).pdf".
     */
    private function isMedia(File $file): bool
    {
        return str_starts_with((string) $file->mime_type, 'video/')
            || str_starts_with((string) $file->mime_type, 'audio/');
    }

    /**
     * A name that does not collide inside the folder.
     *
     * **`withTrashed`, because the unique index does not care that a row is
     * soft-deleted.** `(folder_id, name)` is enforced over every row in the
     * table, so a file whose name matches one sitting in the trash is a failed
     * insert — which, before this, surfaced as a 500 on an ordinary re-upload
     * of a filename somebody had deleted earlier that day.
     */
    private function uniqueNameIn(Folder $folder, string $name): string
    {
        $taken = fn (string $candidate): bool => File::withTrashed()
            ->where('folder_id', $folder->id)
            ->where('name', $candidate)
            ->exists();

        if (! $taken($name)) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $suffix = $extension === '' ? '' : ".{$extension}";

        for ($n = 2; $n < 1000; $n++) {
            $candidate = Str::limit($stem, 200, '')." ({$n}){$suffix}";

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // 998 files of one name in one folder is not a real case; falling back
        // to the public id keeps the insert from failing rather than guessing
        // further.
        return Str::random(26).$suffix;
    }
}
