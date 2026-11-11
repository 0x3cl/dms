<?php

namespace App\Http\Controllers;

use App\Enums\DocumentAction;
use App\Enums\FileAbility;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Services\Access\AccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentLogController extends Controller
{
    public function __construct(private readonly AccessResolver $access) {}

    /**
     * Record a hand-off against a document the user can see.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_id' => ['required', 'exists:documents,id'],
            // Only the hand-off actions a person performs by hand. This was
            // `string|max:255`, so a client could post any text it liked —
            // including a forged "received" entry it had not earned.
            'action' => ['required', Rule::in(DocumentAction::postableValues())],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $document = Document::findOrFail($validated['document_id']);
        $action = DocumentAction::from($validated['action']);

        // A private document's log is as sensitive as the document, so logging
        // against one goes through the same visibility rule a read does.
        if (! $this->canSee($document)) {
            return back()->with('error', 'You are not authorized to log activity on this document.');
        }

        // Two consecutive acknowledgements by the same person say nothing the
        // first one did not.
        if ($action === DocumentAction::Acknowledged) {
            $last = $document->logs()->latest('occurred_at')->first();

            if ($last && $last->actor_id === $user->id && $last->action === DocumentAction::Acknowledged) {
                return back()->with('error', 'You cannot acknowledge this document twice in a row.');
            }
        }

        DocumentLog::record($document, $action, $user, $validated['remarks'] ?? null, $request->ip());

        return back()->with('success', 'Document activity logged.');
    }

    /**
     * A document's full travel history.
     *
     * The route previously returned raw JSON, which nothing consumed — the
     * detail page rendered the same rows itself. It is a real page now.
     *
     * No eager loading: every entry carries the actor's name and office as
     * they were at the time, which is both cheaper and more truthful than
     * joining whatever those records say today.
     */
    public function index(Request $request, Document $document): Response|RedirectResponse|JsonResponse
    {
        if (! $this->canSee($document)) {
            return back()->with('error', 'You are not authorized to view this document.');
        }

        $logs = $document->logs()
            ->latest('occurred_at')
            ->paginate(25)
            ->withQueryString();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($logs);
        }

        return Inertia::render('documents/logs', [
            'document' => $document->only(['id', 'title', 'code', 'type', 'status', 'visibility']),
            'logs' => $logs,
        ]);
    }

    /**
     * A document's travel history is exactly as sensitive as the document: a
     * log listing who received a confidential case file is a disclosure of its
     * own. So this asks the same question reading the document asks.
     *
     * It used to be a hand-copied duplicate of
     * DocumentController::isAuthorized(), each carrying a comment saying the
     * two had to be changed together. Both call the one resolver now, which is
     * the only way that stops being something a future change has to remember.
     */
    private function canSee(Document $document): bool
    {
        return $this->access->allows(Auth::user(), $document, FileAbility::View);
    }
}
