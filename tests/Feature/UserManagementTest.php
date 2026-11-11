<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Section;
use App\Models\User;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Users module, which replaced the staff directory.
 *
 * The directory was a second record of the same people with no sign-in
 * attached; one row is one account now. That makes this screen the place access
 * is granted, so most of what is asserted here is about refusing things: the
 * module is not Staff's to reach, an administrator cannot demote themselves,
 * and the last SUPERADMIN cannot be removed by any route.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superadmin()->create();
    }

    private function fileFor(User $owner): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
    }

    // ----------------------------------------------------------------- access

    public function test_the_screens_render_for_a_superadmin(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->get('/users')->assertOk();
        $this->actingAs($admin)->get('/users/create')->assertOk();
        $this->actingAs($admin)->get("/users/{$user->id}/edit")->assertOk();
    }

    /**
     * Staff held `employees.read` — a people lookup. This screen hands out
     * roles and sets other people's passwords, so it is not that grant and was
     * deliberately not carried over.
     */
    public function test_staff_cannot_reach_the_module(): void
    {
        $staff = User::factory()->create();

        $this->assertTrue($staff->hasRole(Roles::STAFF));

        $other = User::factory()->create();

        // Every route in the group, not only the ones that render a list: a
        // gate missing from one write endpoint is the whole module open.
        //
        // A refused GET renders the 403 page; a refused write goes back with
        // the error flash instead, because a raw 403 body in an Inertia XHR
        // shows the user nothing. That is bootstrap/app.php's rule, not this
        // module's.
        $this->actingAs($staff)->get('/users')->assertForbidden();
        $this->actingAs($staff)->get('/users/create')->assertForbidden();
        $this->actingAs($staff)->get("/users/{$other->id}")->assertForbidden();
        $this->actingAs($staff)->get("/users/{$other->id}/edit")->assertForbidden();

        $this->actingAs($staff)->post('/users', [])->assertSessionHas('error');
        $this->actingAs($staff)->put("/users/{$other->id}", [])->assertSessionHas('error');
        $this->actingAs($staff)->put("/users/{$other->id}/password", [])->assertSessionHas('error');
        $this->actingAs($staff)->delete("/users/{$other->id}")->assertSessionHas('error');

        // Nothing got through: no account created, none closed.
        $this->assertSame(2, User::query()->count());
        $this->assertNotSoftDeleted($other);
    }

    // ---------------------------------------------------------------- writing

    public function test_an_account_is_created_with_its_role_and_a_usable_password(): void
    {
        $section = Section::factory()->create();

        $this->actingAs($this->admin())
            ->post('/users', [
                'employee_id' => 'EMP-7001',
                'first_name' => 'Ana',
                'last_name' => 'Cruz',
                'email' => 'ana@example.test',
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/users');

        $created = User::query()->where('employee_id', 'EMP-7001')->sole();

        $this->assertTrue($created->hasRole(Roles::STAFF));
        $this->assertTrue(Hash::check('correct-horse-battery', $created->password));
        // The credential actually works, which is the whole point of creating
        // an account here rather than listing a name.
        $this->assertTrue(Auth::validate([
            'employee_id' => 'EMP-7001',
            'password' => 'correct-horse-battery',
        ]));
    }

    /**
     * `name` is a stored column every audit snapshot copies, assembled by the
     * model rather than typed. A corrected surname has to reach it, or the
     * trail keeps showing the old one.
     */
    public function test_updating_the_name_halves_keeps_the_assembled_name_in_step(): void
    {
        $section = Section::factory()->create();
        $user = User::factory()->inSection($section)->create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ]);

        $this->assertSame('Ana Cruz', $user->name);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'employee_id' => $user->employee_id,
                'first_name' => 'Ana',
                'last_name' => 'Dela Cruz',
                'email' => $user->email,
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ana Dela Cruz', $user->fresh()->name);
    }

    /**
     * A query cannot call an accessor, so the search covers `name` as well as
     * the two halves — a term spanning both matches nothing otherwise.
     */
    public function test_the_search_matches_a_term_spanning_both_name_halves(): void
    {
        User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Dela Cruz']);

        $this->actingAs($this->admin())
            ->get('/users?search=Ana+Dela')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.data.0.last_name', 'Dela Cruz'));
    }

    public function test_an_employee_id_cannot_be_taken_twice(): void
    {
        $section = Section::factory()->create();
        User::factory()->create(['employee_id' => 'EMP-7001']);

        $this->actingAs($this->admin())
            ->post('/users', [
                'employee_id' => 'EMP-7001',
                'first_name' => 'Ana',
                'last_name' => 'Cruz',
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('employee_id');
    }

    /**
     * Sign-in is by employee ID, so an account without an address is a normal
     * account that simply cannot reset its own password.
     */
    public function test_an_account_may_be_created_without_an_email(): void
    {
        $section = Section::factory()->create();

        $this->actingAs($this->admin())
            ->post('/users', [
                'employee_id' => 'EMP-7002',
                'first_name' => 'Ana',
                'last_name' => 'Cruz',
                'email' => null,
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(User::query()->where('employee_id', 'EMP-7002')->value('email'));
    }

    // --------------------------------------------------------------- passwords

    /**
     * Unlike /password, this endpoint does not ask for the current one — an
     * administrator resetting an account does not know it, and that is the
     * reason the endpoint exists.
     */
    public function test_an_administrator_sets_a_password_without_knowing_the_old_one(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-7003']);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}/password", [
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-secret', $user->fresh()->password));

        $this->assertTrue(Auth::validate([
            'employee_id' => 'EMP-7003',
            'password' => 'a-brand-new-secret',
        ]));
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}/password", [
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');
    }

    // ------------------------------------------------------------ the lockouts

    /**
     * An administrator who demotes themselves locks the install out of the only
     * screen that could promote anyone back.
     */
    public function test_an_administrator_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();
        $section = Section::factory()->create();
        $admin->update(['division_id' => $section->division_id, 'section_id' => $section->id]);

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'employee_id' => $admin->employee_id,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'email' => $admin->email,
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
            ])
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->hasRole(Roles::SUPERADMIN));
    }

    public function test_the_last_superadmin_cannot_be_demoted(): void
    {
        $admin = $this->admin();
        $second = $this->admin();
        $section = Section::factory()->create();

        // With two of them, the first may be demoted by the second.
        $this->actingAs($second)
            ->put("/users/{$admin->id}", [
                'employee_id' => $admin->employee_id,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'email' => $admin->email,
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($admin->fresh()->hasRole(Roles::STAFF));

        // Now `$second` is the only one left, and nobody can take it away.
        $this->actingAs($second)
            ->put("/users/{$second->id}", [
                'employee_id' => $second->employee_id,
                'first_name' => $second->first_name,
                'last_name' => $second->last_name,
                'email' => $second->email,
                'division_id' => $section->division_id,
                'section_id' => $section->id,
                'role' => Roles::STAFF,
            ])
            ->assertSessionHas('error');

        $this->assertTrue($second->fresh()->hasRole(Roles::SUPERADMIN));
    }

    public function test_the_last_superadmin_cannot_be_closed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete("/users/{$admin->id}")
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($admin);
    }

    /**
     * Closing your own account asks for your password, and this screen has no
     * business collecting it. /account does.
     */
    public function test_an_administrator_cannot_close_their_own_account_here(): void
    {
        $admin = $this->admin();
        $this->admin();

        $this->actingAs($admin)
            ->delete("/users/{$admin->id}")
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($admin);
    }

    // ------------------------------------------------------- closing accounts

    public function test_closing_an_account_that_holds_nothing_soft_deletes_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/users/{$user->id}")
            ->assertRedirect('/users');

        // Soft, so every audit row and remaining link stays readable while the
        // account can no longer sign in.
        $this->assertSoftDeleted($user);
    }

    public function test_an_account_holding_files_is_not_closed_without_a_transfer(): void
    {
        $user = User::factory()->create();
        $this->fileFor($user);

        $this->actingAs($this->admin())
            ->delete("/users/{$user->id}")
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($user);
    }

    public function test_closing_an_account_with_a_transfer_hands_its_files_over(): void
    {
        $admin = $this->admin();
        $leaver = User::factory()->create();
        $successor = User::factory()->create();
        $file = $this->fileFor($leaver);

        $this->actingAs($admin)
            ->delete("/users/{$leaver->id}", ['transfer_to' => $successor->id])
            ->assertRedirect('/users');

        $this->assertSoftDeleted($leaver);
        $this->assertSame($successor->id, $file->fresh()->user_id);
        $this->assertSame($successor->name, $file->fresh()->owner_name);
    }

    public function test_a_closed_account_is_off_the_list(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['first_name' => 'Gone', 'last_name' => 'Away']);

        $this->actingAs($admin)->delete("/users/{$user->id}");

        $this->actingAs($admin)
            ->get('/users?search=Gone')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('users.data', []));
    }
}
