<?php

namespace App\Enums;

/**
 * Who may read a document. `Public` means anyone holding the tracking code;
 * `Private` means the uploader and the recipients named at upload time.
 *
 * DocumentController::isAuthorized() is the only gate that reads this, and
 * DocumentLogController::canSee() mirrors it because a document's history is
 * exactly as sensitive as the document.
 */
enum DocumentVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Readable by anyone holding the tracking code.',
            self::Private => 'Readable only by you and the recipients you name.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
