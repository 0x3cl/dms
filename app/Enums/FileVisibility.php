<?php

namespace App\Enums;

/**
 * How widely a file or folder may be seen.
 *
 * Three levels rather than the documents module's two, because the brief
 * distinguishes "not public" from "restricted even from the people a parent
 * folder was shared with".
 */
enum FileVisibility: string
{
    case Private = 'private';

    /*
     * `public` is a reserved word, so the case cannot be named for its value.
     * The value is what is stored and what the UI sends.
     */
    case PublicItem = 'public';

    case Confidential = 'confidential';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::PublicItem => 'Public',
            self::Confidential => 'Confidential',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private => 'Only you and the people you share it with.',
            self::PublicItem => 'Anyone signed in to DocTrack can open it.',
            self::Confidential => 'Only you and people named on this item itself. Never inherited, and never reachable through a share link.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Private => 'amber',
            self::PublicItem => 'emerald',
            self::Confidential => 'rose',
        };
    }

    /**
     * How restrictive this level is, for resolving an item against the folders
     * above it: effective visibility is the **most** restrictive value found on
     * the item and all of its ancestors.
     */
    public function rank(): int
    {
        return match ($this) {
            self::PublicItem => 0,
            self::Private => 1,
            self::Confidential => 2,
        };
    }

    /**
     * May a share link be minted for this?
     *
     * A confidential item never leaves through a link. Checked when the link is
     * created *and* again when it is redeemed, because an item can be
     * reclassified after a link has been handed out.
     */
    public function linkable(): bool
    {
        return $this !== self::Confidential;
    }

    /**
     * The most restrictive of a set — the inheritance rule, in one place.
     *
     * @param  iterable<self>  $levels
     */
    public static function mostRestrictive(iterable $levels): self
    {
        $strictest = self::PublicItem;

        foreach ($levels as $level) {
            if ($level->rank() > $strictest->rank()) {
                $strictest = $level;
            }
        }

        return $strictest;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
