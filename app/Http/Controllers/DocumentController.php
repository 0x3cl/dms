<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Enums\DocumentVisibility;
use App\Enums\FileAbility;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\UploadSetting;
use App\Models\User;
use App\Notifications\PrivateDocumentShared;
use App\Services\Access\AccessResolver;
use App\Services\Files\FileEventRecorder;
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\UploadPolicy;
use App\Services\TrackingCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly TrackingCodeService $trackingCodes,
        private readonly FileStorage $files,
        private readonly FolderService $folders,
        private readonly AccessResolver $access,
        private readonly UploadPolicy $policy,
        private readonly FilePreview $preview,
        private readonly FileEventRecorder $events,
    ) {}

    /**
     * Where a tracked document's bytes are filed in the uploader's tree.
     *
     * A document is a `files` row with a tracking jacket, so it has to live
     * somewhere in the tree like anything else. One folder per year keeps a
     * long-running office's root from becoming a flat list of thousands.
     */
    private function trackedFolder(User $user): \App\Models\Folder
    {
        $root = $this->folders->rootFor($user);
        $name = 'Tracked Documents '.now()->format('Y');

        return \App\Models\Folder::query()
            ->where('parent_id', $root->id)
            ->where('name', $name)
            ->first() ?? $this->folders->create($user, $root, $name);
    }

    /**
     * The Documents page: one screen, three tabs.
     *
     * "My Documents" and "All Documents" used to be two sidebar entries over
     * two controller methods and two Vue pages that listed the same table with
     * different filters. They are one page now — All, Uploaded by me, Shared
     * with me — because which of those a person wants is a filter, not a
     * destination.
     *
     * The All tab is still a different *grant*: "every document in the office"
     * is not "the documents I am party to", so it stays behind
     * `documents-all.read` and the tab is simply absent without it. The tab is
     * resolved server-side and falls back rather than refusing, so a URL
     * someone was sent cannot 403 them off the page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $type = $request->integer('type') ?: null;
        $maySeeAll = $user->can('documents-all.read');
        $tab = $this->tab($request, $maySeeAll);

        $query = match ($tab) {
            'all' => Document::query()
                ->with(['user:id,name,division_id,section_id', 'user.division:id,name', 'user.section:id,name']),
            'shared' => $user->receivedDocuments()->getQuery()->with('user:id,name'),
            default => Document::query()->uploadedBy($user->id)->with('user:id,name'),
        };

        return Inertia::render('documents/index', [
            'documents' => $query
                ->matching($search)
                ->when($type, fn ($inner) => $inner->where('document_type_id', $type))
                ->latest()
                ->paginate($entries)
                ->withQueryString(),
            // filterable(), not selectable(): a document filed under a type
            // that has since been retired or deleted is still on the register,
            // and a filter that cannot find it is a filter that lies.
            'types' => DocumentType::filterable(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
                'tab' => $tab,
                'type' => $type,
            ],
            'counts' => [
                'all' => $maySeeAll ? Document::query()->count() : null,
                'mine' => Document::query()->uploadedBy($user->id)->count(),
                'shared' => $user->receivedDocuments()->count(),
            ],
            'can' => [
                'all' => $maySeeAll,
                'create' => $user->can('documents.create'),
            ],
        ]);
    }

    /**
     * Which tab, with the All tab's permission applied here rather than by the
     * route.
     *
     * A tab is a filter on one page, so an unreachable one has to degrade to a
     * reachable one — middleware would turn a stale link into a refusal of the
     * whole screen.
     */
    private function tab(Request $request, bool $maySeeAll): string
    {
        $tab = $request->string('tab')->toString();

        if ($tab === 'all') {
            return $maySeeAll ? 'all' : 'mine';
        }

        return in_array($tab, ['mine', 'shared'], true) ? $tab : 'mine';
    }

    /**
     * The old "All Documents" screen, kept as a redirect.
     *
     * The route name `documents.all` and the path survive on purpose: a
     * bookmark, a tutorial anchor or a pasted link must land on the merged page
     * rather than 404.
     */
    public function all(): RedirectResponse
    {
        return to_route('documents.index', ['tab' => 'all']);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('documents/form', [
            'document' => null,
            'types' => DocumentType::selectable(),
            'visibilities' => $this->visibilityOptions(),
            // The recipient picker shows a face and an office beside each
            // name, because "Dela Cruz" is not enough to pick the right person
            // out of a list of everyone in the agency.
            'users' => User::query()
                ->whereKeyNot($request->user()->id)
                ->with(['division:id,name', 'section:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'employee_id', 'profile_image', 'avatar_seed', 'division_id', 'section_id'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employee_id' => $user->employee_id,
                    'profile_image' => $user->profile_image,
                    'avatar_seed' => $user->avatar_seed,
                    'division' => $user->division?->name,
                    'section' => $user->section?->name,
                ]),
            // Sent so the form can fail fast and show the rules. The gate is
            // UploadPolicy on the server; this is a courtesy to the browser.
            'limits' => $this->policy->limits(UploadSetting::SCOPE_DOCUMENTS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type_id' => [
                'required',
                /*
                 * `whereNull('deleted_at')` is not redundant.
                 *
                 * `Rule::exists` builds its own query straight against the
                 * table, so the model's soft-delete global scope does not
                 * apply — without this, a deleted type still validated and
                 * could be uploaded against.
                 */
                Rule::exists('document_types', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            // From the administrator-editable `documents` policy rather than a
            // literal. Seeded with exactly the old limits, so this changed no
            // behaviour on the day it landed.
            'file' => $this->policy->singleRules(UploadSetting::SCOPE_DOCUMENTS),
            'visibility' => ['required', Rule::enum(DocumentVisibility::class)],
            'recipients' => ['required_if:visibility,private', 'array'],
            'recipients.*' => ['distinct', 'exists:users,id'],
        ]);

        $user = $request->user();

        /*
         * Naming recipients *is* sharing a document, so it is what
         * `documents.share` gates. The permission previously gated nothing at
         * all — it was a checkbox on the roles screen with no effect — while
         * the act it is named for went unchecked.
         */
        if ($validated['visibility'] === DocumentVisibility::Private->value && ! $user->can('documents.share')) {
            return back()
                ->withInput()
                ->with('error', 'Your role cannot share a document with named recipients. Upload it as public instead.');
        }

        $this->policy->assertPermitted($request->file('file'), UploadSetting::SCOPE_DOCUMENTS, 'file');

        $type = DocumentType::query()->findOrFail($validated['document_type_id']);
        $code = $this->trackingCodes->issue($type);

        // Onto the private `documents` disk, at a path derived from the row's
        // own opaque identifier. Nothing the uploader typed reaches the
        // filesystem, and nothing under that disk is reachable over the web.
        $stored = $this->files->store($request->file('file'), $this->trackedFolder($user), $user);

        // The label is named after the tracking code, not the document title.
        // A title is arbitrary user input — it collided between documents of
        // the same name and, on a public download route that takes the
        // filename, was a traversal risk.
        //
        // It is an SVG, not a PNG: simple-qrcode renders PNG through
        // BaconQrCode's Imagick backend, and this app's servers have GD but
        // not Imagick, so `format('png')` threw on every upload. SVG needs no
        // extension at all and prints sharper at label size. Older rows still
        // carry `.png` names and keep working — downloadQr() serves whatever
        // the row points at.
        $qrFileName = "{$code}.svg";
        Storage::disk(FileStorage::DISK)->put(
            "qrcodes/{$qrFileName}",
            QrCode::format('svg')->size(400)->margin(2)->generate(
                route('documents.view', ['code' => $code]),
            ),
        );

        $document = Document::create([
            'code' => $code,
            'title' => $validated['title'],
            'document_type_id' => $type->id,
            // The type's name as it stands today. A tracking code is
            // permanent, so renaming a type must not rewrite the history of
            // the documents issued under its old name.
            'type' => $type->name,
            'file_id' => $stored->id,
            'file_name' => $stored->original_name,
            'qr_code_path' => $qrFileName,
            'user_id' => $user->id,
            'uploader_name' => $user->name,
            'visibility' => $validated['visibility'],
        ]);

        // The trail opens with the upload itself, so a document's history
        // starts where the document does rather than at whoever first viewed
        // it.
        DocumentLog::record($document, DocumentAction::Uploaded, $user, null, $request->ip());

        // And the general trail, so /activity-trails is the one report that
        // covers everything that happens to a file — tracked or not.
        ActivityLog::record($user, ActivityAction::Uploaded, $stored, [
            'code' => $code,
            'tracked' => true,
        ], $request);

        if ($document->visibility === DocumentVisibility::Private) {
            $recipients = User::findMany($validated['recipients']);

            $document->recipients()->syncWithoutDetaching(
                $recipients->mapWithKeys(fn (User $recipient) => [
                    $recipient->id => ['notified_at' => now()],
                ])->all()
            );

            Notification::send($recipients, new PrivateDocumentShared($document, $user));
        }

        return to_route('documents.success', ['code' => $code, 'file_name' => $qrFileName]);
    }

    /**
     * The upload confirmation, with the QR label to print.
     *
     * **The cast is load-bearing.** `QrCode::generate()` returns an
     * `Illuminate\Support\HtmlString`, not a string — which is harmless when it
     * is written to disk or echoed in Blade, because both stringify it, and
     * wrong here: `HtmlString` is neither `JsonSerializable` nor a carrier of
     * public properties, so Inertia serialised it as `{}` and the page rendered
     * `[object Object]` where the label should be.
     */
    public function success(string $code, string $file_name): Response
    {
        return Inertia::render('documents/upload-success', [
            'code' => $code,
            'fileName' => $file_name,
            'qrCodeSvg' => (string) QrCode::size(300)->generate(route('documents.view', ['code' => $code])),
            'trackingUrl' => route('documents.view', ['code' => $code]),
        ]);
    }

    /**
     * A document by its tracking code — what a scanned QR label resolves to.
     */
    public function view(string $code): Response|RedirectResponse
    {
        $document = Document::with(['user:id,name', 'recipients:id,name'])
            ->where('code', $code)
            ->firstOrFail();

        if (! $this->isAuthorized($document)) {
            return $this->unauthorizedRedirect('You are not authorized to view this private document.');
        }

        return Inertia::render('documents/show', $this->showPayload($document));
    }

    /**
     * The resource route declares `documents.show`, which had no method at all
     * and threw on any hit. It forwards to the tracking-code page, which is the
     * real detail view.
     */
    public function show(Document $document): RedirectResponse
    {
        return to_route('documents.view', $document->code);
    }

    /**
     * Track-by-code, from the dashboard's lookup box.
     *
     * This used to skip isAuthorized() entirely, so anyone holding a tracking
     * code could read a private document the gate was meant to protect. It now
     * goes through the same check as every other read.
     */
    public function viewByCode(Request $request): Response|RedirectResponse
    {
        $code = $request->string('code')->trim()->toString();

        if ($code === '') {
            return Inertia::render('documents/track');
        }

        $document = Document::with(['user:id,name', 'recipients:id,name'])
            ->where('code', $code)
            ->first();

        if (! $document) {
            return Inertia::render('documents/track', [
                'code' => $code,
                'notFound' => true,
            ]);
        }

        if (! $this->isAuthorized($document)) {
            return $this->unauthorizedRedirect('You are not authorized to view this private document.');
        }

        return Inertia::render('documents/show', $this->showPayload($document));
    }

    public function edit(Document $document): Response|RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can edit this document.');
        }

        return Inertia::render('documents/form', [
            'document' => $document->only([
                'id', 'title', 'document_type_id', 'type', 'code', 'file_path', 'file_name', 'visibility',
            ]),
            'types' => DocumentType::selectable(),
            'visibilities' => $this->visibilityOptions(),
            'users' => [],
            'limits' => $this->policy->limits(UploadSetting::SCOPE_DOCUMENTS),
        ]);
    }

    /**
     * Only the uploader may edit. This check did not exist: any authenticated
     * user could edit or delete any document in the system.
     */
    public function update(Request $request, Document $document): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can edit this document.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type_id' => [
                'required',
                /*
                 * `whereNull('deleted_at')` is not redundant.
                 *
                 * `Rule::exists` builds its own query straight against the
                 * table, so the model's soft-delete global scope does not
                 * apply — without this, a deleted type still validated and
                 * could be uploaded against.
                 */
                Rule::exists('document_types', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'file' => $this->policy->singleRules(UploadSetting::SCOPE_DOCUMENTS, required: false),
        ]);

        $type = DocumentType::query()->findOrFail($validated['document_type_id']);
        $replacement = $request->file('file');

        if ($replacement) {
            $this->policy->assertPermitted($replacement, UploadSetting::SCOPE_DOCUMENTS, 'file');
        }
        $stored = $replacement
            ? $this->files->store($replacement, $this->trackedFolder($request->user()), $request->user())
            : null;

        $document->update([
            'title' => $validated['title'],
            'document_type_id' => $type->id,
            'type' => $type->name,
            'file_id' => $stored?->id ?? $document->file_id,
            'file_name' => $stored?->original_name ?? $document->file_name,
        ]);

        return to_route('documents.edit', $document)->with('success', 'Document updated.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can delete this document.');
        }

        $document->delete();

        return to_route('documents.index')->with('success', 'Document deleted.');
    }

    public function scan(): Response
    {
        return Inertia::render('documents/scan');
    }

    /**
     * Who has taken a copy of which document.
     *
     * The rows carry their own snapshot of the person and their office, so the
     * search reads this one table rather than running a `whereHas` against
     * `users` and another against `documents` on every keystroke, and the
     * report still answers the question after an account is deleted.
     *
     * Note this page is reachable by any authenticated user — DocTrack has no
     * roles, so the `admin.` route name is a label, not a gate. See
     * documentation/architecture.md.
     */
    public function downloadLogs(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();

        return Inertia::render('documents/download-logs', [
            'downloads' => DocumentDownload::query()
                ->with('document:id,title,code')
                ->matching($search)
                ->latest('downloaded_at')
                ->paginate($entries)
                ->withQueryString(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
            ],
        ]);
    }

    /**
     * The printable QR label.
     *
     * The filename is matched against a stored `qr_code_path` rather than
     * interpolated straight into a path: this route is public and used to hand
     * any filename to storage_path().
     */
    public function downloadQr(string $file_name): StreamedResponse|BinaryFileResponse
    {
        abort_unless(Document::query()->where('qr_code_path', $file_name)->exists(), 404, 'QR code not found.');

        // basename() still, belt and braces: the name has already been matched
        // against a stored `qr_code_path`, but this is a public route and the
        // value reaches a disk call.
        $path = 'qrcodes/'.basename($file_name);
        $disk = Storage::disk(FileStorage::DISK);

        // Labels written before the move to private storage are still on the
        // public disk. Serve whichever holds this one.
        if (! $disk->exists($path)) {
            $legacy = Storage::disk('public');

            abort_unless($legacy->exists($path), 404, 'QR code not found.');

            return $legacy->download($path);
        }

        return $disk->download($path);
    }

    /**
     * How this document's file should be previewed in place.
     *
     * The same descriptor the file manager asks for, through the same service —
     * so a Word document opens in Microsoft's Office viewer here exactly as it does in
     * My Files, and a PDF, an image or a video render in the page. "View file"
     * used to hand the raw bytes to a new browser tab, which showed a download
     * prompt for every format a browser cannot render, which is most of what an
     * office files.
     *
     * **Public, like the page it serves.** `documents/show` renders for a
     * signed-out reader of a public document, and the bytes behind the preview
     * come from the file-stream route, which is public for the same reason and
     * gated by AccessResolver. Gating this one on a session would mean the
     * preview worked for everybody except the person holding the printed label.
     * The gate is the document's own rule, applied here before anything else.
     *
     * A refusal is a 404 rather than the usual redirect: the caller is a fetch
     * behind a button, not a page visit, and it has nothing to render a flash
     * with.
     */
    public function preview(Request $request, Document $document): JsonResponse
    {
        abort_unless($this->isAuthorized($document), 404);

        $file = $document->file;

        if (! $file || ! $this->files->exists($file)) {
            // The record survives its bytes — `documents.file_id` is
            // nullOnDelete and a file can go missing off the disk — so this
            // answers honestly instead of 404ing the whole dialog.
            return response()->json([
                'mode' => 'none',
                'name' => $document->file_name ?? $document->title,
                'can_download' => false,
                'download_url' => null,
            ]);
        }

        /*
         * Debounced, and recorded here rather than when the bytes are fetched.
         * An embedded frame re-requests on every re-render, and for an Office
         * document the fetch is made by Microsoft's servers — so this is the
         * request that both carries an actor and happens once per opening.
         * FileEventRecorder mirrors it into the document's own trail as a
         * `viewed` entry, which is what this page renders.
         */
        $this->events->recordOnce(ActivityAction::Previewed, $file, $request->user(), $request);

        return response()->json($this->preview->describe($file, $request->user()));
    }

    /**
     * View or download the file itself.
     *
     * This returns a file response, so it must be reached by a plain link —
     * an Inertia XHR visit cannot follow it.
     */
    public function accessFile(Request $request, Document $document, string $action): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        if (! $this->isAuthorized($document)) {
            return $this->unauthorizedRedirect('You are not authorized to access this file.');
        }

        $stored = $document->storedFile();

        // Checked before anything is written, so a missing file never leaves a
        // download recorded that did not happen.
        if (! $stored || ! Storage::disk($stored['disk'])->exists($stored['path'])) {
            return to_route('documents.view', $document->code)
                ->with('error', 'The stored file for this document is missing.');
        }

        $log = DocumentLog::record(
            $document,
            $action === 'view' ? DocumentAction::Viewed : DocumentAction::Downloaded,
            $request->user(),
            null,
            $request->ip(),
        );

        $this->recordActivity(
            $document,
            $action === 'view' ? ActivityAction::Previewed : ActivityAction::Downloaded,
            $request,
        );

        if ($action === 'download') {
            DocumentDownload::register($document, $request->user(), $log, $request->ip());

            return Storage::disk($stored['disk'])->download($stored['path'], $stored['name']);
        }

        return Storage::disk($stored['disk'])->response($stored['path'], $stored['name'], [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Formally acknowledge receipt, once per user, and take a copy.
     */
    public function receiveFile(Request $request, Document $document): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        if (! $this->isAuthorized($document)) {
            return $this->unauthorizedRedirect('You are not authorized to receive this document.');
        }

        if ($this->hasReceived($document, $request->user()->id)) {
            return to_route('documents.view', $document->code)
                ->with('error', 'You have already logged this document as received.');
        }

        $stored = $document->storedFile();

        if (! $stored || ! Storage::disk($stored['disk'])->exists($stored['path'])) {
            return to_route('documents.view', $document->code)
                ->with('error', 'The stored file for this document is missing.');
        }

        $log = DocumentLog::record(
            $document,
            DocumentAction::Received,
            $request->user(),
            $validated['remarks'] ?? null,
            $request->ip(),
        );

        DocumentDownload::register($document, $request->user(), $log, $request->ip());
        $this->recordActivity($document, ActivityAction::Downloaded, $request);

        return Storage::disk($stored['disk'])->download($stored['path'], $stored['name']);
    }

    /**
     * An activity-log row for something that happened to a document.
     *
     * The subject is the `files` row when there is one, so the general trail
     * and the file manager agree about what was touched. A document imported
     * from before private storage may not have one yet, in which case the
     * document itself is the subject — which is why `activity_logs.subject` is
     * a plain morph with no foreign key.
     */
    private function recordActivity(Document $document, ActivityAction $action, Request $request): void
    {
        ActivityLog::record(
            $request->user(),
            $action,
            $document->file ?? $document,
            ['code' => $document->code, 'tracked' => true],
            $request,
        );
    }

    /**
     * The props behind documents/show, shared by the two routes that reach it.
     *
     * The trail needs no eager loading: each entry carries the actor's name
     * and office as they were when it happened, which is both cheaper and
     * more truthful than joining whatever those records say today.
     *
     * @return array<string, mixed>
     */
    private function showPayload(Document $document): array
    {
        return [
            'document' => $document,
            'logs' => $document->logs()->latest('occurred_at')->get(),
            'actions' => DocumentAction::options(),
            'can' => [
                'manage' => $this->owns($document),
                'download' => true,
                'receive' => ! $this->hasReceived($document, Auth::id()),
            ],
        ];
    }

    /**
     * Has this person already acknowledged receipt? Covered by the
     * `[document_id, actor_id, action]` index.
     */
    private function hasReceived(Document $document, ?int $userId): bool
    {
        return $userId !== null && $document->logs()
            ->where('actor_id', $userId)
            ->where('action', DocumentAction::Received)
            ->exists();
    }

    /**
     * Editing and deleting — a different question from reading.
     *
     * A *public* document is readable by everyone and still editable only by
     * its uploader, which is why this is not `isAuthorized()` with a stricter
     * name.
     */
    private function owns(Document $document): bool
    {
        return $this->access->allows(Auth::user(), $document, FileAbility::Manage);
    }

    /**
     * The one gate every document read goes through.
     *
     * The rule itself now lives in App\Services\Access\AccessResolver, which
     * DocumentLogController::canSee() calls too. Those two were a deliberate
     * duplicate of each other — a document's travel history is exactly as
     * sensitive as the document — and the duplication was a standing hazard
     * that had to be remembered twice. One resolver removes it.
     */
    private function isAuthorized(Document $document): bool
    {
        return $this->access->allows(Auth::user(), $document, FileAbility::View);
    }

    private function unauthorizedRedirect(string $message): RedirectResponse
    {
        return (Auth::check() ? to_route('dashboard') : to_route('login'))->with('error', $message);
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function visibilityOptions(): array
    {
        return array_map(fn (DocumentVisibility $visibility): array => [
            'value' => $visibility->value,
            'label' => $visibility->label(),
            'description' => $visibility->description(),
        ], DocumentVisibility::cases());
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
