<?php

namespace App\Enums;

/**
 * Every entry a document's trail can carry.
 *
 * These used to be human-readable strings written straight into the column
 * (`'Received and Downloaded'`, `'Acknowledged / Forwarded'`) and compared
 * against literals in three controllers and two Vue pages. The stored value
 * is a slug now and the sentence is `label()`, which is the PROCMIS
 * convention: the wording on screen can be corrected without rewriting
 * history, and a mistyped comparison is a type error rather than a check that
 * quietly never matches.
 *
 * `postable()` is the subset a user may submit through the "log activity"
 * form. The rest are written by the system when it happens — the hand-off
 * endpoint used to accept any string up to 255 characters, so a client could
 * forge a "received" entry it had not earned.
 */
enum DocumentAction: string
{
    case Uploaded = 'uploaded';
    case Viewed = 'viewed';
    case Downloaded = 'downloaded';
    case Received = 'received';
    case Acknowledged = 'acknowledged';
    case Reviewed = 'reviewed';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Viewed => 'Viewed file',
            self::Downloaded => 'Downloaded file',
            self::Received => 'Received and downloaded',
            self::Acknowledged => 'Acknowledged / Forwarded',
            self::Reviewed => 'Reviewed',
            self::Returned => 'Returned',
        };
    }

    /** Font Awesome icon for the trail entry. */
    public function icon(): string
    {
        return match ($this) {
            self::Uploaded => 'fa-solid fa-upload',
            self::Viewed => 'fa-solid fa-eye',
            self::Downloaded => 'fa-solid fa-download',
            self::Received => 'fa-solid fa-inbox',
            self::Acknowledged => 'fa-solid fa-share-from-square',
            self::Reviewed => 'fa-solid fa-circle-check',
            self::Returned => 'fa-solid fa-rotate-left',
        };
    }

    /**
     * The actions a user may record by hand. Everything else is recorded by
     * the system at the moment it happens and must not be submittable.
     *
     * @return list<self>
     */
    public static function postable(): array
    {
        return [self::Acknowledged, self::Reviewed, self::Returned];
    }

    /**
     * The actions that mean this person took delivery of a copy — what the
     * dashboard counts as a document received.
     *
     * @return list<self>
     */
    public static function deliveries(): array
    {
        return [self::Downloaded, self::Received];
    }

    /** @return list<string> */
    public static function postableValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::postable());
    }

    /** @return list<string> */
    public static function deliveryValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::deliveries());
    }

    /**
     * The shape the Vue pages render: the stored value, the sentence and the
     * icon, so no page has to carry its own copy of this mapping.
     *
     * @return list<array{value: string, label: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'icon' => $case->icon(),
        ], self::postable());
    }
}
