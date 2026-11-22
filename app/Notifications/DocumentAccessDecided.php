<?php

namespace App\Notifications;

use App\Enums\AccessRequestStatus;
use App\Enums\NotificationKind;
use App\Models\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Your request was answered.
 *
 * The `url` differs by outcome on purpose. A granted request points at the
 * document, which the requester can now open. A declined one points at the
 * dashboard — `NotificationController::read()` redirects to whatever is in
 * `url`, so sending a declined requester to the document would bounce them
 * straight back into the refusal they were just given.
 */
class DocumentAccessDecided extends Notification
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
        $granted = $this->request->status === AccessRequestStatus::Granted;

        return [
            'message' => $granted
                ? "You can now open {$this->request->document_code}."
                : "Your request to open {$this->request->document_code} was declined.",
            'url' => $granted
                ? route('documents.view', ['code' => $this->request->document_code])
                : route('dashboard'),
            'icon' => $granted ? 'fa-solid fa-lock-open' : 'fa-solid fa-circle-xmark',
            // A yes and a no are two kinds, not one with a flag: they are read
            // at a glance in a list, and the colour is the thing being read.
            'kind' => ($granted ? NotificationKind::AccessGranted : NotificationKind::AccessDeclined)->value,
            // Who answered, snapshotted on the request when the decision was
            // made. The face comes from the live account, and is simply absent
            // once that account is gone.
            'actor_name' => $this->request->decided_by_name,
            'actor_profile_image' => $this->request->decider?->profile_image,
            'actor_avatar_seed' => $this->request->decider?->avatar_seed,
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
