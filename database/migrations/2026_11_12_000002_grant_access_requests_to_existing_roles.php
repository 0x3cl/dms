<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Hand the new permissions to the roles that already exist.
 *
 * The seeder covers a fresh install; an office already running this app has
 * its roles in the database, and a permission nobody holds is a feature
 * nobody has. Both roles get both: anyone may ask to be let into a document,
 * and deciding is gated per-record by ownership rather than by a checkbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = ['access-requests.read', 'access-requests.create'];

        $permissions = collect($names)->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

        foreach ([Roles::SUPERADMIN, Roles::STAFF] as $roleName) {
            $role = Role::where('name', $roleName)->first();

            $role?->givePermissionTo($permissions);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', ['access-requests.read', 'access-requests.create'])->delete();
    }
};
