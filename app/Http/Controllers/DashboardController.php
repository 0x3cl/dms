<?php

namespace App\Http\Controllers;

use App\Enums\DocumentVisibility;
use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Which documents this person has taken delivery of. One query, used
        // for both the count and the list below it; the actions that count as
        // delivery live on DocumentAction rather than in a literal array here.
        //
        // There is no "ended" figure any more: the End Action is gone, so a
        // document has no lifecycle state to count. What is left are the four
        // questions a person can actually act on.
        $deliveredIds = $user->deliveredDocumentIds();

        return Inertia::render('dashboard/index', [
            'stats' => [
                'uploaded' => Document::query()->uploadedBy($user->id)->count(),
                'received' => $deliveredIds->count(),
                'shared' => Document::query()
                    ->uploadedBy($user->id)
                    ->where('visibility', DocumentVisibility::Private)
                    ->count(),
                'open' => Document::query()
                    ->uploadedBy($user->id)
                    ->where('visibility', DocumentVisibility::Public)
                    ->count(),
            ],
            // Only a handful each: the full lists live behind their own
            // paginated screens rather than being loaded in full here, which
            // is what the old dashboard did with every document in the system.
            'recentUploads' => Document::query()
                ->uploadedBy($user->id)
                ->latest()
                ->limit(5)
                ->get(['id', 'title', 'code', 'type', 'visibility', 'created_at']),
            'recentReceived' => Document::query()
                ->whereKey($deliveredIds)
                ->with('user:id,name')
                ->latest()
                ->limit(5)
                ->get(['id', 'title', 'code', 'type', 'visibility', 'user_id', 'uploader_name', 'created_at']),
            // The trail carries the actor's own snapshot, so this needs only
            // the document it points at.
            'recentActivity' => $user->documentLogs()
                ->with('document:id,title,code')
                ->latest('occurred_at')
                ->limit(6)
                ->get(['id', 'document_id', 'action', 'remarks', 'occurred_at']),
        ]);
    }
}
