<?php

namespace Tests\Feature\Acceptance;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Acceptance item 9 — roles, from several directions, with different outcomes.
 *
 * `RolePermissionTest` covers the mechanism and `ModuleRegistryTest` covers the
 * vocabulary. What this adds is the sweep: a purpose-built role is given one
 * module's read and nothing else, and then every module in the registry is
 * knocked on. The outcome has to be "that one opens, the rest refuse" —
 * asserted against `Modules::MODULES` rather than a hand-written list, so a
 * module added later is covered the day it is added and cannot be the one
 * nobody remembered to gate.
 *
 * The two distinctions this exists to keep honest:
 *
 * - **the sidebar is navigation, not authorization.** What `auth.permissions`
 *   contains decides what is *offered*; the route middleware decides what
 *   opens. A hidden entry over an open route reads as protection that is not
 *   there, which is worse than an honest open list.
 * - **a module permission is not a per-record permission.** `documents.delete`
 *   says the capability exists for this account; ownership still decides which
 *   document — so two accounts holding exactly the same role get different
 *   answers about the same row, and that is correct.
 */
class RolePermissionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /**
     * The screen each module's read opens, as the route table declares it.
     *
     * Keyed by module so the sweep below can be driven by `Modules::MODULES`
     * and fail loudly on a module that has no entry here — which is the signal
     * that a module landed without anybody deciding what its read reaches.
     *
     * @return array<string, string>
     */
    private function readPaths(): array
    {
        return [
            'documents' => '/documents',
            'documents-all' => '/documents?tab=all',
            'access-requests' => '/access-requests',
            'files' => '/files',
            'download-logs' => '/admin/download-logs',
            'activity-trails' => '/activity-trails',
            'upload-settings' => '/upload-settings',
            'document-types' => '/document-types',
            'users' => '/users',
            'divisions' => '/divisions',
            'sections' => '/sections',
            'roles' => '/roles',
        ];
    }

    private function roleHolding(string ...$permissions): User
    {
        $role = Role::create(['name' => 'Acceptance '.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }

    // --------------------------------------------------------- the sweep

    /**
     * Item 9: one module's read opens that module and refuses every other.
     *
     * Driven by the registry, so this is a statement about the whole
     * application rather than about the twelve paths somebody listed.
     */
    public function test_one_modules_read_opens_that_module_and_nothing_else(): void
    {
        $paths = $this->readPaths();

        $this->assertSame(
            [],
            array_diff(Modules::MODULES, array_keys($paths)),
            'A module in the registry has no screen listed here — decide what its read reaches.',
        );

        foreach (Modules::MODULES as $module) {
            /*
             * `documents-all.read` is the one grant that is not a screen of its
             * own: it reaches the All **tab**, which lives on the Documents
             * page, so the page's own `documents.read` is part of reaching it.
             * Granting the pair here is not a loophole — the refusal half below
             * is what proves the tab's own grant is doing something, and
             * the test below covers what happens without it.
             */
            $user = $module === 'documents-all'
                ? $this->roleHolding('documents.read', 'documents-all.read')
                : $this->roleHolding("{$module}.read");

            $this->actingAs($user)->get($paths[$module])
                ->assertSuccessful();

            foreach (Modules::MODULES as $other) {
                if ($other === $module) {
                    continue;
                }

                /*
                 * Two modules are excluded from the refusal half, and neither
                 * is an exception to the rule:
                 *
                 * - `documents-all` is a **tab** on the Documents page, so its
                 *   path without the grant degrades to "Uploaded by me" rather
                 *   than refusing — a tab is a filter, and middleware would
                 *   turn a stale link into a refusal of the whole screen;
                 * - `access-requests.read` reaches a page whose answer is
                 *   per-record, so holding `documents.read` alone still leaves
                 *   it gated — it is in the list below, not here.
                 */
                if ($other === 'documents-all' && in_array($module, ['documents', 'documents-all'], true)) {
                    continue;
                }

                // Conversely, the holder of the All tab's pair can open the
                // Documents page, because that grant includes its page.
                if ($other === 'documents' && $module === 'documents-all') {
                    continue;
                }

                $response = $this->actingAs($user)->get($paths[$other]);

                $this->assertTrue(
                    in_array($response->status(), [302, 403], true),
                    "{$module}.read opened {$other} (status {$response->status()}).",
                );
            }
        }
    }

    /**
     * Item 9: a tab whose grant somebody lacks degrades rather than refusing.
     *
     * "Every document in the office" is not "the documents I am party to", so
     * the All tab is a real grant — but a tab is a filter on one screen, and
     * route middleware would turn a link somebody was sent into a refusal of
     * the whole page. So it falls back to "Uploaded by me" and simply is not
     * offered.
     */
    public function test_a_tab_whose_grant_is_missing_falls_back_instead_of_refusing(): void
    {
        $user = $this->roleHolding('documents.read');

        $this->actingAs($user)->get('/documents?tab=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tab', 'mine')
                ->where('can.all', false)
                ->where('counts.all', null));

        $allowed = $this->roleHolding('documents.read', 'documents-all.read');

        $this->actingAs($allowed)->get('/documents?tab=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.tab', 'all')->where('can.all', true));
    }

    /**
     * Item 9: the shared permission list is exactly the role's, and the
     * sidebar is built from it.
     *
     * The list is for navigation. Nothing is ever decided by it — which is why
     * the sweep above goes through the routes.
     */
    public function test_the_shared_list_is_the_roles_own_and_a_guest_has_none(): void
    {
        $user = $this->roleHolding('documents.read', 'files.read');

        $props = $this->actingAs($user)->get('/dashboard')->viewData('page')['props'];

        $this->assertEqualsCanonicalizing(['documents.read', 'files.read'], $props['auth']['permissions']);
        $this->assertSame($user->roles->first()->name, $props['auth']['role']);

        // And the quick-search reads the same filtered list, so it cannot jump
        // somebody to a page the sidebar is hiding.
        $this->assertNotContains('users.read', $props['auth']['permissions']);
    }

    // ------------------------------------------- the two real role outcomes

    /**
     * Item 9: Staff holds what self-registration grants, and no more.
     *
     * Whatever this role carries is what anyone who can reach the sign-up page
     * gets, which is why it is worth asserting by name rather than by count.
     */
    public function test_staff_reaches_the_workflow_and_not_the_access_model(): void
    {
        $staff = User::factory()->create();

        $this->assertSame([Roles::STAFF], $staff->roles->pluck('name')->all());

        foreach (['/documents', '/files', '/divisions', '/sections', '/access-requests'] as $open) {
            $this->actingAs($staff)->get($open)->assertSuccessful();
        }

        // The access model, and the two reports that reach across everybody.
        foreach (['/users', '/roles', '/upload-settings', '/activity-trails', '/admin/download-logs', '/files/browse'] as $closed) {
            $response = $this->actingAs($staff)->get($closed);

            $this->assertTrue(
                in_array($response->status(), [302, 403], true),
                "Staff reached {$closed} (status {$response->status()}).",
            );
        }
    }

    /** Item 9: SUPERADMIN is allowed without holding the permission at all. */
    public function test_superadmin_is_allowed_by_the_gate_rather_than_by_its_grants(): void
    {
        $admin = User::factory()->superadmin()->create();
        $admin->syncPermissions([]);
        $admin->roles->first()->syncPermissions([]);

        $this->assertFalse($admin->fresh()->hasPermissionTo('users.read'));

        foreach ($this->readPaths() as $path) {
            $this->actingAs($admin->fresh())->get($path)->assertSuccessful();
        }
    }

    // ------------------------------------- module grant vs per-record rule

    /**
     * Item 9, the distinction that catches people out: two accounts with the
     * *same* role get different answers about the same document.
     *
     * `documents.write` and `documents.delete` are Staff permissions and are
     * not a licence over other people's uploads. Withholding them would not
     * protect anybody — it would stop a person withdrawing their own document.
     */
    public function test_the_same_role_gives_different_answers_per_record(): void
    {
        $uploader = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($uploader->can('documents.delete'));
        $this->assertTrue($other->can('documents.delete'));

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        // Same permission, same document, different answers — because the
        // module grant says the capability exists and ownership says whose.
        $this->actingAs($other)->delete("/documents/{$document->id}")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error');
        $this->assertNotSoftDeleted($document);

        $this->actingAs($uploader)->delete("/documents/{$document->id}")
            ->assertRedirect('/documents');
        $this->assertSoftDeleted($document);
    }

    /**
     * Item 9: a grant can be given to one account without touching the role,
     * and write implies read when a role is saved.
     */
    public function test_a_direct_grant_adds_to_a_role_and_write_implies_read(): void
    {
        $user = $this->roleHolding('documents.read');

        $this->actingAs($user)->get('/users')->assertStatus(403);

        $user->givePermissionTo('users.read');

        $this->actingAs($user->fresh())->get('/users')->assertOk();

        // And the roles screen cannot save a write without its read: a role
        // holding half a grant is a screen somebody can post to and not open.
        $admin = User::factory()->superadmin()->create();
        $role = Role::create(['name' => 'Editors', 'guard_name' => 'web']);

        $this->actingAs($admin)->patch("/roles/{$role->id}", [
            'name' => 'Editors',
            'access' => ['divisions' => ['write']],
        ])->assertSessionHasNoErrors();

        $granted = $role->fresh()->permissions->pluck('name')->all();

        $this->assertContains('divisions.write', $granted);
        $this->assertContains('divisions.read', $granted);
    }

    /**
     * Item 9: the role that the code depends on cannot be taken apart, and a
     * role still held by somebody cannot be deleted out from under them.
     */
    public function test_the_access_model_protects_itself(): void
    {
        $admin = User::factory()->superadmin()->create();
        $superadmin = Role::query()->where('name', Roles::SUPERADMIN)->sole();

        $this->actingAs($admin)->patch("/roles/{$superadmin->id}", [
            'name' => 'Renamed',
            'permissions' => [],
        ])->assertSessionHas('error');

        $this->assertSame(Roles::SUPERADMIN, $superadmin->fresh()->name);

        $this->actingAs($admin)->delete("/roles/{$superadmin->id}")->assertSessionHas('error');
        $this->assertNotNull(Role::query()->find($superadmin->id));

        // A role somebody holds. Deleting it would silently strip an account
        // of every grant it has.
        $staff = Role::query()->where('name', Roles::STAFF)->sole();
        User::factory()->create();

        $this->actingAs($admin)->delete("/roles/{$staff->id}")->assertSessionHas('error');
        $this->assertNotNull(Role::query()->find($staff->id));
    }
}
