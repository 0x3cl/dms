<?php

namespace App\Events;

use App\Models\ActivityLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something happened to a file or folder.
 *
 * One channel for the whole report, because `/activity-trails` is itself one
 * report across every account's storage, behind `activity-trails.read`. A
 * channel per subject would be thousands of subscriptions for a page that
 * shows all of them.
 *
 * A signal, for the same three reasons as `DocumentTrailUpdated` — and one
 * more that is sharper here: what a given viewer may see depends on their
 * current filters as well as their permission, and only the controller knows
 * both.
 */
class ActivityTrailUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $entryId,
        public readonly string $occurredAt,
    ) {}

    public static function from(ActivityLog $log): self
    {
        return new self($log->id, (string) ($log->occurred_at ?? $log->created_at));
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('activity-trails')];
    }

    public function broadcastAs(): string
    {
        return 'trail.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->entryId, 'occurred_at' => $this->occurredAt];
    }
}
