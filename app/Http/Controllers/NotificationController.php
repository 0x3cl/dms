<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * The payload behind the header bell, shared on every page by
     * HandleInertiaRequests. Kept here rather than in the middleware so the
     * shape of a notification row is defined in one place.
     *
     * @return array{items: list<array{id: string, message: string, url: string|null, read: bool, created_at: string}>, unread: int}
     */
    public static function feedFor(User $user, int $limit = 8): array
    {
        $items = $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($notification): array => [
                'id' => $notification->id,
                'message' => $notification->data['message'] ?? 'Notification',
                'url' => $notification->data['url'] ?? null,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at->diffForHumans(),
            ])
            ->all();

        return [
            'items' => $items,
            'unread' => $user->unreadNotifications()->count(),
        ];
    }

    /**
     * Mark a specific notification as read and redirect to its associated URL.
     */
    public function read(string $id): RedirectResponse
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        // A notification whose payload predates the `url` key still has to go
        // somewhere, so fall back to the dashboard rather than redirect(null).
        return redirect($notification->data['url'] ?? route('dashboard'));
    }

    /**
     * Mark all of the user's unread notifications as read.
     */
    public function markAllRead(): RedirectResponse
    {
        Auth::user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
