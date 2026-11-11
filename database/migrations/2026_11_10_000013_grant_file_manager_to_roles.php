<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDIT 4 of 4 for the file-manager module — see App\Support\Modules.
 *
 * Two grants, because the file manager is not only an administrative module:
 *
 * - SUPERADMIN gets everything, `files.manage` included.
 * - `Staff` gets its own storage. A user's own filing cabinet is the point of
 *   the feature, so withholding it would ship a module nobody could use. It
 *   deliberately does **not** get `files.manage`, which is the reach across
 *   everyone else's storage.
 *
 * `down()` is empty on purpose: removing the permissions while the routes still
 * gate on them locks the module rather than restoring it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ALL = [
        'files.read', 'files.create', 'files.write', 'files.edit',
        'files.delete', 'files.share', 'files.download', 'files.manage',
        'files-shared.read', 'files-browse.read',
    ];

    /** @var list<string> */
    private const STAFF = [
        'files.read', 'files.create', 'files.write', 'files.edit',
        'files.delete', 'files.share', 'files.download',
        'files-shared.read', 'files-browse.read',
    ];

    public function up(): void
    {
        $this->grant(Roles::SUPERADMIN, self::ALL);
        $this->grant(Roles::STAFF, self::STAFF);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}

    /**
     * @param  list<string>  $permissions
     */
    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::query()->where('name', $roleName)->first();

        if (! $role) {
            return;
        }

        $role->givePermissionTo(array_map(
            fn (string $name) => Permission::findOrCreate($name, $role->guard_name),
            $permissions,
        ));
    }
};
