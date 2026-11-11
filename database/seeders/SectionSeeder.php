<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Section;
use Illuminate\Database\Seeder;

/**
 * The sections inside each DOST-PTRI division.
 *
 * Runs after DivisionSeeder, which it looks its parents up from. Grouped by
 * division code rather than carrying a `division_id`, because an id is an
 * accident of insertion order and a code is the office's own handle.
 *
 * > **Trap, and the one real difference from the PROCMIS seeder this is modelled
 * > on.** PROCMIS matches on `['code' => $sectionCode]` alone, because its
 * > section codes are globally unique. DocTrack's are unique **per division** —
 * > two divisions may each run a "Records" section, though one may not run two.
 * > Matching on the code alone here would mean that the day somebody adds a
 * > second section sharing a code in another division, re-running this seeder
 * > silently *moves* the existing one across divisions instead of leaving it
 * > alone. The match is `['division_id', 'code']`, the same pair the unique
 * > index enforces.
 */
class SectionSeeder extends Seeder
{
    /**
     * @var array<string, array<string, array{name: string, description: string}>>
     */
    private const SECTIONS = [
        'OD' => [
            'PICTS' => [
                'name' => 'Planning and Information Communication Technology Staff',
                'description' => 'Corporate planning, monitoring, and ICT systems and support.',
            ],
            'TIPS' => [
                'name' => 'Technology Transfer, Information and Promotion Staff',
                'description' => 'Technology transfer, publications, and public information.',
            ],
        ],

        'RDD' => [
            'NFUS' => [
                'name' => 'Natural Fiber Utilization Section',
                'description' => 'Research on the processing and application of natural fibers.',
            ],
            'CDABPUS' => [
                'name' => 'Chemicals, Dyes, Auxiliaries and By-Product Utilization Section',
                'description' => 'Research on textile chemicals, natural dyes and by-product utilization.',
            ],
        ],

        'TSD' => [
            'PCTS' => [
                'name' => 'Physical and Chemical Testing Section',
                'description' => 'Laboratory testing of textiles and related materials.',
            ],
            'MCPS' => [
                'name' => 'Mechanical and Chemical Processing Section',
                'description' => 'Pilot-scale spinning, weaving, dyeing and finishing services.',
            ],
        ],

        'FAD' => [
            'AS' => [
                'name' => 'Accounting Section',
                'description' => 'Bookkeeping, disbursement and financial reporting.',
            ],
            'BTS' => [
                'name' => 'Budget and Treasury Section',
                'description' => 'Budget preparation and execution, collections and cash management.',
            ],
            'HRRMS' => [
                'name' => 'Human Resource and Records Management Section',
                'description' => 'Personnel administration, and the records the institute keeps.',
            ],
            'PPGSS' => [
                'name' => 'Property, Procurement and General Services Section',
                'description' => 'Procurement, supply and property management, and general services.',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::SECTIONS as $divisionCode => $sections) {
            // firstOrFail, not firstOrCreate: a section whose division is
            // missing means DivisionSeeder has not run, and inventing a
            // placeholder division would hide that rather than report it.
            $division = Division::query()->where('code', $divisionCode)->firstOrFail();

            foreach ($sections as $code => $attributes) {
                Section::query()->updateOrCreate(
                    ['division_id' => $division->id, 'code' => $code],
                    $attributes,
                );
            }
        }
    }
}
