<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDIT 4 of 4 for a new module — see App\Support\Modules.
 *
 * `RolePermissionSeeder` only runs on a fresh install, so without this an
 * already-deployed administrator silently cannot reach the module that was just
 * shipped. The sidebar entry would be filtered out and the route would 403, and
 * nothing would say why.
 *
 * `down()` is deliberately empty: taking the permission away again would leave
 * the route gating on something nobody holds, which locks the module rather
 * than restoring it. Dropping the permission tables is the real reverse, and
 * that belongs to the migration that created them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('name', Roles::SUPERADMIN)->first();

        if ($role) {
            $role->givePermissionTo(Permission::findOrCreate('activity-trails.read', $role->guard_name));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
