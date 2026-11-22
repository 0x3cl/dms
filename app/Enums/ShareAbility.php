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
    /**
     * The link names the document and grants nothing of its own.
     *
     * Whoever opens it gets exactly what they would have got without it: the
     * uploader and the tagged people reach the document, and anybody else is
     * told it exists and is restricted. It is the one term that is *not* a
     * bearer credential — the URL is an address rather than a key — which is
     * why it is the default the moment a document is restricted to a named
     * list, and why a printed label on such a document can be photographed
     * without handing the document away.
     *
     * It is a **document's** term. A file or folder has no named-reader rule
     * of its own for the link to defer to, so `SharingService` refuses it
     * there rather than inventing one.
     */
    case Restricted = 'restricted';
    case View = 'view';
    case Download = 'download';
    case Manage = 'manage';

    public function label(): string
    {
        return match ($this) {
            self::Restricted => 'Restricted — people with access only',
            self::View => 'Can view',
            self::Download => 'Can view and download',
            self::Manage => 'Can manage',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Restricted => 'Opens only for the uploader and the people tagged on it. Anyone else is told it exists and is restricted.',
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
            /*
             * View, and the holder check decides whether it is reached.
             *
             * Not the empty set: the link still has to resolve *something*
             * for the uploader and the tagged reader it is meant for, and
             * every path that redeems one asks `opensForHolder()` as well,
             * which is where "people with access only" is actually enforced.
             */
            self::Restricted => [FileAbility::View],
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
        return [self::Restricted, self::View, self::Download];
    }

    /** @return list<string> */
    public static function linkableValues(): array
    {
        return array_map(fn (self $ability) => $ability->value, self::linkable());
    }

    /**
     * Is this a term only a tracked document can carry?
     *
     * `Restricted` defers to the subject's own rule about who may read it,
     * and a document is the only subject that has one — a file's rule is its
     * visibility and its shares, which a link is already the way around.
     */
    public function documentOnly(): bool
    {
        return $this === self::Restricted;
    }

    /**
     * Does holding this URL open the subject by itself?
     *
     * The question the landing page and the stream both turn on: a bearer
     * term is redeemed by whoever has it, a restricted one is redeemed only
     * by somebody the subject's own rule already admits.
     */
    public function isBearer(): bool
    {
        return $this !== self::Restricted;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
