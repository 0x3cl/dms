<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
     * @param  object  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // We will store the notification in the database to display on the site.
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  object  $notifiable
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
        ];
    }
}
