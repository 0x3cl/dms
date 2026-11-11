<?php

namespace App\Models;

use App\Enums\ShareAbility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A shareable URL for one file or folder.
 *
 * The token *is* the credential — whoever holds the URL is the audience — which
 * is why a link never grants write and never grants manage, and why a
 * confidential item can never have one.
 */
class ShareLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'token', 'shareable_type', 'shareable_id', 'ability', 'expires_at',
        'revoked_at', 'max_views', 'view_count', 'last_accessed_at',
        'created_by_id', 'created_by_name',
    ];

    protected $casts = [
        'ability' => ShareAbility::class,
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'max_views' => 'integer',
        'view_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->token ??= Str::random(64);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Is this link still good?
     *
     * Three independent ways to be spent, and all three are checked on every
     * redemption rather than once at creation: revoked by hand, past its
     * expiry, or out of views.
     */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return ! ($this->max_views !== null && $this->view_count >= $this->max_views);
    }

    /**
     * Count a redemption.
     *
     * An atomic increment, not a read-modify-write: a link with `max_views = 1`
     * passed to two people at once would otherwise be spent twice, which is
     * exactly the case a view cap exists for.
     */
    public function recordAccess(): void
    {
        $this->newQuery()->whereKey($this->getKey())->update([
            'view_count' => $this->getConnection()->raw('view_count + 1'),
            'last_accessed_at' => now(),
        ]);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $inner) => $inner
                ->whereNull('max_views')
                ->orWhereColumn('view_count', '<', 'max_views'));
    }

    public function url(): string
    {
        return route('share.show', ['token' => $this->token]);
    }
}
