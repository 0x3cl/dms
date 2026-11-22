<?php

namespace Tests\Feature;

use App\Enums\AccessRequestStatus;
use App\Models\AccessRequest;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAccessDecided;
use App\Notifications\DocumentAccessRequested;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Asking to be let into a document.
 *
 * The thing these tests are really guarding is that **granting adds a
 * recipient row and nothing else**. `AccessResolver::forDocument()` already
 * reads that table, so this feature introduces no new way into a document —
 * which is why the resolver is untouched and `DocumentAuthorizationTest` runs
 * unmodified beside this file.
 */
class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::STAFF);

        return $user;
    }

    private function document(User $owner, string $visibility = 'private'): Document
    {
        return Document::factory()->create([
            'user_id' => $owner->id,
            'uploader_name' => $owner->name,
            'visibility' => $visibility,
        ]);
    }

    private function ask(User $asker, Document $document, ?string $message = null)
    {
        return $this->actingAs($asker)->post('/access-requests', [
            'code' => $document->code,
            'message' => $message,
        ]);
    }

    // ------------------------------------------------- clearing one's own

    /**
     * Clearing is a stamp, and the record survives it.
     *
     * The `access_requests` row is the only place the asking and the answer
     * are written — nothing puts them on the document's travel history — so a
     * real delete would take the uploader's record of their own decision with
     * it. The asker gets their list tidied; the history stays.
     */
    public function test_clearing_an_answered_request_hides_it_without_deleting_it(): void
    {
        $owner = $this->staff();
        $asker = $this->staff();
        $document = $this->document($owner);

        $this->ask($asker, $document);

        $row = AccessRequest::query()->sole();
        $this->actingAs($owner)->patch("/access-requests/{$row->id}", ['decision' => 'granted']);

        $this->actingAs($asker)->delete("/access-requests/{$row->id}")->assertRedirect();

        // Still there, still answered, and stamped.
        $row->refresh();
        $this->assertNotNull($row->dismissed_at, 'Clearing must stamp rather than delete.');
        $this->assertSame(AccessRequestStatus::Granted, $row->status);
        $this->assertSame($owner->name, $row->decided_by_name);

        // Gone from the asker's list.
        $this->actingAs($asker)->getJson('/access-requests')
            ->assertOk()
            ->assertJsonCount(0, 'outgoing');

        /*
         * And still on the uploader's, because it is their record of a
         * decision they made. The asymmetry is the point of the stamp.
         */
        $this->actingAs($owner)->getJson('/access-requests')
            ->assertOk()
            ->assertJsonCount(1, 'granted');

        // The access itself is untouched by the tidying.
        $this->assertTrue($document->fresh()->recipients->contains($asker));
    }

    /** Clearing an open request withdraws it: nobody is asked to answer it. */
    public function test_clearing_an_open_request_takes_it_off_the_uploaders_queue(): void
    {
        $owner = $this->staff();
        $asker = $this->staff();
        $document = $this->document($owner);

        $this->ask($asker, $document);

        $row = AccessRequest::query()->sole();

        $this->actingAs($owner)->getJson('/access-requests')->assertJsonCount(1, 'incoming');

        $this->actingAs($asker)->delete("/access-requests/{$row->id}")->assertRedirect();

        $this->actingAs($owner)->getJson('/access-requests')->assertJsonCount(0, 'incoming');
        $this->assertNotNull($row->fresh()->dismissed_at);
        $this->assertSame(1, AccessRequest::query()->count(), 'The row must survive a withdrawal.');
    }

    /**
     * Somebody else's request is not theirs to clear, and the refusal is a
     * 404 so the endpoint cannot be used to discover that a request exists.
     */
    public function test_only_the_asker_can_clear_a_request(): void
    {
        $owner = $this->staff();
        $asker = $this->staff();
        $document = $this->document($owner);

        $this->ask($asker, $document);

        $row = AccessRequest::query()->sole();

        // Not the uploader, and not a superadmin either: deciding is their
        // half, and this is the asker tidying their own list.
        $this->actingAs($owner)->delete("/access-requests/{$row->id}")->assertNotFound();

        $stranger = User::factory()->superadmin()->create();
        $this->actingAs($stranger)->delete("/access-requests/{$row->id}")->assertNotFound();

        $this->assertNull($row->fresh()->dismissed_at);
    }

    // ------------------------------------------------------------ the ask

    public function test_a_refused_reader_is_offered_the_code_they_can_ask_for(): void
    {
        $document = $this->document($this->staff());

        $this->actingAs($this->staff())
            ->get("/documents/view/{$document->code}")
            ->assertOk()
            /*
             * The offer is on the page about the document now, not a flash
             * on the dashboard the reader was bounced to. `canRequest` is
             * what puts the button there, and the code it will ask with is
             * the page's own.
             */
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code)
                ->where('canRequest', true));
    }

    /** The code and nothing else — a title would disclose the subject. */
    public function test_the_offer_carries_only_the_code(): void
    {
        $document = $this->document($this->staff());

        $this->actingAs($this->staff())
            ->get("/documents/view/{$document->code}")
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false)
                ->where('code', $document->code)
                /*
                 * The page a refused reader sees carries the code, the level
                 * and why the door is shut — and nothing that says what the
                 * document *is*. Asserted as an absence, because that is the
                 * disclosure this refusal exists to prevent and the easiest
                 * thing to add back by accident.
                 */
                ->missing('title')
                ->missing('uploader')
                ->missing('type'));
    }

    public function test_a_request_records_the_asker_and_notifies_the_uploader(): void
    {
        Notification::fake();

        $owner = $this->staff();
        $asker = $this->staff();
        $document = $this->document($owner);

        $this->ask($asker, $document, 'I am drafting the reply.')
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = AccessRequest::sole();

        $this->assertSame(AccessRequestStatus::Pending, $row->status);
        $this->assertSame($asker->id, $row->requester_id);
        $this->assertSame($asker->name, $row->requester_name);
        $this->assertSame($owner->id, $row->owner_id);
        $this->assertSame($document->code, $row->document_code);
        $this->assertSame('I am drafting the reply.', $row->message);

        Notification::assertSentTo($owner, DocumentAccessRequested::class);
    }

    public function test_a_second_pending_request_is_refused(): void
    {
        $asker = $this->staff();
        $document = $this->document($this->staff());

        $this->ask($asker, $document);
        $this->ask($asker, $document)->assertSessionHas('error');

        $this->assertSame(1, AccessRequest::query()->count());
    }

    public function test_a_confidential_document_can_be_asked_for(): void
    {
        $asker = $this->staff();
        $document = $this->document($this->staff(), 'confidential');

        $this->ask($asker, $document)->assertSessionHas('success');
        $this->assertSame(1, AccessRequest::query()->count());
    }

    public function test_a_public_document_cannot_be_asked_for(): void
    {
        $this->ask($this->staff(), $this->document($this->staff(), 'public'))
            ->assertSessionHas('error');

        $this->assertSame(0, AccessRequest::query()->count());
    }

    public function test_the_uploader_cannot_ask_for_their_own_document(): void
    {
        $owner = $this->staff();

        $this->ask($owner, $this->document($owner))->assertSessionHas('error');
        $this->assertSame(0, AccessRequest::query()->count());
    }

    /**
     * An unknown code answers exactly as a forbidden one does.
     *
     * Otherwise this endpoint becomes a way to discover which tracking codes
     * exist, which is the one thing a public code must not leak.
     */
    public function test_an_unknown_code_is_refused_identically(): void
    {
        $asker = $this->staff();
        $real = $this->document($this->staff(), 'public');

        $unknown = $this->actingAs($asker)
            ->post('/access-requests', ['code' => 'MEMO-19000101-9999']);
        $forbidden = $this->ask($asker, $real);

        $this->assertSame(
            $unknown->getSession()->get('error'),
            $forbidden->getSession()->get('error'),
        );
    }

    // -------------------------------------------------------- the decision

    /**
     * Granting is a recipient row, and the resolver — unchanged — is what
     * then lets them in.
     */
    public function test_granting_lets_the_requester_open_the_document(): void
    {
        Notification::fake();

        $owner = $this->staff();
        $asker = $this->staff();
        $document = $this->document($owner);

        $this->ask($asker, $document);
        $row = AccessRequest::sole();

        $this->actingAs($owner)
            ->patch("/access-requests/{$row->id}", ['decision' => 'granted'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(AccessRequestStatus::Granted, $row->fresh()->status);
        $this->assertTrue($document->fresh()->recipients->contains($asker));

        // The point: it is the ordinary recipient rule that admits them.
        $this->actingAs($asker)->get("/documents/view/{$document->code}")->assertOk();

        Notification::assertSentTo($asker, DocumentAccessDecided::class);
    }

    public function test_declining_grants_nothing(): void
    {
        $owner = $this->staff();
        $asker = $this->staff();
        $document = $this->document($owner);

        $this->ask($asker, $document);
        $row = AccessRequest::sole();

        $this->actingAs($owner)->patch("/access-requests/{$row->id}", ['decision' => 'denied']);

        $this->assertSame(AccessRequestStatus::Denied, $row->fresh()->status);
        $this->assertFalse($document->fresh()->recipients->contains($asker));
        $this->actingAs($asker)->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false));
    }

    public function test_a_stranger_cannot_decide(): void
    {
        $asker = $this->staff();
        $document = $this->document($this->staff());

        $this->ask($asker, $document);
        $row = AccessRequest::sole();

        $this->actingAs($this->staff())
            ->patch("/access-requests/{$row->id}", ['decision' => 'granted'])
            ->assertSessionHas('error');

        $this->assertSame(AccessRequestStatus::Pending, $row->fresh()->status);
    }

    /** So a request does not stall when the uploader is on leave. */
    public function test_a_superadmin_can_decide(): void
    {
        $asker = $this->staff();
        $document = $this->document($this->staff());
        $admin = User::factory()->create();
        $admin->assignRole(Roles::SUPERADMIN);

        $this->ask($asker, $document);
        $row = AccessRequest::sole();

        $this->actingAs($admin)
            ->patch("/access-requests/{$row->id}", ['decision' => 'granted'])
            ->assertSessionHas('success');

        $this->assertSame(AccessRequestStatus::Granted, $row->fresh()->status);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $owner = $this->staff();
        $document = $this->document($owner);

        $this->ask($this->staff(), $document);
        $row = AccessRequest::sole();

        $this->actingAs($owner)->patch("/access-requests/{$row->id}", ['decision' => 'denied']);
        $this->actingAs($owner)
            ->patch("/access-requests/{$row->id}", ['decision' => 'granted'])
            ->assertSessionHas('error');

        $this->assertSame(AccessRequestStatus::Denied, $row->fresh()->status);
    }

    // ------------------------------------------------------------ the feed

    public function test_each_tab_returns_only_the_callers_rows(): void
    {
        $owner = $this->staff();
        $asker = $this->staff();

        $mine = $this->document($owner);
        $someone_elses = $this->document($this->staff());

        $this->ask($asker, $mine);
        $this->ask($owner, $someone_elses);

        $feed = $this->actingAs($owner)->getJson('/access-requests')->assertOk()->json();

        $this->assertCount(1, $feed['incoming']);
        $this->assertSame($mine->code, $feed['incoming'][0]['document_code']);
        $this->assertCount(0, $feed['granted']);
        $this->assertCount(1, $feed['outgoing']);
        $this->assertSame($someone_elses->code, $feed['outgoing'][0]['document_code']);
    }

    public function test_the_pending_count_is_shared_with_every_page(): void
    {
        $owner = $this->staff();
        $this->ask($this->staff(), $this->document($owner));

        $props = $this->actingAs($owner)->get('/dashboard')->viewData('page')['props'];

        $this->assertSame(1, $props['accessRequests']['pending']);
    }
}
