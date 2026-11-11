<?php

namespace App\Models;

use App\Enums\FileVisibility;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 */
class File extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id', 'user_id', 'owner_name', 'folder_id', 'name', 'original_name',
        'extension', 'mime_type', 'size_bytes', 'checksum', 'disk', 'storage_path',
        'visibility', 'source', 'has_thumbnail',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'visibility' => FileVisibility::class,
        'has_thumbnail' => 'boolean',
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

    public function scopeMatching(Builder $query, string $term): Builder
    {
        return $query->when($term !== '', fn (Builder $inner) => $inner
            ->where(fn (Builder $group) => $group
                ->where('name', 'like', "%{$term}%")
                ->orWhere('original_name', 'like', "%{$term}%")));
    }
}
