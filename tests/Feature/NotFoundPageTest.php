<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branded 404.
 *
 * Laravel's default body said "404 | NOT FOUND" and offered nothing, which is
 * the worst available answer for the reader who hits it most — somebody
 * following a share link that no longer resolves, who has no account and no
 * sidebar to go back to. These assert that a page visit renders our own page
 * with the right subject, that the status is still 404, and that a JSON client
 * is left alone.
 */
class NotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_page_renders_the_branded_404(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('errors/404', false)->where('subject', 'page'));
    }

    public function test_a_share_link_that_does_not_resolve_is_a_share_miss(): void
    {
        $this->get('/s/'.str_repeat('a', 64))
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('errors/404', false)->where('subject', 'share'));
    }

    public function test_a_missing_file_is_a_file_miss(): void
    {
        $this->get('/files/'.str_repeat('a', 26).'/'.str_repeat('b', 26))
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('errors/404', false)->where('subject', 'file'));
    }

    public function test_a_signed_in_user_gets_the_same_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('errors/404', false));
    }

    public function test_a_json_client_still_gets_a_plain_404(): void
    {
        $response = $this->getJson('/no-such-page');

        $response->assertNotFound();
        $this->assertNull($response->headers->get('X-Inertia'));
    }
}
