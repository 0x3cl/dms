<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfileImageService;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A face for every account, and the two ways of choosing one.
 *
 * The invariant worth protecting is that a photo and a generated avatar are two
 * answers to one question: an account holding both would render differently
 * depending on which screen read it first, so setting either clears the other
 * and the uploaded bytes go with it.
 *
 * The third state — neither — is the normal one and is not "no avatar": it
 * means the face is generated from the person's name, which is what gives every
 * account a recognisable avatar from the moment it exists.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_new_account_has_no_photo_and_no_seed(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->profile_image);
        // Null means "generate one from the name", so there is nothing to set.
        $this->assertNull($user->avatar_seed);
    }

    public function test_choosing_an_avatar_stores_the_seed_and_drops_the_photo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['profile_image' => UploadedFile::fake()->image('me.jpg')])
            ->assertRedirect('/profile');

        $uploaded = $user->fresh()->profile_image;
        $this->assertNotNull($uploaded);
        Storage::disk('public')->assertExists($uploaded);

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar_seed' => 'pangolin'])
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame('pangolin', $user->avatar_seed);
        $this->assertNull($user->profile_image);
        // The bytes go too: a file nothing points at is a leak no later change
        // can reach.
        Storage::disk('public')->assertMissing($uploaded);
    }

    public function test_uploading_a_photo_clears_a_chosen_seed(): void
    {
        $user = User::factory()->create(['avatar_seed' => 'pangolin']);

        $this->actingAs($user)
            ->post('/profile/avatar', ['profile_image' => UploadedFile::fake()->image('me.png')])
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotNull($user->profile_image);
        $this->assertNull($user->avatar_seed);
    }

    public function test_removing_an_avatar_returns_to_the_generated_default(): void
    {
        $user = User::factory()->create(['avatar_seed' => 'pangolin']);

        $this->actingAs($user)->delete('/profile/avatar')->assertRedirect('/profile');

        $user->refresh();
        $this->assertNull($user->avatar_seed);
        $this->assertNull($user->profile_image);
    }

    public function test_the_endpoint_needs_one_of_the_two(): void
    {
        $user = User::factory()->create();

        // The avatar endpoints validate into their own bag, so a rejected
        // photo cannot wipe a half-corrected name on the same page.
        $this->actingAs($user)->post('/profile/avatar', [])
            ->assertSessionHasErrors('profile_image', null, 'updateAvatar');
    }

    public function test_a_seed_longer_than_the_column_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar_seed' => str_repeat('a', ProfileImageService::SEED_MAX + 1)])
            ->assertSessionHasErrors('avatar_seed', null, 'updateAvatar');
    }

    /** An administrator sets somebody else's the same way, on its own endpoint. */
    public function test_an_administrator_can_set_another_accounts_avatar(): void
    {
        $admin = User::factory()->superadmin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post("/users/{$user->id}/avatar", ['avatar_seed' => 'tarsier'])
            ->assertRedirect("/users/{$user->id}/edit");

        $this->assertSame('tarsier', $user->fresh()->avatar_seed);
    }

    public function test_an_ordinary_account_cannot_set_somebody_elses_avatar(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // A permission failure on a non-GET request goes back with the error
        // flash the shell watches for, rather than dumping a 403 body into an
        // Inertia XHR — see bootstrap/app.php.
        $this->actingAs($user)
            ->post("/users/{$other->id}/avatar", ['avatar_seed' => 'tarsier'])
            ->assertRedirect();

        $this->assertNull($other->fresh()->avatar_seed);
    }

    /**
     * The staff seeder is fixed, repeatable test data.
     *
     * Idempotent on `employee_id`, which is the sign-in credential and the one
     * column that identifies a person here — so running it twice corrects ten
     * accounts rather than creating ten more. The password is deliberately not
     * rewritten on a re-run: somebody testing may have changed it.
     */
    public function test_the_staff_seeder_can_be_run_twice(): void
    {
        $this->seed(StaffSeeder::class);
        $first = User::query()->count();

        $this->seed(StaffSeeder::class);

        $this->assertSame($first, User::query()->count());
        $this->assertSame(10, User::query()->whereBetween('employee_id', ['1001', '1010'])->count());

        // Posted to real sections, and holding the least-privilege role.
        $staff = User::query()->where('employee_id', '1001')->sole();
        $this->assertNotNull($staff->section_id);
        $this->assertNotNull($staff->division_id);
        $this->assertSame('Maria Santos', $staff->name);
        $this->assertTrue($staff->hasRole(\App\Support\Roles::STAFF));
    }
}
