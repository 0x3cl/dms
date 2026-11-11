<?php

use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives every account that already exists a role.
 *
 * Without this, the migration that gates the routes locks out every user of a
 * deployed install: they would hold no role, so no permission, so no module.
 * It has to land in the same change as the route middleware, which is why it is
 * a migration and not a seeder — `database/seeders` only runs on a fresh
 * install, and a fresh install has no accounts to rescue.
 *
 * It also creates the roles themselves, because an existing database has never
 * run RolePermissionSeeder.
 *
 * Existing accounts become `Staff` rather than keeping the reach they had
 * before. That is a deliberate narrowing: until now every authenticated user
 * could edit the whole directory and read the download register, and carrying
 * that forward for everyone would defeat the point of the change. The seeded
 * administrator keeps everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect(Modules::permissions())
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        $superadmin = Role::findOrCreate(Roles::SUPERADMIN, 'web');
        $superadmin->syncPermissions($permissions);

        $staff = Role::findOrCreate(Roles::STAFF, 'web');
        $staff->syncPermissions(
            collect(Roles::STAFF_PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'))
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Only accounts that hold no role at all, so re-running this never
        // demotes somebody an administrator has since promoted.
        $unassigned = DB::table('users')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', \App\Models\User::class))
            ->pluck('id');

        foreach ($unassigned as $id) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $staff->id,
                'model_type' => \App\Models\User::class,
                'model_id' => $id,
            ]);
        }

        // The install's first account is the one that has to be able to hand
        // out every other grant.
        $seeded = DB::table('users')->where('employee_id', '826')->value('id');

        if ($seeded) {
            DB::table('model_has_roles')
                ->where('model_type', \App\Models\User::class)
                ->where('model_id', $seeded)
                ->delete();

            DB::table('model_has_roles')->insert([
                'role_id' => $superadmin->id,
                'model_type' => \App\Models\User::class,
                'model_id' => $seeded,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would strip every account's role while the routes gating on
     * them stay in place, which locks the application rather than restoring it.
     * Dropping the permission tables is the real reverse, and that belongs to
     * the migration that created them.
     */
    public function down(): void {}
};
