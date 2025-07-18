<?php

namespace App\Http\Controllers;

use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentLogController extends Controller
{
    /**
     * Store a newly created document log in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'document_id' => 'required|exists:documents,id',
            'action' => 'required|string|max:255',
            'remarks' => 'nullable|string|max:1000', // Validate the new remarks field
        ]);

        $user = Auth::user();
        $documentId = $request->input('document_id');
        $action = $request->input('action');

        // Check for duplicate acknowledgements
        if ($action === 'Acknowledged / Forwarded') {
            $lastLog = DocumentLog::where('document_id', $documentId)
                ->latest('received_at')
                ->first();

            if ($lastLog && $lastLog->user_id === $user->id && $lastLog->action === 'Acknowledged / Forwarded') {
                return redirect()->back()
                    ->with('error', 'You cannot acknowledge this document twice in a row.');
            }
        }

        // Create the new log entry, now including the remarks
        DocumentLog::create([
            'document_id' => $documentId,
            'user_id'     => $user->id,
            'division_id' => $user->division_id,
            'section_id'  => $user->section_id,
            'action'      => $action,
            'remarks'     => $request->input('remarks'), // <-- Save the remarks
            'received_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Document activity logged successfully!');
    }

    /**
     * Display a listing of the resource.
     */
    public function index($document_id)
    {
        $logs = DocumentLog::where('document_id', $document_id)->latest()->get();
        return response()->json($logs);
    }
}
