<?php

namespace App\Models;

use App\Enums\FileVisibility;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A folder in an account's storage tree.
 *
 * Every account has exactly one root (`is_root`), created on demand by
 * App\Services\Files\FolderService::rootFor(). Everything else has a parent,
 * which is what lets the `(parent_id, name)` unique index do its job.
 *
 * `path` is the materialized ancestor path — see the migration for why.
 */
class Folder extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id', 'user_id', 'owner_name', 'parent_id', 'is_root',
        'name', 'path', 'depth', 'visibility',
    ];

    protected $casts = [
        'is_root' => 'boolean',
        'depth' => 'integer',
        'visibility' => FileVisibility::class,
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * The public ids of every folder above this one, nearest last.
     *
     * Read straight off `path`, so it costs nothing — no query, however deep
     * the tree.
     *
     * @return list<string>
     */
    public function ancestorPublicIds(): array
    {
        return array_values(array_filter(explode('/', $this->path)));
    }

    /**
     * Every folder above this one, ordered root-first.
     *
     * One query regardless of depth. Ordered by `depth` rather than by the
     * path string so the caller can rely on nearest-last.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ids = $this->ancestorPublicIds();

        if ($ids === []) {
            return new Collection;
        }

        return self::query()
            ->whereIn('public_id', $ids)
            ->orderBy('depth')
            ->get();
    }

    /**
     * The path a *child* of this folder would carry.
     *
     * Kept here rather than in the service so the two places that build a path
     * — creating a folder and moving one — cannot disagree.
     */
    public function childPath(): string
    {
        return $this->path.$this->public_id.'/';
    }

    /** This folder and everything beneath it. */
    public function scopeSubtreeOf(Builder $query, self $folder): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('id', $folder->id)
            ->orWhere('path', 'like', $folder->childPath().'%'));
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
        return $query->when($term !== '', fn (Builder $inner) => $inner->where('name', 'like', "%{$term}%"));
    }
}
