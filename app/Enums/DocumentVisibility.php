<?php

namespace App\Enums;

/**
 * Who may open a document.
 *
 * Three levels, and the thing to hold onto is that they answer **two**
 * questions rather than one — who may read it while signed in, and whether it
 * may be reached by a link at all:
 *
 * | Level | Signed-in readers | A share link |
 * | --- | --- | --- |
 * | `Public` | anyone holding the tracking code | yes — opens without an account |
 * | `Private` | the uploader and the people tagged on it | yes, but it opens only for them |
 * | `Confidential` | the uploader and the people tagged on it | **never** |
 *
 * The middle row is narrower than it was. A private document's link used to
 * open for whoever held it, which made the token a key to something whose
 * whole classification is a list of names; it now answers the holder's own
 * access, so it is a convenient, revocable, countable way to send a tagged
 * colleague straight to the document and nothing more. What survives of the
 * old distinction is that a private document can have a link at all.
 *
 * So private and confidential grant exactly the same *reading*, and differ only
 * in whether that reading can be handed to somebody outside the list. That is
 * not a redundancy: it is the whole distinction the office asked for. A private
 * document is restricted but still something you can send to a colleague
 * without an account; a confidential one is restricted to the named list and
 * nothing else, so no URL exists that would open it for whoever holds it.
 *
 * This mirrors `FileVisibility`, which has had three levels from the start, and
 * deliberately shares its vocabulary — the two modules are one workflow over
 * one store, and "confidential" meaning one thing for a file and another for a
 * document is how an office ends up filing a confidential document somewhere it
 * is not confidential. What it does **not** share is inheritance: a tracked
 * document has no containing folder, so there is nothing above it to clamp it.
 *
 * `AccessResolver::forDocument()` is the only gate that reads this.
 * `DocumentLogController::canSee()` delegates to the same resolver, because a
 * document's travel history is exactly as sensitive as the document.
 */
enum DocumentVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Confidential = 'confidential';

    /**
     * The document level matching a file's own.
     *
     * The two enums share their *values* — `private`, `public`,
     * `confidential` — which is what lets a file and a document badge
     * identically, and is also the trap: a `match` on the case would be a
     * third statement of a correspondence the values already make. So it is
     * read `from()` the value, and a level either enum gains without the
     * other is a hard failure here rather than a silent fall to public.
     *
     * Used when a file is filed automatically: the document takes the
     * classification the bytes already had. Never the reverse — a document's
     * level is a decision about readers, and writing it back onto the file
     * row would make an automatic filing govern its own file, which is the
     * one thing `File::governingDocument()` exists to prevent.
     */
    public static function forFile(FileVisibility $visibility): self
    {
        return self::from($visibility->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private',
            self::Confidential => 'Confidential',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Anyone holding the tracking code can open it, and a link opens it without an account.',
            /*
             * No longer "plus anyone you send a link to". A private
             * document's link stops at whoever is holding it: the page it
             * opens states the level and nothing else unless the holder is
             * signed in and on the list. Describing it the old way told an
             * uploader the opposite of what the system does, on the very
             * screen where they choose the level.
             */
            self::Private => 'Only you and the people you tag. A link can be sent, but it opens only for them.',
            self::Confidential => 'Only you and the people you tag. It cannot be shared by link at all.',
        };
    }

    /**
     * The badge colour, matching `FileVisibility::tone()` level for level so
     * the same word is never two colours in one application.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Public => 'green',
            self::Private => 'orange',
            self::Confidential => 'red',
        };
    }

    /**
     * May a share link be minted for this document?
     *
     * Checked when the link is created **and** again on every redemption,
     * because a document can be reclassified as confidential after a link has
     * been sent — and the link has to stop working at that moment rather than
     * when somebody remembers to revoke it. The same rule, for the same reason,
     * as `FileVisibility::linkable()`.
     */
    public function linkable(): bool
    {
        return $this !== self::Confidential;
    }

    /**
     * Is reading restricted to the uploader and the tagged list?
     *
     * True for both of the restricted levels. Written as a question about
     * *reading* rather than as `!== Public`, so that the resolver's clause says
     * what it means and a fourth level later cannot silently join the public
     * side by omission.
     */
    public function restrictedToNamedPeople(): bool
    {
        return $this !== self::Public;
    }

    /** Levels on which people may be tagged. */
    public function taggable(): bool
    {
        return $this->restrictedToNamedPeople();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
