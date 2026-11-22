<?php

namespace App\Events;

use App\Models\DocumentLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something was written to a document's trail.
 *
 * **A signal, not the row**, and the three reasons are worth keeping:
 *
 * 1. The trail is **paginated**. Splicing a pushed row into page two produces
 *    a list whose totals disagree with its contents — the same class of bug
 *    `FileListingTest` exists to catch on the listing side. A signal lets the
 *    client reload through the controller, which re-paginates correctly.
 * 2. A refetch is **re-authorized and re-serialized by the one code path that
 *    already gets it right**. A broadcast payload would be a second
 *    serializer, and a second serializer is how a field nobody meant to expose
 *    gets exposed.
 * 3. **No audit row ever carries a live credential.** A signal cannot violate
 *    that; a payload has to be re-checked every time it changes.
 *
 * `ShouldBroadcastNow`, not `ShouldBroadcast`: the payload is two integers and
 * the write is already inside a request. Queued, nothing would reach a browser
 * until a worker ran, which on an installation without a supervised
 * `queue:work` is never.
 */
class DocumentTrailUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $logId,
        public readonly string $occurredAt,
    ) {}

    public static function from(DocumentLog $log): self
    {
        return new self(
            $log->document_id,
            $log->id,
            (string) ($log->occurred_at ?? $log->created_at),
        );
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("documents.{$this->documentId}")];
    }

    public function broadcastAs(): string
    {
        return 'trail.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->logId, 'occurred_at' => $this->occurredAt];
    }
}
