<?php

namespace App\Enums;

/**
 * Who a share is addressed to.
 *
 * A `role` is this application's notion of a group: it reuses the membership
 * the roles screen already manages, rather than adding a second, parallel idea
 * of a group that someone then has to keep in step with it.
 */
enum GranteeType: string
{
    case User = 'user';
    case Division = 'division';
    case Section = 'section';
    case Role = 'role';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Person',
            self::Division => 'Division',
            self::Section => 'Section',
            self::Role => 'Role',
        };
    }

    /** The `item_shares` column this type's grantee id lives in. */
    public function column(): string
    {
        return match ($this) {
            self::User => 'user_id',
            self::Division => 'division_id',
            self::Section => 'section_id',
            self::Role => 'role_id',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
