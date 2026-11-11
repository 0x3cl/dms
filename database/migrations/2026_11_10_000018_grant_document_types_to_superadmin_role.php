<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDIT 4 of 4 for the document-types module — see App\Support\Modules.
 *
 * SUPERADMIN only. The `code` on a document type becomes the prefix of every
 * tracking code it issues, and those are printed on labels stuck to physical
 * folders, so this list is closer to the office's filing conventions than to a
 * day-to-day setting.
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
                ['document-types.read', 'document-types.write', 'document-types.delete'],
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
