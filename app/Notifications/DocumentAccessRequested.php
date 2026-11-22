<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody has asked to be let into your document.
 *
 * Database only. The payload carries the request's own id and the document's
 * code — both of which the uploader can already see — and never the message
 * verbatim, because a notification row is read on every page load and a
 * request's text belongs in the modal where there is room for it.
 */
class DocumentAccessRequested extends Notification
{
    use Queueable;

    public function __construct(private readonly AccessRequest $request) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $requester = $this->request->requester;

        return [
            'message' => "{$this->request->requester_name} is asking to open {$this->request->document_code}.",
            'url' => route('documents.index').'?requests=incoming',
            'icon' => 'fa-solid fa-user-clock',
            'kind' => NotificationKind::AccessRequested->value,
            // The name from the request's own snapshot, which outlives the
            // account; the face from the account, because there is nowhere
            // else to take it from and it is absent rather than wrong once
            // that account is gone.
            'actor_name' => $this->request->requester_name,
            'actor_profile_image' => $requester?->profile_image,
            'actor_avatar_seed' => $requester?->avatar_seed,
            'access_request_id' => $this->request->id,
        ];
    }

    /**
     * The same four keys `toArray()` stores.
     *
     * One shape rather than two: a second serializer is how a field nobody
     * meant to expose ends up on a channel. Nothing here is a credential.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
