<?php

namespace Tests\Feature\Acceptance;

use App\Enums\DocumentAction;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Files\GuestIdentity;
use App\Services\Files\SharingService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance item 6 — access taken away, and the two refusals kept apart.
 *
 * The item's claim is that "you are not authorized" and "you were removed as a
 * viewer" are two different answers, and it is right: the first describes a
 * document somebody has no part in, and invites them to ask for access; the
 * second is the answer to a question that has already been decided about them.
 * Telling a removed reader the first sends them to ask the wrong person why a
 * link that worked yesterday has stopped.
 *
 * Two halves had to be built for this to be testable at all, and both are under
 * test here:
 *
 * - **a way to remove a viewer.** There was none. Nothing in the application
 *   detached a `document_recipients` row, so a document tagged with the wrong
 *   person stayed tagged with them.
 * - **a record that it happened.** The row is stamped rather than deleted, so
 *   the refusal can tell the two cases apart and the trail can say a withdrawal
 *   took place. A deleted row makes a removed viewer indistinguishable from
 *   somebody who was never there — which is exactly the collapse this item is
 *   about.
 */
class RemovedViewerAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /**
     * A private document with one tagged reader, filed through the form.
     *
     * @return array{Document, User, User} document, uploader, tagged reader
     */
    private function scene(string $level = 'private'): array
    {
        $uploader = User::factory()->superadmin()->create();
        $tagged = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => $level,
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
            'recipients' => [$tagged->id],
        ])->assertSessionHasNoErrors();

        return [Document::query()->latest('id')->firstOrFail(), $uploader, $tagged];
    }

    private function remove(User $actor, Document $document, User $viewer)
    {
        return $this->actingAs($actor)->delete("/documents/{$document->id}/recipients/{$viewer->id}");
    }

    // ------------------------------------------------- the register's badge

    /**
     * The All tab says which rows are *yours to read*, and stops saying it
     * the moment the uploader takes you off.
     *
     * All is every document the office has logged, so a row somebody is
     * actually party to is otherwise indistinguishable from one their role
     * merely lets them see. The badge is `shared_with_me`, an exists
     * subquery over `Document::recipients()` — the live list, which carries
     * `wherePivotNull('revoked_at')`.
     *
     * Asking that relation rather than the table is the whole point of the
     * test: a removed viewer who still reads "Shared with you" on the
     * register is being told something the resolver has already stopped
     * agreeing with, which is how a withdrawal becomes a thing people argue
     * about rather than a thing the screens agree on.
     */
    public function test_the_register_badges_a_shared_row_until_the_viewer_is_removed(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        // The All tab is its own grant, so the reader needs it to be on the
        // screen this badge appears on at all.
        $tagged->syncRoles([Roles::SUPERADMIN]);

        $row = fn () => collect(
            $this->actingAs($tagged)->get('/documents?tab=all')
                ->assertOk()
                ->viewData('page')['props']['documents']['data'],
        )->firstWhere('id', $document->id);

        $this->assertTrue((bool) $row()['shared_with_me'], 'A tagged reader was not told the row was shared with them.');

        $this->remove($uploader, $document, $tagged);

        $this->assertFalse((bool) $row()['shared_with_me'], 'A removed viewer is still being told the document is shared with them.');
    }

    /** And the uploader's own row is not badged as shared with them. */
    public function test_the_register_does_not_badge_the_uploaders_own_row(): void
    {
        [$document, $uploader] = $this->scene();

        $row = collect(
            $this->actingAs($uploader)->get('/documents?tab=all')
                ->assertOk()
                ->viewData('page')['props']['documents']['data'],
        )->firstWhere('id', $document->id);

        $this->assertFalse((bool) $row['shared_with_me']);
    }

    // ------------------------------------------------- removing a viewer

    /**
     * The tagged reader could open it; after the removal they cannot.
     */
    public function test_a_removed_viewer_loses_the_document_and_its_history(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->actingAs($tagged)->get("/documents/view/{$document->code}")->assertOk();

        $this->remove($uploader, $document, $tagged)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($tagged)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                // The removed viewer is told they were removed, which is the
                // whole point of the pair of messages — now carried as a prop
                // rather than as a flash on another page.
                ->where('message', fn ($message) => str_contains($message, 'removed as a viewer')));

        // The trail is as restricted as the document, so it closes with it.
        $this->actingAs($tagged)->get("/documents/{$document->id}/logs")
            ->assertRedirect()
            ->assertSessionHas('error');

        // And they cannot reach the bytes by the file route either — the one
        // rule decides both, so there is no looser second door. It refuses
        // with the same explanation rather than a bare 404: the person is
        // looking at a page and deserves to be told which refusal this is.
        $refusal = $this->actingAs($tagged)
            ->get("/documents/{$document->id}/file/download")
            ->assertRedirect()
            ->assertSessionHas('error')
            ->getSession()->get('error');

        $this->assertStringContainsString('removed', strtolower($refusal));
        $this->assertSame(0, $document->downloads()->count(), 'A refused read recorded a download.');
    }

    /**
     * The point of the item: the two refusals do not read the same.
     *
     * Asserted as a *difference* rather than against two literal sentences, so
     * the wording stays the office's to change and the distinction stays the
     * test's to keep.
     */
    public function test_a_removed_viewer_is_told_they_were_removed_and_a_stranger_is_not(): void
    {
        [$document, $uploader, $tagged] = $this->scene();
        $stranger = User::factory()->create();

        $this->remove($uploader, $document, $tagged);

        /*
         * Read off the page rather than out of the session: the refusal is a
         * prop on `documents/restricted` now rather than a flash on the
         * dashboard. The distinction under test is unchanged — which of the
         * two sentences each reader is given.
         */
        $message = function (User $reader) use ($document): string {
            $captured = '';

            $this->actingAs($reader)
                ->get("/documents/view/{$document->code}")
                ->assertOk()
                ->assertInertia(function ($page) use (&$captured) {
                    $captured = $page->toArray()['props']['message'];
                });

            return $captured;
        };

        $removed = $message($tagged);
        $never = $message($stranger);

        $this->assertNotSame($never, $removed, 'A removed viewer gets the same refusal as a stranger.');
        $this->assertStringContainsString('removed', strtolower($removed));
        $this->assertStringNotContainsString('removed', strtolower($never));
    }

    /** The same distinction on the trail page, which is the same refusal. */
    public function test_the_history_refusal_also_says_which_refusal_it_is(): void
    {
        [$document, $uploader, $tagged] = $this->scene();
        $stranger = User::factory()->create();

        $this->remove($uploader, $document, $tagged);

        $removed = $this->actingAs($tagged)->get("/documents/{$document->id}/logs")
            ->getSession()->get('error');
        $never = $this->actingAs($stranger)->get("/documents/{$document->id}/logs")
            ->getSession()->get('error');

        $this->assertStringContainsString('removed', strtolower($removed));
        $this->assertNotSame($never, $removed);
    }

    /**
     * The removal is a row, not an erasure.
     *
     * This is what makes the sentence above possible: a deleted row would leave
     * the application with nothing to tell a removed viewer apart from a
     * stranger, and nothing to tell the uploader they removed the right person.
     */
    public function test_the_removal_is_recorded_rather_than_erasing_the_row(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->remove($uploader, $document, $tagged);

        $this->assertDatabaseHas('document_recipients', [
            'document_id' => $document->id,
            'user_id' => $tagged->id,
            'revoked_by' => $uploader->id,
        ]);

        // The live list is empty; the record is not.
        $this->assertSame(0, $document->recipients()->count());
        $this->assertSame(1, $document->allRecipients()->count());
        $this->assertSame([$tagged->id], $document->removedViewers()->pluck('users.id')->all());
        $this->assertTrue($document->wasRemovedAsViewer($tagged));

        // On the document's own trail: a history that records every grant and
        // no withdrawal overstates who can read the document.
        $row = $document->logs()->where('action', DocumentAction::ViewerRemoved)->sole();

        $this->assertSame($uploader->id, $row->actor_id);
        // The name is written into the remarks, so the entry still reads
        // correctly once that account is gone.
        $this->assertStringContainsString($tagged->name, $row->remarks);
        $this->assertNotContains(DocumentAction::ViewerRemoved, DocumentAction::postable());
    }

    /**
     * And the "Shared with me" tab stops listing it, because that tab is "what
     * can I open" rather than "what was I ever sent".
     */
    public function test_a_removed_viewer_no_longer_lists_the_document_as_shared_with_them(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->actingAs($tagged)->get('/documents?tab=shared')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.shared', 1));

        $this->remove($uploader, $document, $tagged);

        $this->actingAs($tagged)->get('/documents?tab=shared')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.shared', 0));

        $this->assertSame(0, $tagged->fresh()->receivedDocuments()->count());
    }

    // --------------------------------------------------- who may remove

    public function test_only_the_uploader_can_remove_a_viewer(): void
    {
        [$document, , $tagged] = $this->scene();
        $meddler = User::factory()->superadmin()->create();

        $this->remove($meddler, $document, $tagged)->assertRedirect('/dashboard');

        $this->assertSame(1, $document->recipients()->count(), 'Somebody who is not the uploader removed a viewer.');
        $this->assertTrue(app(\App\Services\Access\AccessResolver::class)
            ->allows($tagged, $document->fresh(), \App\Enums\FileAbility::View));
    }

    /** A tagged reader cannot remove the other readers. */
    public function test_a_tagged_reader_cannot_remove_anybody(): void
    {
        [$document, $uploader, $tagged] = $this->scene();
        $other = User::factory()->create();
        app(\App\Services\Documents\DocumentRegistrar::class)->tag($document, collect([$other]));

        $this->remove($tagged, $document, $other)->assertRedirect('/dashboard');

        $this->assertSame(2, $document->recipients()->count());
        $this->assertNotNull($uploader);
    }

    /**
     * Removing somebody who is not a viewer answers rather than throwing, and
     * writes no second trail row — a removal that happened once must not read
     * as having happened twice.
     */
    public function test_removing_the_same_person_twice_changes_nothing_further(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->remove($uploader, $document, $tagged);
        $first = $document->allRecipients()->whereKey($tagged->id)->first()->pivot->revoked_at;

        $this->remove($uploader, $document, $tagged)->assertSessionHas('error');

        $this->assertSame(1, $document->logs()->where('action', DocumentAction::ViewerRemoved)->count());
        $this->assertEquals(
            $first,
            $document->allRecipients()->whereKey($tagged->id)->first()->pivot->revoked_at,
            'A second removal rewrote when the first one happened.',
        );
    }

    // ------------------------------------------- the other half of item 6

    /**
     * Item 6's other case: "if sharing was disabled".
     *
     * A revoked link answers 404 — the same answer an unknown token gets, and
     * deliberately so. The visitor holding it has no account and no identity
     * this system ever verified, so there is nobody for a "you were removed"
     * message to be about, and distinguishing "revoked" from "never existed"
     * would confirm that a token was once real.
     */
    public function test_a_revoked_link_closes_for_its_holder_without_saying_why(): void
    {
        [$document, $uploader] = $this->scene('public');
        $link = $document->shareLinks()->whereNull('revoked_at')->firstOrFail();

        /*
         * The scene was built by the uploader, and `actingAs()` sets the user
         * for every later request in the test — so without this the "holder"
         * below is the uploader, who is now taken to the document itself
         * rather than to the link's landing page.
         */
        $this->app['auth']->forgetGuards();

        $identify = $this->post("/s/{$link->token}/identify", ['name' => 'Maria Santos']);
        $this->withCookies([
            GuestIdentity::COOKIE => $identify->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);
        $this->get("/s/{$link->token}")->assertOk();

        app(SharingService::class)->revokeLink($link->fresh());

        $this->get("/s/{$link->token}")->assertNotFound();
        $this->get('/s/'.str_repeat('z', 64))->assertNotFound();

        // The document itself is untouched by the revocation: a public document
        // is still readable by every account, through the door accounts use.
        $this->actingAs($uploader)->get("/documents/view/{$document->code}")->assertOk();
    }

    /**
     * Re-tagging somebody who was removed lets them back in, and keeps the
     * record that they had been removed in between.
     *
     * The row is updated rather than inserted — the unique pair would refuse a
     * second — which is why tagging goes through `allRecipients()`.
     */
    public function test_tagging_a_removed_viewer_again_restores_them(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->remove($uploader, $document, $tagged);
        app(\App\Services\Documents\DocumentRegistrar::class)->tag($document, collect([$tagged]));

        $this->assertSame(1, $document->recipients()->count());
        $this->assertSame(1, $document->allRecipients()->count(), 'Re-tagging wrote a second row.');
        $this->assertFalse($document->wasRemovedAsViewer($tagged));

        $this->actingAs($tagged)->get("/documents/view/{$document->code}")->assertOk();

        // The withdrawal stays on the trail. An audit row is a fact about then.
        $this->assertSame(1, $document->logs()->where('action', DocumentAction::ViewerRemoved)->count());
    }

    /**
     * A removed viewer may still ask for access, and is still offered the code
     * to ask with.
     *
     * Being removed is a decision, not a ban: asking is the designed way back
     * in, and the offer carries only the tracking code — never the title, which
     * would disclose the subject of a document to somebody just refused it.
     */
    public function test_a_removed_viewer_is_still_offered_the_ask(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->remove($uploader, $document, $tagged);

        $this->actingAs($tagged)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code)
                ->where('canRequest', true)
                // Never the title: it would disclose the subject of a
                // document to somebody who has just been refused it.
                ->missing('title'));
    }
}
