<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retire three permissions whose screens were folded into other screens.
 *
 * - `files-shared.read` — Shared with Me is a filter on My Files now, and that
 *   listing is `files.read` like the rest of it.
 * - `files-browse.read` — Browse Storage is the reach across other people's
 *   storage, which is exactly what `files.manage` has always meant. Two names
 *   for one grant is how a role ends up holding half of it.
 * - `files.write` — it gated no route and never did. `files.edit` is the coarse
 *   toggle the roles screen offers.
 *
 * A permission that gates nothing is not harmless: it is a checkbox on the
 * roles screen that grants the administrator nothing, and a name somebody will
 * later gate a route on believing it was already assignable. So the rows go,
 * which also revokes them from every role holding them.
 *
 * `down()` is empty on purpose. Recreating a permission no route reads would
 * restore the checkbox without restoring any capability, and the screens these
 * named no longer exist to gate.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const RETIRED = ['files-shared.read', 'files-browse.read', 'files.write'];

    public function up(): void
    {
        Permission::query()->whereIn('name', self::RETIRED)->get()
            ->each(fn (Permission $permission) => $permission->delete());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
