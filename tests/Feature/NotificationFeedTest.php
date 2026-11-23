<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
use App\Http\Controllers\NotificationController;
use App\Models\AccessRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Notifications\DocumentAccessRequested;
use App\Notifications\PrivateDocumentShared;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the header bell is handed.
 *
 * A notification is history — it says what happened and when — so the row
 * stores a *slug* and the wording, the symbol and the colour are read off
 * `NotificationKind` when the feed is built. That split is what these tests
 * hold: an icon corrected next year must not mean rewriting rows already
 * written, and a row written before the slug existed must still render.
 */
class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function sharedDocument(): array
    {
        $uploader = User::factory()->superadmin()->create();
        $recipient = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'private',
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
            'recipients' => [$recipient->id],
        ])->assertSessionHasNoErrors();

        return [Document::query()->latest('id')->firstOrFail(), $uploader, $recipient];
    }

    public function test_a_shared_notification_carries_its_kind_and_the_senders_face(): void
    {
        [, $uploader, $recipient] = $this->sharedDocument();

        $item = NotificationController::feedFor($recipient->fresh())['items'][0];

        $this->assertSame(NotificationKind::Shared->value, $item['kind']);
        $this->assertSame(NotificationKind::Shared->label(), $item['kind_label']);
        $this->assertSame(NotificationKind::Shared->icon(), $item['icon']);
        $this->assertSame(NotificationKind::Shared->tone(), $item['tone']);
        $this->assertSame($uploader->name, $item['actor']['name']);
        $this->assertArrayHasKey('avatar_seed', $item['actor']);
    }

    /**
     * The face is a **snapshot**, like every actor on an audit row: a sender
     * who later changes their photo has not changed what the notification
     * recorded, and one whose account is gone still has a face to render.
     */
    public function test_the_senders_face_does_not_change_when_they_edit_their_profile(): void
    {
        [, $uploader, $recipient] = $this->sharedDocument();

        $before = NotificationController::feedFor($recipient->fresh())['items'][0]['actor'];

        $uploader->forceFill(['profile_image' => 'avatars/new.png', 'avatar_seed' => 'changed'])->save();

        $this->assertSame($before, NotificationController::feedFor($recipient->fresh())['items'][0]['actor']);
    }

    /** Asking to open a document is its own kind, in its own colour. */
    public function test_an_access_request_notification_is_its_own_kind(): void
    {
        [$document, $uploader, $recipient] = $this->sharedDocument();
        $stranger = User::factory()->create();

        $uploader->notify(new DocumentAccessRequested(
            AccessRequest::open($document, $stranger, 'Please.'),
        ));

        $item = NotificationController::feedFor($uploader->fresh())['items'][0];

        $this->assertSame(NotificationKind::AccessRequested->value, $item['kind']);
        $this->assertSame($stranger->name, $item['actor']['name']);
        $this->assertNotSame(
            NotificationKind::Shared->tone(),
            $item['tone'],
            'a request waiting on you must not read as a document arriving for you',
        );
    }

    /**
     * A row written before any of this existed still renders.
     *
     * It keeps whatever icon it stored, falls back to a bell, and shows no
     * face — which is honest about knowing nothing, rather than deriving
     * initials from the sentence.
     */
    public function test_a_notification_written_before_the_kind_existed_still_renders(): void
    {
        $user = User::factory()->create();

        $user->notify(new PrivateDocumentShared(
            Document::factory()->create(['user_id' => $user->id]),
            $user,
        ));

        $row = $user->notifications()->sole();
        $row->forceFill(['data' => ['message' => 'Something happened.', 'url' => '/dashboard']])->save();

        $item = NotificationController::feedFor($user->fresh())['items'][0];

        $this->assertNull($item['kind']);
        $this->assertNull($item['kind_label']);
        $this->assertSame('fa-solid fa-bell', $item['icon']);
        $this->assertNull($item['actor']);
        $this->assertSame('Something happened.', $item['message']);
    }

    /**
     * An older row still shows whose it is.
     *
     * `sender_name` has always been stored by `PrivateDocumentShared`, so a
     * notification written before the face was snapshotted still *knows* the
     * name — it only lacks the photo and the seed, and `avatar.js` derives a
     * stable face from a name alone. The kind is read off the payload's
     * structure, never off its sentence.
     */
    public function test_an_older_shared_row_still_resolves_its_sender_and_kind(): void
    {
        [, $uploader, $recipient] = $this->sharedDocument();

        $row = $recipient->notifications()->sole();
        $data = $row->data;
        unset($data['kind'], $data['actor_name'], $data['actor_profile_image'], $data['actor_avatar_seed']);
        $row->forceFill(['data' => $data])->save();

        $item = NotificationController::feedFor($recipient->fresh())['items'][0];

        $this->assertSame(NotificationKind::Shared->value, $item['kind']);
        $this->assertSame($uploader->name, $item['actor']['name']);
        $this->assertSame($uploader->name, $item['actor_name']);
        $this->assertNull($item['actor']['profile_image'], 'an older row recorded no photo, and must not borrow the live one');
    }

    /**
     * A request from before the snapshot resolves its *kind* from the icon it
     * stored, and has no face — it never recorded a name anywhere, and one
     * read out of the sentence would be an invention. The sentence itself
     * still names the person.
     */
    public function test_an_older_access_request_row_resolves_its_kind_but_has_no_face(): void
    {
        [$document, $uploader] = $this->sharedDocument();
        $stranger = User::factory()->create();

        $uploader->notify(new DocumentAccessRequested(
            AccessRequest::open($document, $stranger, null),
        ));

        $row = $uploader->notifications()->latest()->first();
        $data = $row->data;
        unset($data['kind'], $data['actor_name']);
        $row->forceFill(['data' => $data])->save();

        $item = NotificationController::feedFor($uploader->fresh())['items'][0];

        $this->assertSame(NotificationKind::AccessRequested->value, $item['kind']);
        $this->assertNull($item['actor']);
        $this->assertStringContainsString($stranger->name, $item['message']);
    }

    /**
     * Every notification goes somewhere it can be acted on.
     *
     * Clicking a row redirects to the `url` in its payload, and two of the
     * three kinds were pointing at pages that say nothing about them. A
     * request to be let in landed on the plain document register — the
     * answer lives behind a header icon, and the reader had to work that out
     * — and a refusal landed on the dashboard, which names neither the
     * document nor the note from whoever refused it.
     *
     * Both now carry `?requests=`, which `AccessRequestsMenu` reads to open
     * the panel on the right tab. The parameter was already on the first
     * one and read by nothing, so every notification already sent starts
     * working rather than only the ones written from now on.
     */
    public function test_each_notification_points_at_something_actionable(): void
    {
        [$document, $uploader] = $this->sharedDocument();
        $asker = User::factory()->create();

        // Shared with you: the document itself.
        $asker->notify(new PrivateDocumentShared($document, $uploader));
        $this->assertStringContainsString(
            "/documents/view/{$document->code}",
            $asker->notifications()->where('data->kind', NotificationKind::Shared->value)->sole()->data['url'],
        );

        // Somebody is asking: the panel that can answer, on Incoming.
        $request = AccessRequest::open($document, $asker, null);
        $uploader->notify(new DocumentAccessRequested($request));
        $this->assertStringContainsString(
            'requests=incoming',
            $uploader->notifications()->where('data->kind', NotificationKind::AccessRequested->value)->sole()->data['url'],
        );

        // Declined: their own list, where the answer and its note are.
        $request->update([
            'status' => \App\Enums\AccessRequestStatus::Denied,
            'decided_by' => $uploader->id,
            'decided_by_name' => $uploader->name,
            'decided_at' => now(),
        ]);
        $asker->notify(new \App\Notifications\DocumentAccessDecided($request->fresh()));
        $this->assertStringContainsString(
            'requests=outgoing',
            $asker->notifications()->where('data->kind', NotificationKind::AccessDeclined->value)->sole()->data['url'],
        );

        // Granted: the document, because now they can open it.
        $request->update(['status' => \App\Enums\AccessRequestStatus::Granted]);
        $asker->notify(new \App\Notifications\DocumentAccessDecided($request->fresh()));
        $this->assertStringContainsString(
            "/documents/view/{$document->code}",
            $asker->notifications()->where('data->kind', NotificationKind::AccessGranted->value)->sole()->data['url'],
        );
    }

    /**
     * And clicking one marks it read and goes there, in one request.
     */
    public function test_opening_a_notification_marks_it_read_and_redirects(): void
    {
        [$document, $uploader] = $this->sharedDocument();
        $asker = User::factory()->create();

        $uploader->notify(new DocumentAccessRequested(AccessRequest::open($document, $asker, null)));
        $row = $uploader->notifications()->latest()->first();

        $this->assertNull($row->read_at);

        $this->actingAs($uploader)
            ->get("/notifications/{$row->id}/read")
            ->assertRedirect($row->data['url']);

        $this->assertNotNull($row->fresh()->read_at);
    }

    /**
     * A row already in somebody's bell is upgraded on the way out.
     *
     * Every refusal sent before this pointed at the dashboard, which names
     * neither the document nor the note from whoever refused it. The kind is
     * stored on the row, so the destination can be derived from it at read
     * time — the same move `legacyKind()` makes for the icon, and for the
     * same reason: a fix that only applies to rows written from now on
     * leaves a bell full of links that go nowhere.
     */
    public function test_an_older_row_pointing_at_the_dashboard_is_sent_somewhere_useful(): void
    {
        [$document, $uploader] = $this->sharedDocument();
        $asker = User::factory()->create();

        $request = AccessRequest::open($document, $asker, null);
        $request->update([
            'status' => \App\Enums\AccessRequestStatus::Denied,
            'decided_by' => $uploader->id,
            'decided_by_name' => $uploader->name,
            'decided_at' => now(),
        ]);
        $asker->notify(new \App\Notifications\DocumentAccessDecided($request->fresh()));

        // Wound back to what such a row used to carry.
        $row = $asker->notifications()->sole();
        $row->forceFill(['data' => [...$row->data, 'url' => route('dashboard')]])->save();

        $this->actingAs($asker)
            ->get("/notifications/{$row->id}/read")
            ->assertRedirect(route('documents.index').'?requests=outgoing');
    }

    /**
     * And a row nothing can place keeps the dashboard.
     *
     * A wrong destination is worse than a neutral one, so a kind that
     * matches nothing is not guessed at.
     */
    public function test_a_row_with_no_recognisable_kind_keeps_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->notify(new PrivateDocumentShared(...$this->sharedDocument()));

        $row = $user->notifications()->sole();
        $row->forceFill(['data' => ['message' => 'Something happened.']])->save();

        $this->actingAs($user)
            ->get("/notifications/{$row->id}/read")
            ->assertRedirect(route('dashboard'));
    }
}
