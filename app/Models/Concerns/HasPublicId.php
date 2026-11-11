<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * An opaque public identifier, used in URLs in place of the primary key.
 *
 * 26 characters of `Str::random()` — 155 bits from the CSPRNG. Deliberately
 * not a ULID or UUIDv7: both lead with a timestamp, so they leak creation
 * order and time, and the requirement is that an identifier be unpredictable.
 *
 * The primary key stays a bigint. This is an extra column, not a replacement,
 * so every existing foreign key, index and join is untouched.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= self::freshPublicId();
        });
    }

    /**
     * Route-model binding resolves by the public identifier, so a controller
     * never has to translate one by hand and can never forget to.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }

    /**
     * A collision is vanishingly unlikely at 155 bits, but a duplicate here
     * would surface as a failed insert on an unrelated save, so it is cheaper
     * to check than to debug.
     */
    private static function freshPublicId(): string
    {
        do {
            $candidate = Str::random(26);
        } while (static::query()->withoutGlobalScopes()->where('public_id', $candidate)->exists());

        return $candidate;
    }
}
