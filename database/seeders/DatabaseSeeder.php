<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The document types are not sample data — nothing can be uploaded without
     * them, because a tracking code takes its abbreviation from the type. The
     * roles are not either: every module route is permission-gated, so an
     * unseeded install has an account that can reach nothing.
     *
     * The divisions and sections are not sample data either: registration asks
     * for both, so an unseeded install cannot accept a sign-up at all. Sections
     * look their parents up by division code, so they run after divisions.
     *
     * The first account is the sign-in for a fresh install (employee ID 826,
     * password "password") and holds SUPERADMIN, because somebody has to be
     * able to create the second account's role.
     *
     * `StaffSeeder` is deliberately **not** called here. Ten colleagues are
     * what makes sharing, recipients and per-record access testable, and they
     * are also ten accounts that can sign in — which has no business appearing
     * on a production install because somebody ran `db:seed`. It is opt-in:
     *
     *     php artisan db:seed --class=StaffSeeder
     */
    public function run(): void
    {
        $this->call(DivisionSeeder::class);
        $this->call(SectionSeeder::class);
        $this->call(DocumentTypeSeeder::class);
        $this->call(RolePermissionSeeder::class);
        $this->call(UploadSettingSeeder::class);
        $this->call(StaffSeeder::class);
        $admin = User::firstOrCreate(
            ['employee_id' => '826'],
            [
                'first_name' => 'Carl',
                'last_name' => 'Llemos',
                'password' => 'password',
            ]
        );

        $admin->syncRoles([Roles::SUPERADMIN]);
    }
}
