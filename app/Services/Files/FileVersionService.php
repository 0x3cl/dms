<?php

namespace App\Services\Files;

use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Replacing a file, reverting it, and reading its history.
 *
 * ## A replacement is one sentence
 *
 * **Move the current-version descriptor off `files` into `file_versions`
 * verbatim, then write the new descriptor onto `files`.** Nothing is invented
 * and nothing is derived, which is why the history can be read out of two
 * tables with one shape — and why a replacement cannot half-happen: both
 * writes are in one transaction, opened by the caller.
 *
 * The `files` row stays the current version. A row per version with a
 * current-version flag would cost the `(folder_id, name)` unique index, since
 * versions share a name by definition, and would hand the listing scope,
 * `documents.file_id` and every share and link a new case to handle. Keeping
 * the current row as the pointer means a replacement disturbs none of them —
 * not the tracking code, not the printed label, not the share link, not the
 * file's URL.
 *
 * ## What this service deliberately does not do
 *
 * It does not authorize, and it does not transact. `FileManager::replace()` and
 * `::revertTo()` own both, because every structural file operation in this
 * application goes through that class and gets its ability check, its
 * transaction and its audit row — including the failure row — from one place.
 * A service that opened its own transaction would be a second answer to "how
 * does a file operation behave when it fails".
 *
 * It also never touches `files.name`. A version is the same file by definition,
 * so renaming would be renaming under the reader's feet — and worse, a rename
 * has to consult `assertNameFree()`, which can *refuse*. A replacement being
 * refused because somebody else's trashed file holds the new name would be
 * absurd. So the listing keeps showing the name it always had, while the
 * download name, the viewer and the thumbnail follow the real bytes.
 */
class FileVersionService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    /**
     * Supersede this file's current version with the uploaded bytes.
     *
     * Call inside a transaction, with the row already locked — see
     * `FileManager::replace()`, which does both. `$written` collects every path
     * this writes so the caller can unlink them if the transaction rolls back:
     * disk writes are not transactional, and bytes left behind by a rolled-back
     * replacement are referenced by nothing and findable by nothing.
     *
     * @param  list<string>  $written
     */
    public function supersede(
        File $file,
        UploadedFile $upload,
        User $actor,
        ?string $note,
        array &$written,
    ): File {
        $next = $this->nextVersion($file);
        $descriptor = $this->storage->putVersion($file, $upload, $next);
        $written[] = $descriptor['storage_path'];

        $this->archiveCurrent($file);
        $this->makeCurrent($file, $next, $descriptor, $actor, $note, revertedFrom: null);

        return $file;
    }

    /**
     * Bring an earlier version back as the next one.
     *
     * Forward, never backward. Moving the pointer back would leave a history
     * that no longer reads as a sequence of what the file actually was, and
     * would make "which bytes did the person who downloaded this on Tuesday
     * get" unanswerable. `version_reverted_from` records what it is a copy of,
     * so "version 4 is version 1 brought back" is structural rather than prose
     * in a note somebody may not have written.
     *
     * @param  list<string>  $written
     */
    public function revert(
        File $file,
        FileVersion $source,
        User $actor,
        ?string $note,
        array &$written,
    ): File {
        $next = $this->nextVersion($file);
        $descriptor = $this->storage->copyVersionForward($file, $source, $next);
        $written[] = $descriptor['storage_path'];

        $this->archiveCurrent($file);
        $this->makeCurrent($file, $next, $descriptor, $actor, $note, revertedFrom: (int) $source->version);

        return $file;
    }

    /**
     * A file's whole history, newest first.
     *
     * Two sources, one shape, and **this is the only place that seam is
     * crossed**: the current version is the `files` row, because there is
     * deliberately no `file_versions` row for it, and every reader — the JSON
     * endpoint, the document page, the file manager's modal — has to agree
     * about that. Two assemblers would be two ideas of which version is
     * current.
     *
     * No URLs are built here. A stream URL is a credential for bytes, so it
     * belongs to the controller that has already resolved who is asking; this
     * returns the facts about each version and nothing that opens one.
     *
     * @return list<array<string, mixed>>
     */
    public function history(File $file): array
    {
        $entries = [$this->describeCurrent($file)];

        foreach ($file->versions()->get() as $version) {
            $entries[] = $this->describeVersion($version);
        }

        return $entries;
    }

    /** One version of this file, or null — including the current one by number. */
    public function find(File $file, int $version): FileVersion|File|null
    {
        if ($version === $file->versionNumber()) {
            return $file;
        }

        return $file->versions()->where('version', $version)->first();
    }

    /**
     * Are these bytes the ones already current?
     *
     * `files.checksum` exists so this is answerable without reading bytes.
     *
     * It used to be a gate — `FileManager::replace()` refused an identical
     * upload — and it is a **label** now. Re-uploading the same bytes under
     * a different name is a real change to `original_name`, and the refusal
     * fired from the document edit form, where it failed a title change
     * because the file picker still held the file it was already holding.
     * The history stays truthful by recording `identical_bytes` on the row
     * rather than by refusing to record what somebody did.
     */
    public function isUnchanged(File $file, UploadedFile $upload): bool
    {
        $incoming = hash_file('sha256', $upload->getRealPath());

        return $incoming !== false
            && $file->checksum !== null
            && hash_equals($file->checksum, $incoming);
    }

    /**
     * Copy the current descriptor into history, exactly as it stands.
     *
     * `became_current_at` falls back to the row's creation time, because a file
     * uploaded before versioning existed has no `version_created_at` — and
     * guessing one would be inventing a date. The same reason its author may be
     * null: `files.user_id` is the *owner*, which `OwnershipTransferService`
     * rewrites when an account is closed, so copying it here would assert that
     * whoever holds the file now uploaded its first version.
     */
    private function archiveCurrent(File $file): void
    {
        FileVersion::create([
            'file_id' => $file->id,
            'version' => $file->versionNumber(),
            'disk' => $file->disk,
            'storage_path' => $file->storage_path,
            'original_name' => $file->original_name,
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'checksum' => $file->checksum,
            'has_thumbnail' => $file->has_thumbnail,
            'created_by_id' => $file->version_created_by_id,
            'created_by_name' => $file->version_created_by_name,
            'note' => $file->version_note,
            'reverted_from' => $file->version_reverted_from,
            'became_current_at' => $file->version_created_at ?? $file->created_at,
            'superseded_at' => now(),
        ]);
    }

    /**
     * Point the file at the new bytes and record whose they are.
     *
     * `has_thumbnail` is reset before the generator runs, so a format that
     * cannot be thumbnailed replacing one that could does not leave the row
     * claiming a thumbnail that belongs to the superseded version — which still
     * has its own, at its own path.
     *
     * @param  array{storage_path: string, original_name: string, extension: string, mime_type: string, size_bytes: int, checksum: string|null}  $descriptor
     */
    private function makeCurrent(
        File $file,
        int $version,
        array $descriptor,
        User $actor,
        ?string $note,
        ?int $revertedFrom,
    ): void {
        $file->update([
            ...$descriptor,
            'version' => $version,
            'has_thumbnail' => false,
            'version_created_by_id' => $actor->id,
            'version_created_by_name' => $actor->name,
            'version_note' => $note,
            'version_reverted_from' => $revertedFrom,
            'version_created_at' => now(),
        ]);

        $file->refresh();

        if ($this->thumbnails->supports($file)) {
            $this->thumbnails->generate($file);
        }
    }

    private function nextVersion(File $file): int
    {
        return $file->versionNumber() + 1;
    }

    /** @return array<string, mixed> */
    private function describeCurrent(File $file): array
    {
        return [
            'version' => $file->versionNumber(),
            'is_current' => true,
            'original_name' => $file->downloadName(),
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size' => $file->sizeBytes(),
            'checksum' => $this->shortChecksum($file->checksum),
            'has_thumbnail' => $file->hasThumbnail(),
            /*
             * Null where it is genuinely unknown, and the page says "Original
             * upload" rather than naming somebody. A file never replaced since
             * this feature landed has no author recorded for version 1, and
             * borrowing the owner's name would be a snapshot nobody took.
             */
            'created_by' => $file->version_created_by_name,
            'created_at' => $file->version_created_at ?? $file->created_at,
            'note' => $file->version_note,
            'reverted_from' => $file->version_reverted_from,
            'superseded_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function describeVersion(FileVersion $version): array
    {
        return [
            'version' => $version->versionNumber(),
            'is_current' => false,
            'original_name' => $version->downloadName(),
            'extension' => $version->extension,
            'mime_type' => $version->mime_type,
            'size' => $version->sizeBytes(),
            'checksum' => $this->shortChecksum($version->checksum),
            'has_thumbnail' => $version->hasThumbnail(),
            'created_by' => $version->created_by_name,
            'created_at' => $version->became_current_at,
            'note' => $version->note,
            'reverted_from' => $version->reverted_from,
            'superseded_at' => $version->superseded_at,
        ];
    }

    /**
     * Enough of the hash to compare two versions by eye, and no more.
     *
     * The whole digest is not a secret, but a column of 64-character hashes is
     * unreadable, and the question somebody actually asks of it is "are these
     * two the same file".
     */
    private function shortChecksum(?string $checksum): ?string
    {
        return $checksum === null ? null : substr($checksum, 0, 12);
    }
}
