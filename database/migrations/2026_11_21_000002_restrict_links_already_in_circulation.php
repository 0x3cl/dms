<?php

use App\Enums\DocumentVisibility;
use App\Models\Document;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hold every link already handed out to the reach it had when it was sent.
 *
 * What a share link's term means has changed. It used to say only what the
 * holder could *do* — view, or view and take a copy — while whether a
 * restricted document's link opened at all was read off the document's level,
 * and the answer there was always no. The term now decides both: a view or
 * download link is redeemed by whoever holds the URL, whatever the document's
 * level, and `restricted` is the term that defers to the reader's own access.
 *
 * Applied to the rows as they stand, that is a silent **widening**: every
 * `view` link sitting on a private document — including the ones minted
 * automatically for QR labels, printed, and stuck to folders in an office —
 * would become a bearer credential on the day this deploys, for URLs sent
 * while the rule said the opposite. The one rule this module is built on is
 * that a link's reach is only ever narrowed after it has been sent.
 *
 * So every live link on a document restricted to a named list is moved onto
 * the term that matches what it did yesterday. Widening one is then a
 * deliberate act by its uploader, on the document's own screen, which is the
 * whole point of making it a choice.
 *
 * Files and folders are untouched: a link on one has always been a bearer
 * credential and still is, and `restricted` is not a term they may carry.
 *
 * `down()` is the exact inverse of what this writes, which is only safe
 * because the term did not exist before it ran — nothing could already be
 * `restricted` by choice, so there is nothing to mistake for a row this
 * migration made.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('share_links')
            ->where('shareable_type', Document::class)
            ->whereNull('revoked_at')
            ->whereIn('ability', ['view', 'download'])
            ->whereIn('shareable_id', $this->restrictedDocumentIds())
            ->update(['ability' => 'restricted']);
    }

    public function down(): void
    {
        DB::table('share_links')->where('ability', 'restricted')->update(['ability' => 'view']);
    }

    /**
     * The documents whose level is a list of names.
     *
     * Read through the enum rather than written out, so a level added later
     * that restricts to named people is covered by the same statement.
     *
     * @return list<int>
     */
    private function restrictedDocumentIds(): array
    {
        $levels = array_map(
            fn (DocumentVisibility $visibility) => $visibility->value,
            array_filter(
                DocumentVisibility::cases(),
                fn (DocumentVisibility $visibility) => $visibility->restrictedToNamedPeople(),
            ),
        );

        return DB::table('documents')
            ->whereIn('visibility', $levels)
            ->pluck('id')
            ->all();
    }
};
