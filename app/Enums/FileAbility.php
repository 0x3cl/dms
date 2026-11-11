<?php

namespace App\Enums;

/**
 * What someone may do with a particular file or folder.
 *
 * Distinct from the `{module}.{action}` permissions in App\Support\Modules, and
 * the difference is the whole access model: a module permission says the
 * capability exists for this user at all, an ability says it applies to *this*
 * item. Holding `files.delete` does not let you delete someone else's file.
 */
enum FileAbility: string
{
    case View = 'view';
    case Download = 'download';
    case Rename = 'rename';
    case Move = 'move';
    case Copy = 'copy';
    case Delete = 'delete';
    case Share = 'share';
    case Manage = 'manage';

    /** Everything an owner holds over their own item. */
    public static function all(): array
    {
        return self::cases();
    }

    /** What a read-only grant carries. */
    public static function readOnly(): array
    {
        return [self::View];
    }

    /** What a view-and-take-a-copy grant carries. */
    public static function readAndTake(): array
    {
        return [self::View, self::Download, self::Copy];
    }
}
