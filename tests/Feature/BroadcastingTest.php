<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Events\ActivityTrailUpdated;
use App\Events\DocumentTrailUpdated;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * What goes out over a websocket, and what must not.
 */
class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Announced from the one place a trail row is built.
     *
     * Dispatching from `DocumentLog::record()` rather than from each caller is
     * what makes it impossible to write a row without the page hearing about
     * it — the same reason that factory exists at all.
     */
    public function test_recording_a_trail_row_announces_it(): void
    {
        Event::fake([DocumentTrailUpdated::class]);

        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->id]);

        $log = DocumentLog::record($document, DocumentAction::Reviewed, $user);

        Event::assertDispatched(
            DocumentTrailUpdated::class,
            fn (DocumentTrailUpdated $event): bool => $event->documentId === $document->id
                && $event->logId === $log->id,
        );
    }

    public function test_recording_an_activity_row_announces_it(): void
    {
        Event::fake([ActivityTrailUpdated::class]);

        $user = User::factory()->create();
        $row = ActivityLog::record($user, ActivityAction::Uploaded);

        Event::assertDispatched(
            ActivityTrailUpdated::class,
            fn (ActivityTrailUpdated $event): bool => $event->entryId === $row->id,
        );
    }

    /** A document's trail is announced only on that document's channel. */
    public function test_a_trail_event_names_only_its_own_document(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->id]);
        $log = DocumentLog::record($document, DocumentAction::Reviewed, $user);

        $channels = array_map(
            fn ($channel): string => (string) $channel,
            DocumentTrailUpdated::from($log)->broadcastOn(),
        );

        $this->assertSame(["private-documents.{$document->id}"], $channels);
    }

    /**
     * A signal, not the row.
     *
     * The payload is two values the client needs only to decide whether to
     * refetch. Anything more would be a second serializer beside the
     * controller's, which is how a field nobody meant to expose gets exposed —
     * and an audit row must never carry a live credential.
     */
    public function test_the_payload_carries_no_record_and_no_credential(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $user->id]);
        $log = DocumentLog::record($document, DocumentAction::Reviewed, $user, 'a private remark', '10.0.0.9');

        $payload = DocumentTrailUpdated::from($log)->broadcastWith();

        $this->assertSame(['id', 'occurred_at'], array_keys($payload));

        $flat = json_encode($payload);
        foreach (['token', 'signature', 'ip_address', '10.0.0.9', 'a private remark', 'actor_name'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $flat);
        }
    }

    /**
     * Broadcast now, not queued.
     *
     * With `ShouldBroadcast` and a database queue, nothing would reach a
     * browser until a worker ran — which on an installation without a
     * supervised `queue:work` is never. These payloads are two integers and
     * the write is already inside a request.
     */
    public function test_trail_events_broadcast_immediately(): void
    {
        $this->assertInstanceOf(ShouldBroadcastNow::class, new DocumentTrailUpdated(1, 1, 'now'));
        $this->assertInstanceOf(ShouldBroadcastNow::class, new ActivityTrailUpdated(1, 'now'));
    }

    /** Notifications reach the bell on the recipient's own channel. */
    public function test_a_user_is_notified_on_their_own_channel(): void
    {
        $user = User::factory()->create();

        $this->assertSame("users.{$user->id}", $user->receivesBroadcastNotificationsOn());
    }
}
