<?php

namespace Database\Seeders;

use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission App\Support\Modules knows about, and the two roles
 * the application itself depends on (App\Support\Roles).
 *
 * Idempotent: `findOrCreate` throughout, so running it again after a module is
 * added tops the permission list up without disturbing any role an
 * administrator has since defined.
 *
 * Note this only runs on a fresh install. Granting a *new* module's permissions
 * to an already-deployed SUPERADMIN needs its own migration — see
 * App\Support\Modules for the four edits adding a module requires.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Every permission up front, so a role can be granted one before any
        // route happens to reference it.
        $permissions = collect(Modules::permissions())
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::findOrCreate(Roles::SUPERADMIN, 'web')->syncPermissions($permissions);

        Role::findOrCreate(Roles::STAFF, 'web')->syncPermissions(
            collect(Roles::STAFF_PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'))
        );

        // The registrar caches the whole permission map for the life of the
        // process, and a seeder that leaves a stale cache behind makes the very
        // next request answer from before the grant.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
