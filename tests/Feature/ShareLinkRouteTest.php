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
     * Every value the trail column can hold still reads.
     *
     * `document_logs.action` is a stored column and `DocumentAction` is what
     * reads it, so deleting a case does not delete the rows holding its
     * value — it makes them unreadable. Removing `reclassified` when the
     * feature that wrote it was withdrawn did exactly that: the next request
     * that touched one of those rows died with `"reclassified" is not a
     * valid backing value`, and it took the whole page with it rather than
     * degrading.
     *
     * So this asserts the property rather than a list: every distinct value
     * the table holds must map to a case. It cannot rot as actions are
     * added, and it fails the moment one is taken away while rows still
     * carry it.
     */
    public function test_every_action_value_in_the_trail_still_maps_to_a_case(): void
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

        // Including the ones nothing writes any more: a withdrawn feature's
        // rows are still in the table, and still have to render.
        foreach (\App\Enums\DocumentAction::cases() as $action) {
            \App\Models\DocumentLog::record($document, $action, $owner);
        }

        $stored = \Illuminate\Support\Facades\DB::table('document_logs')
            ->distinct()->pluck('action');

        foreach ($stored as $value) {
            $this->assertNotNull(
                \App\Enums\DocumentAction::tryFrom($value),
                "The trail holds [{$value}], which no DocumentAction case backs. Removing a case does not remove the rows.",
            );
        }

        // And the page that renders them survives every one.
        $this->actingAs($owner)->get("/documents/view/{$document->code}")->assertOk();
        $this->actingAs($owner)->get("/documents/{$document->id}/logs")->assertOk();
    }

    /**
     * And a restricted *level* keeps the sentence it always had, which
     * `AccessRequestTest` is the contract for.
     */
    public function test_a_restricted_level_still_says_restricted(): void
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

        $this->assertSame('restricted', $document->refusalReason($stranger));

        $this->actingAs($stranger)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('message', fn (string $message) => str_contains($message, 'restricted to the people tagged on it')));
    }

    /**
     * The term and the list it needs are set together.
     *
     * Choosing `restricted` says "only the people tagged on this document",
     * and the list is optional at every level by design — a private document
     * can be filed before anybody knows who it is for — so the term, taken
     * alone, can close a document to its uploader and to nobody else.
     * Sending somebody off to the edit form and back is how that happens, so
     * the dialog carries the picker.
     *
     * Underneath it is the edit form's own path, and the properties that
     * path has are asserted with it: a **diff**, so a later edit that does
     * not touch the list re-notifies nobody, and an absent key leaves the
     * list alone.
     */
    public function test_the_share_dialog_tags_the_people_the_restricted_term_means(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $owner = User::factory()->create();
        $colleague = User::factory()->create();
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

        $this->actingAs($owner)->put("/documents/{$document->id}/share-links/{$link->id}", [
            'ability' => 'restricted',
            'recipients' => [$colleague->id],
        ])->assertSessionHas('success');

        $this->actingAs($colleague)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/show', false));
        $this->actingAs($stranger)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false));

        \Illuminate\Support\Facades\Notification::assertSentTo($colleague, \App\Notifications\PrivateDocumentShared::class);

        // A diff, not a sync: changing a limit must not re-notify the people
        // already on the list.
        \Illuminate\Support\Facades\Notification::fake();
        $this->actingAs($owner)->put("/documents/{$document->id}/share-links/{$link->id}", [
            'ability' => 'restricted',
            'recipients' => [$colleague->id],
            'max_views' => 5,
        ])->assertSessionHas('success');
        \Illuminate\Support\Facades\Notification::assertNothingSent();

        // And leaving the field out leaves the list alone, which is what
        // every other caller of this endpoint sends.
        $this->actingAs($owner)->put("/documents/{$document->id}/share-links/{$link->id}", ['ability' => 'restricted']);
        $this->assertTrue($document->fresh()->recipients()->whereKey($colleague->id)->exists());
    }

    /**
     * A term the level cannot carry has no effect.
     *
     * `assertLinkable()` refuses `restricted` on a public document, so one
     * that holds such a link is a row from the window when the term was
     * offered everywhere. Honouring it would enforce a rule that no longer
     * exists: the URL would open for nobody but the uploader while the
     * document itself opens for anyone holding its tracking code — and the
     * URL is the thing people actually have, so it reads as the link being
     * broken rather than as a policy.
     *
     * So a non-bearer term on a document with no named list resolves the
     * document's own rule, which is what the term always meant — the
     * holder's own access. Nothing is widened: it grants exactly what
     * `/track` grants the same person with the same code, which for a public
     * document is a read without an account.
     */
    public function test_a_restricted_term_on_a_public_document_resolves_as_the_document_does(): void
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

        // Written directly, because the service refuses to mint it now.
        $link = app(SharingService::class)->createLink($document, ShareAbility::View, $owner);
        \App\Models\ShareLink::whereKey($link->id)->update(['ability' => 'restricted']);
        $link = $link->fresh();

        $this->assertTrue(
            app(AccessResolver::class)->allows(null, $document, FileAbility::View, $link),
            'A public document opens for anybody; a term it cannot carry must not close it.',
        );

        Auth::logout();
        $this->sayWhoYouAre($link);

        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('share/show', false));
        $this->get("/s/{$link->token}/file")->assertOk();
    }

    /**
     * And where the level *does* carry it, the term still closes the link.
     *
     * The fallback above is scoped to the one case that cannot happen any
     * more, so it must not become a way around the term where the term is
     * real.
     */
    public function test_the_term_still_closes_a_private_documents_link(): void
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

        $this->assertFalse(
            app(AccessResolver::class)->allows(null, $document, FileAbility::View, $link),
        );

        Auth::logout();
        $this->sayWhoYouAre($link);
        $this->get("/s/{$link->token}/file")->assertNotFound();
    }

    /**
     * The term belongs to a private or confidential document, and nowhere else.
     *
     * It says "the uploader and the people tagged on this document", which
     * is a narrowing of a private document and a contradiction of a public
     * one: to mean anything on a public document it would have to close the
     * register and the printed tracking code too, and a public document is
     * public. A document that should be for named people is filed as
     * private — that is what the level is for — and the link's terms
     * describe the URL within it.
     *
     * Asserted at the **service**, not at the dialog. An option a page
     * merely hides is a rule only the page keeps, and this endpoint takes a
     * posted value.
     */
    public function test_a_public_documents_link_cannot_carry_the_restricted_term(): void
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

        // Minting one outright.
        try {
            app(SharingService::class)->createLink($document, ShareAbility::Restricted, $owner);
            $this->fail('A public document must not be given a restricted link.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('private or confidential', $e->getMessage());
        }

        // And narrowing an existing one to it.
        $link = app(SharingService::class)->createLink($document, ShareAbility::View, $owner);

        try {
            app(SharingService::class)->updateLink($link, ShareAbility::Restricted, $owner);
            $this->fail('A public document\'s link must not be narrowed to restricted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('private or confidential', $e->getMessage());
        }

        // The document is untouched by the refusal, and still public.
        $this->assertSame(ShareAbility::View, $link->fresh()->ability);
        $this->assertSame(DocumentVisibility::Public, $document->fresh()->visibility);
    }

    /**
     * A signed-in link holder is sent **into the documents module**.
     *
     * They used to be left on the share card, which is the one page in the
     * system that shows a tracked document without saying it is one: no
     * code, no classification, no uploader. Coming in with an account should
     * never be a worse page than coming in without one — so the redirect
     * carries the token, and `documents.view` resolves the grant through it.
     *
     * What the token buys there is exactly what it buys on `/s/{token}`:
     * **the bytes on the link's term, and nothing else**. The trail, the
     * thread and the version history are the document's own record — a trail
     * listing who else received a case file is a disclosure of its own — so
     * all three are empty in the payload, and the version endpoint still
     * refuses outright. This asserts the boundary from both sides, because
     * a page that merely *hides* a prop it was sent has disclosed it.
     */
    public function test_a_signed_in_link_holder_lands_on_the_document_page(): void
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
        // Something for the trail and the thread to hold, so "empty" is a
        // boundary rather than an accident of an empty document.
        \App\Models\DocumentLog::record($document, \App\Enums\DocumentAction::Uploaded, $owner);
        \App\Models\DocumentComment::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'actor_name' => $owner->name,
            'body' => 'Needs the annex before it goes out.',
            'version' => 1,
        ]);

        $link = app(SharingService::class)->createLink($document, ShareAbility::Download, $owner);

        $this->actingAs($stranger)->get("/s/{$link->token}")
            ->assertRedirect(route('documents.view', ['code' => $document->code, 'token' => $link->token]));

        $this->actingAs($stranger)
            ->get(route('documents.view', ['code' => $document->code, 'token' => $link->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/show', false)
                ->where('viaLink', true)
                ->where('linkToken', $link->token)
                // The link carries a copy, so the button is offered.
                ->where('can.download', true)
                // And never the uploader's half.
                ->where('can.manage', false)
                ->where('links', [])
                ->where('removedViewers', [])
                // The document's own record, which the link never carried.
                ->where('logs.total', 0)
                ->where('comments', [])
                ->where('commentsEnabled', false))
            ->assertDontSee('Needs the annex');

        // The version history stays refused outright, not merely hidden: a
        // link reaches the current version only.
        $this->actingAs($stranger)
            ->getJson("/documents/{$document->id}/versions?token={$link->token}")
            ->assertNotFound();
    }

    /**
     * And the term still decides what the bytes do.
     *
     * A **view** link carries no copy. Landing on the document page must not
     * quietly promote that to the reader's ordinary set, which includes
     * download — the page is a better place to read from, not a wider grant.
     */
    public function test_a_view_only_link_holder_cannot_download_from_the_document_page(): void
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
        $link = app(SharingService::class)->createLink($document, ShareAbility::View, $owner);

        $this->actingAs($stranger)
            ->get(route('documents.view', ['code' => $document->code, 'token' => $link->token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/show', false)
                ->where('viaLink', true)
                ->where('can.download', false));

        // The route itself, not only the button.
        $this->actingAs($stranger)
            ->get("/documents/{$document->id}/file/download?token={$link->token}")
            ->assertNotFound();
        $this->actingAs($stranger)
            ->get("/documents/{$document->id}/file/view?token={$link->token}")
            ->assertOk();
    }

    /**
     * A token that is not a grant changes nothing.
     *
     * Unknown, revoked, expired, spent, for another document, or restricted
     * and held by somebody it is not for: `viewerLink()` returns null for
     * every one of them, so the page falls back to the document's own rule
     * and refuses. The token is not a second door that merely needs to be
     * *present*.
     */
    public function test_a_dead_token_on_the_document_url_grants_nothing(): void
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
        app(SharingService::class)->revokeLink($link, $owner);

        foreach ([$link->token, 'not-a-real-token'] as $token) {
            $this->actingAs($stranger)
                ->get(route('documents.view', ['code' => $document->code, 'token' => $token]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('documents/restricted', false));
        }
    }

    /**
     * A bearer link still opens for a stranger with an account.
     *
     * The other end of the ordering: the redirect runs *after* the term's
     * gate, and it must not have become the gate. A view or download link is
     * redeemed by whoever holds the URL, signed in or not.
     *
     * Where they land has since changed, and deliberately. They used to stop
     * on the share card, on the reasoning that somebody with no claim on the
     * document had no document page to be entitled to. That was right about
     * the grant and wrong about the page: the card is the one screen that
     * shows a tracked document without saying it is one — no code, no
     * classification, no uploader — so holding an account made the document
     * *less* legible, not more. They are sent into the module now, with the
     * token, and the link's term still decides what they may do there.
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
            ->assertRedirect(route('documents.view', ['code' => $document->code, 'token' => $link->token]));

        // The link's own routes keep working either way: the redirect is
        // where a *browser* is sent, not a narrowing of what the token does.
        $this->actingAs($stranger)->get("/s/{$link->token}/file")->assertOk();

        // And a holder with no account is untouched by all of it — the card
        // is still the right page for somebody with nowhere else to be.
        Auth::logout();
        $this->sayWhoYouAre($link);
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('share/show', false));
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
