<?php

namespace Tests\Feature\Acceptance;

use App\Enums\FileAbility;
use App\Models\Document;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Files\GuestIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance items 3, 4 and 5 — the three levels, each asked the same four
 * questions.
 *
 * One scene, four audiences: the uploader, a tagged reader, a signed-in reader
 * who was not tagged, and somebody with no account at all. Every level is put
 * to all four, so what separates the levels is a difference in this file rather
 * than a sentence in a document.
 *
 * **Two of the three items as stated do not match what this system does, and
 * the mismatch is deliberate on the system's side.** They are recorded here
 * rather than quietly reinterpreted:
 *
 * - Item 3 asks that a *public* document open for somebody with no account.
 *   It does — through a **share link**, which is the door built for that
 *   audience. What does not open for them is the tracking URL, because a
 *   tracking code is printed on the folder and is a short per-type daily
 *   series: anyone who photographed a desk could otherwise read every public
 *   document in the institute. Public means "any member of staff", and a
 *   visitor is given a link, which carries an ability, an expiry, a view cap
 *   and a revocation. See CLAUDE.md, "A tracking code is not a credential".
 *
 * - Item 4 asks that a *private* document open for "anyone who has a user
 *   account" **and** for the tagged people. Those are two different levels in
 *   this system: the first is what `public` means, and `private` is the
 *   uploader plus the tagged list — identical to `confidential` for reading,
 *   differing only in whether a link may be minted. Asserting item 4 as
 *   written would be asserting that private and public are the same level.
 *   Both readings are covered below, each against the level that implements
 *   it, so the distinction is visible rather than assumed.
 */
class DocumentVisibilityAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /**
     * One filed document at a given level, with one tagged reader.
     *
     * Filed through the upload form rather than the factory, so the scene is
     * the one the office actually produces — bytes on the private disk, a
     * `files` row, a trail, a label and (where the level allows it) a link.
     *
     * @return array{Document, User, User, User} document, uploader, tagged reader, untagged account
     */
    private function scene(string $level): array
    {
        $uploader = User::factory()->superadmin()->create();
        $tagged = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => ucfirst($level).' Case File',
            'document_type_id' => \App\Models\DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => $level,
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
            'recipients' => [$tagged->id],
        ])->assertSessionHasNoErrors();

        return [Document::query()->latest('id')->firstOrFail(), $uploader, $tagged, $stranger];
    }

    /**
     * The document's live link on a stated term — created first if the
     * document has none.
     *
     * Every linkable level is minted a link with the document, because the
     * registrar prints it on the label; what differs is the **term** it
     * starts on. A document restricted to a named list starts on
     * `restricted`, which is an address rather than a key, so the automatic
     * link behind a printed label gives nothing away. A confidential document
     * refuses to have one at all, which is what `null` reports back to the
     * tests that assert it.
     *
     * An item has one live link, so where there already is one this edits its
     * terms rather than minting a second, which the endpoint refuses. The
     * term is passed in rather than fixed, because the whole of item 4 is now
     * the difference between two of them.
     */
    private function link(Document $document, User $uploader, string $ability = 'download'): ?ShareLink
    {
        $existing = $document->shareLinks()->whereNull('revoked_at')->first();

        if ($existing === null) {
            $this->actingAs($uploader)
                ->post("/documents/{$document->id}/share-links", ['ability' => $ability]);

            $this->signOut();

            $existing = $document->shareLinks()->whereNull('revoked_at')->first();
        }

        if ($existing === null) {
            return null;
        }

        $this->actingAs($uploader)
            ->patch("/documents/{$document->id}/share-links/{$existing->id}", ['ability' => $ability])
            ->assertSessionHasNoErrors();

        $this->signOut();

        return $existing->fresh();
    }

    /**
     * Put the test client back to being nobody.
     *
     * `actingAs()` sets the user for every later request in the test, so a
     * scene built by an uploader leaves the client signed in as them — and an
     * assertion about a visitor with no account would silently be an assertion
     * about that uploader. This is the difference between items 3 to 5 passing
     * and meaning something.
     */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** What a person with no account gets from the tracking URL. */
    private function visitorReadingTheCode(Document $document)
    {
        $this->signOut();

        return $this->get("/documents/view/{$document->code}");
    }

    // --------------------------------------------------------- item 3: public

    /**
     * Item 3: a public document, shared by link, opens for somebody with no
     * DMS account — page and bytes both.
     */
    public function test_a_public_document_shared_by_link_opens_for_a_visitor_with_no_account(): void
    {
        [$document, $uploader] = $this->scene('public');
        $link = $this->link($document, $uploader);

        $this->assertNotNull($link, 'A public document must be linkable.');

        // The landing page asks who the visitor is before it records anything
        // or spends a view. Declining is an answer.
        $props = $this->get("/s/{$link->token}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['needsIdentity'] ?? false, 'A visitor must be asked who they are first.');

        $identify = $this->post("/s/{$link->token}/identify", ['name' => null]);
        $this->withCookies([
            GuestIdentity::COOKIE => $identify->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);

        $this->get("/s/{$link->token}")->assertOk();
        // And the bytes, not only the page.
        $this->get("/s/{$link->token}/file")->assertOk();
    }

    /**
     * Item 3, the other half: public means "any member of staff", so every
     * signed-in account opens it from the tracking code — and a visitor with no
     * account does not.
     *
     * The second assertion is the one worth keeping: the tracking code is
     * printed on the folder and is guessable by construction, so it is not the
     * credential. The link above is.
     */
    public function test_a_public_document_opens_for_any_account_and_not_for_a_bare_visitor(): void
    {
        [$document, , , $stranger] = $this->scene('public');

        $this->actingAs($stranger)->get("/documents/view/{$document->code}")->assertOk();
        $this->actingAs($stranger)->get("/documents/track?code={$document->code}")->assertOk();

        /*
         * A visitor with no account is forwarded to the public door rather
         * than handed a sign-in form — which is what the printed label needs,
         * since the courier holding the folder is exactly the person with no
         * account. The code still is not the credential: it buys what
         * `/track` has always given it and no more, which for a public
         * document is a look and never a copy.
         */
        $this->visitorReadingTheCode($document)
            ->assertRedirect('/track?code='.$document->code);
    }

    // -------------------------------------------------------- item 4: private

    /**
     * Item 4: a private document is the uploader and the tagged list, and a
     * signed-in account that was not tagged is refused.
     *
     * This is where item 4 as written and the system part company: "visible to
     * anyone who has a user account" is `public`, asserted above. A level that
     * admitted every account *and* a tagged list would have no second clause to
     * do.
     */
    public function test_a_private_document_is_the_uploader_and_the_tagged_list_only(): void
    {
        [$document, $uploader, $tagged, $stranger] = $this->scene('private');

        $this->actingAs($uploader)->get("/documents/view/{$document->code}")->assertOk();
        $this->actingAs($tagged)->get("/documents/view/{$document->code}")->assertOk();

        $this->actingAs($stranger)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code));

        // The trail is as restricted as the document it describes.
        $this->actingAs($tagged)->get("/documents/{$document->id}/logs")->assertOk();
        $this->actingAs($stranger)->get("/documents/{$document->id}/logs")
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * Item 4, the second door: a private document's link is **not** a key to
     * it on the term it is minted with.
     *
     * The token opens a page that says the document exists and what its level
     * is — the same answer a tracking code gets — and the bytes behind it
     * answer 404. A link to a private document is still a link: revocable,
     * counted and expiring, and it still takes a *tagged* reader straight to
     * the document. What it is not, by default, is a way for whoever holds
     * the URL to read something whose whole classification is a list of names.
     *
     * "By default" is the part that changed, and the test below this one is
     * the other half. The uploader may widen the link, and then it is a key —
     * because a document restricted to three named colleagues is the ordinary
     * case in which somebody outside the office has to be sent a copy. What
     * matters is that widening is a decision somebody made rather than what a
     * link does on its own: the printed label, and every link made in
     * passing, stays on this term.
     */
    public function test_a_private_documents_link_does_not_open_for_a_visitor_with_no_account(): void
    {
        [$document, $uploader, $tagged] = $this->scene('private');
        $link = $this->link($document, $uploader, 'restricted');

        $this->assertNotNull($link, 'A private document must still be linkable.');

        $this->assertSame(
            'restricted',
            $document->shareLinks()->whereNull('revoked_at')->sole()->ability->value,
            'A restricted document\'s link must be minted as an address, not a key.',
        );

        $identify = $this->post("/s/{$link->token}/identify", ['name' => 'Maria Santos']);
        $this->withCookies([
            GuestIdentity::COOKIE => $identify->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);

        // The page answers, and says only what the level is.
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.code', $document->code)
                ->where('result.open', false)
                ->where('result.title', null));

        // And the bytes do not, or the page would be decoration.
        $this->get("/s/{$link->token}/file")->assertNotFound();

        // Nothing was opened, so nothing is on the trail as having been.
        $this->assertSame(
            0,
            $document->logs()->whereNull('actor_id')->count(),
            'A refused link recorded a read.',
        );

        /*
         * The same token, for somebody the document is actually for: they
         * are sent to the document itself rather than to a card about it,
         * because the share page is a worse version of the page they are
         * entitled to — no trail, no comments, no version history.
         */
        $this->actingAs($tagged)->get("/s/{$link->token}")
            ->assertRedirect('/documents/view/'.$document->code);
        $this->actingAs($tagged)->get("/s/{$link->token}/file")->assertOk();
    }

    /**
     * Item 4, the other half: the uploader may widen that link, and then it
     * is a key.
     *
     * This is what the office asked for after living with the rule above. A
     * private document is the normal way an office files something, and
     * "send this one to the supplier" is the normal thing that then has to
     * happen; with no way to widen the link, the answer was to reclassify the
     * document as public — which opens it to the whole institute to let one
     * outsider read it, the opposite of what was wanted.
     *
     * So the reach is a term on the link rather than a consequence of the
     * level. The recipient list goes on governing every other way in: this
     * widens one URL, which can be revoked, re-addressed, capped and expired,
     * and which is counted every time it is opened.
     */
    public function test_a_private_documents_link_opens_for_a_visitor_once_the_uploader_widens_it(): void
    {
        [$document, $uploader] = $this->scene('private');
        $link = $this->link($document, $uploader, 'download');

        $this->assertNotNull($link, 'A private document must still be linkable.');
        $this->assertSame('download', $link->ability->value);

        $identify = $this->post("/s/{$link->token}/identify", ['name' => 'Maria Santos']);
        $this->withCookies([
            GuestIdentity::COOKIE => $identify->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);

        // The document itself, not a card stating its level.
        $this->get("/s/{$link->token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('share/show', false)
                ->where('item.code', $document->code)
                ->where('can.download', true));

        // And the bytes, because the term says download.
        $this->get("/s/{$link->token}/file")->assertOk();

        /*
         * Widening the link widens nothing else. The tracking code is the
         * other door and it is untouched: a visitor quoting the code still
         * gets the restricted answer, because what the uploader opened was
         * one URL and not the document's level.
         */
        $this->get('/track?code='.$document->code)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false)
                ->where('result.title', null));
    }

    // --------------------------------------------------- item 5: confidential

    /**
     * Item 5: confidential is the tagged list and nothing else.
     *
     * It reads exactly like private — the uploader and the tagged people — and
     * differs in the one thing item 5 is really about: there is no URL that
     * opens it for whoever is holding it.
     */
    public function test_a_confidential_document_is_readable_by_the_tagged_people_only(): void
    {
        [$document, $uploader, $tagged, $stranger] = $this->scene('confidential');

        $this->actingAs($uploader)->get("/documents/view/{$document->code}")->assertOk();
        $this->actingAs($tagged)->get("/documents/view/{$document->code}")->assertOk();

        $this->actingAs($stranger)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code));

        /*
         * And the visitor is forwarded to the same public door — which, for
         * a confidential document, states the level and nothing else.
         * Followed through here rather than asserted at the redirect, because
         * the thing worth protecting is not where they land but what is on
         * the page when they do.
         */
        $this->visitorReadingTheCode($document)
            ->assertRedirect('/track?code='.$document->code);

        $this->get('/track?code='.$document->code)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('track/index', false)
                ->where('result.open', false)
                ->where('result.title', null)
                ->where('result.uploader', null));
    }

    /**
     * Item 5: and no link, ever — not at upload, not afterwards, and not by
     * reclassifying a document that already had one.
     */
    public function test_a_confidential_document_has_no_link_and_cannot_be_given_one(): void
    {
        [$document, $uploader] = $this->scene('confidential');

        // The registrar mints a link with every document whose level allows
        // one, so that a printed label has something real to point at. A
        // confidential document gets none.
        $this->assertSame(0, $document->shareLinks()->count(), 'A confidential document was minted a link at upload.');

        $this->actingAs($uploader)
            ->post("/documents/{$document->id}/share-links", ['ability' => 'download'])
            ->assertSessionHas('error');

        $this->assertSame(0, $document->shareLinks()->count());
    }

    /**
     * Item 5, the case a check at creation time alone would miss: a document
     * that *was* private, had a link handed out, and has since been
     * reclassified.
     *
     * The link has to stop working at the moment of reclassification rather
     * than when somebody remembers to revoke it — which is why linkability is
     * re-asked on every redemption.
     */
    public function test_a_link_handed_out_earlier_stops_working_once_the_document_is_confidential(): void
    {
        /*
         * A **public** document, because the scene needs a link that really
         * does open for whoever holds it before the reclassification takes
         * it away. A private one no longer does — its link answers the
         * holder's own access, which is the test above — so starting there
         * would assert the reclassification had done something that was
         * already true.
         */
        [$document, $uploader] = $this->scene('public');
        $link = $this->link($document, $uploader);

        // Said who they are, and the link worked — page and bytes.
        $identify = $this->post("/s/{$link->token}/identify", ['name' => 'Maria Santos']);
        $this->withCookies([
            GuestIdentity::COOKIE => $identify->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);

        $this->get("/s/{$link->token}")->assertOk();
        $this->get("/s/{$link->token}/file")->assertOk();

        $document->update(['visibility' => 'confidential']);

        // The same holder, the same token, the same answer a stranger gets.
        $this->get("/s/{$link->token}")->assertNotFound();
        $this->get("/s/{$link->token}/file")->assertNotFound();
    }

    /**
     * All three levels, asked of the resolver directly — the one place access
     * is decided.
     *
     * Over HTTP above, because that is what the office does; here, because a
     * route that stopped calling the resolver would pass every test above by
     * accident. The table *is* the specification of items 3 to 5.
     */
    public function test_the_resolver_answers_the_same_table_the_screens_do(): void
    {
        $resolver = app(AccessResolver::class);

        foreach (['public', 'private', 'confidential'] as $level) {
            [$document, $uploader, $tagged, $stranger] = $this->scene($level);

            $this->assertTrue(
                $resolver->allows($uploader, $document, FileAbility::View),
                "The uploader must read their own {$level} document.",
            );
            $this->assertTrue(
                $resolver->allows($tagged, $document, FileAbility::View),
                "A tagged reader must read a {$level} document.",
            );
            $this->assertSame(
                $level === 'public',
                $resolver->allows($stranger, $document, FileAbility::View),
                "An untagged account must read a {$level} document only when it is public.",
            );
            /*
             * No user at all. A restricted document resolves to denied; a
             * public one resolves to *allowed*, and that is correct here — the
             * resolver answers "which documents may a reader open", and the
             * door a reader comes in through is the route's `auth`, which the
             * HTTP assertion above covers. Stating it this way round is the
             * point: if the public clause ever stopped admitting a null user,
             * the share-link path would break, and if `documents.view` ever
             * lost its `auth`, this table would still be right and the test
             * above would fail. Two different checks, deliberately.
             */
            $this->assertSame(
                $level === 'public',
                $resolver->allows(null, $document, FileAbility::View),
                "A {$level} document resolved wrongly for nobody at all.",
            );
            // And only the uploader may ever write, whatever the level.
            $this->assertFalse(
                $resolver->allows($tagged, $document, FileAbility::Manage),
                "A tagged reader of a {$level} document must not hold manage.",
            );
        }
    }
}
