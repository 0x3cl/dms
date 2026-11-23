<?php

namespace Tests\Feature;

use App\Enums\DocumentVisibility;
use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Enums\ShareAbility;
use App\Models\Document;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\GuestIdentity;
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
        $this->sayWhoYouAre($link);

        return [$file, $link, $owner];
    }

    /**
     * A document's share link, and the file its bytes live in.
     *
     * A document's link is minted against the **document**, never its `File`
     * row — but `ShareLinkController::stream()` deliberately re-resolves
     * against the file, so `AccessResolver::linkCovers()` is what has to decide
     * that this file is that document's.
     *
     * @return array{0: \App\Models\Document, 1: \App\Models\File, 2: \App\Models\ShareLink}
     */
    private function sharedDocument(DocumentVisibility $visibility = DocumentVisibility::Public): array
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => $visibility,
        ]);
        $link = app(SharingService::class)->createLink($document, ShareAbility::Download, $owner);

        Auth::logout();
        $this->sayWhoYouAre($link);

        return [$document, $file, $link];
    }

    /**
     * The regression this guards: `linkCovers()` read `$subject->document_id`,
     * a column `files` does not have — the link runs the other way, through
     * `documents.file_id`. Eloquent answers an absent attribute with null, so
     * the branch was dead: the landing page resolved, because its subject is
     * the Document and the identity branch matched, and the download 404'd.
     */
    public function test_a_document_link_can_download_that_documents_bytes(): void
    {
        [, , $link] = $this->sharedDocument();

        $this->get("/s/{$link->token}")->assertOk();
        $this->get("/s/{$link->token}/file")->assertOk();
    }

    /**
     * And nothing else's. A link names one document, so it reaches one file.
     *
     * Asserted against the resolver rather than over HTTP, because the share
     * routes give no way to name another document's file — the token *is* the
     * subject there. The second document is private, so the public clause
     * cannot answer for it and the link is the only thing under test.
     */
    public function test_a_document_link_does_not_cover_another_documents_file(): void
    {
        [, , $link] = $this->sharedDocument();
        [, $otherFile] = $this->sharedDocument(DocumentVisibility::Private);

        $resolver = app(AccessResolver::class);

        $this->assertFalse(
            $resolver->allows(null, $otherFile, FileAbility::Download, $link),
            'A link minted against one document must not reach another document\'s bytes.',
        );
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

        $props = $this->get("/s/{$viewOnly->token}")->viewData('page')['props'];

        $this->assertFalse($props['can']['download']);

        // The descriptor is FilePreview's, resolved through the link: a
        // view-only holder still gets a viewer, and it still refuses to hand
        // them a download URL.
        $this->assertNotNull($props['preview']);
        $this->assertFalse($props['preview']['can_download']);
        $this->assertNull($props['preview']['download_url']);
        $this->assertStringContainsString($viewOnly->token, (string) $props['preview']['src']);
    }

    /**
     * A visitor with no account is asked who they are before anything opens.
     *
     * The link still works for whoever holds the URL — that is what a link is
     * — but the trail used to say "Unknown" on every row it wrote, which for
     * a hand-off history is the least useful thing it could say.
     */
    public function test_a_visitor_is_asked_who_they_are_first(): void
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('report.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $link = app(SharingService::class)->createLink($file, ShareAbility::Download, $owner);
        Auth::logout();

        $props = $this->get("/s/{$link->token}")->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['needsIdentity']);
        // Nothing that costs anything is shipped before they answer.
        $this->assertNull($props['preview']);
        $this->assertFalse($props['can']['download']);

        $this->sayWhoYouAre($link, 'Maria Santos');

        $after = $this->get("/s/{$link->token}")->assertOk()->viewData('page')['props'];

        $this->assertFalse($after['needsIdentity'] ?? false);
        $this->assertSame('Maria Santos', $after['visitor']['name']);
        $this->assertFalse($after['visitor']['anonymous']);
    }

    /** A signed-in reader is never asked: they already have a name. */
    public function test_a_signed_in_reader_is_not_asked(): void
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('report.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $link = app(SharingService::class)->createLink($file, ShareAbility::Download, $owner);

        $props = $this->actingAs(User::factory()->create())
            ->get("/s/{$link->token}")->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['needsIdentity'] ?? false);
    }

    // ------------------------------------------- the restricted link term

    /**
     * The term, not the level, decides whether the URL is a key.
     *
     * Both halves in one test over one scene, because the whole of this
     * feature is the difference between the two rows: the same private
     * document, the same holder, the same token — and the only thing that
     * changed is what the uploader said the link was for.
     */
    public function test_the_links_term_decides_whether_a_restricted_document_opens_for_a_stranger(): void
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Private,
        ]);
        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);

        Auth::logout();
        $this->sayWhoYouAre($link);

        // Restricted: the page states the level and the bytes answer 404.
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false));
        $this->get("/s/{$link->token}/file")->assertNotFound();

        app(SharingService::class)->updateLink($link->refresh(), ShareAbility::Download, $owner);
        Auth::logout();

        // Widened: the same URL, for the same stranger, is now a key.
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('share/show', false));
        $this->get("/s/{$link->token}/file")->assertOk();
    }

    /**
     * The restricted term is available at **every** level, public included,
     * and it means the named list wherever it is set.
     *
     * The dialog used to hide it on a public document, reasoning that
     * "people who already have access" is everybody there — and the resolver
     * agreed, because it answered the term by asking what the holder could
     * do with no link at all. So the one control that says "only the people
     * on this document" did nothing on exactly the documents somebody is
     * most likely to be careless with. It resolves the **named audience**
     * now: the uploader and the tagged list, whatever the level.
     */
    public function test_a_restricted_link_on_a_public_document_opens_for_nobody_else(): void
    {
        $owner = User::factory()->create();
        $tagged = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Public,
        ]);

        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);
        $document->allRecipients()->syncWithoutDetaching([$tagged->id => ['notified_at' => now()]]);

        Auth::logout();
        $this->sayWhoYouAre($link);

        // A holder with no account: the page states the classification and
        // the bytes answer 404, exactly as on a private document.
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false));
        $this->get("/s/{$link->token}/file")->assertNotFound();

        // The named people still reach it — that is what the term addresses.
        $this->actingAs($tagged)->get("/s/{$link->token}")->assertRedirect();
        $this->actingAs($owner)->get("/s/{$link->token}")->assertRedirect();
    }

    /**
     * A **signed-in** stranger is refused by the term too.
     *
     * The regression, and it was invisible in every test above because they
     * all probed the link signed out. `show()` sends a reader who may open
     * the document to the document rather than to a card about it, and that
     * check asked the resolver with *no link* — which on a public document
     * every account passes. Asked before the term, it was therefore the
     * question that decided whether the URL opened: a link marked Restricted
     * redirected anybody holding an account straight to the document it was
     * closed to, while a guest on the same URL was correctly refused.
     *
     * Both halves are asserted, because the fix is an ordering and the way
     * to get an ordering wrong is to fix one end of it. A tagged reader must
     * still land on the document.
     */
    public function test_a_restricted_link_on_a_public_document_refuses_a_signed_in_stranger(): void
    {
        $owner = User::factory()->create();
        $tagged = User::factory()->create();
        $stranger = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Public,
        ]);
        $document->allRecipients()->syncWithoutDetaching([$tagged->id => ['notified_at' => now()]]);

        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);

        // The stranger holds an account and the document is public, so the
        // old check passed them. The term says otherwise.
        $this->actingAs($stranger)->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false));
        $this->actingAs($stranger)->get("/s/{$link->token}/file")->assertNotFound();

        // And the people the link is addressed to still get the document.
        $this->actingAs($tagged)->get("/s/{$link->token}")
            ->assertRedirect(route('documents.view', ['code' => $document->code]));
    }

    /**
     * The refusal must not carry the way in.
     *
     * The page offered a "Request access" button, which was not a request at
     * all — a plain navigation to `/documents/view/{code}`. On a document
     * restricted to named people that page *is* the asking: it refuses and
     * carries the form. On a **public** document closed only by its link's
     * term, the level admits every account, so the identical button opened
     * the document in full. It said "Request access" and delivered the
     * document without one.
     *
     * The code went with it, and that is the same leak rather than a second
     * one: on a public document the tracking code *is* the door, so printing
     * it on the refusal was handing over the key beside the lock.
     * `restrictedSummary()` withholds it wherever the level does not
     * restrict, and `grantable` tells the page whether there is anything an
     * uploader could grant — on a public document there is not, since a
     * recipient row adds nothing to what everyone may already read.
     */
    public function test_a_restricted_links_refusal_offers_no_way_into_a_public_document(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Public,
        ]);
        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);

        $this->actingAs($stranger)->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false)
                // Nothing to ask for, so nothing is offered.
                ->where('result.grantable', false)
                // And the code is not disclosed, in the result or beside it.
                ->where('result.code', null)
                ->where('code', null))
            ->assertDontSee($document->code);
    }

    /**
     * Where the level *does* restrict, the refusal keeps both.
     *
     * The withholding above is about a code that opens the document by
     * itself. A private document's code does not — it is what the reader has
     * to quote to ask — so taking it away there would remove the one thing
     * that makes the refusal actionable.
     */
    public function test_a_private_documents_refusal_still_carries_its_code_and_the_ask(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Private,
        ]);
        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);

        $this->actingAs($stranger)->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false)
                ->where('result.grantable', true)
                ->where('result.code', $document->code));

        // And the page that button leads to is the ask, not the document.
        $this->actingAs($stranger)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false));
    }

    /**
     * A bearer link still opens for a stranger with an account.
     *
     * The other end of the same ordering: the redirect moved *after* the
     * term's gate, and it must not have become the gate. A view link is
     * redeemed by whoever holds the URL, signed in or not, and somebody with
     * an account but no claim on the document belongs on the share card —
     * there is no document page they are entitled to.
     */
    public function test_a_bearer_link_on_a_private_document_still_opens_for_a_signed_in_stranger(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Private,
        ]);

        $link = app(SharingService::class)->createLink($document, ShareAbility::Download, $owner);

        $this->actingAs($stranger)->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('share/show', false));
        $this->actingAs($stranger)->get("/s/{$link->token}/file")->assertOk();
    }

    /**
     * The document stays public; only the link is closed.
     *
     * A restricted link is a statement about one URL, never about the
     * document — anybody may still open a public document by its tracking
     * code, which is the door the classification actually describes.
     */
    public function test_a_restricted_link_does_not_close_the_public_document_itself(): void
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Public,
        ]);

        app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);

        Auth::logout();

        $this->get("/track?code={$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', true));
    }

    /**
     * A restricted link resolves to the **holder's own** access, which is
     * more than the term itself carries.
     *
     * The regression this guards: the term was briefly implemented as "the
     * link grants view", so a tagged reader following it could open the
     * document and not download it — a link that *narrowed* what its own
     * audience could already do, which is the opposite of what a link is.
     * An address adds nothing and takes nothing away.
     */
    public function test_a_restricted_link_gives_a_tagged_reader_their_own_access(): void
    {
        $owner = User::factory()->create();
        $tagged = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Private,
        ]);
        $document->allRecipients()->attach($tagged->id);

        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);

        $this->assertTrue(
            app(AccessResolver::class)->allows($tagged, $file, FileAbility::Download, $link),
            'A restricted link must leave its own audience with everything their grant allows.',
        );
        $this->assertFalse(
            app(AccessResolver::class)->allows(null, $file, FileAbility::View, $link),
            'A restricted link must give a holder with no account nothing at all.',
        );
    }

    /**
     * A restricted link still stops when the link stops.
     *
     * Deferring to the holder's access must not mean ignoring the link's own
     * state: a revoked token is over, and "I can read this anyway" is not a
     * reason for a dead URL to answer.
     */
    public function test_a_revoked_restricted_link_is_dead_even_for_its_own_audience(): void
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'visibility' => DocumentVisibility::Private,
        ]);
        $link = app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);
        app(SharingService::class)->revokeLink($link, $owner);

        $this->assertFalse(
            app(AccessResolver::class)->allows($owner, $document, FileAbility::View, $link->refresh()),
            'A revoked link must resolve to nothing whatever its term.',
        );

        $this->actingAs($owner)->get("/s/{$link->token}")->assertNotFound();
    }

    /**
     * Restricted is a document's term. A file has no named-reader rule for a
     * link to defer to — its rule is the visibility and the shares a link
     * already exists to go around — so the service refuses rather than
     * inventing one.
     */
    public function test_a_file_link_cannot_carry_the_restricted_term(): void
    {
        $owner = User::factory()->create();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('report.pdf', 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );

        $this->expectException(\RuntimeException::class);

        app(SharingService::class)->createLink($file, ShareAbility::Restricted, $owner);
    }

    /**
     * Answer the prompt, and keep answering it.
     *
     * `withCookies()` mutates the test client's default cookies, so one call
     * covers every later request in the test — which is what a browser does.
     * The value is taken *decrypted* off the response, because the client
     * encrypts whatever it is handed and passing ciphertext back encrypts it
     * twice.
     */
    private function sayWhoYouAre(ShareLink $link, ?string $name = null): void
    {
        $response = $this->post("/s/{$link->token}/identify", ['name' => $name]);

        $this->withCookies([
            GuestIdentity::COOKIE => $response->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);
    }
}
