<?php

namespace Tests\Feature;

use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Documents\DocumentRegistrar;
use App\Services\Files\FileManager;
use App\Services\Files\FolderService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public tracking surface — a label scanned, or a code typed, by somebody
 * with no account.
 *
 * The contract these assert is the one thing this page has to get right: a
 * code is not a credential. It opens a **public** document, which is what
 * `AccessResolver::forDocument()` has always said about one, and it tells a
 * visitor nothing whatsoever about a private or confidential document beyond
 * the fact that it is classified. A test asserting the restricted case is
 * therefore an *absence* test — the title must not be in the payload — because
 * the hole this page could open is a leak of metadata, not a leak of bytes.
 *
 * `actingAs()` sets the user for every later request in a test, so each of
 * these builds its scene and then signs out before asking as a visitor.
 *
 * `component(..., false)` throughout: the page-existence check reads
 * `resource_path('js/Pages')`, and this project's pages are in `js/pages`, so
 * on a case-sensitive filesystem the finder would fail every assertion here
 * for a page that is plainly there.
 */
class PublicTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function tracked(string $visibility, string $title = 'Quarterly Memo'): Document
    {
        $uploader = User::factory()->create();
        $uploader->assignRole(Roles::STAFF);

        $folder = app(FolderService::class)->rootFor($uploader);

        $file = app(FileManager::class)->upload(
            $uploader,
            $folder,
            UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        );

        return app(DocumentRegistrar::class)->register(
            $file,
            DocumentType::query()->firstOrFail(),
            $title,
            DocumentVisibility::from($visibility),
            [],
            $uploader,
        );
    }

    public function test_the_lookup_opens_without_an_account(): void
    {
        $this->get('/track')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)->where('result', null));
    }

    public function test_a_visitor_sees_a_public_document_and_its_preview(): void
    {
        $document = $this->tracked('public');

        auth()->logout();

        $this->get("/track?code={$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/index', false)
                ->where('result.open', true)
                ->where('result.visibility', 'public')
                ->where('result.title', 'Quarterly Memo')
                ->where('result.preview.mode', 'document'));
    }

    public function test_a_visitor_is_told_only_the_level_of_a_private_document(): void
    {
        $document = $this->tracked('private', 'Disciplinary Case');

        auth()->logout();

        $this->get("/track?code={$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/index', false)
                ->where('result.open', false)
                ->where('result.visibility', 'private')
                ->where('result.title', null)
                ->where('result.uploader', null)
                ->where('result.preview', null))
            ->assertDontSee('Disciplinary Case');
    }

    public function test_a_visitor_is_told_only_the_level_of_a_confidential_document(): void
    {
        $document = $this->tracked('confidential', 'Board Minutes');

        auth()->logout();

        $this->get("/track?code={$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/index', false)
                ->where('result.open', false)
                ->where('result.visibility', 'confidential')
                ->where('result.preview', null))
            ->assertDontSee('Board Minutes');
    }

    public function test_an_unknown_code_says_so_rather_than_erroring(): void
    {
        $this->get('/track?code=NOPE-20260101-0001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)->where('notFound', true));
    }

    public function test_an_empty_code_renders_the_lookup_rather_than_a_miss(): void
    {
        $this->get('/track?code=%20%20')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/index', false)
                ->where('notFound', false)
                ->where('malformed', false)
                ->where('result', null));
    }

    /**
     * The miss that is not a miss.
     *
     * A QR code carrying a vCard, a wifi label or a pasted paragraph is not a
     * document that could not be found — it is not a tracking code — and the
     * two answers send a person to different places.
     */
    public function test_input_that_cannot_be_a_tracking_code_is_told_so(): void
    {
        foreach (['hello world', 'https://example.com/promo', 'MEMO', '20260101-0001', str_repeat('x', 300)] as $input) {
            $this->get('/track?code='.urlencode($input))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('track/index', false)
                    ->where('malformed', true)
                    ->where('notFound', false)
                    ->where('result', null));
        }
    }

    public function test_a_well_formed_code_that_matches_nothing_is_a_miss_not_a_malformation(): void
    {
        $this->get('/track?code=MEMO-20260101-9999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/index', false)
                ->where('notFound', true)
                ->where('malformed', false));
    }

    public function test_an_overlong_input_is_truncated_rather_than_refused(): void
    {
        $this->get('/track?code='.urlencode(str_repeat('A', 5000)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('track/index', false)
                ->where('malformed', true)
                ->where('code', str_repeat('A', 64)));
    }

    public function test_the_authenticated_lookup_answers_the_same_way(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Roles::STAFF);

        $this->actingAs($staff)
            ->get('/documents/track?code=not+a+code')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('documents/track', false)
                ->where('malformed', true));
    }

    public function test_a_reader_who_may_open_it_is_sent_to_the_document_page(): void
    {
        $document = $this->tracked('public');

        $reader = User::factory()->create();
        $reader->assignRole(Roles::STAFF);

        $this->actingAs($reader)
            ->get("/track?code={$document->code}")
            ->assertRedirect("/documents/view/{$document->code}");
    }

    public function test_a_visitor_may_reach_the_bytes_of_a_public_document(): void
    {
        $document = $this->tracked('public');

        auth()->logout();

        $file = $document->file;
        $url = "/files/{$file->owner->public_id}/{$file->public_id}";

        $this->get($url.'?disposition=inline')->assertOk();
    }

    public function test_a_visitor_cannot_reach_the_bytes_of_a_restricted_document(): void
    {
        $document = $this->tracked('private');

        auth()->logout();

        $file = $document->file;
        $url = "/files/{$file->owner->public_id}/{$file->public_id}";

        // 404 rather than 403, matching the stream route's convention:
        // "not yours" and "no such file" stay indistinguishable.
        $this->get($url.'?disposition=inline')->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    /**
     * `/scan` is gone, and the lookup opens on the camera instead.
     *
     * It was a third address for the middle of three ways to name a
     * document — a camera page reachable from the landing page and the
     * dashboard, forwarding a guest here and rendering its own screen for
     * anybody signed in. Both lookups already read a label with the same
     * `QrScanner`, so it was one list at three addresses, and the one nobody
     * maintains is the one that drifts.
     *
     * What replaced it is a parameter on the screens that already existed:
     * a visitor gets this one, somebody signed in gets the register's own.
     * The route answering nothing is asserted too, because a removal that
     * leaves the old address serving is not a removal.
     */
    public function test_the_scan_route_is_gone_and_the_lookup_opens_on_the_camera(): void
    {
        $this->get('/scan')->assertNotFound();

        $this->get('/track?method=scan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('method', 'scan'));

        // And the register's own lookup, for somebody with an account.
        $this->actingAs(User::factory()->create())
            ->get('/documents/track?method=scan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/track', false)
                ->where('method', 'scan'));

        // A stray value opens on the code box rather than on nothing.
        $this->actingAs(User::factory()->create())
            ->get('/documents/track?method=nonsense')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/track', false)
                ->where('method', 'code'));
    }
}
