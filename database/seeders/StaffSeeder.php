<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ten `Staff` accounts, for exercising the things one account cannot.
 *
 * Document ownership, sharing, recipients, the file manager's badges, the
 * directory's section filter and every per-record access rule need *other
 * people* to be meaningful: with a single administrator, "shared with me" has
 * nobody to share from and every access check passes for the wrong reason.
 *
 * Three decisions in here are worth knowing.
 *
 * **It is fixed data, not `fake()`.** Every account has a written-down employee
 * ID, name and section, so the same seeder produces the same ten people on
 * every machine. A tester writing "log in as 1004 and check the Records folder"
 * means something; "log in as whoever ended up fourth" does not.
 *
 * **It is idempotent on `employee_id`.** `updateOrCreate` matches the sign-in
 * credential, which is the one column that identifies a person here — so
 * running it again after a section is renamed corrects the posting instead of
 * creating a second Dela Cruz. The password is deliberately *not* rewritten:
 * somebody testing may have changed it, and a seeder that silently resets
 * credentials on an existing account is a seeder nobody can run twice.
 *
 * **It is test data, and `DatabaseSeeder` says so.** It is not called by
 * default. The document types, roles, divisions and sections are load-bearing —
 * nothing can be uploaded or reached without them — and these ten people are
 * not. `php artisan db:seed --class=StaffSeeder` is the opt-in.
 */
class StaffSeeder extends Seeder
{
    /**
     * The office, as ten people.
     *
     * Spread across all four divisions on purpose, and two of them share a
     * section: a section with exactly one member cannot show that a share
     * granted to a section reaches more than the person who made it.
     *
     * @var list<array{employee_id: string, first_name: string, last_name: string, section: string}>
     */
    private const STAFF = [
        ['employee_id' => '1001', 'first_name' => 'Maria',   'last_name' => 'Santos',    'section' => 'OD/PICTS'],
        ['employee_id' => '1002', 'first_name' => 'Jose',    'last_name' => 'Rizal',     'section' => 'OD/TIPS'],
        ['employee_id' => '1003', 'first_name' => 'Andrea',  'last_name' => 'Bonifacio', 'section' => 'RDD/NFUS'],
        ['employee_id' => '1004', 'first_name' => 'Emilio',  'last_name' => 'Aguinaldo', 'section' => 'RDD/CDABPUS'],
        ['employee_id' => '1005', 'first_name' => 'Gabriela', 'last_name' => 'Silang',    'section' => 'TSD/PCTS'],
        ['employee_id' => '1006', 'first_name' => 'Apolinario', 'last_name' => 'Mabini', 'section' => 'TSD/MCPS'],
        ['employee_id' => '1007', 'first_name' => 'Melchora', 'last_name' => 'Aquino',    'section' => 'FAD/AS'],
        ['employee_id' => '1008', 'first_name' => 'Juan',    'last_name' => 'Luna',      'section' => 'FAD/BTS'],
        ['employee_id' => '1009', 'first_name' => 'Trinidad', 'last_name' => 'Tecson',    'section' => 'FAD/HRRMS'],
        // Shares a section with 1009, so a section-wide share has somebody
        // other than its author to reach.
        ['employee_id' => '1010', 'first_name' => 'Marcelo', 'last_name' => 'Del Pilar', 'section' => 'FAD/HRRMS'],
    ];

    /** What every seeded account signs in with. */
    public const PASSWORD = 'password';

    public function run(): void
    {
        /*
         * Its own prerequisites, because a section is what makes this data
         * realistic: without them every seeded account lands with no office,
         * which is exactly the state these ten people exist to avoid. Both are
         * idempotent, so calling them here costs nothing on an install that
         * has already run them.
         */
        $this->call(DivisionSeeder::class);
        $this->call(SectionSeeder::class);

        $sections = $this->sectionsByPath();

        foreach (self::STAFF as $person) {
            $section = $sections[$person['section']] ?? null;

            $user = User::query()->updateOrCreate(
                ['employee_id' => $person['employee_id']],
                [
                    'first_name' => $person['first_name'],
                    'last_name' => $person['last_name'],
                    // `name` is deliberately absent: the model's `saving` hook
                    // assembles it from the two halves, and writing it here
                    // would be writing a value the next save overwrites.
                    'email' => $this->emailFor($person),
                    'division_id' => $section?->division_id,
                    'section_id' => $section?->id,
                    // Null, which means "generate a face from this person's
                    // name". Seeding a chosen avatar would make the seeded
                    // accounts the only ones in the system carrying a seed, and
                    // the default that every real account uses would go
                    // untested.
                    'avatar_seed' => null,
                    // Only on creation. Somebody testing may have changed it,
                    // and a seeder that resets credentials is one nobody can
                    // run twice.
                    ...(User::query()->where('employee_id', $person['employee_id'])->exists()
                        ? []
                        : ['password' => Hash::make(self::PASSWORD)]),
                ],
            );

            // syncRoles, not assignRole: an account holds exactly one role, and
            // a re-run must not leave a second one attached.
            $user->syncRoles([Roles::STAFF]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Sections keyed by `DIVISION/SECTION`.
     *
     * A section code is unique only *within* its division — two divisions may
     * each run a "Records" section — so the key has to carry both or this
     * silently posts people to whichever matched first.
     *
     * @return array<string, Section>
     */
    private function sectionsByPath(): array
    {
        return Section::query()
            ->with('division:id,code')
            ->get()
            ->mapWithKeys(fn (Section $section) => [
                "{$section->division?->code}/{$section->code}" => $section,
            ])
            ->all();
    }

    /**
     * A deliverable-looking address that cannot actually be delivered to.
     *
     * `users.email` exists only so the password broker has something to look an
     * account up by, and these are test accounts — an address that reaches a
     * real inbox would mean a seeded install could send mail to a stranger.
     * `.invalid` is reserved by RFC 2606 for exactly this.
     *
     * @param  array{first_name: string, last_name: string, employee_id: string}  $person
     */
    private function emailFor(array $person): string
    {
        $handle = str($person['first_name'].'.'.$person['last_name'])->lower()->slug('.');

        return "{$handle}@ptri.test.invalid";
    }
}
