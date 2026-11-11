<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;

/**
 * The four DOST-PTRI divisions.
 *
 * The same structure PROCMIS seeds, so an account exists in the same office in
 * both systems and `employees.user_id` can be matched up between them. What is
 * absent here is PROCMIS's `skipped_statuses` column — that maps a division
 * onto its purchase-request clearance flow, which DocTrack has no equivalent of.
 *
 * **Not sample data.** Registration asks for a division and a section, so an
 * unseeded install cannot accept a sign-up at all — the same reason
 * `DocumentTypeSeeder` is required.
 *
 * `updateOrCreate` on `code` rather than `firstOrCreate`: the code is the short
 * stable handle that survives a rename, so re-running this corrects a name or a
 * description without creating a second division or disturbing the sections and
 * staff attached to the first.
 *
 * See documentation/modules/directory.md for the seeded structure.
 */
class DivisionSeeder extends Seeder
{
    /**
     * Keyed by the code that is printed and matched on; the name is what people
     * read.
     *
     * @var array<string, array{name: string, description: string}>
     */
    private const DIVISIONS = [
        'OD' => [
            'name' => 'Office of the Director',
            'description' => 'Executive office, planning, ICT, and technology transfer and promotion.',
        ],
        'RDD' => [
            'name' => 'Research and Development Division',
            'description' => 'Natural fiber utilization, and chemicals, dyes and by-product research.',
        ],
        'TSD' => [
            'name' => 'Technical Services Division',
            'description' => 'Physical, chemical and mechanical testing and processing services.',
        ],
        'FAD' => [
            'name' => 'Finance and Administrative Division',
            'description' => 'Accounting, budget and treasury, human resource and records, property and procurement.',
        ],
    ];

    public function run(): void
    {
        foreach (self::DIVISIONS as $code => $attributes) {
            Division::query()->updateOrCreate(['code' => $code], $attributes);
        }
    }
}
