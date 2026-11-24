<?php

namespace App\Enums;

/**
 * How a document came to be on the register.
 *
 * Every uploaded file is filed now, so the register would otherwise be a list
 * of every byte in the institute — drafts, scratch files, each entry of every
 * zip — and a register nobody reads is a register that has stopped being the
 * office's record. This is the distinction that keeps it readable: what
 * somebody sat down and filed, and what was given a code because it arrived.
 *
 * **It is also the hinge on which file access turns, and that is the load
 * bearing half.** `AccessResolver::forFile()` answers a tracked file by its
 * document's rule *and nothing else* — no item share, no folder inheritance,
 * no administrator read, because the obfuscated file URL must not be a looser
 * second door to a document somebody deliberately classified. Applied to
 * every file that rule is a catastrophe rather than a protection: a colleague
 * would lose the files inside a folder shared with them, and Browse Storage
 * would go blank for an administrator. So only a *deliberate* filing governs
 * its bytes. An automatic one is a jacket: a code, a label, a trail, and no
 * say over who may read the file it is stapled to.
 *
 * Neither case may ever be removed. `documents.filed` is a stored column and
 * this enum is what reads it — see `DocumentAction::Reclassified` for what
 * deleting a case does to rows that still carry its value.
 */
enum DocumentFiling: string
{
    /** Somebody filled in the form: a title, a type, a classification. */
    case Deliberate = 'deliberate';

    /** A file was uploaded and given a code because every file gets one. */
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Deliberate => 'Filed',
            self::Automatic => 'Automatic',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Deliberate => 'Somebody filed this deliberately, with a title and a classification.',
            self::Automatic => 'Given a code because every uploaded file gets one.',
        };
    }

    /**
     * Does this filing decide who may read the file underneath it?
     *
     * **Only a deliberate one.** The whole reason `AccessResolver::forFile()`
     * defers to a document is that somebody classified it and a recipient
     * list was built around it; neither is true of a file that was given a
     * code on its way into personal storage. Asked in exactly one place —
     * `File::governingDocument()` — so the four callers that need the answer
     * cannot each arrive at their own.
     */
    public function governsItsFile(): bool
    {
        return $this === self::Deliberate;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
