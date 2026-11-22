<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\DocumentTypeGroup;
use Illuminate\Database\Seeder;

/**
 * The document types the office tracks, and the abbreviation each contributes
 * to a tracking code.
 *
 * Seeded rather than hard-coded because adding a type, correcting an
 * abbreviation or retiring one is office business, not a deploy. A `code` is
 * immutable in practice — it is stamped into every tracking code that type has
 * issued, and those are printed on paper.
 *
 * `position` is the order the upload form offers them in — office convention,
 * not alphabetical. The seeder is idempotent on `code`: it will not duplicate
 * a type or overwrite a name the office has since corrected.
 *
 * `TRACE` and `JRU` were dropped from this list because their names were
 * placeholders rather than document types anyone could identify. Dropping a
 * line here does **not** delete an existing row — `firstOrCreate` only ever
 * adds — and that is deliberate: a type that has already issued tracking codes
 * must keep resolving, or the codes on those documents stop meaning anything.
 * Retire such a type from the Document Types screen with `is_active`, which is
 * what that flag is for.
 *
 * **Types are grouped.** The list had grown past the point where a flat
 * dropdown is a list of choices and become a wall somebody reads top to
 * bottom, so the picker asks the kind of thing first and the exact type
 * second. The groups are rows in `document_type_groups`, not an enum, because
 * the office will invent a group nobody writing this file thought of.
 *
 * A group is an arrangement of the picker and nothing more: it gates nothing,
 * it is not part of a tracking code, and a type whose group is deleted keeps
 * working and shows under "Other".
 *
 * **A type is a tag, and it gates nothing.** The document type picks the
 * tracking-code prefix and labels the row; who may read a document is
 * `documents.visibility`, decided on the upload form and read by
 * `AccessResolver::forDocument()` alone.
 *
 * That is worth stating twice here, because three of these types are named
 * after access levels and will be read as setting one. A document of type
 * "Confidential Document" is not confidential, one of type "Public Document"
 * is not public, and one of type "Private Document" is readable by whoever
 * its *visibility* says — somebody has to classify it, and filing it under
 * one of these names does not. They are seeded because the office files
 * paperwork under those headings; if the register ever shows a document whose
 * type and visibility disagree, the visibility is the answer.
 */
class DocumentTypeSeeder extends Seeder
{
    /**
     * The groups, and the types inside each.
     *
     * Ordered as the office reads them: the paperwork that carries a tracking
     * code first, then the things that are filed alongside it.
     *
     * @var list<array{code: string, name: string, description: string, types: list<array{code: string, name: string}>}>
     */
    private const GROUPS = [
        [
            'code' => 'DOC',
            'name' => 'Documents',
            'description' => 'Office paperwork — orders, memoranda and agreements.',
            'types' => [
                ['code' => 'CONFID', 'name' => 'Confidential Document'],
                ['code' => 'ADOR', 'name' => 'Administrative Order'],
                ['code' => 'SO', 'name' => 'Special Order'],
                ['code' => 'MEMO', 'name' => 'Memorandum'],
                ['code' => 'COS', 'name' => 'Contract of Service'],
                ['code' => 'MOU', 'name' => 'Memorandum of Understanding'],
                ['code' => 'MOA', 'name' => 'Memorandum of Agreement'],
                ['code' => 'IPDP', 'name' => 'IPCR / DPCR'],
                ['code' => 'LETTER', 'name' => 'Letter'],
                ['code' => 'REPORT', 'name' => 'Report'],
                ['code' => 'PUBDOC', 'name' => 'Public Document'],
                ['code' => 'PRIVDOC', 'name' => 'Private Document'],
                // Last in the group on purpose: a catch-all offered before
                // the named types is the one everybody picks.
                ['code' => 'OTHER', 'name' => 'Others'],
            ],
        ],
        [
            'code' => 'ASSET',
            'name' => 'Assets',
            'description' => 'Working files that belong to a document or a project.',
            'types' => [
                ['code' => 'FORM', 'name' => 'Form or Template'],
                ['code' => 'DATA', 'name' => 'Dataset or Spreadsheet'],
                ['code' => 'PRES', 'name' => 'Presentation'],
                ['code' => 'DESIGN', 'name' => 'Design or Artwork'],
            ],
        ],
        [
            'code' => 'IMG',
            'name' => 'Images',
            'description' => 'Photographs and scans.',
            'types' => [
                ['code' => 'PHOTO', 'name' => 'Photograph'],
                ['code' => 'SCAN', 'name' => 'Scanned Copy'],
                ['code' => 'CERT', 'name' => 'Certificate or Citation'],
            ],
        ],
        [
            'code' => 'VID',
            'name' => 'Videos',
            'description' => 'Recordings of events, training and interviews.',
            'types' => [
                ['code' => 'VIDEO', 'name' => 'Video Recording'],
                ['code' => 'TRAIN', 'name' => 'Training Material'],
                ['code' => 'AUDIO', 'name' => 'Audio Recording'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::GROUPS as $groupPosition => $group) {
            /*
             * Idempotent on `code`, like everything else here: a run must not
             * duplicate a group or overwrite a name the office has corrected.
             */
            $row = DocumentTypeGroup::query()->firstOrCreate(
                ['code' => $group['code']],
                [
                    'name' => $group['name'],
                    'description' => $group['description'],
                    'position' => $groupPosition + 1,
                ],
            );

            foreach ($group['types'] as $type) {
                /*
                 * The next free position, read per insert rather than counted
                 * off from one.
                 *
                 * A running counter is right exactly once — on a fresh
                 * install, where it produces 1..n in declaration order, which
                 * is what this does too. On a database that already holds
                 * these rows it is wrong: adding a type to the middle of the
                 * list hands it a number another type already has, and two
                 * rows tied on `position` order arbitrarily. `MOA` and
                 * `LETTER` both came out as 8 the first time these four were
                 * added.
                 *
                 * So a new type appends. Within its group that is last, which
                 * is where a type added after the office has been using the
                 * list belongs anyway — and the office reorders from the
                 * Document Types screen, which is whose job it is.
                 */
                $next = (int) DocumentType::withTrashed()->max('position') + 1;

                $existing = DocumentType::query()->firstOrCreate(
                    ['code' => $type['code']],
                    [
                        'name' => $type['name'],
                        'is_active' => true,
                        'position' => $next,
                        'group_id' => $row->id,
                    ],
                );

                /*
                 * A type that predates grouping is filed into its group, and
                 * only then. Nothing else about it is touched: its name may
                 * have been corrected, its order rearranged, and it may have
                 * been retired deliberately — none of which this seeder has
                 * any business undoing.
                 */
                if ($existing->group_id === null) {
                    $existing->forceFill(['group_id' => $row->id])->save();
                }
            }
        }
    }
}
