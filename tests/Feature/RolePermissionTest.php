<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\User;
use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The access model this application spent its first life without.
 *
 * Every module route is gated on a "{module}.{action}" permission, and the
 * sidebar is filtered against the same list. These tests cover the two halves
 * together, because a filtered sidebar over an open route is worse than no
 * filter at all.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_may_read_the_directory_but_not_write_it(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/divisions')->assertOk();

        $this->actingAs($staff)->get('/divisions/create')->assertForbidden();
        $this->actingAs($staff)
            ->post('/divisions', ['code' => 'NEW', 'name' => 'New Division'])
            ->assertRedirect();

        $this->assertDatabaseMissing('divisions', ['code' => 'NEW']);
    }

    /**
     * The download register carries the IP address every copy of a document
     * went to. It was reachable by any authenticated account for the whole of
     * the app's first life — the `admin.` route name was a label, not a gate.
     */
    public function test_the_download_register_is_not_open_to_every_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/download-logs')
            ->assertForbidden();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/download-logs')
            ->assertOk();
    }

    public function test_superadmin_reaches_every_module(): void
    {
        $admin = User::factory()->superadmin()->create();

        foreach (['/dashboard', '/documents', '/documents?tab=all', '/divisions', '/sections', '/users', '/roles', '/admin/download-logs'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    /**
     * Gate::before short-circuits on the role, so a superadmin is allowed
     * things nobody has enumerated — including a permission that does not
     * exist yet.
     */
    public function test_superadmin_is_allowed_without_holding_the_permission(): void
    {
        $admin = User::factory()->superadmin()->create();
        $admin->roles()->first()->revokePermissionTo('divisions.write');

        $this->assertTrue($admin->fresh()->can('divisions.write'));
    }

    public function test_a_direct_per_user_grant_adds_to_the_role(): void
    {
        $staff = User::factory()->create();
        $this->assertFalse($staff->can('download-logs.read'));

        $staff->givePermissionTo('download-logs.read');

        $this->assertTrue($staff->fresh()->can('download-logs.read'));
        $this->actingAs($staff->fresh())->get('/admin/download-logs')->assertOk();
    }

    /**
     * A role that may change a record it cannot open is not a coherent grant,
     * so the server expands write into read rather than trusting the payload.
     */
    public function test_write_implies_read_when_a_role_is_saved(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/roles', [
                'name' => 'Records Officer',
                'access' => ['divisions' => ['write']],
            ])
            ->assertRedirect('/roles');

        $granted = Role::findByName('Records Officer')->permissions->pluck('name')->all();

        $this->assertContains('divisions.write', $granted);
        $this->assertContains('divisions.read', $granted);
    }

    /**
     * The roles screen only offers what the registry says a module offers, and
     * the server re-derives the payload rather than trusting it — so a crafted
     * request cannot grant a write on a read-only module.
     */
    public function test_a_read_only_module_cannot_be_granted_a_write(): void
    {
        $this->assertContains('download-logs', Modules::READ_ONLY_MODULES);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/roles', [
                'name' => 'Auditor',
                'access' => ['download-logs' => ['read', 'write', 'delete']],
            ])
            ->assertRedirect('/roles');

        $granted = Role::findByName('Auditor')->permissions->pluck('name')->all();

        $this->assertSame(['download-logs.read'], $granted);
    }

    /** A permission name the registry does not know about cannot be granted. */
    public function test_an_unknown_module_in_the_payload_is_ignored(): void
    {
        Permission::findOrCreate('secrets.read', 'web');

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/roles', [
                'name' => 'Imposter',
                'access' => ['secrets' => ['read']],
            ])
            ->assertRedirect('/roles');

        $this->assertEmpty(Role::findByName('Imposter')->permissions);
    }

    /**
     * SUPERADMIN is the role Gate::before short-circuits on and the one that
     * can hand out every other grant. Renaming it would silently strip that
     * bypass from whoever holds it.
     */
    public function test_the_superadmin_role_cannot_be_renamed_or_deleted(): void
    {
        $admin = User::factory()->superadmin()->create();
        $role = Role::findByName(Roles::SUPERADMIN);

        $this->actingAs($admin)
            ->put("/roles/{$role->id}", ['name' => 'Something Else', 'access' => []])
            ->assertRedirect();

        $this->assertNotNull(Role::findByName(Roles::SUPERADMIN));

        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertRedirect();

        $this->assertNotNull(Role::findByName(Roles::SUPERADMIN));
    }

    public function test_a_role_still_held_by_an_account_is_not_deleted(): void
    {
        $staff = User::factory()->create();
        $role = Role::findByName(Roles::STAFF);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/roles/{$role->id}")
            ->assertRedirect();

        $this->assertNotNull(Role::findByName(Roles::STAFF));
        $this->assertTrue($staff->fresh()->hasRole(Roles::STAFF));
    }

    /**
     * The sidebar reads this prop, so what it contains is what the user can
     * navigate to. It must not advertise a module the routes refuse.
     */
    public function test_the_shared_permissions_prop_matches_the_role(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $permissions = $response->viewData('page')['props']['auth']['permissions'];

        $this->assertSame(Roles::STAFF, $response->viewData('page')['props']['auth']['role']);
        $this->assertContains('documents.read', $permissions);
        $this->assertNotContains('download-logs.read', $permissions);
        $this->assertNotContains('divisions.write', $permissions);
    }

    /** A signed-out visitor has no role and no permissions, not a null crash. */
    public function test_a_guest_gets_an_empty_permission_list(): void
    {
        $response = $this->get('/');

        $this->assertSame([], $response->viewData('page')['props']['auth']['permissions']);
        $this->assertNull($response->viewData('page')['props']['auth']['role']);
    }

    /**
     * There is no sign-up page, and nothing answers where one used to be.
     *
     * Self-registration created a working account on the spot, with no
     * approval and nothing checking the employee ID was real. Accounts are
     * created by an administrator on the Users screen now, so both verbs must
     * 404 rather than quietly resolving to something else.
     */
    public function test_there_is_no_self_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['employee_id' => 'EMP-9999'])->assertNotFound();

        $this->assertFalse(User::query()->where('employee_id', 'EMP-9999')->exists());
        $this->assertNull(app('router')->getRoutes()->getByName('register'));
    }

    /**
     * An account an administrator creates still lands on a real role.
     *
     * `Staff` stopped being "what anyone who reaches the sign-up page gets"
     * when that page was removed, and it is still the least-privilege floor —
     * the Users screen is simply what chooses it now, deliberately, per
     * account.
     */
    public function test_an_administrator_created_account_holds_the_role_it_was_given(): void
    {
        $division = Division::factory()->create();
        $section = $division->sections()->create(['code' => 'REC', 'name' => 'Records']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/users', [
                'first_name' => 'New',
                'last_name' => 'Person',
                'employee_id' => 'EMP-9999',
                'division_id' => $division->id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSessionHasNoErrors();

        $user = User::where('employee_id', 'EMP-9999')->firstOrFail();

        $this->assertTrue($user->hasRole(Roles::STAFF));
        $this->assertFalse($user->can('divisions.write'));
    }
}
