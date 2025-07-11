<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Document;
use App\Models\Department;
use App\Models\Section;
use App\Models\Employee;
use App\Models\DocumentLog;

class DashboardController extends Controller
{
   public function index()
{
    $user = Auth::user();

    $documents = Document::with(['latestLog'])->get();

    // Count documents based on current status
    $statusCounts = [
        'incoming' => $documents->where('current_status', 'incoming')->count(),
        'pending' => $documents->where('current_status', 'pending')->count(),
        'received' => $documents->where('current_status', 'received')->count(),
        'ended' => $documents->where('current_status', 'ended')->count(),
    ];

    return view('dashboard', [
        'incomingCount' => $statusCounts['incoming'],
        'pendingCount' => $statusCounts['pending'],
        'receivedCount' => $statusCounts['received'],
        'endedCount' => $statusCounts['ended'],

        'myDocuments' => $documents->where('user_id', $user->id),
        'allDocuments' => $documents,
    ]);
}

}
