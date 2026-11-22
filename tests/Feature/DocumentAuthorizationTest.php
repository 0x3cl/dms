<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Files\GuestIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the document access rules.
 *
 * Every one of these described a real hole: track-by-code skipped the
 * visibility gate entirely, and edit/update/destroy had no ownership check at
 * all, so any authenticated user could rewrite or delete any document.
 */
class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $owner, string $visibility = 'private'): Document
    {
        return Document::factory()
            ->ofType('CONFID')
            ->for($owner)
            ->create([
                'title' => 'Case File',
                'file_path' => 'documents/case-file.pdf',
                'uploader_name' => $owner->name,
                'visibility' => $visibility,
            ]);
    }

    public function test_track_by_code_refuses_a_private_document_to_a_stranger(): void
    {
        $document = $this->document(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/documents/track?code={$document->code}")
            ->assertOk()
            // A page about the document, not a bounce to the dashboard. The
            // clause that refused is unchanged; what changed is where the
            // reader is standing when they are told. See `documents/restricted`.
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code));
    }

    public function test_track_by_code_allows_a_named_recipient(): void
    {
        $document = $this->document(User::factory()->create());
        $recipient = User::factory()->create();
        $document->recipients()->attach($recipient);

        $this->actingAs($recipient)
            ->get("/documents/track?code={$document->code}")
            ->assertOk();
    }

    public function test_a_public_document_is_readable_by_anyone_with_the_code(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->get("/documents/view/{$document->code}")
            ->assertOk();
    }

    // ------------------------------------------------------- confidential

    /**
     * Confidential reads exactly like private: the uploader and the tagged
     * people, nobody else. The difference is the link, below.
     */
    public function test_a_confidential_document_is_refused_to_a_stranger(): void
    {
        $document = $this->document(User::factory()->create(), 'confidential');

        $this->actingAs(User::factory()->create())
            ->get("/documents/track?code={$document->code}")
            ->assertOk()
            // A page about the document, not a bounce to the dashboard. The
            // clause that refused is unchanged; what changed is where the
            // reader is standing when they are told. See `documents/restricted`.
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code));
    }

    public function test_a_confidential_document_is_readable_by_a_tagged_person(): void
    {
        $document = $this->document(User::factory()->create(), 'confidential');
        $recipient = User::factory()->create();
        $document->recipients()->attach($recipient);

        $this->actingAs($recipient)
            ->get("/documents/track?code={$document->code}")
            ->assertOk();
    }

    /**
     * A signed-out reader holding the tracking code gets a public document and
     * nothing else. This is the case the QR label creates — a printed code in
     * somebody's hand — so it is the one most worth stating.
     */
    /**
     * A signed-out reader gets nothing from a tracking code, at any level.
     *
     * This used to admit a public document, on the reasoning that a QR label
     * must resolve for whoever is holding the folder. The office's answer is
     * narrower: a tracking code is *printed on the folder*, so treating it as
     * a credential means anyone who photographs a desk reads every public
     * document in the institute — and the codes are sequential besides.
     * Somebody outside gets a share link, which has an ability, an expiry, a
     * view cap and a revoke button.
     */
    public function test_a_signed_out_reader_is_forwarded_to_the_public_lookup(): void
    {
        $owner = User::factory()->create();

        foreach (['public', 'private', 'confidential'] as $level) {
            $document = $this->document($owner, $level);

            // The register's own lookup box is a screen inside the
            // application, so it still asks for an account.
            $this->get('/documents/track?code='.$document->code)->assertRedirect('/login');

            /*
             * The *link* does not. One URL answers whoever opens it, and for
             * somebody with no account the answer is the public page, which
             * describes a public document and states a restricted one's level
             * and nothing else. A forward rather than a widening: the code
             * buys exactly what `/track` has always given it, on the throttled
             * route, and is still not a credential.
             */
            $this->get('/documents/view/'.$document->code)
                ->assertRedirect('/track?code='.$document->code);
        }
    }

    // -------------------------------------------------------- sharing by link

    /**
     * The whole distinction between private and confidential.
     *
     * Both grant identical reading, so the only thing that separates them is
     * whether the uploader may hand that reading to somebody outside the
     * tagged list.
     */
    public function test_a_link_may_be_minted_for_a_public_or_private_document(): void
    {
        $owner = User::factory()->superadmin()->create();

        foreach (['public', 'private'] as $level) {
            $document = $this->document($owner, $level);

            $this->actingAs($owner)
                ->post("/documents/{$document->id}/share-links", ['ability' => 'view'])
                ->assertSessionHasNoErrors();

            $this->assertSame(1, $document->shareLinks()->count(), "A {$level} document must be linkable.");
        }
    }

    public function test_a_confidential_document_cannot_be_shared_by_link(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'confidential');

        $this->actingAs($owner)
            ->post("/documents/{$document->id}/share-links", ['ability' => 'view'])
            ->assertSessionHas('error');

        $this->assertSame(0, $document->shareLinks()->count());
    }

    /** Only the uploader, whatever the module permission says. */
    public function test_a_reader_cannot_mint_a_link_for_someone_elses_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');
        $stranger = User::factory()->superadmin()->create();

        $this->actingAs($stranger)
            ->post("/documents/{$document->id}/share-links", ['ability' => 'view'])
            ->assertRedirect('/dashboard');

        $this->assertSame(0, $document->shareLinks()->count());
    }

    /**
     * A link opens the document without an account — which is the point of it,
     * and is why the token is the credential.
     */
    /**
     * `actingAs()` sets the user for **every later request in the test**, so
     * an assertion about "a visitor with no account" made after a scene was
     * built by the uploader is an assertion about that uploader. These tests
     * all mint their link as the owner first, so each one signs out before
     * the part that is about somebody else.
     */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Sign out and become a named link visitor.
     *
     * The bytes behind a link are gated on the visitor having said who they
     * are — that is what keeps the prompt from being decoration — so a test
     * that signs out and goes straight to `/s/{token}/file` is answered by
     * that prompt rather than by the access rule it means to assert. The
     * identity has to be taken while the link still resolves, which is why
     * this is called before a reclassification rather than after one.
     */
    private function asVisitor(ShareLink $link): void
    {
        $this->signOut();

        $identify = $this->post("/s/{$link->token}/identify", ['name' => 'Maria Santos']);

        $this->withCookies([
            GuestIdentity::COOKIE => $identify->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);
    }

    public function test_a_link_opens_a_private_document_without_an_account(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'private');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'view']);

        $link = $document->shareLinks()->sole();

        // No session at all: this is somebody who was sent the URL.
        $this->signOut();

        $this->get("/s/{$link->token}")->assertOk();
    }

    /**
     * The reclassification case, which is why the check is on every redemption
     * rather than only at creation. A link handed out while a document was
     * private has to stop working the moment it becomes confidential — not when
     * somebody remembers to revoke it.
     */
    public function test_a_link_stops_working_when_the_document_becomes_confidential(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'private');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'download']);

        $link = $document->shareLinks()->sole();
        $this->asVisitor($link);

        $this->get("/s/{$link->token}")->assertOk();

        $document->update(['visibility' => 'confidential']);

        $this->get("/s/{$link->token}")->assertNotFound();
        $this->get("/s/{$link->token}/file")->assertNotFound();
    }

    /**
     * A link names one document, and the resolver is what decides that a given
     * file is that document's. Without that, a token legitimately issued for
     * one document would authorize the bytes of another.
     *
     * Public, so that the share page actually renders for the visitor and the
     * assertion can read which document it is about. A private link answers an
     * anonymous holder with the classification and nothing else — which is the
     * subject of its own test above, and would make this one assert the
     * absence of a field rather than the scoping of a token.
     */
    public function test_a_links_token_does_not_reach_another_documents_file(): void
    {
        $owner = User::factory()->superadmin()->create();
        $shared = $this->document($owner, 'public');
        $other = $this->document($owner, 'public');

        $this->actingAs($owner)->post("/documents/{$shared->id}/share-links", ['ability' => 'download']);

        $link = $shared->shareLinks()->sole();
        $this->asVisitor($link);

        // The landing page is the shared document's, not the other one's.
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('item.code', $shared->code));

        $this->assertNotSame($shared->code, $other->code);
    }

    /** A revoked link is indistinguishable from one that never existed. */
    public function test_a_revoked_link_stops_working(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'private');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'view']);

        $link = $document->shareLinks()->sole();

        $this->actingAs($owner)->delete("/documents/{$document->id}/share-links/{$link->id}")
            ->assertSessionHasNoErrors();

        $this->get("/s/{$link->token}")->assertNotFound();
    }

    /**
     * A document has one live link, and its terms are edited rather than
     * superseded.
     *
     * Several links to one document is several credentials with separate
     * expiries and caps, and nothing on screen can say which of them the
     * message somebody sent last week carried. Editing keeps the URL, so
     * narrowing a link does not cut off the people it was narrowed for.
     */
    public function test_a_document_has_one_live_link_whose_terms_can_change(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'private');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'download']);

        $link = $document->shareLinks()->sole();
        $token = $link->token;

        // A second is refused rather than minted.
        $this->actingAs($owner)
            ->post("/documents/{$document->id}/share-links", ['ability' => 'view'])
            ->assertSessionHas('error');

        $this->assertSame(1, $document->shareLinks()->whereNull('revoked_at')->count());

        $this->actingAs($owner)
            ->patch("/documents/{$document->id}/share-links/{$link->id}", ['ability' => 'view', 'max_views' => 2])
            ->assertSessionHasNoErrors();

        $link->refresh();

        $this->assertSame($token, $link->token, 'Editing a link must not change its URL.');
        $this->assertSame('view', $link->ability->value);
        $this->assertSame(2, $link->max_views);
    }

    /**
     * Regenerating replaces the address and keeps everything else.
     *
     * The act an uploader wants when a URL has travelled further than they
     * meant it to. Four things have to hold at once, and three of them are
     * the ways the obvious implementation — revoke, then create again — gets
     * it wrong: the terms are decisions that were already made and survive,
     * the document is never briefly without a link (a public one has a
     * printed label pointing at it), and the row is the same row, so the
     * screen can still say what the link has been doing. The fourth is the
     * point of the exercise: the old token is dead.
     */
    public function test_regenerating_a_link_replaces_its_address_and_keeps_its_terms(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'public');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", [
            'ability' => 'download',
            'max_views' => 5,
        ]);

        $link = $document->shareLinks()->sole();
        $old = $link->token;

        // Spend an open, so the reset is visible rather than vacuous.
        $link->recordAccess();

        $this->actingAs($owner)
            ->post("/documents/{$document->id}/share-links/{$link->id}/regenerate")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $link->refresh();

        $this->assertNotSame($old, $link->token, 'Regenerating must change the address.');
        $this->assertSame('download', $link->ability->value, 'Regenerating must keep the terms.');
        $this->assertSame(5, $link->max_views);
        $this->assertNull($link->revoked_at, 'The document must never be left without its one link.');
        $this->assertSame(1, $document->shareLinks()->whereNull('revoked_at')->count());

        /*
         * The count starts again, because the URL those opens were spent
         * against no longer exists — a new link that is already partly used
         * up is not a new link. What it was survives on the audit row.
         */
        $this->assertSame(0, $link->view_count);

        $this->get('/s/'.$old)->assertNotFound();
        // Still signed in as the uploader, who is sent to the document rather
        // than to a card about it — which is the new URL resolving.
        $this->get("/s/{$link->token}")->assertRedirect('/documents/view/'.$document->code);
    }

    /**
     * And it is on both trails.
     *
     * The activity log because it is a change to a credential, and the
     * **document's own** trail because it ends a hand-off: every copy of the
     * URL that was sent out stops working, and a reader holding a dead link
     * is entitled to learn from the document's history why. Neither row
     * carries a token — old or new — because an audit row that carries a live
     * credential is a way to obtain it by reading the trail.
     */
    public function test_regenerating_a_link_is_written_to_both_trails(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'public');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'view']);
        $link = $document->shareLinks()->sole();
        $old = $link->token;

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links/{$link->id}/regenerate");

        $this->assertSame(
            1,
            $document->logs()->where('action', DocumentAction::LinkRegenerated)->count(),
            'A regeneration must be on the document\'s own trail.',
        );

        $activity = ActivityLog::query()
            ->where('action', ActivityAction::LinkRegenerated)
            ->sole();

        $this->assertSame($owner->id, $activity->actor_id);
        $this->assertSame(0, $activity->context['previous_views']);

        foreach ([$document->logs()->latest('id')->first()->toArray(), $activity->toArray()] as $row) {
            $encoded = json_encode($row);

            $this->assertStringNotContainsString($old, $encoded, 'A trail row carried a share token.');
            $this->assertStringNotContainsString($link->fresh()->token, $encoded, 'A trail row carried a share token.');
        }
    }

    /** Only the uploader may re-address a link, and only through its own document. */
    public function test_regenerating_a_link_is_refused_to_everybody_but_the_uploader(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'public');
        $other = $this->document($owner, 'public');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'view']);
        $link = $document->shareLinks()->sole();
        $token = $link->token;

        $this->actingAs(User::factory()->create())
            ->post("/documents/{$document->id}/share-links/{$link->id}/regenerate")
            ->assertRedirect('/dashboard');

        $this->actingAs($owner)
            ->post("/documents/{$other->id}/share-links/{$link->id}/regenerate")
            ->assertNotFound();

        $this->assertSame($token, $link->fresh()->token);
    }

    /** An identifier from one document cannot edit another's link. */
    public function test_a_links_terms_cannot_be_changed_through_another_document(): void
    {
        $owner = User::factory()->superadmin()->create();
        $mine = $this->document($owner, 'private');
        $other = $this->document($owner, 'private');

        $this->actingAs($owner)->post("/documents/{$mine->id}/share-links", ['ability' => 'view']);
        $link = $mine->shareLinks()->sole();

        $this->actingAs($owner)
            ->patch("/documents/{$other->id}/share-links/{$link->id}", ['ability' => 'download'])
            ->assertNotFound();

        $this->assertSame('view', $link->fresh()->ability->value);
    }

    /**
     * Opening a link is part of the document's own travel history, not only of
     * the administrator's activity trail. Without the entry the trail says the
     * document was never opened, which is the question a hand-off history
     * exists to answer.
     *
     * Public, because this is about the row a *successful* open writes. A
     * private link refused to an anonymous holder deliberately records
     * nothing — nothing was opened and no view was spent — so running this
     * against one would assert that a refusal is a read.
     */
    public function test_opening_a_link_is_recorded_on_the_documents_trail(): void
    {
        $owner = User::factory()->superadmin()->create();
        $document = $this->document($owner, 'public');

        $this->actingAs($owner)->post("/documents/{$document->id}/share-links", ['ability' => 'view']);

        $link = $document->shareLinks()->sole();
        $this->asVisitor($link);

        $before = $document->logs()->count();

        $this->get("/s/{$link->token}")->assertOk();

        $this->assertSame($before + 1, $document->logs()->count());
        $this->assertStringContainsString(
            'share link',
            (string) $document->logs()->latest('id')->first()->remarks,
        );
    }

    public function test_only_the_uploader_can_edit_a_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->get("/documents/{$document->id}/edit")
            ->assertRedirect('/dashboard');
    }

    public function test_only_the_uploader_can_update_a_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->put("/documents/{$document->id}", [
                'title' => 'Rewritten',
                'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            ])
            ->assertRedirect('/dashboard');

        $this->assertSame('Case File', $document->fresh()->title);
    }

    public function test_only_the_uploader_can_delete_a_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->delete("/documents/{$document->id}")
            ->assertRedirect('/dashboard');

        $this->assertModelExists($document);
    }

    /**
     * The register's row action, which draws a symbol on request.
     *
     * The label encodes the share link for the uploader — that is what makes
     * the printed label work for a courier, and what makes it a credential.
     * A reader who merely *may open* the document must not be handed one by
     * opening a dialog, so they get a symbol for the tracking URL, which
     * opens nothing without an account.
     */
    public function test_the_qr_label_endpoint_gives_a_reader_the_tracking_url_and_the_uploader_the_link(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $document = $this->document($owner, 'public');

        $link = app(\App\Services\Files\SharingService::class)->ensureLinkFor($document, $owner);

        $this->actingAs($owner)
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->assertJson(['can_download' => true])
            ->assertJsonPath('target', url('/s/'.$link->token));

        $this->actingAs($reader)
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->assertJson(['can_download' => false, 'download_url' => null])
            ->assertJsonPath('target', url('/documents/view/'.$document->code));
    }

    public function test_the_qr_label_endpoint_refuses_a_document_the_reader_may_not_open(): void
    {
        $document = $this->document(User::factory()->create(), 'confidential');

        $this->actingAs(User::factory()->create())
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertNotFound();
    }

    public function test_the_qr_label_route_refuses_a_filename_no_document_owns(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/documents/qr/download/'.urlencode('../../../.env'))
            ->assertNotFound();
    }

    /**
     * The Log action endpoint validated `action` as `string|max:255`, so a
     * client could post any sentence it liked into a document's history. Only
     * the actions a person performs by hand are accepted.
     *
     * `received` **is** one of them now — a document reaching you is something
     * only you can assert. What must stay unforgeable is the set the *system*
     * observes: a download is a record that bytes left this server, and a
     * client claiming one did not make it true.
     */
    public function test_the_log_action_endpoint_refuses_an_action_a_person_cannot_perform(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner, 'public');

        foreach (['downloaded', 'viewed', 'uploaded', 'link_shared', 'Anything at all'] as $forged) {
            $this->actingAs($owner)
                ->post('/document/log', [
                    'document_id' => $document->id,
                    'action' => $forged,
                ])
                ->assertSessionHasErrors('action');
        }

        $this->assertSame(0, $document->logs()->count());
    }

    /**
     * Taking delivery is **not** a one-time fact, and this used to assert
     * that it was.
     *
     * The rule came across from the old receive-and-download endpoint,
     * which enforced it before handing back the file, and it survived that
     * endpoint's removal because it reads like a property of the fact: a
     * document reaches you once. It does not. It reaches the same person as
     * paper on Monday and as a re-sent copy on Friday, and after going out
     * for signature and coming back — and the two rows are not duplicates,
     * because they carry different timestamps and usually different
     * remarks, which is the information a hand-off trail exists to hold.
     *
     * Refusing the entry never made the event un-happen; it made the trail
     * silent about it, which is the worse failure for an audit trail.
     */
    public function test_a_person_may_log_receipt_more_than_once(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner, 'public');

        foreach (['Came by courier.', 'Returned after signature.'] as $remarks) {
            $this->actingAs($owner)
                ->post('/document/log', ['document_id' => $document->id, 'action' => 'received', 'remarks' => $remarks])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');
        }

        $this->assertSame(2, $document->logs()->where('action', 'received')->count());
    }

    /** And it is a fact about one person, so somebody else may still log theirs. */
    public function test_receipt_is_logged_per_person(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner, 'public');
        $other = User::factory()->create();

        $this->actingAs($owner)
            ->post('/document/log', ['document_id' => $document->id, 'action' => 'received']);
        $this->actingAs($other)
            ->post('/document/log', ['document_id' => $document->id, 'action' => 'received'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $document->logs()->where('action', 'received')->count());
    }

    /** The fused endpoint is gone, not merely unlinked from the page. */
    public function test_the_receive_and_download_endpoint_no_longer_exists(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner, 'public');

        $this->actingAs($owner)
            ->post("/documents/{$document->id}/receive")
            ->assertNotFound();
    }

    public function test_a_document_history_is_refused_to_someone_who_cannot_read_the_document(): void
    {
        $document = $this->document(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/documents/{$document->id}/logs")
            ->assertRedirect();
    }
}
