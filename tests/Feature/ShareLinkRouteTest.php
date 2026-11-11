<?php

namespace Tests\Feature;

use App\Enums\FileVisibility;
use App\Enums\ShareAbility;
use App\Models\User;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Redeeming a share link over HTTP, as an anonymous holder of the URL.
 */
class ShareLinkRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /** @return array{0: \App\Models\File, 1: \App\Models\ShareLink, 2: User} */
    private function sharedFile(ShareAbility $ability = ShareAbility::Download, ?int $maxViews = null): array
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('report.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $link = app(SharingService::class)->createLink($file, $ability, $owner, null, $maxViews);

        // Nothing below may rely on being signed in: the audience for a link is
        // whoever holds the URL, and actingAs() would otherwise persist.
        Auth::logout();

        return [$file, $link, $owner];
    }

    public function test_an_anonymous_holder_can_open_the_landing_page(): void
    {
        [, $link] = $this->sharedFile();

        $this->get("/s/{$link->token}")->assertOk();
    }

    public function test_an_anonymous_holder_can_download_with_a_download_link(): void
    {
        [, $link] = $this->sharedFile(ShareAbility::Download);

        $this->get("/s/{$link->token}/file")->assertOk();
    }

    /** View-only means no copy leaves the server. */
    public function test_a_view_only_link_refuses_a_download(): void
    {
        [, $link] = $this->sharedFile(ShareAbility::View);

        $this->get("/s/{$link->token}/file?disposition=inline")->assertOk();
        $this->get("/s/{$link->token}/file")->assertNotFound();
    }

    public function test_a_revoked_link_is_not_found(): void
    {
        [, $link] = $this->sharedFile();
        app(SharingService::class)->revokeLink($link);

        $this->get("/s/{$link->token}")->assertNotFound();
        $this->get("/s/{$link->token}/file")->assertNotFound();
    }

    /**
     * "Revoked", "expired", "spent" and "never existed" must be
     * indistinguishable — the difference would confirm a token was once real.
     */
    public function test_an_unknown_token_answers_the_same_as_a_revoked_one(): void
    {
        [, $link] = $this->sharedFile();
        app(SharingService::class)->revokeLink($link);

        $revoked = $this->get("/s/{$link->token}");
        $unknown = $this->get('/s/'.str_repeat('z', 64));

        $this->assertSame($revoked->status(), $unknown->status());
        $this->assertSame(404, $unknown->status());
    }

    /**
     * The check is re-run on every request, not carried over from the landing
     * page: an item can be reclassified after a link has been handed out.
     */
    public function test_a_link_stops_working_once_its_item_is_confidential(): void
    {
        [$file, $link] = $this->sharedFile();

        $this->get("/s/{$link->token}/file")->assertOk();

        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $this->get("/s/{$link->token}/file")->assertNotFound();
        $this->get("/s/{$link->token}")->assertNotFound();
    }

    public function test_a_view_cap_is_enforced(): void
    {
        [, $link] = $this->sharedFile(ShareAbility::Download, 1);

        $this->get("/s/{$link->token}")->assertOk();
        $this->get("/s/{$link->token}")->assertNotFound();
    }

    /** A malformed token cannot even match the route. */
    public function test_a_malformed_token_cannot_match_the_route(): void
    {
        foreach (['short', '../../.env', str_repeat('a', 65)] as $token) {
            $this->get("/s/{$token}")->assertNotFound();
        }
    }

    /**
     * The landing page tells the holder only what the link grants, so a
     * view-only link does not render a download button it would refuse.
     */
    public function test_the_landing_page_reports_only_what_the_link_grants(): void
    {
        [, $viewOnly] = $this->sharedFile(ShareAbility::View);

        $can = $this->get("/s/{$viewOnly->token}")->viewData('page')['props']['can'];

        $this->assertFalse($can['download']);
        $this->assertTrue($can['preview']);
    }
}
