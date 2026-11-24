<?php

namespace App\Models;

use App\Contracts\StoredBytes;
use App\Services\Files\FilePathFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file as it was before it was replaced.
 *
 * Only **superseded** versions live here. The current one is the `files` row
 * itself, which is what keeps `(folder_id, name)` unique, keeps a file's
 * `public_id` and therefore its URL stable across a replacement, and keeps the
 * current bytes stated in exactly one place. A row here for the current version
 * would be a second statement of "where are the bytes", and a second statement
 * is how two copies of one fact drift apart.
 *
 * So the history of a file is "that row's own values, plus these rows" — see
 * App\Services\Files\FileVersionService::history(), which is the only place
 * that seam is crossed.
 *
 * **A row describes the version it holds, not the act that ended it.** It
 * carries its own author, note and dates, because the obvious shape — only
 * `superseded_at` and who did the superseding — records "who put version 3
 * there" on version 2's row and "who put the current version there" nowhere at
 * all. It cannot be borrowed from `activity_logs` either: that table is behind
 * `activity-trails.read`, while this history is readable by anyone who may read
 * the file.
 *
 * A version carries a full copy of the metadata rather than a diff, because a
 * version has to be servable on its own: which viewer draws it is decided from
 * its extension and MIME type, and a replacement is free to change both.
 *
 * **It is not an access subject.** A version has no visibility, no shares and
 * no links of its own; whoever may read the file may read its history, which is
 * resolved against the file by App\Services\Access\AccessResolver exactly as
 * before. Giving a version its own rule would be a second, quieter door to the
 * same bytes — see the note on App\Contracts\StoredBytes.
 */
class FileVersion extends Model implements StoredBytes
{
    use HasFactory;

    protected $fillable = [
        'file_id', 'version', 'disk', 'storage_path', 'original_name', 'extension',
        'mime_type', 'size_bytes', 'checksum', 'has_thumbnail',
        'created_by_id', 'created_by_name', 'note', 'reverted_from',
        'became_current_at', 'superseded_at',

        'stamp', ];

    protected $casts = [
        'version' => 'integer',
        'size_bytes' => 'integer',
        'has_thumbnail' => 'boolean',
        'reverted_from' => 'integer',
        'became_current_at' => 'datetime',
        'superseded_at' => 'datetime',

        'stamp' => \App\Enums\DocumentStamp::class, ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** Who put this version there — the live account, where it still exists. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function diskName(): string
    {
        return (string) $this->disk;
    }

    public function storagePath(): string
    {
        return (string) $this->storage_path;
    }

    public function mimeType(): string
    {
        return (string) $this->mime_type;
    }

    public function extensionName(): string
    {
        return (string) $this->extension;
    }

    /**
     * The name a download of this version should carry.
     *
     * Mirrors File::downloadName(), because one controller serves both and a
     * version must not come down named after an internal identifier.
     */
    public function downloadName(): string
    {
        return $this->original_name !== '' && $this->original_name !== null
            ? $this->original_name
            : "version-{$this->version}";
    }

    public function checksumValue(): ?string
    {
        return $this->checksum;
    }

    public function sizeBytes(): int
    {
        return (int) $this->size_bytes;
    }

    /**
     * When these bytes last changed — which, for a superseded version, is when
     * it stopped being current. Its own bytes never change again.
     */
    public function modifiedAt(): ?Carbon
    {
        return $this->superseded_at ?? $this->updated_at;
    }

    public function hasThumbnail(): bool
    {
        return (bool) $this->has_thumbnail;
    }

    public function thumbnailPath(): string
    {
        return app(FilePathFactory::class)
            ->thumbnailForVersion($this->file->public_id, $this->version);
    }

    public function versionNumber(): int
    {
        return (int) $this->version;
    }
}
