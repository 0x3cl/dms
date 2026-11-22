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
    /**
     * Twelve rows a page, on both the panel and the full-history page.
     *
     * The panel used to render **every** row the document had ever collected,
     * unpaginated, which on a long-running document is a page that grows
     * without limit and a payload nobody reads the bottom of.
     */
    public const PER_PAGE = 12;

    public function __construct(private readonly AccessResolver $access) {}

    /**
     * Record a hand-off against a document the user can see.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_id' => ['required', 'exists:documents,id'],
            // Only the actions a person performs by hand. This was
            // `string|max:255`, so a client could post any sentence it liked
            // into a document's history.
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

        /*
         * **A logged action repeats, and nothing here refuses a second one.**
         *
         * Two rules used to live at this point: a person could log Received
         * once per document, and could not Acknowledge twice in a row. Both
         * were reasoned from the shape of the fact — "a document reaches you
         * once", "a second acknowledgement says nothing the first did not" —
         * and both were wrong about the office.
         *
         * A document really does reach the same person twice: as paper on
         * Monday and as a re-sent copy on Friday, or after going out for
         * signature and coming back. An acknowledgement repeats for the same
         * reason, and the two rows are not duplicates — they carry different
         * timestamps and usually different remarks, which is precisely the
         * information a hand-off trail exists to hold.
         *
         * The cost of the rules was paid in the wrong place, too. Refusing
         * the entry does not make the event un-happen; it makes the trail
         * silent about it, and an audit trail that declines to record
         * something that occurred is a worse failure than one holding two
         * similar rows. The reader can always see both and judge.
         *
         * What still cannot be posted is the set the **system** observes —
         * `Viewed`, `Downloaded`, `Uploaded`, `LinkShared`, `LinkRevoked` —
         * which `DocumentAction::postable()` enforces through the `Rule::in`
         * above. That is the protection that matters: a client claiming a
         * download does not make bytes have left the server. Whether a
         * person says "this reached me" once or twice is their account of
         * their own work.
         */
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
            // A removed viewer gets told so here too: the trail is as
            // restricted as the document, so it is the same refusal and has to
            // give the same reason. See DocumentController::refusalMessage().
            return back()->with('error', $document->wasRemovedAsViewer($request->user())
                ? 'You were removed as a viewer of this document, so its history is closed to you.'
                : 'You are not authorized to view this document.');
        }

        $filters = self::filters($request);

        $logs = $document->logs()
            ->trail($filters)
            ->paginate(self::perPage($request))
            ->withQueryString();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($logs);
        }

        return Inertia::render('documents/logs', [
            'document' => $document->only(['id', 'title', 'code', 'type', 'visibility']),
            'logs' => $logs,
            // Every action that can *appear* in a trail, not just the postable
            // ones: a filter that cannot find a system-written row is a filter
            // that lies about what the history contains.
            'actions' => DocumentAction::all(),
            // Only the offices this document's trail actually contains, so the
            // filter cannot offer an option that matches nothing.
            'offices' => DocumentLog::officesFor($document->id),
            // Derived from the rows, so the filter cannot offer a version the
            // trail has nothing against.
            'versions' => DocumentLog::versionsFor($document->id),
            // The same split, as the tabs that render it: one per version the
            // trail holds, counted under the filters in force but not under
            // the version itself, so a tab says what choosing it would show.
            'versionTabs' => DocumentLog::versionTabsFor($document->id, $filters),
            'filters' => [...$filters, 'entries' => $logs->perPage()],
        ]);
    }

    /**
     * The trail's filters, parsed once.
     *
     * Shared with `DocumentController::showPayload()` because the panel and
     * the full-history page are one list at two lengths. Two hand-written
     * parsers over one query string is how a filter that works on one screen
     * quietly does not on the other — the same reason `scopeTrail()` is one
     * builder.
     *
     * @return array{search: ?string, action: ?string, from: ?string, to: ?string, office: ?string, version: int|'none'|null}
     */
    public static function filters(Request $request): array
    {
        $action = $request->string('action')->trim()->toString() ?: null;

        // Only an action the enum knows. A stray value would otherwise filter
        // the trail down to nothing and read as "there is no history".
        if ($action !== null && DocumentAction::tryFrom($action) === null) {
            $action = null;
        }

        return [
            'search' => $request->string('search')->trim()->toString() ?: null,
            'action' => $action,
            // Dates as given, validated only by being parseable: a malformed
            // one is dropped rather than throwing, because a filter in a URL
            // is somebody's bookmark, not a form submission.
            'from' => self::date($request->string('from')->toString()),
            'to' => self::date($request->string('to')->toString()),
            'office' => $request->string('office')->trim()->toString() ?: null,
            /*
             * Which version of the file. Clamped rather than checked against
             * the document, because this parser is static and has none in
             * hand — and a number no row carries simply matches nothing, which
             * is how the office filter behaves too.
             *
             * `none` is the trail's unversioned rows, which is the one tab a
             * number cannot name. It stays a distinct value rather than
             * collapsing to null, because null here already means the whole
             * history.
             */
            'version' => self::version($request->string('version')->trim()->toString()),
        ];
    }

    /** A page size the UI actually offers; anything else is the default. */
    public static function perPage(Request $request): int
    {
        $entries = $request->integer('entries', self::PER_PAGE);

        return in_array($entries, [12, 25, 50], true) ? $entries : self::PER_PAGE;
    }

    /** A version tab the trail can actually answer: a number, the unversioned rows, or all of them. */
    private static function version(string $value): int|string|null
    {
        if ($value === 'none') {
            return 'none';
        }

        return max(0, (int) $value) ?: null;
    }

    private static function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
