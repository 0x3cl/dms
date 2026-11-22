<?php

namespace App\Enums;

/**
 * What a notification in the header bell is *about*.
 *
 * The bell used to render one bell icon over every row, so a document shared
 * with you, somebody asking to open one of yours, and an answer to something
 * you asked for were three different events reading as one list of sentences.
 * A reader with nine of them had to read all nine to find out whether any of
 * them needed an answer.
 *
 * It is an enum beside `DocumentAction` rather than an icon string written
 * into each notification, for the same reason that one is: the stored value is
 * a slug and the wording, the symbol and the colour are read off it, so a
 * correction to any of the three does not have to be applied to rows already
 * written. A notification is history — it says what happened and when — and
 * nothing may rewrite it.
 *
 * `tryFrom()` is how every reader gets at it, because the oldest rows predate
 * the key entirely: a notification written before this existed has no kind,
 * which is a gap rather than an error, and the bell falls back to a plain
 * bell. Inventing one by parsing the sentence would be a guess recorded as a
 * fact.
 */
enum NotificationKind: string
{
    /** A restricted document was shared with you — by upload, or by an edit. */
    case Shared = 'shared';

    /** Somebody is asking to open a document you uploaded. */
    case AccessRequested = 'access_requested';

    /** Something you asked for was granted, or was not. */
    case AccessGranted = 'access_granted';
    case AccessDeclined = 'access_declined';

    /** The short word the bell puts above the sentence. */
    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Shared with you',
            self::AccessRequested => 'Access requested',
            self::AccessGranted => 'Access granted',
            self::AccessDeclined => 'Access declined',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Shared => 'fa-solid fa-share-from-square',
            self::AccessRequested => 'fa-solid fa-user-clock',
            self::AccessGranted => 'fa-solid fa-lock-open',
            self::AccessDeclined => 'fa-solid fa-circle-xmark',
        };
    }

    /**
     * The colour of the badge on the face, grouped by what the row *means*
     * rather than one shade each — the same rule `DocumentAction::tone()`
     * follows. Something arriving for you is blue, something waiting on you is
     * amber, a yes is green and a no is rose.
     *
     * Tailwind utilities rather than a token, because the bell renders them
     * directly, and with no `dark:` variants: the dark theme remaps these same
     * utilities under `html.dark`.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Shared => 'bg-sky-100 text-[#07559e]',
            self::AccessRequested => 'bg-amber-100 text-amber-600',
            self::AccessGranted => 'bg-emerald-100 text-emerald-600',
            self::AccessDeclined => 'bg-rose-100 text-rose-600',
        };
    }
}
