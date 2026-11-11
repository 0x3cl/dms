<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What every test needs present before it does anything.
 *
 * Two things, both of them load-bearing rather than sample data:
 *
 * - the document types, because a tracking code takes its abbreviation from
 *   the document's type, so an unseeded install cannot accept an upload at all;
 * - the roles and permissions, because every module route is gated and a user
 *   built by the factory is assigned `Staff` on creation — a role that has to
 *   exist by then.
 */
class TestSupportSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DocumentTypeSeeder::class);
        $this->call(RolePermissionSeeder::class);
        $this->call(UploadSettingSeeder::class);
    }
}
