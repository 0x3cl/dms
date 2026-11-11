<?php

namespace App\Services\Access;

use App\Enums\FileAbility;

/**
 * The answer to "what may this person do with this item, and why".
 *
 * The `source` is carried so an audit row can record *why* an action was
 * allowed, not merely that it was. After the fact, "allowed because of the
 * division it was shared with" and "allowed because the item was public" are
 * very different answers to the same question.
 */
final readonly class AccessGrant
{
    /**
     * @param  list<FileAbility>  $abilities
     */
    public function __construct(
        public array $abilities,
        public AccessSource $source,
        public ?int $shareId = null,
    ) {}

    public static function denied(): self
    {
        return new self([], AccessSource::Denied);
    }

    /**
     * @param  list<FileAbility>  $abilities
     */
    public static function of(array $abilities, AccessSource $source, ?int $shareId = null): self
    {
        return $abilities === []
            ? self::denied()
            : new self(array_values(array_unique($abilities, SORT_REGULAR)), $source, $shareId);
    }

    public function allows(FileAbility $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function isDenied(): bool
    {
        return $this->abilities === [];
    }

    /** @return list<string> */
    public function abilityValues(): array
    {
        return array_map(fn (FileAbility $ability) => $ability->value, $this->abilities);
    }
}
