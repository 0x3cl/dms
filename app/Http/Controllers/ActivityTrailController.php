<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything that has happened to a file or folder.
 *
 * Its own permission (`activity-trails.read`), because it reaches across every
 * account's storage — unlike a document's own trail, which is scoped to a
 * document the viewer can already read.
 *
 * The rows carry their own snapshot of the actor and the subject, so this needs
 * **no eager loading at all** and the search reads one table. The alternative —
 * joining live records — is what made the old trail rewrite itself whenever the
 * org chart changed.
 */
class ActivityTrailController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $action = $request->string('action')->toString() ?: null;
        $from = $request->date('from');
        $to = $request->date('to');

        return Inertia::render('activity-trails/index', [
            'entries' => ActivityLog::query()
                ->matching($search)
                ->ofAction($action)
                ->when($from, fn ($query) => $query->where('occurred_at', '>=', $from->startOfDay()))
                ->when($to, fn ($query) => $query->where('occurred_at', '<=', $to->endOfDay()))
                ->latest('occurred_at')
                ->latest('id')
                ->paginate($entries)
                ->withQueryString(),
            'actions' => ActivityAction::options(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
                'action' => $action,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 25);

        return in_array($entries, [10, 25, 50], true) ? $entries : 25;
    }
}
