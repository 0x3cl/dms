<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Every account the factory builds is a `Staff` account.
     *
     * This is what keeps the pre-RBAC test suite meaningful: those tests act as
     * "some authenticated user", and after the module routes were gated that
     * had to mean something concrete. `Staff` is the same least-privilege role
     * a self-registered account gets, so a test that passes here passes for a
     * real user of the system.
     *
     * A test that needs more reaches for ->superadmin().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->assignRole(Roles::STAFF);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            // `name` is deliberately not set: the model's `saving` hook
            // assembles it from the two halves, and a factory that set it
            // directly would hide a regression in that hook.
            'employee_id' => fake()->unique()->numerify('EMP-####'),
            'email' => fake()->unique()->safeEmail(),
            // Both nullable on the table: an account does not need an office
            // to sign in, and a test that cares about one attaches it.
            'division_id' => null,
            'section_id' => null,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An account holding every permission.
     *
     * Assigned instead of `Staff`, not alongside it: the application gives a
     * user exactly one role, and `Gate::before` short-circuits SUPERADMIN to
     * true anyway, so a second role would be noise.
     */
    public function superadmin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->syncRoles([Roles::SUPERADMIN]);
        });
    }

    /** An account posted to a section, and so to that section's division. */
    public function inSection(Section $section): static
    {
        return $this->state(fn (): array => [
            'division_id' => $section->division_id,
            'section_id' => $section->id,
        ]);
    }
}
