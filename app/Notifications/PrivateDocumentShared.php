<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PrivateDocumentShared extends Notification
{
    use Queueable;

    public $document;

    public $sender;

    /**
     * Create a new notification instance.
     */
    public function __construct(Document $document, User $sender)
    {
        $this->document = $document;
        $this->sender = $sender;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // We will store the notification in the database to display on the site.
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_title' => $this->document->title,
            'sender_name' => $this->sender->name,
            'message' => "{$this->sender->name} shared the document '{$this->document->title}' with you.",
            'url' => route('documents.view', $this->document->code),
            // What this row is about, as a slug: the bell reads the wording,
            // the symbol and the colour off it, so correcting any of those
            // never has to be applied to rows already written.
            'kind' => NotificationKind::Shared->value,
            /*
             * The sender's face, snapshotted beside their name for the reason
             * every audit row snapshots its actor: a notification says what
             * happened and when, so it must still render after that account is
             * closed, and must not silently change when somebody edits their
             * profile. Neither value is a credential — a seed is an arbitrary
             * token the person picked, and the image path is already served to
             * anyone who can see them in a list.
             */
            'actor_name' => $this->sender->name,
            'actor_profile_image' => $this->sender->profile_image,
            'actor_avatar_seed' => $this->sender->avatar_seed,
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
