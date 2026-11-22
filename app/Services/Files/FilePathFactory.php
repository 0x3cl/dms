<?php

namespace App\Services\Files;

use App\Models\File;

/**
 * The only place a storage path is built.
 *
 * A path is `{first two characters of public_id}/{public_id}.{ext}` — derived
 * entirely from values this application generated. Nothing a user supplied,
 * and in particular no uploaded filename, ever reaches the filesystem.
 *
 * That is the whole defence against path traversal and filename collision, and
 * it is a structural one rather than a filter: there is no input to sanitise,
 * because there is no input. It is also why extracting a zip is safe — an
 * archive entry never chooses where its bytes land.
 *
 * The two-character prefix shards the tree. A single flat directory holding
 * hundreds of thousands of files is slow to list and, on some filesystems,
 * slow to open a file in at all.
 *
 * ## Versions
 *
 * A file's versions are distinguished by a `-v{N}` segment, so the inputs are
 * `(public_id, version, extension)` — still only values this application
 * generated. **Version 1 has no segment**, which is deliberate: it returns
 * byte-identically what this class returned before versioning existed, so no
 * stored path anywhere needed rewriting. All of a file's versions also land
 * under its own shard, which makes "everything this file occupies on disk" one
 * directory listing.
 *
 * The rule that keeps this honest: **derive on write, read from the column.**
 * This class decides where the *next* write goes; `files.storage_path` and
 * `file_versions.storage_path` are the only authority at read time, because a
 * version's extension may differ from the file's current one and recomputing a
 * path from the wrong extension would point at bytes that are not there.
 */
class FilePathFactory
{
    public function for(File $file): string
    {
        return $this->forVersion($file->public_id, (int) ($file->version ?? 1), $file->extension);
    }

    public function fromParts(string $publicId, string $extension): string
    {
        return $this->forVersion($publicId, 1, $extension);
    }

    /** Where version `$version` of this file's bytes belong. */
    public function forVersion(string $publicId, int $version, string $extension): string
    {
        $shard = substr($publicId, 0, 2);
        $suffix = $this->normalizeExtension($extension);

        return "{$shard}/{$publicId}{$this->versionSegment($version)}{$suffix}";
    }

    /** The thumbnail beside its file, under the same shard. */
    public function thumbnailFor(File $file): string
    {
        return $this->thumbnailForVersion($file->public_id, (int) ($file->version ?? 1));
    }

    /**
     * A thumbnail per version, which is not tidiness.
     *
     * At one shared path a replacement would overwrite it, and every
     * superseded image in the history list would then render the *newest*
     * picture — a quiet lie in exactly the screen the history exists for. Per
     * version, a replacement invalidates nothing and only the new current
     * version needs generating.
     */
    public function thumbnailForVersion(string $publicId, int $version): string
    {
        $shard = substr($publicId, 0, 2);

        return "{$shard}/{$publicId}{$this->versionSegment($version)}-thumb.webp";
    }

    /** Empty for version 1, so every pre-versioning path still resolves. */
    private function versionSegment(int $version): string
    {
        return $version > 1 ? "-v{$version}" : '';
    }

    /**
     * An extension is kept only so the stored object has a recognisable tail;
     * it is never trusted and never used to decide a content type. Anything
     * that is not plainly alphanumeric is dropped rather than escaped, because
     * a path component here has no legitimate reason to contain anything else.
     */
    private function normalizeExtension(string $extension): string
    {
        $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');

        return $clean === '' ? '' : '.'.substr($clean, 0, 16);
    }
}
