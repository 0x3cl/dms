<?php

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * Something with bytes on a disk: a file's current version, or one of its
 * superseded ones.
 *
 * It exists so that serving a version is not a `File|FileVersion` union
 * repeated at every call site. `FileStorage`, `ThumbnailGenerator` and
 * `FilePreview` between them read the storage path, the MIME type, the
 * extension, the download name, the checksum, the size, the modified time and
 * the thumbnail of whatever they are handed — nine places that would each have
 * to write `$version?->storage_path ?? $file->storage_path` and agree about it
 * forever. One contract is one place.
 *
 * **It says nothing about access, and must not.** Who may read a file is
 * decided by `App\Services\Access\AccessResolver` against the `File` — or,
 * when the file carries a tracking code, against its `Document`. A version has
 * no visibility, no shares and no links of its own: whoever may read the file
 * may read its history, and whoever may not, may not. Teaching the resolver to
 * take a version would be the beginning of a second, quieter door to the same
 * bytes, which is exactly what `AccessResolver::forFile()` delegating to
 * `forDocument()` exists to prevent. So this interface is for *reading bytes
 * once permission is already settled*, and nothing here is ever the subject of
 * an authorization call.
 */
interface StoredBytes
{
    /** The disk these bytes live on. */
    public function diskName(): string;

    /**
     * Where they are on it.
     *
     * Always read from the stored column, never recomputed: `FilePathFactory`
     * decides where the *next* write goes, and a version's extension may
     * differ from the file's current one, so the column is the only authority
     * at read time.
     */
    public function storagePath(): string;

    public function mimeType(): string;

    public function extensionName(): string;

    /** The filename a download should carry. */
    public function downloadName(): string;

    public function checksumValue(): ?string;

    public function sizeBytes(): int;

    /** For the `Last-Modified` header; null when it cannot be known. */
    public function modifiedAt(): ?Carbon;

    public function hasThumbnail(): bool;

    /** Where this version's thumbnail is, whether or not one has been made. */
    public function thumbnailPath(): string;

    public function versionNumber(): int;
}
