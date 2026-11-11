<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * The document types the office tracks, and the abbreviation each contributes
 * to a tracking code.
 *
 * These are the nine types that used to live in `DocumentController::TYPES`,
 * with the same codes, so tracking codes already issued keep their meaning.
 * They are seeded rather than hard-coded because adding a type, correcting an
 * abbreviation or retiring one is office business, not a deploy.
 *
 * `position` is the order the upload form offers them in — office convention,
 * not alphabetical. The seeder is idempotent on `code`: it will not duplicate
 * a type or overwrite a name the office has since corrected.
 */
class DocumentTypeSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string}>
     */
    private const TYPES = [
        ['code' => 'CONFID', 'name' => 'Confidential'],
        ['code' => 'ADOR', 'name' => 'Administrative Order'],
        ['code' => 'SO', 'name' => 'Special Order'],
        ['code' => 'MEMO', 'name' => 'Memo'],
        ['code' => 'TRACE', 'name' => 'TRACE docs'],
        ['code' => 'COS', 'name' => 'Contract of Service'],
        ['code' => 'MOU', 'name' => 'Memorandum of Understanding'],
        ['code' => 'JRU', 'name' => 'JRU'],
        ['code' => 'IPDP', 'name' => 'IPCR/DPCR'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $position => $type) {
            DocumentType::query()->firstOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'is_active' => true,
                    'position' => $position + 1,
                ],
            );
        }
    }
}
