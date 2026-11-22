<?php

namespace App\Enums;

/**
 * Where a request to be let into a document has got to.
 *
 * An enum rather than a string for the reason `DocumentStatus` taught this
 * codebase the hard way: a status compared by hand against a literal is a
 * comparison that can silently never match, and nobody notices until something
 * depends on the answer.
 */
enum AccessRequestStatus: string
{
    case Pending = 'pending';
    case Granted = 'granted';
    case Denied = 'denied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting a decision',
            self::Granted => 'Granted',
            self::Denied => 'Declined',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Granted => 'emerald',
            self::Denied => 'rose',
        };
    }

    /** A decided request cannot be decided again. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
