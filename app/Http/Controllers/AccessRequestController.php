<?php

namespace App\Http\Controllers;

use App\Enums\AccessRequestStatus;
use App\Enums\FileAbility;
use App\Models\AccessRequest;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAccessDecided;
use App\Notifications\DocumentAccessRequested;
use App\Services\Access\AccessResolver;
use App\Services\Documents\DocumentRegistrar;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Asking to be let into a document, and answering.
 *
 * Granting creates a `document_recipients` row through `DocumentRegistrar::tag()`
 * and nothing else — `AccessResolver::forDocument()` already reads that table,
 * so this adds no new way into a document and the resolver is untouched. That
 * is the whole design: this controller records a conversation; the recipient
 * list stays the single statement of who may read what.
 */
class AccessRequestController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly DocumentRegistrar $registrar,
    ) {}

    /**
     * The three tabs, as JSON.
     *
     * Fetched by the modal rather than shipped as a page prop, the way the
     * share drawer fetches its own state: this is a worklist somebody opens
     * occasionally, not something every page should carry.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            /*
             * A request the asker has cleared is not put to the uploader.
             *
             * Clearing a *pending* one is a withdrawal in everything but
             * name: asking somebody to decide a question nobody is waiting
             * on any more is how a queue fills with rows that mean nothing.
             * The row itself stays, so the record of the asking survives
             * either way.
             */
            'incoming' => $this->rows(
                AccessRequest::query()->where('owner_id', $user->id)
                    ->whereNull('dismissed_at')
                    ->pending()
            ),
            /*
             * Not filtered by `dismissed_at`: this is the **uploader's**
             * record of who they let in, and the asker tidying their own
             * list must not erase it from the list of the person who made
             * the decision. That asymmetry is the whole point of a stamp
             * rather than a delete.
             */
            'granted' => $this->rows(
                AccessRequest::query()->where('owner_id', $user->id)
                    ->where('status', AccessRequestStatus::Granted)
            ),
            'outgoing' => $this->rows(
                AccessRequest::query()->where('requester_id', $user->id)
                    ->whereNull('dismissed_at')
            ),
        ]);
    }

    /**
     * Ask for access, by tracking code.
     *
     * **By code, not by id.** Somebody who was refused never saw the row's id;
     * what they do have is the code, from a printed label or a pasted link.
     *
     * Every refusal answers identically. Distinguishing "no such code" from
     * "you may not ask about that" would make this endpoint a way to discover
     * which codes exist, which is the one thing a public tracking code must not
     * leak.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $document = Document::query()->where('code', $validated['code'])->first();

        $refusal = fn (): RedirectResponse => back()->with(
            'error',
            'That request could not be sent. Check the tracking code, or you may already be able to open it.',
        );

        if (! $document
            || $document->user_id === $user->id
            // The effective restriction, so a public document sealed by a
            // restricted link can be asked about: there is a named list to
            // be added to, which is the whole question this endpoint asks.
            || ! $document->restrictsToNamedPeople()
            || $this->access->allows($user, $document, FileAbility::View)
        ) {
            return $refusal();
        }

        // One open request at a time. A refresh should not queue five.
        $alreadyAsking = AccessRequest::query()
            ->where('document_id', $document->id)
            ->where('requester_id', $user->id)
            ->pending()
            ->exists();

        if ($alreadyAsking) {
            return back()->with('error', 'You have already asked for this one. The uploader has not answered yet.');
        }

        $accessRequest = AccessRequest::open($document, $user, $validated['message'] ?? null);

        if ($document->user) {
            Notification::send($document->user, new DocumentAccessRequested($accessRequest));
        }

        return back()->with('success', 'Your request has been sent to the uploader.');
    }

    /**
     * Grant or decline.
     *
     * Authorised against the document's **live** `user_id`, not the `owner_id`
     * snapshot on the row: `OwnershipTransferService` moves documents when an
     * account is closed, and a transferred document's pending requests should
     * become answerable by whoever holds it now rather than stalling forever.
     * The snapshot is for the record of who was asked.
     *
     * A superadmin may also decide, so a request does not stall when the
     * uploader is on leave. `Gate::before` short-circuits them, so asking the
     * gate covers both without naming the role.
     */
    public function update(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['granted', 'denied'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $document = $accessRequest->document;

        if (! $document || ! $this->mayDecide($user, $document)) {
            return back()->with('error', 'Only the person who uploaded that document can answer this.');
        }

        if (! $accessRequest->status->isOpen()) {
            return back()->with('error', 'That request has already been answered.');
        }

        $status = AccessRequestStatus::from($validated['decision']);

        $accessRequest->update([
            'status' => $status,
            'decided_by' => $user->id,
            'decided_by_name' => $user->name,
            'decision_note' => $validated['note'] ?? null,
            'decided_at' => now(),
        ]);

        if ($status === AccessRequestStatus::Granted && $accessRequest->requester) {
            /*
             * The grant itself: one recipient row, through the same helper the
             * upload form uses. `notify: false` because the decision
             * notification below is the message — `PrivateDocumentShared` would
             * be a second one saying the same thing.
             */
            $this->registrar->tag($document, collect([$accessRequest->requester]), notify: false);
        }

        if ($accessRequest->requester) {
            Notification::send($accessRequest->requester, new DocumentAccessDecided($accessRequest->fresh()));
        }

        return back()->with('success', $status === AccessRequestStatus::Granted
            ? "{$accessRequest->requester_name} can now open {$accessRequest->document_code}."
            : 'The request was declined.');
    }

    /**
     * Clear a request off the asker's own list.
     *
     * A delete in the person's language and a **stamp** in the database, for
     * the reason `document_recipients.revoked_at` is a stamp: this row is the
     * only record that the request was made and answered. Nothing writes the
     * asking to `document_logs` — who asked for what is not the document's
     * travel history — so erasing it would take the uploader's record of
     * their own decision with it, and leave a granted request as a recipient
     * nobody could account for.
     *
     * **Only the asker, and only their own.** Deciding is the uploader's
     * half and is answered by `update()`; this is the asker tidying their
     * list, which is why it does not ask `mayDecide()` and why a superadmin
     * gets no say either. A request nobody can see is not a request that was
     * never made.
     *
     * It answers 404 rather than 403 for somebody else's row, matching the
     * share routes: "not yours" and "no such request" stay indistinguishable,
     * so the endpoint cannot be used to discover that a request exists.
     */
    public function destroy(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        abort_unless($accessRequest->requester_id === $request->user()->id, 404);

        // Already cleared: nothing to do, and re-stamping would move the time
        // a record was tidied, which is a fact about the first time.
        if ($accessRequest->dismissed_at === null) {
            $accessRequest->update(['dismissed_at' => now()]);
        }

        return back()->with('success', $accessRequest->status->isOpen()
            ? 'Request withdrawn.'
            : 'Request cleared from your list.');
    }

    /**
     * The uploader, or a superadmin.
     *
     * The role is named here rather than leaned on through `Gate::before`.
     * That hook makes a superadmin pass *any* `can()` check, so a made-up
     * ability would work — and would read as a permission that exists,
     * sending the next person looking for it in `App\Support\Modules`. There
     * is deliberately no `access-requests.decide` permission, because whether
     * somebody may answer is a per-record question, so this says plainly what
     * it means.
     */
    private function mayDecide(User $user, Document $document): bool
    {
        return $document->user_id === $user->id || $user->hasRole(Roles::SUPERADMIN);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AccessRequest>  $query
     * @return list<array<string, mixed>>
     */
    private function rows($query): array
    {
        return $query->latest()->limit(20)->get()
            ->map(fn (AccessRequest $row): array => [
                'id' => $row->id,
                'document_code' => $row->document_code,
                'document_title' => $row->document_title,
                'requester_name' => $row->requester_name,
                'requester_employee_id' => $row->requester_employee_id,
                'office' => collect([$row->division_name, $row->section_name])->filter()->implode(' · '),
                'owner_name' => $row->owner_name,
                'message' => $row->message,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'status_tone' => $row->status->tone(),
                'decided_by_name' => $row->decided_by_name,
                'decision_note' => $row->decision_note,
                'decided_at' => $row->decided_at,
                'created_at' => $row->created_at,
            ])->values()->all();
    }
}
