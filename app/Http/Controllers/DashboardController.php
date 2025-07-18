<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Document;
use App\Models\DocumentLog;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // --- UPDATED: Logic now specifically targets download/receive actions ---
        $receivedActions = [
            'Downloaded file',
            'Received and Downloaded' // Only these actions count as "received"
        ];

        // 1. Get the unique IDs of documents the user has received/downloaded.
        $receivedDocumentIds = DocumentLog::where('user_id', $user->id)
            ->whereIn('action', $receivedActions)
            ->distinct()
            ->pluck('document_id');

        // 2. Fetch the full Document models for those IDs.
        $receivedDocuments = Document::whereIn('id', $receivedDocumentIds)
            ->with('user') // Eager load uploader info to prevent N+1 query issues
            ->latest()
            ->get();
        
        // 3. The count is now just the size of the collection we fetched.
        $documentsReceivedCount = $receivedDocuments->count();
        
        // --- End of updated logic ---

        // Count how many documents the current user uploaded (Docs Sent)
        $outgoingCount = Document::where('user_id', $user->id)->count();
        
        // Count how many documents the current user has personally marked as 'ended'
        $endedCount = DocumentLog::where('action', 'ended')
            ->where('user_id', $user->id)
            ->distinct('document_id')
            ->count();

        // Get documents for the "My Files" and "PTRI Files" tables
        $myDocuments = Document::where('user_id', $user->id)->latest()->get();
        $allDocuments = Document::latest()->get();

        // Pass all the data to the dashboard view
        return view('dashboard', [
            'documentsReceivedCount' => $documentsReceivedCount,
            'receivedDocuments' => $receivedDocuments,
            'outgoingCount' => $outgoingCount,
            'endedCount' => $endedCount,
            'myDocuments' => $myDocuments,
            'allDocuments' => $allDocuments,
        ]);
    }
}
