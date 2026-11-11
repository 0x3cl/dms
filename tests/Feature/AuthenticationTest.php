<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sign-in is by employee ID. These also cover the routes in routes/auth.php,
 * which went unregistered for the whole of the app's first life — password
 * reset and password confirmation were simply unreachable.
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
            'employee_id' => 'EMP-0001',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'employee_id' => 'EMP-0001',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'employee_id' => 'EMP-0001',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('employee_id');

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
                'employee_id' => 'EMP-0001',
                'password' => 'wrong-password',
            ]);
        }

        $this->post('/login', [
            'employee_id' => 'EMP-0001',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('employee_id');

        $this->assertStringContainsString(
            'seconds',
            session('errors')->first('employee_id'),
            'The sixth attempt should be throttled, not merely refused.',
        );
    }

    public function test_the_password_reset_routes_are_registered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_a_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
