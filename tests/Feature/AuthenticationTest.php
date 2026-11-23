<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sign-in takes either identifier the account carries — the employee ID the
 * office issued, or the email address the password broker already uses. These
 * also cover the routes in routes/auth.php, which went unregistered for the
 * whole of the app's first life: password reset and password confirmation
 * were simply unreachable.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_user_signs_in_with_their_employee_id(): void
    {
        $user = User::factory()->create([
            'employee_id' => 'EMP-0001',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'login' => 'EMP-0001',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The same account, reached by the address it would reset its password
     * with. Somebody who has just used their email to recover an account and
     * is then told to sign in with something else has been handed a puzzle.
     */
    public function test_a_user_signs_in_with_their_email(): void
    {
        $user = User::factory()->create([
            'employee_id' => 'EMP-0001',
            'email' => 'maria@ptri.test',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'login' => 'maria@ptri.test',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * An account with no address on file is unreachable by email and still
     * perfectly reachable by its employee ID: `users.email` is nullable, and
     * a null column must never match a typed one.
     */
    public function test_an_account_without_an_email_is_not_reachable_by_an_empty_one(): void
    {
        User::factory()->create([
            'employee_id' => 'EMP-0002',
            'email' => null,
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'login' => 'nobody@ptri.test',
            'password' => 'password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'employee_id' => 'EMP-0001',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'login' => 'EMP-0001',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /**
     * Login bypassed LoginRequest entirely, which is where the throttling
     * lives, so there was no rate limiting at all.
     */
    public function test_repeated_failures_are_rate_limited(): void
    {
        User::factory()->create([
            'employee_id' => 'EMP-0001',
            'password' => Hash::make('password'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'login' => 'EMP-0001',
                'password' => 'wrong-password',
            ]);
        }

        $this->post('/login', [
            'login' => 'EMP-0001',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('login');

        $this->assertStringContainsString(
            'seconds',
            session('errors')->first('login'),
            'The sixth attempt should be throttled, not merely refused.',
        );
    }

    public function test_the_password_reset_routes_are_registered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    /**
     * Out to the sign-in page, not the public landing page.
     *
     * Signing out is done at the end of a shift at a shared desk, and the
     * next thing needed is the form. It is also where every other way out of
     * a session lands — an expired one, and `unauthorizedRedirect()` for a
     * guest — so one answer rather than two.
     */
    public function test_a_user_can_sign_out_and_lands_on_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
