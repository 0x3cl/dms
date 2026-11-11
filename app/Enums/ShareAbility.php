<?php

namespace App\Enums;

/**
 * What a share or a share link grants.
 *
 * Deliberately coarser than App\Enums\FileAbility, which is the resolved
 * answer. Someone choosing who to share a folder with should be picking
 * between three understandable things, not ticking eight boxes — and each of
 * these expands to a fixed set, so what "Can edit" means cannot drift between
 * the screen that grants it and the resolver that reads it.
 */
enum ShareAbility: string
{
    case View = 'view';
    case Download = 'download';
    case Manage = 'manage';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Can view',
            self::Download => 'Can view and download',
            self::Manage => 'Can manage',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => 'Open and preview it. No copy leaves the server.',
            self::Download => 'Open it, and take a copy.',
            self::Manage => 'Open, take a copy, rename, move, delete and share it on.',
        };
    }

    /**
     * The abilities this grant expands to.
     *
     * `manage` is everything except ownership itself. It does **not** include
     * being able to hand ownership away, which only an owner can do.
     *
     * @return list<FileAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::View => [FileAbility::View],
            self::Download => [FileAbility::View, FileAbility::Download, FileAbility::Copy],
            self::Manage => [
                FileAbility::View,
                FileAbility::Download,
                FileAbility::Copy,
                FileAbility::Rename,
                FileAbility::Move,
                FileAbility::Delete,
                FileAbility::Share,
                FileAbility::Manage,
            ],
        };
    }

    /**
     * What a share *link* may carry.
     *
     * A link is held by whoever has the URL, so it never grants write and never
     * grants manage.
     *
     * @return list<self>
     */
    public static function linkable(): array
    {
        return [self::View, self::Download];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
