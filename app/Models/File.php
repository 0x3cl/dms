<?php

namespace App\Models;

use App\Contracts\StoredBytes;
use App\Enums\FileVisibility;
use App\Models\Concerns\HasPublicId;
use App\Services\Files\FilePathFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One stored file.
 *
 * The bytes live on the private `documents` disk at a path built only by
 * App\Services\Files\FilePathFactory, from this row's `public_id`. The name the
 * user uploaded is metadata (`original_name`) and never touches the
 * filesystem, which is what makes path traversal and filename collisions
 * structurally impossible rather than filtered against.
 *
 * A file that also carries a tracking code has a `documents` row pointing back
 * at it. That is the only difference between a tracked document and any other
 * file.
 *
 * **This row is the file's current version**, and `storage_path` is the one
 * statement of where its bytes are. Superseded versions move to `file_versions`
 * when a replacement arrives; the `version_*` columns here are the current
 * version's own descriptor, mirrored so that the history reads out of one shape
 * whichever of the two tables a version came from. See
 * App\Services\Files\FileVersionService.
 */
class File extends Model implements StoredBytes
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id', 'user_id', 'owner_name', 'folder_id', 'name', 'original_name',
        'extension', 'mime_type', 'size_bytes', 'checksum', 'disk', 'storage_path',
        'visibility', 'source', 'has_thumbnail',
        'version', 'version_created_by_id', 'version_created_by_name',
        'version_note', 'version_reverted_from', 'version_created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'visibility' => FileVisibility::class,
        'has_thumbnail' => 'boolean',
        'version' => 'integer',
        'version_reverted_from' => 'integer',
        'version_created_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /** The tracking jacket, when this file is a tracked document. */
    public function document(): HasOne
    {
        return $this->hasOne(Document::class);
    }

    /**
     * Every superseded version, newest first.
     *
     * The current version is **not** in here — it is this row. Assemble the
     * whole history through App\Services\Files\FileVersionService::history(),
     * which is the only place that seam is crossed.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class)->orderByDesc('version');
    }

    /** Has this file ever been replaced? */
    public function hasHistory(): bool
    {
        return (int) ($this->version ?? 1) > 1;
    }

    /**
     * Does this file carry a tracking code?
     *
     * Read by the event recorder: a tracked file's view or download also writes
     * the document's own travel trail, while an untracked one writes only the
     * activity log.
     */
    public function isTracked(): bool
    {
        return $this->document()->exists();
    }

    /**
     * Every folder from the root down to the one holding this file.
     *
     * @return Collection<int, Folder>
     */
    public function ancestors(): Collection
    {
        $folder = $this->folder;

        if (! $folder) {
            return new Collection;
        }

        return $folder->ancestors()->push($folder);
    }

    /**
     * The visibility that actually applies: the most restrictive value on this
     * file and every folder above it, excluding the account's root.
     *
     * Delegates to App\Services\Access\AccessResolver, which is where the rule
     * lives. It had its own copy here, which is exactly how the resolver, the
     * share-link service and the listing scopes end up disagreeing about what
     * "confidential" reaches.
     */
    public function effectiveVisibility(): FileVisibility
    {
        return app(\App\Services\Access\AccessResolver::class)->effectiveVisibility($this);
    }

    /** The filename a download should be served under. */
    public function downloadName(): string
    {
        return $this->original_name !== '' ? $this->original_name : $this->name;
    }

    /*
     * StoredBytes — this row, as the bytes it currently holds.
     *
     * Implemented so that serving the current version and serving a superseded
     * one is one code path rather than a `File|FileVersion` union repeated at
     * every call site in FileStorage, ThumbnailGenerator and FilePreview.
     * Nothing here says anything about access: that is resolved against this
     * model, never against a version.
     */

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

    public function checksumValue(): ?string
    {
        return $this->checksum;
    }

    public function sizeBytes(): int
    {
        return (int) $this->size_bytes;
    }

    public function modifiedAt(): ?Carbon
    {
        return $this->updated_at;
    }

    public function hasThumbnail(): bool
    {
        return (bool) $this->has_thumbnail;
    }

    public function thumbnailPath(): string
    {
        return app(FilePathFactory::class)->thumbnailFor($this);
    }

    public function versionNumber(): int
    {
        return (int) ($this->version ?? 1);
    }

    public function shares(): MorphMany
    {
        return $this->morphMany(ItemShare::class, 'shareable');
    }

    public function shareLinks(): MorphMany
    {
        return $this->morphMany(ShareLink::class, 'shareable');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Search a listing by name — or by tracking code.
     *
     * A tracked document *is* a file, so typing its code in My Files should
     * find it. It would not before: the code lives on `documents`, so the only
     * way to reach a tracked file from here was to already know its filename.
     *
     * `whereExists` rather than a join, so the paginator's totals stay honest
     * — a join against a one-to-one would be harmless today and quietly
     * duplicate rows the moment the relationship changes.
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        return $query->when($term !== '', fn (Builder $inner) => $inner
            ->where(fn (Builder $group) => $group
                ->where('name', 'like', "%{$term}%")
                ->orWhere('original_name', 'like', "%{$term}%")
                ->orWhereExists(fn ($exists) => $exists
                    ->selectRaw('1')
                    ->from('documents')
                    ->whereColumn('documents.file_id', 'files.id')
                    ->whereNull('documents.deleted_at')
                    ->where('documents.code', 'like', "%{$term}%"))));
    }
}
