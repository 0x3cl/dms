<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Mark a specific notification as read and redirect to its associated URL.
     *
     * @param  string  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function read($id)
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        // Redirect to the URL that was stored in the notification's data
        return redirect($notification->data['url']);
    }

    /**
     * Mark all of the user's unread notifications as read.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function markAllRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
        return redirect()->back()->with('success', 'All notifications marked as read.');
    }
}
