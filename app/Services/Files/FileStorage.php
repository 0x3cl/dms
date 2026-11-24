<?php

namespace App\Services\Files;

use App\Contracts\StoredBytes;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\User;
use App\Support\FileTypes;
use App\Support\VersionedFilename;
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
    /**
     * The media groups a browser may render in place.
     *
     * Images, audio and video, plus PDF by name — the one document format a
     * browser renders by itself. Everything else is handed back as a
     * download, so a caller cannot accidentally render an arbitrary upload
     * in place.
     */
    private const INLINE_GROUPS = [
        FileTypes::GROUP_IMAGES,
        FileTypes::GROUP_AUDIO,
        FileTypes::GROUP_VIDEO,
    ];

    /**
     * Never inline, whatever its group says.
     *
     * An SVG *is* a document that can carry script, and rendering one inline
     * executes it against this origin. It is in the images group because
     * that is what it is to a person choosing a file; it is excluded here
     * because of what it is to a browser.
     */
    private const NEVER_INLINE = ['svg'];

    /**
     * The MIME types served inline, derived from the catalogue.
     *
     * **It was a hand-written list, and it was wrong in the usual way.** It
     * named `audio/wav` and the file `finfo` actually produces for a WAV is
     * `audio/x-wav`, so every WAV preview fell through to the download
     * branch and the `<audio>` element was handed an attachment, which
     * plays nothing. That is the same failure the upload policy had when it
     * kept extensions and MIME types in two lists: a format is admitted in
     * one vocabulary and refused in another, and nobody maintaining the
     * first has reason to know about the second.
     *
     * `App\Support\FileTypes` already records every type an extension may
     * legitimately sniff as, because the upload gate needs exactly that. So
     * this is derived from it and cannot disagree with what was permitted
     * at upload.
     *
     * @return list<string>
     */
    public static function inlineTypes(): array
    {
        $types = ['application/pdf'];

        foreach (FileTypes::extensions() as $extension) {
            if (in_array($extension, self::NEVER_INLINE, true)) {
                continue;
            }

            if (! in_array(FileTypes::group($extension), self::INLINE_GROUPS, true)) {
                continue;
            }

            foreach (FileTypes::mimeTypes($extension) as $type) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

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
                /*
                 * Stated, not left to the column default. The default exists
                 * to backfill rows written before versioning; a row created
                 * here must carry the value in memory too, or every reader
                 * between this insert and the next refresh sees null and has
                 * to guess — which is how `?? 1` ends up sprinkled through
                 * callers as a workaround for a fact nobody wrote down.
                 */
                'version' => 1,
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
                /*
                 * A copy starts its own history at version 1 and takes the
                 * source's *current* bytes only. Carrying the history across
                 * would hand whoever took the copy every earlier version of
                 * somebody else's file, which is reach the share never gave
                 * them — and a copy is a new file, not a view of an old one.
                 */
                'version' => 1,
            ]);

            $path = $this->paths->for($copy);
            $this->disk()->writeStream($path, $this->disk()->readStream($file->storage_path));
            $copy->update(['storage_path' => $path]);

            return $copy;
        });
    }

    /**
     * Write a new version's bytes for a file that already exists.
     *
     * The path comes from `(public_id, version, extension)`, so the outgoing
     * version's bytes are never in the way: nothing in this class has ever
     * overwritten bytes belonging to a live row, and this does not start. A
     * copy is a different act — it takes the *current* bytes into a new row
     * that starts its own history at version 1.
     *
     * Only the bytes. The row is updated by FileVersionService, inside the
     * transaction that also moves the outgoing descriptor into `file_versions`,
     * because those two writes have to happen together or not at all.
     *
     * @return array{storage_path: string, original_name: string, extension: string, mime_type: string, size_bytes: int, checksum: string|null}
     */
    public function putVersion(File $file, UploadedFile $upload, int $version): array
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $this->paths->forVersion($file->public_id, $version, $extension);

        $this->disk()->put($path, file_get_contents($upload->getRealPath()));

        return [
            'storage_path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'extension' => $extension,
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $upload->getSize() ?: 0,
            'checksum' => hash_file('sha256', $upload->getRealPath()) ?: null,
        ];
    }

    /**
     * Copy an earlier version's bytes forward as the next version.
     *
     * A revert does not move the pointer back: it writes the old bytes again
     * under a new version number, so the history stays append-only and reads
     * as a true sequence of what the file was over time. The bytes are streamed
     * rather than read into memory, as `copy()` does, because a version can be
     * as large as anything the upload policy permits.
     *
     * @return array{storage_path: string, original_name: string, extension: string, mime_type: string, size_bytes: int, checksum: string|null}
     */
    public function copyVersionForward(File $file, FileVersion $source, int $version): array
    {
        $path = $this->paths->forVersion($file->public_id, $version, (string) $source->extension);

        $this->disk()->writeStream($path, $this->disk()->readStream($source->storage_path));

        return [
            'storage_path' => $path,
            'original_name' => (string) $source->original_name,
            'extension' => (string) $source->extension,
            'mime_type' => (string) $source->mime_type,
            'size_bytes' => (int) $source->size_bytes,
            'checksum' => $source->checksum,
        ];
    }

    /*
     * Everything below reads `StoredBytes` rather than `File`, so that serving
     * a superseded version is the same code path as serving the current one.
     * The alternative was `$version?->storage_path ?? $file->storage_path` at
     * nine call sites that would then have to agree with each other forever.
     *
     * None of it decides access. Who may read a file is settled by
     * AccessResolver against the `File` — or its `Document` — before anything
     * here is called; a version is never the subject of an authorization check.
     */

    public function exists(StoredBytes $bytes): bool
    {
        return $bytes->storagePath() !== '' && $this->disk()->exists($bytes->storagePath());
    }

    /** A download, named after what the user originally uploaded. */
    /**
     * The bytes, as a file somebody keeps.
     *
     * **The version is stamped onto the name here and nowhere else.** Two
     * downloads of one file used to arrive under the same name, which is
     * the problem versioning exists to solve reproduced in the one place a
     * reader keeps the file: a folder holding `report.docx` and
     * `report(1).docx` says which one the browser saved second and nothing
     * about which version either is.
     *
     * It is applied at the point of *serving* rather than on
     * `StoredBytes::downloadName()`, and that distinction is load-bearing:
     * `downloadName()` is the file's name, and `ActivityLog::record()`
     * snapshots it as `subject_label`. Stamping the model would have put
     * "(v3)" into the audit trail's record of what a file was called — a
     * presentation choice rewriting history. A test caught exactly that.
     */
    public function download(StoredBytes $bytes): StreamedResponse|BinaryFileResponse
    {
        /*
         * The tracking code goes in front of the name, and for every format.
         *
         * A PDF carries its code on its pages and an image in its corner, but
         * a video, an MP3, a zip, a spreadsheet and a plain text file have no
         * surface to draw on. The filename is the surface they do have: it
         * survives the download, a copy onto a USB stick and an email
         * attachment, and it is what somebody types into the lookup when the
         * file is all they are holding.
         *
         * Applied here rather than on the model for the same reason the
         * version stamp is — see below.
         */
        $name = VersionedFilename::withCode(
            VersionedFilename::stamp($bytes->downloadName(), $bytes->versionNumber()),
            $bytes instanceof File ? $bytes->document?->code : null,
        );

        return $this->disk()->download($bytes->storagePath(), $name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * An inline response, for the preview iframe.
     *
     * Anything outside inlineTypes() is handed back as a download instead, so a
     * caller cannot accidentally render an arbitrary upload in place.
     *
     * ## Why this response is cacheable
     *
     * Laravel's default is `no-cache, private`, and an embedded preview
     * re-requests its source on a resize, a re-mount and a reload — so a 20 MB
     * PDF was fetched again from the disk every time, and arrowing back to a
     * file already looked at re-downloaded all of it. These headers make a
     * second look free.
     *
     * **`private` is load-bearing.** A proxy must never hold one of these: the
     * URL is obfuscated but the bytes are a confidential document's, and a
     * shared cache would hand them to whoever asked for the same URL next,
     * behind the resolver's back. `no-store` is not used, because the browser's
     * own cache is exactly what makes the second render instant, and it is the
     * cache of the one person who already passed the gate.
     *
     * The validator is the stored checksum, which is the file's content hash and
     * so changes if and only if the bytes do.
     */
    public function inline(StoredBytes $bytes): StreamedResponse|BinaryFileResponse
    {
        if (! in_array($bytes->mimeType(), self::inlineTypes(), true)) {
            return $this->download($bytes);
        }

        $headers = [
            'Content-Type' => $bytes->mimeType(),
            'Content-Disposition' => 'inline; filename="'.addslashes($bytes->downloadName()).'"',
            'X-Content-Type-Options' => 'nosniff',
            // Belt and braces for the types that *are* rendered: a PDF can
            // carry JavaScript, and a sandboxed document has no origin of its
            // own to use it against.
            'Content-Security-Policy' => 'sandbox; default-src \'none\'; img-src data: blob:; media-src \'self\' data: blob:; object-src \'none\'',
            ...$this->cacheHeaders($bytes),
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
        if ($this->isMedia($bytes) && method_exists($this->disk(), 'path')) {
            $absolute = $this->disk()->path($bytes->storagePath());

            if (is_file($absolute)) {
                return response()->file($absolute, $headers);
            }
        }

        return $this->disk()->response($bytes->storagePath(), $bytes->downloadName(), $headers);
    }

    /**
     * The bytes for an external document viewer, at the signed URL.
     *
     * Not `inline()`, and the difference is the point. An Office file is not in
     * the inline list — rightly, because this application must never render one in
     * place — so `inline()` handed it back through `download()`: a streamed
     * attachment, with the generic type the upload was recorded with and no
     * length. Microsoft's viewer fetches this URL from their own servers, and a
     * fetcher given no `Content-Length` and a `Content-Disposition: attachment`
     * has to read the whole body before it can start, cannot resume, and in
     * some cases guesses the format wrongly and renders nothing.
     *
     * So this is a real file response: the format's canonical content type from
     * the catalogue rather than whatever was sniffed at upload, a length, range
     * support, and a short cache window inside the signed URL's own lifetime —
     * the viewer fetches the same URL more than once while it renders.
     *
     * There is no Content-Security-Policy header here and that is deliberate:
     * the response is not rendered by a browser against our origin, it is read
     * by a server. The protection that matters on this route is the one in
     * `FileStreamController::viewer()` — the signature, the expiry, and the
     * visibility re-check on every fetch.
     */
    public function viewerStream(StoredBytes $bytes): StreamedResponse|BinaryFileResponse
    {
        $headers = [
            'Content-Type' => FileTypes::canonicalMimeType($bytes->extensionName())
                ?? ($bytes->mimeType() ?: 'application/octet-stream'),
            'Content-Disposition' => 'inline; filename="'.addslashes($bytes->downloadName()).'"',
            'X-Content-Type-Options' => 'nosniff',
            ...$this->cacheHeaders($bytes),
        ];

        if (method_exists($this->disk(), 'path')) {
            $absolute = $this->disk()->path($bytes->storagePath());

            if (is_file($absolute)) {
                // Sends Content-Length and answers a Range request itself.
                return response()->file($absolute, $headers);
            }
        }

        return $this->disk()->response($bytes->storagePath(), $bytes->downloadName(), $headers);
    }

    /**
     * Validators, and a cache the bytes can invalidate.
     *
     * It was `max-age=600`, on the reasoning that a preview re-rendered
     * within ten minutes costs nothing — and `max-age` means *do not even
     * ask*, so the validators below were never consulted inside that window.
     * That is correct only while a URL's bytes cannot change under it, which
     * `?version=` buys for a replacement and does not buy for a **stamp**: a
     * stamp rewrites a file in place and keeps its version, deliberately,
     * because a mark on the bytes is not a new version of them. So somebody
     * who had just stamped a document was served their own cached copy of
     * the file from before the mark, and the cover page was simply not
     * there — which is indistinguishable from stamping having failed.
     *
     * `no-cache` keeps the copy and revalidates it: unchanged bytes answer
     * 304 against the checksum, which is nearly as cheap as not asking, and
     * changed bytes are fetched the moment they change.
     *
     * @return array<string, string>
     */
    private function cacheHeaders(StoredBytes $bytes): array
    {
        $headers = ['Cache-Control' => 'private, no-cache, must-revalidate'];

        if ($bytes->checksumValue()) {
            $headers['ETag'] = '"'.$bytes->checksumValue().'"';
        }

        if ($bytes->modifiedAt()) {
            $headers['Last-Modified'] = $bytes->modifiedAt()->toRfc7231String();
        }

        return $headers;
    }

    /**
     * A file's thumbnail, or a 404 if it has none.
     *
     * Served inline as webp from the same private disk, through the same gate
     * as the file itself.
     */
    public function thumbnail(StoredBytes $bytes): StreamedResponse|BinaryFileResponse
    {
        $path = $bytes->thumbnailPath();

        abort_unless($bytes->hasThumbnail() && $this->disk()->exists($path), 404);

        return $this->disk()->response($path, null, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            // Private: a thumbnail of a confidential file must not be cached by
            // a proxy on the way to one viewer and handed to the next. And
            // revalidated rather than held, for the reason the bytes are: a
            // stamp redraws this picture without changing its URL.
            'Cache-Control' => 'private, no-cache, must-revalidate',
            ...($bytes->checksumValue() ? ['ETag' => '"thumb-'.$bytes->checksumValue().'"'] : []),
        ]);
    }

    public function delete(File $file): void
    {
        $this->unlink($file);

        /*
         * Every superseded version goes with it, and this is the one piece of
         * versioning that must not be forgotten.
         *
         * `file_versions.file_id` cascades, so purging a file drops its version
         * *rows* on its own — and that is exactly the danger: without this the
         * rows vanish while their bytes stay on disk, unreferenced by anything
         * that could ever find them again. A `files` row is the only thing that
         * knows where its versions' bytes are.
         *
         * `withTrashed()`-free on purpose: `file_versions` has no soft deletes,
         * because nothing ever soft-deletes a version.
         */
        foreach ($file->versions()->get() as $version) {
            $this->unlink($version);
        }
    }

    /**
     * Unlink a path this application just wrote, by path.
     *
     * For one caller only: rolling back a failed version write, where the row
     * that would name these bytes no longer exists, so there is no
     * `StoredBytes` left to ask. Deliberately takes a raw path and deliberately
     * does not look anything up — it is cleanup of something written moments
     * ago inside the same request, never a way to delete by user input.
     */
    public function forget(string $path): void
    {
        if ($path !== '' && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /** One version's bytes and its thumbnail, if either is there. */
    private function unlink(StoredBytes $bytes): void
    {
        if ($bytes->storagePath() !== '' && $this->disk()->exists($bytes->storagePath())) {
            $this->disk()->delete($bytes->storagePath());
        }

        if ($bytes->hasThumbnail() && $this->disk()->exists($bytes->thumbnailPath())) {
            $this->disk()->delete($bytes->thumbnailPath());
        }
    }

    /**
     * A name that does not collide inside the folder.
     *
     * `(folder_id, name)` is unique, so without this a second upload of the
     * same filename is a failed insert rather than "report (2).pdf".
     */
    private function isMedia(StoredBytes $bytes): bool
    {
        return str_starts_with($bytes->mimeType(), 'video/')
            || str_starts_with($bytes->mimeType(), 'audio/');
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
