<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDIT 4 of 4 for the upload-settings module — see App\Support\Modules.
 *
 * SUPERADMIN only. Loosening what the office accepts is the most consequential
 * setting in the application — it is the one screen that can permit an
 * executable — so it is not part of the `Staff` floor.
 *
 * `down()` is empty on purpose: removing the permission while the route still
 * gates on it locks the module rather than restoring it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('name', Roles::SUPERADMIN)->first();

        if ($role) {
            $role->givePermissionTo(array_map(
                fn (string $name) => Permission::findOrCreate($name, $role->guard_name),
                ['upload-settings.read', 'upload-settings.write'],
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
