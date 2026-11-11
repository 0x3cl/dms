<?php

namespace App\Models;

use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Permission\Models\Role;

/**
 * One live grant over one file or folder.
 *
 * Not an audit row: it is current state, so a revoked or expired share stays in
 * the table (so the screen can show it, and so re-granting is a deliberate act)
 * but grants nothing.
 */
class ItemShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'shareable_type', 'shareable_id', 'grantee_type', 'user_id', 'division_id',
        'section_id', 'role_id', 'ability', 'granted_by_id', 'granted_by_name',
        'expires_at', 'revoked_at',
    ];

    protected $casts = [
        'grantee_type' => GranteeType::class,
        'ability' => ShareAbility::class,
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    /**
     * Shares that are actually in force right now.
     *
     * Every read of this table goes through here. A query that forgets either
     * clause hands out access that was revoked, which is the single worst way
     * this feature can fail.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    /**
     * Shares that reach this person: addressed to them, to their division, to
     * their section, or to a role they hold.
     *
     * Note what is *not* here. A null grantee column cannot match, because the
     * grantee foreign keys cascade — so a share whose division was deleted is
     * gone from the table rather than sitting here as a row with a null
     * `division_id` that `where('division_id', null)` would quietly match for
     * every user who also has no division.
     */
    public function scopeReaching(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->all();

        return $query->where(function (Builder $inner) use ($user, $roleIds): void {
            $inner->where(fn (Builder $q) => $q
                ->where('grantee_type', GranteeType::User->value)
                ->where('user_id', $user->id));

            if ($user->division_id !== null) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('grantee_type', GranteeType::Division->value)
                    ->where('division_id', $user->division_id));
            }

            if ($user->section_id !== null) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('grantee_type', GranteeType::Section->value)
                    ->where('section_id', $user->section_id));
            }

            if ($roleIds !== []) {
                $inner->orWhere(fn (Builder $q) => $q
                    ->where('grantee_type', GranteeType::Role->value)
                    ->whereIn('role_id', $roleIds));
            }
        });
    }

    public function scopeFor(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('shareable_type', $subject::class)
            ->where('shareable_id', $subject->getKey());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** What this grant is addressed to, for the screen. */
    public function granteeLabel(): string
    {
        return match ($this->grantee_type) {
            GranteeType::User => $this->user?->name ?? 'Deleted account',
            GranteeType::Division => $this->division?->name ?? 'Deleted division',
            GranteeType::Section => $this->section?->name ?? 'Deleted section',
            GranteeType::Role => Role::query()->whereKey($this->role_id)->value('name') ?? 'Deleted role',
        };
    }
}
