<?php

namespace App\Http\Controllers;

use App\Enums\AccessRequestStatus;
use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Enums\DocumentVisibility;
use App\Enums\FileAbility;
use App\Enums\ShareAbility;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\DocumentTypeGroup;
use App\Models\ShareLink;
use App\Models\User;
use App\Notifications\PrivateDocumentShared;
use App\Services\Access\AccessResolver;
use App\Services\Documents\DocumentRegistrar;
use App\Services\Documents\QrLabelService;
use App\Services\Files\FileEventRecorder;
use App\Services\Files\FileManager;
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use App\Services\Files\FileVersionService;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use App\Services\Files\UploadPolicy;
use App\Services\TrackingCodeService;
use App\Support\VersionedFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
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
        private readonly SharingService $sharing,
        private readonly QrLabelService $qrLabels,
        private readonly DocumentRegistrar $registrar,
        private readonly FileManager $fileManager,
        private readonly FileVersionService $versions,
    ) {}

    /**
     * Where a tracked document's bytes are filed in the uploader's tree.
     *
     * **Where the uploader said**, and My Files itself when they said
     * nothing. A document is a `files` row with a tracking jacket, so it
     * lives in the tree like anything else — and where in the tree is the
     * person's business, not the register's.
     *
     * It used to be a `Tracked Documents {year}` folder this method created
     * on the spot, on the reasoning that the form had no folder field and
     * needed *a* destination, and that one folder per year keeps a long
     * office's root from becoming a flat list of thousands. The first half
     * was true and has been fixed by giving the form the field; the second
     * was the system arranging somebody else's storage for them. What it
     * produced was a folder nobody made, holding files they then could not
     * find — they went looking in My Files, where they had put everything
     * else, and the office's own filing scheme was overridden by a default.
     *
     * An unreadable or unwritable destination is refused rather than
     * silently swapped for the root: an upload that lands somewhere other
     * than where it was sent is the failure this exists to prevent.
     */
    private function destinationFor(User $user, ?string $folderId): \App\Models\Folder
    {
        $root = $this->folders->rootFor($user);

        if ($folderId === null || $folderId === '') {
            return $root;
        }

        $folder = \App\Models\Folder::query()->where('public_id', $folderId)->first();

        // `Rename` is the write ability on a folder, which is what the Move
        // and Copy pickers resolve a destination by — the same question asked
        // the same way, so a folder this picker offers is one an upload can
        // actually land in.
        abort_unless(
            $folder !== null && $this->access->allows($user, $folder, FileAbility::Rename),
            404,
        );

        return $folder;
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

        /*
         * The rest of the filter dialog.
         *
         * Each one is read here and applied below; nothing is offered on
         * screen that this method does not narrow by, because a control that
         * changes no rows is worse than a missing one — the reader believes
         * they have narrowed the register and trusts a list that never moved.
         *
         * `group` is the type's **category** (Documents, Assets, Images,
         * Videos), which is a column on `document_types` rather than on a
         * document: a document's category is whatever its type is filed
         * under today, so it is resolved through the type instead of being
         * copied onto the row and going stale the first time a type is
         * regrouped.
         */
        $group = $request->integer('group') ?: null;
        $visibility = $request->string('visibility')->toString();
        $visibility = DocumentVisibility::tryFrom($visibility)?->value;
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        /*
         * Sorting is a whitelist mapped to qualified columns, for the same
         * reason the default order is qualified: the "Shared with me" tab is
         * a join through `document_recipients`, which carries its own
         * `created_at`, and an unqualified column is a 500 on that tab alone.
         * The whitelist is also what keeps a column name out of SQL.
         */
        $sorts = [
            'created' => 'documents.created_at',
            'updated' => 'documents.updated_at',
            'title' => 'documents.title',
            'code' => 'documents.code',
        ];

        $sort = $request->string('sort')->toString();
        $sort = isset($sorts[$sort]) ? $sort : 'created';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        /*
         * The uploader's face is two columns on the eager-loaded account —
         * `profile_image` and `avatar_seed`, the pair `resources/js/avatar.js`
         * needs — because the register renders a face beside the date now.
         * Neither is a credential: a seed is an arbitrary token the person
         * picked, and the path is already served to anybody who can see them
         * in a list. The *name* beside it is still the row's own
         * `uploader_name` snapshot, so a document filed by an account that has
         * since been closed keeps saying who filed it.
         */
        $uploader = 'user:id,name,profile_image,avatar_seed';

        $query = match ($tab) {
            'all' => Document::query()
                ->with([$uploader.',division_id,section_id', 'user.division:id,name', 'user.section:id,name'])
                /*
                 * "This one was shared with me", for the All tab.
                 *
                 * All is every document the office has logged, so a row the
                 * reader is actually party to is indistinguishable from one
                 * they are merely allowed to see — and on a register where
                 * most rows are somebody else's, that is the one thing worth
                 * knowing at a glance. The other two tabs answer it by being
                 * the tab they are.
                 *
                 * An exists subquery rather than a loaded relation: the
                 * question is a boolean per row, and eager-loading every
                 * document's recipient list to answer it would load the whole
                 * office's tagging to render a badge.
                 *
                 * Asked of `recipients()`, which carries
                 * `wherePivotNull('revoked_at')` — so somebody taken off a
                 * document stops being told it was shared with them, without
                 * this having to remember the rule. That single live-list
                 * statement is why a removed viewer cannot leak back in
                 * through a listing.
                 */
                ->withExists(['recipients as shared_with_me' => fn ($recipients) => $recipients
                    ->where('users.id', $user->id)]),
            'shared' => $user->receivedDocuments()->getQuery()->with($uploader),
            /*
             * What you withdrew, and the way back.
             *
             * `documents` is soft-deleted, so a withdrawn document was always
             * still there — it simply appeared on no screen and had no
             * restore, which made "Delete" a one-way door whose only undo was
             * a hand-written UPDATE. A trash is the honest shape for a soft
             * delete; a soft delete nobody can reach is just a row nobody can
             * account for.
             *
             * **Your own uploads only**, even for an administrator, because
             * restoring is the mirror of deleting and `destroy()` is
             * uploader-only. A screen listing other people's withdrawn
             * documents would also be a disclosure: withdrawing something is
             * a decision, and what somebody decided to take off the register
             * is not a thing the register should go on showing to everybody.
             */
            'deleted' => Document::query()->withdrawnBy($user->id)->with($uploader),
            default => Document::query()->uploadedBy($user->id)->with($uploader),
        };

        return Inertia::render('documents/index', [
            'documents' => $query
                ->matching($search)
                ->when($type, fn ($inner) => $inner->where('document_type_id', $type))
                ->when($group, fn ($inner) => $inner->whereHas(
                    'documentType',
                    fn ($types) => $types->withTrashed()->where('group_id', $group),
                ))
                ->when($visibility, fn ($inner) => $inner->where('documents.visibility', $visibility))
                // whereDate, so "to" includes everything filed that day
                // rather than stopping at midnight as it would against a
                // datetime column.
                ->when($from !== '', fn ($inner) => $inner->whereDate('documents.created_at', '>=', $from))
                ->when($to !== '', fn ($inner) => $inner->whereDate('documents.created_at', '<=', $to))
                /*
                 * Qualified, because one of the three tabs is a join.
                 *
                 * "Shared with me" reads through `document_recipients`, which
                 * carries its own timestamps — so a bare `latest()` orders by a
                 * `created_at` that belongs to two tables and MySQL refuses the
                 * query outright. The tab answered 500 for anyone who actually
                 * had a document shared with them, and nothing caught it
                 * because every test of that tab had an empty list, which the
                 * ambiguity reaches just the same.
                 */
                ->orderBy($sorts[$sort], $direction)
                ->paginate($entries)
                ->withQueryString(),
            // filterable(), not selectable(): a document filed under a type
            // that has since been retired or deleted is still on the register,
            // and a filter that cannot find it is a filter that lies.
            'types' => DocumentType::filterable(),
            // The categories the picker offers, from the rows — so a group
            // the office invented is filterable without a deploy, and one
            // nobody uses is not offered.
            'groups' => DocumentTypeGroup::ordered()->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
            ])->all(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
                'tab' => $tab,
                'type' => $type,
                'group' => $group,
                'visibility' => $visibility ?? '',
                'from' => $from,
                'to' => $to,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'counts' => [
                'all' => $maySeeAll ? Document::query()->count() : null,
                'mine' => Document::query()->uploadedBy($user->id)->count(),
                'shared' => $user->receivedDocuments()->count(),
                // Counted like the others, so the tab says whether there is
                // anything behind it before somebody opens it — a trash whose
                // emptiness you learn only by navigating is a trash people
                // check out of anxiety.
                'deleted' => Document::query()->withdrawnBy($user->id)->count(),
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

        return in_array($tab, ['mine', 'shared', 'deleted'], true) ? $tab : 'mine';
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
            // Grouped on the server, because the upload form and the file
            // manager's tracking section both offer this list and two
            // hand-built groupings is how one screen quietly offers a type the
            // other does not.
            'types' => DocumentType::grouped(),
            'visibilities' => $this->visibilityOptions(),
            'users' => $this->taggablePeople($request->user()),
            // Sent so the form can fail fast and show the rules. The gate is
            // UploadPolicy on the server; this is a courtesy to the browser.
            'limits' => $this->policy->limits(),
            /*
             * Whether to offer the destination picker at all.
             *
             * It walks `/files/destinations` and creates folders through
             * `/files/folders`, which are the file manager's own grants — so
             * a role that can file a document but cannot browse storage would
             * otherwise be shown a control that fetches a 403 and sits
             * spinning. Without it the upload simply lands at the top of
             * their storage, which is the default anyway.
             *
             * Server-computed, as every `can` on a page is: the shared
             * `auth.permissions` array is for navigation, never for deciding
             * what a screen may do.
             */
            'can' => [
                'chooseFolder' => $request->user()->can('files.read'),
                'createFolder' => $request->user()->can('files.create'),
            ],
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
            'file' => $this->policy->singleRules(),
            /*
             * Where in My Files the bytes land, as a folder's public id.
             * Optional: nothing chosen means the top of the person's own
             * storage, which is where somebody who did not think about it
             * will look for the file.
             */
            'folder' => ['nullable', 'string'],
            'visibility' => ['required', Rule::enum(DocumentVisibility::class)],
            /*
             * Optional at every level, confidential included.
             *
             * It used to be required there, reasoning that a confidential
             * document with nobody tagged is readable by its uploader alone
             * and by no link — not a classification but a document filed
             * where nobody can act on it. That reading of the document was
             * right and the conclusion drawn from it was wrong: a document
             * nobody else can open yet is exactly what filing a confidential
             * document looks like at the moment of upload, and the office
             * often does not know who it goes to until it has been filed.
             *
             * What the rule actually produced was the thing it was meant to
             * prevent. Refusing the upload until a name was typed made the
             * uploader tag whoever came to mind to get past the form, which
             * is a *worse* outcome than nobody: an untagging is a decision
             * somebody has to notice and undo, where an empty list is simply
             * a document waiting to be addressed. The form says so rather
             * than refusing, and tagging somebody later is an edit.
             */
            'recipients' => ['array'],
            'recipients.*' => ['distinct', 'exists:users,id'],
            /*
             * Whether this document collects discussion. Defaults to on
             * when the form omits it, because a feature that has to be
             * switched on per document is one nobody finds — the uploader
             * turns it off for a document that should not gather comment.
             */
            'comments_enabled' => ['boolean'],
        ]);

        $user = $request->user();

        /*
         * Naming recipients *is* sharing a document, so it is what
         * `documents.share` gates. The permission previously gated nothing at
         * all — it was a checkbox on the roles screen with no effect — while
         * the act it is named for went unchecked.
         */
        $visibility = DocumentVisibility::from($validated['visibility']);

        if ($visibility->taggable() && ! $user->can('documents.share')) {
            return back()
                ->withInput()
                ->with('error', 'Your role cannot share a document with named recipients. Upload it as public instead.');
        }

        $this->policy->assertPermitted($request->file('file'), 'file');

        $type = DocumentType::query()->findOrFail($validated['document_type_id']);

        // Onto the private `documents` disk, at a path derived from the row's
        // own opaque identifier. Nothing the uploader typed reaches the
        // filesystem, and nothing under that disk is reachable over the web.
        //
        // Into the folder the uploader picked, or the top of their own
        // storage — the same answer My Files gives, which is the point: a
        // tracked upload is a file in somebody's storage and the register has
        // no business filing it somewhere of its own invention.
        $stored = $this->files->store(
            $request->file('file'),
            $this->destinationFor($user, $validated['folder'] ?? null),
            $user,
        );

        /*
         * Everything after the bytes — the code, the QR Tracker, the document
         * row, the trail, the recipients — is the same work whichever screen
         * the upload came from, so it lives in one place. See
         * App\Services\Documents\DocumentRegistrar.
         */
        $document = $this->registrar->register(
            $stored,
            $type,
            $validated['title'],
            $visibility,
            $validated['recipients'] ?? [],
            $user,
            $request,
        );

        /*
         * Set after registration rather than threaded through the registrar.
         *
         * The registrar is the work that is the same whichever screen an
         * upload came from, and this control is on this form only — My
         * Files' tracking dialog does not offer it. The column defaults to
         * true, so an upload from there gets the default without the
         * registrar knowing the setting exists, and only a deliberate "off"
         * is written here.
         */
        if (($validated['comments_enabled'] ?? true) === false) {
            $document->update(['comments_enabled' => false]);
        }

        // And the general trail, so /activity-trails is the one report that
        // covers everything that happens to a file — tracked or not.
        ActivityLog::record($user, ActivityAction::Uploaded, $stored, [
            'code' => $document->code,
            'tracked' => true,
        ], $request);

        return to_route('documents.success', ['code' => $document->code, 'file_name' => $document->qr_code_path]);
    }

    /**
     * The upload confirmation, with the QR Tracker to print.
     *
     * The symbol is sent as **SVG markup** for the screen — sharp at any size,
     * and no second request — while the Download button fetches the stored
     * PNG. Both encode the same tracking URL; they differ only in what each is
     * good at.
     */
    public function success(string $code, string $file_name): Response
    {
        $document = Document::query()->where('code', $code)->firstOrFail();

        abort_unless($this->owns($document), 404);

        /*
         * What the label actually encodes — the document's share link, or
         * the tracking URL where there is none, which is a confidential
         * document only.
         *
         * `opensWithoutAccount` is narrower than "is this a link": a private
         * document has one, and it does *not* open for whoever holds it. The
         * page needs the distinction because the sentence under the label is
         * the uploader's understanding of what they are about to print, and
         * telling them a private label opens for anyone would be the most
         * expensive wrong sentence on the screen.
         */
        $target = $this->qrLabels->targetFor($document);
        $trackingUrl = $this->qrLabels->trackingUrl($document->code);

        return Inertia::render('documents/upload-success', [
            'code' => $code,
            'fileName' => $file_name,
            'qrCodeSvg' => $this->qrLabels->svg($target),
            'trackingUrl' => $target,
            'opensWithoutAccount' => $document->visibility === DocumentVisibility::Public
                && $target !== $trackingUrl,
            'visibility' => $document->visibility->value,
            'visibilityLabel' => $document->visibility->label(),
        ]);
    }

    /**
     * A document by its tracking code — what a scanned QR label resolves to.
     */
    public function view(Request $request, string $code): Response|RedirectResponse
    {
        /*
         * A guest is sent to the public door, not to a sign-in form.
         *
         * `/track` already answers a code for somebody with no account, and
         * answers it the way this page would have to: a public document is
         * described and previewed, a restricted one states its level and
         * nothing else. Forwarding keeps that one implementation, keeps the
         * throttle that makes walking the series slow, and keeps `AdminShell`
         * — which assumes an account — off a guest's screen.
         *
         * Before the lookup, deliberately. A guest must not be able to tell a
         * live code from a dead one by whether this route 404s, and the
         * public page answers both uniformly.
         */
        if (Auth::guest()) {
            return to_route('tracking.public', ['code' => $code]);
        }

        $document = Document::with(['user:id,name', 'recipients:id,name'])
            ->where('code', $code)
            ->firstOrFail();

        /*
         * The link the reader may be standing on.
         *
         * Resolved before the refusal, because a signed-in person holding a
         * view or download link to a document they are not tagged on has a
         * real grant — it is simply not the document's own rule. They used to
         * be left on the share card, which is the one page in the system that
         * shows a tracked document without saying it is one: no code, no
         * classification, no uploader. Coming in with an account should never
         * be a worse page than coming in without one.
         */
        $link = $this->viewerLink($request, $document);

        if (! $this->isAuthorized($document) && $link !== null) {
            return Inertia::render('documents/show', $this->showPayload($document, $link));
        }

        if (! $this->isAuthorized($document)) {
            /*
             * A page about this document, not a redirect away from it.
             *
             * The refusal used to bounce to the dashboard with an `error`
             * flash. The explanation was right and the place was wrong: the
             * reader lost the code out of the address bar, so a reload, a
             * bookmark or forwarding the URL to the person who *can* grant
             * it no longer carried the one thing the exchange was about, and
             * the ask was offered on a page they had just been moved to.
             *
             * What may be said is unchanged — the code, the level, and
             * `refusalMessage()`, which still distinguishes somebody never
             * tagged from somebody the uploader removed. Never the title,
             * the uploader or the type: telling a person what a document
             * they may not read is about is the disclosure this refusal
             * exists to prevent.
             *
             * The route is behind `auth`, so a signed-out visitor never
             * reaches this; middleware sends them to the sign-in page, and
             * the public door at `/track` is what answers them by code.
             */
            $user = Auth::user();

            return Inertia::render('documents/restricted', [
                'code' => $document->code,
                'visibility' => $document->visibility->value,
                'message' => $this->refusalMessage($document),
                'canRequest' => $user?->can('access-requests.create') ?? false,
                // Asked here so the page can say "already sent" instead of
                // offering a second ask that the endpoint would refuse.
                'pending' => AccessRequest::query()
                    ->where('document_id', $document->id)
                    ->where('requester_id', $user?->id)
                    ->where('status', AccessRequestStatus::Pending)
                    ->exists(),
            ]);
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
        // Bounded and shape-checked exactly as the public door is, so the two
        // cannot disagree about what a tracking code is. See
        // PublicTrackingController and TrackingCodeService::looksLikeCode().
        $code = Str::limit($request->string('code')->trim()->toString(), 64, '');

        if ($code === '') {
            return Inertia::render('documents/track');
        }

        if (! TrackingCodeService::looksLikeCode($code)) {
            return Inertia::render('documents/track', [
                'code' => $code,
                'malformed' => true,
            ]);
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
            /*
             * A page about this document, not a redirect away from it.
             *
             * The refusal used to bounce to the dashboard with an `error`
             * flash. The explanation was right and the place was wrong: the
             * reader lost the code out of the address bar, so a reload, a
             * bookmark or forwarding the URL to the person who *can* grant
             * it no longer carried the one thing the exchange was about, and
             * the ask was offered on a page they had just been moved to.
             *
             * What may be said is unchanged — the code, the level, and
             * `refusalMessage()`, which still distinguishes somebody never
             * tagged from somebody the uploader removed. Never the title,
             * the uploader or the type: telling a person what a document
             * they may not read is about is the disclosure this refusal
             * exists to prevent.
             *
             * The route is behind `auth`, so a signed-out visitor never
             * reaches this; middleware sends them to the sign-in page, and
             * the public door at `/track` is what answers them by code.
             */
            $user = Auth::user();

            return Inertia::render('documents/restricted', [
                'code' => $document->code,
                'visibility' => $document->visibility->value,
                'message' => $this->refusalMessage($document),
                'canRequest' => $user?->can('access-requests.create') ?? false,
                // Asked here so the page can say "already sent" instead of
                // offering a second ask that the endpoint would refuse.
                'pending' => AccessRequest::query()
                    ->where('document_id', $document->id)
                    ->where('requester_id', $user?->id)
                    ->where('status', AccessRequestStatus::Pending)
                    ->exists(),
            ]);
        }

        return Inertia::render('documents/show', $this->showPayload($document));
    }

    public function edit(Request $request, Document $document): Response|RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can edit this document.');
        }

        return Inertia::render('documents/form', [
            'document' => $document->only([
                'id', 'title', 'document_type_id', 'type', 'code', 'file_path', 'file_name', 'visibility',
                // So the toggle opens showing what the document actually
                // holds rather than the default.
                'comments_enabled',
            ]),
            'types' => DocumentType::grouped(),
            'visibilities' => $this->visibilityOptions(),
            /*
             * The same list the upload form gets, because the edit form now
             * offers the same control.
             *
             * It used to be `[]`, which is what made tagging a one-shot
             * decision taken at upload: a document filed before anybody knew
             * who it was for — which the upload form explicitly invites — had
             * no way to be addressed afterwards, and the only correction for
             * a name left off was for that person to request access to a
             * document they had no reason to know existed.
             */
            'users' => $this->taggablePeople($request->user()),
            /*
             * Who the document is currently shared with, from the **live**
             * list: a viewer the uploader removed must not come back as a
             * pre-ticked chip, or saving an unrelated edit would silently
             * re-grant them the document.
             */
            'recipients' => $document->recipients()->pluck('users.id')->all(),
            'limits' => $this->policy->limits(),
        ]);
    }

    /**
     * Everybody the uploader can tag, with a face and an office beside each
     * name — "Dela Cruz" is not enough to pick the right person out of a list
     * of everyone in the agency.
     *
     * One query for both forms: the upload form and the edit form offer the
     * same control, and two hand-built lists is how one screen comes to offer
     * somebody the other does not.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function taggablePeople(User $actor): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereKeyNot($actor->id)
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
            'file' => $this->policy->singleRules(required: false),
            'note' => ['nullable', 'string', 'max:255'],
            /*
             * Who may open a restricted document, editable here.
             *
             * The *level* is still set at upload and is not in this form: a
             * document reclassified under readers who already hold it is a
             * different question, and one with an answer on the share panel
             * (the link) rather than here. Who is tagged is the part the
             * office actually corrects — a name left off, somebody who has
             * moved on, a document filed before anybody knew who it was for,
             * which the upload form invites explicitly by making the list
             * optional at every level.
             *
             * Optional and absent-means-unchanged is deliberately *not* the
             * rule: the form always sends the field, and reading an empty
             * array as "leave as it was" would make untagging the last viewer
             * impossible. A level that tags nobody ignores it entirely — see
             * `syncRecipients()`.
             */
            'recipients' => ['array'],
            'recipients.*' => ['distinct', 'exists:users,id'],
            // Turning comments off closes the thread; it never deletes what
            // was already said. Erasing other people's words as a side
            // effect of a setting is not something a toggle should do.
            'comments_enabled' => ['boolean'],
        ]);

        $type = DocumentType::query()->findOrFail($validated['document_type_id']);
        $replacement = $request->file('file');
        $user = $request->user();

        /*
         * Naming recipients *is* sharing a document, so changing the list is
         * what `documents.share` gates — the same check `store()` makes, for
         * the same act. It is asked only when the list actually changes, so a
         * role that cannot share can still correct a title on a document it
         * uploaded while holding one.
         */
        $taggable = $document->visibility->taggable();
        $current = $taggable ? $document->recipients()->pluck('users.id')->all() : [];
        $wanted = $taggable ? array_map('intval', $validated['recipients'] ?? []) : [];
        $additions = array_values(array_diff($wanted, $current));
        $removals = array_values(array_diff($current, $wanted));

        if (($additions || $removals) && ! $user->can('documents.share')) {
            return back()
                ->withInput()
                ->with('error', 'Your role cannot change who a document is shared with.');
        }

        /*
         * Before the transaction, as every upload path does it: a file the
         * policy refuses must store nothing at all, rather than be rolled back
         * after its bytes are on the disk.
         */
        if ($replacement) {
            $this->policy->assertPermitted($replacement, 'file');
        }

        $notify = DB::transaction(function () use ($request, $document, $type, $validated, $replacement, $additions, $removals) {
            $document->update([
                'title' => $validated['title'],
                'document_type_id' => $type->id,
                'type' => $type->name,
                // An unchecked checkbox posts nothing, so its absence is
                // "off" rather than "leave as it was" — the form always
                // sends the control, and reading it as unchanged would make
                // turning comments off impossible.
                'comments_enabled' => (bool) ($validated['comments_enabled'] ?? false),
            ]);

            /*
             * The recipient rows and their trail entries commit with the rest
             * of the edit, so a replacement that fails cannot leave the
             * document shared with somebody the save never completed for.
             *
             * The people tagged are handed back rather than notified here:
             * a notification sent inside a transaction that then rolls back
             * is a message about something that did not happen, and nothing
             * un-sends it.
             */
            $added = $this->syncRecipients($document, $additions, $removals, $request);

            if (! $replacement) {
                return $added;
            }

            /*
             * A replacement is a **new version of the same file**, not a new
             * file.
             *
             * This used to call `FileStorage::store()`, which created a second
             * `files` row and repointed `documents.file_id` at it — leaving the
             * previous row orphaned with no link back to the document, nothing
             * in either audit trail, no transaction and no thumbnail.
             * `files:purge --orphans` exists to sweep up what that left behind.
             *
             * Going through `FileManager::replace()` means `documents.file_id`
             * never changes, so the tracking code, the stored QR label, the
             * share link and its token, every recipient row and the whole trail
             * are untouched by a replacement — and the file's history records
             * who changed the bytes and why.
             *
             * `TrackingCodeService` is deliberately never called here. The
             * series counter is `max(highest code, counter) + 1` under a row
             * lock and never goes backwards, so issuing a code for a
             * replacement would burn a number *and* change the string already
             * printed on paper.
             */
            if ($document->file) {
                $file = $this->fileManager->replace(
                    $request->user(),
                    $document->file,
                    $replacement,
                    $validated['note'] ?? null,
                    $request,
                );

                $document->update(['file_name' => $file->original_name]);

                return $added;
            }

            /*
             * A legacy or imported row with no `files` row at all — `file_id`
             * is `nullOnDelete`, so this is a reachable state. Its first stored
             * file is version 1, through the ordinary upload path so that it
             * gets a thumbnail and an `uploaded` activity row, both of which
             * the old `FileStorage::store()` call skipped.
             */
            $stored = $this->fileManager->upload(
                $request->user(),
                // A legacy row with no file at all: its first stored file
                // goes to the top of the uploader's storage, because this
                // form has no destination field and inventing a folder for
                // one file is what the register stopped doing.
                $this->folders->rootFor($request->user()),
                $replacement,
                $request,
            );

            $document->update(['file_id' => $stored->id, 'file_name' => $stored->original_name]);

            return $added;
        });

        /*
         * After the commit, for the reason above — and the same notification
         * a tagged recipient gets at upload, because being tagged on an
         * existing document is the same fact arriving later.
         */
        if ($notify->isNotEmpty()) {
            Notification::send($notify, new PrivateDocumentShared($document, $user));
        }

        return to_route('documents.edit', $document)->with('success', 'Document updated.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can delete this document.');
        }

        $document->delete();

        return to_route('documents.index')
            ->with('success', 'Document withdrawn. You can restore it from the Deleted tab.');
    }

    /**
     * Put a withdrawn document back on the register.
     *
     * The mirror of `destroy()`, and gated the same way: the uploader, and
     * nobody else. `owns()` asks the resolver, which reads `user_id` — a
     * column a soft delete does not touch — so a trashed document still knows
     * whose it is.
     *
     * Nothing else has to be undone, which is the point of the delete having
     * been soft. The tracking code, the QR label, the file, every recipient
     * row and the whole trail were never altered; clearing `deleted_at` is
     * the entire act, and the document comes back as the same document rather
     * than as a copy of one.
     *
     * No trail entry, deliberately — and it is the symmetry that decides it,
     * not indifference. Withdrawing a document writes nothing to
     * `document_logs`, so a restore that wrote a row would leave the history
     * saying a document came back from somewhere it is never recorded as
     * having gone. If one of these should be on the trail, both should, and
     * that is a change to what every reader of a document learns.
     */
    public function restore(Document $document): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can restore this document.');
        }

        // Already on the register. A second restore is a no-op rather than an
        // error: the usual way to send one is a double-click.
        if (! $document->trashed()) {
            return back()->with('error', 'That document is already on the register.');
        }

        $document->restore();

        return back()->with('success', $document->code.' is back on the register.');
    }

    /**
     * The camera page.
     *
     * Public, and a guest is forwarded to the public lookup rather than shown
     * this page: the authenticated one is wrapped in AdminShell, which has a
     * sidebar and a navigation grant behind it, and a scan that lands a
     * courier on a sign-in form is no answer to somebody holding the folder.
     * The public page reads a label with the same QrScanner component.
     */
    public function scan(Request $request): Response|RedirectResponse
    {
        if (! $request->user()) {
            return to_route('tracking.public', ['method' => 'scan']);
        }

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
     * Rewrite the stored label for what it should now point at.
     *
     * Called when a link is created or revoked, because those are the two
     * events that change the label's *target*. Editing a link does not: the
     * whole point of an edit is that the URL survives it, so a label printed
     * last year keeps working while its terms change underneath.
     *
     * A failure here must not fail the request that triggered it. The link is
     * the thing that was asked for and it has already been written; a label
     * that could not be rewritten is stale, which the next create or revoke
     * fixes, where a 500 would leave the person believing nothing happened.
     */
    private function refreshLabel(Document $document): void
    {
        $document->unsetRelation('shareLinks');

        try {
            Storage::disk(FileStorage::DISK)->put(
                'qrcodes/'.$this->qrLabels->fileName($document->code),
                $this->qrLabels->png($this->qrLabels->targetFor($document)),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The QR label, for the register's row action.
     *
     * Fetched when somebody asks for it rather than shipped with the listing,
     * for the reason `FilePreview` is: a page of ten rows would otherwise
     * carry ten symbols — each one an SVG with the seal embedded, and for the
     * uploader each one a live credential — on every navigation, nine of them
     * never looked at.
     *
     * **What it encodes depends on who is looking**, exactly as it does on the
     * document page, and it is the same two calls: the uploader gets a symbol
     * for the share link, which is what a label is for and what makes it a
     * credential; everybody else gets one for the tracking URL, which opens
     * nothing without an account and is the string already on their screen.
     * A reader must not be handed a credential by opening a dialog.
     *
     * `can_download` is the *stored PNG*, which is the share-link label on
     * disk — `downloadQr()` answers 404 to anybody but the uploader, so
     * offering the button to a reader would be offering a 404.
     */
    public function qrLabel(Document $document): JsonResponse
    {
        abort_unless($this->isAuthorized($document), 404);

        $manages = $this->owns($document);

        $target = $manages
            ? $this->qrLabels->targetFor($document)
            : $this->qrLabels->trackingUrl($document->code);

        return response()->json([
            'code' => $document->code,
            'svg' => $this->qrLabels->svg($target),
            'target' => $target,
            'can_download' => $manages && $document->qr_code_path !== null,
            'download_url' => $manages && $document->qr_code_path !== null
                ? route('documents.qrcode.download', ['file_name' => $document->qr_code_path])
                : null,
        ]);
    }

    /**
     * The printable QR label.
     *
     * The filename is matched against a stored `qr_code_path` rather than
     * interpolated straight into a path: the value reaches a disk call and
     * this route once handed any filename to storage_path().
     *
     * **Restricted to whoever may share the document**, which is new and is a
     * consequence of what the label now encodes. It used to be the tracking
     * URL — the same string printed beside it on screen, no more sensitive
     * than the code itself. It is the document's share link now, so the PNG
     * *is* a credential, and a route that handed one to any signed-in person
     * who could guess `{CODE}.png` would be a second, quieter way to obtain
     * a link the uploader never sent them.
     */
    public function downloadQr(string $file_name): StreamedResponse|BinaryFileResponse
    {
        $document = Document::query()->where('qr_code_path', $file_name)->first();

        abort_if($document === null, 404, 'QR code not found.');

        // 404 rather than 403, matching the share routes: "not yours" and "no
        // such label" must be indistinguishable, or the difference confirms
        // which codes exist.
        abort_unless($this->owns($document), 404, 'QR code not found.');

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
        // Or the link they came in on — see viewerLink(). The resolver is
        // what reads it, so this is the same gate with the same answer, not
        // a second one beside it.
        $link = $this->viewerLink($request, $document);

        abort_unless($this->isAuthorized($document) || $link !== null, 404);

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
        /*
         * An older version may be asked for by number. Nothing about access
         * changes: the gate above is the document's own rule, and a version has
         * none of its own — see App\Contracts\StoredBytes. A number that is not
         * in this file's history is a 404, never a quiet fall back to the
         * current bytes, which would draw one version while recording another.
         */
        $version = $request->integer('version') ?: null;
        $bytes = $version === null ? $file : $this->versions->find($file, $version);

        abort_if($bytes === null, 404);

        $this->events->recordOnce(
            ActivityAction::Previewed,
            $file,
            $request->user(),
            $request,
            version: $bytes->versionNumber(),
        );

        /*
         * Described **through the link** when that is the grant, which is
         * what points `src` and `download_url` at `/s/{token}/file` instead
         * of the session-bound route — the one that would refuse this reader
         * — and what resolves `can_download` to the link's own term.
         */
        return response()->json($this->preview->describe($file, $request->user(), $link, version: $bytes));
    }

    /**
     * The file's version history, for the panel under the document.
     *
     * Behind the document's own read rule, because "this was revised four
     * times, by these people, for these reasons" is a disclosure about the
     * document — and 404 rather than a redirect because the caller is a fetch
     * behind a button, with nothing to render a flash with. The same shape as
     * `preview()` above, for the same reason.
     *
     * Fetched rather than shipped in `showPayload()`: each entry carries URLs
     * that open bytes, and a page prop would mint them on every visit whether
     * or not anybody opened the panel.
     */
    public function versions(Request $request, Document $document): JsonResponse
    {
        /*
         * Deliberately **not** opened to a link holder.
         *
         * "This was revised four times, by these people, for these reasons"
         * is a disclosure about the document, and a share link reaches the
         * current version only — widening it to the history would widen
         * every token already in circulation. The page hides the panel for
         * the same reason; this is the half that enforces it.
         */
        abort_unless($this->isAuthorized($document), 404);

        $file = $document->file;

        if (! $file) {
            return response()->json(['current' => null, 'can_download' => false, 'can_revert' => false, 'versions' => []]);
        }

        $grant = $this->access->grant($request->user(), $document);
        $canDownload = $grant->allows(FileAbility::Download);
        $stream = fn (array $entry, string $disposition): string => route('documents.accessFile', [
            'document' => $document->id,
            'action' => $disposition,
        ]).($entry['is_current'] ? '' : '?version='.$entry['version']);

        return response()->json([
            'current' => $file->versionNumber(),
            /*
             * Resolved once, against the document, and never per entry: a
             * version carries no rule of its own and a per-entry answer would
             * read as though it might.
             */
            'can_download' => $canDownload,
            'can_revert' => $request->user() !== null
                && $this->fileManager->mayReplace($request->user(), $file),
            'versions' => array_map(fn (array $entry): array => [
                ...$entry,
                'view_url' => $stream($entry, 'view'),
                'download_url' => $canDownload ? $stream($entry, 'download') : null,
            ], $this->versions->history($file)),
        ]);
    }

    /**
     * View or download the file itself.
     *
     * This returns a file response, so it must be reached by a plain link —
     * an Inertia XHR visit cannot follow it.
     */
    public function accessFile(Request $request, Document $document, string $action): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $link = $this->viewerLink($request, $document);

        /*
         * A link holder is held to the link's own term here, not to the
         * reader's set: a `view` link carries no copy, so asking this route
         * for one is refused even though the same person may open the file.
         * The resolver answers both halves; `accessFile` only has to ask the
         * right ability.
         */
        if ($link !== null) {
            $needed = $action === 'download' ? FileAbility::Download : FileAbility::View;

            abort_unless($this->access->allows($request->user(), $document, $needed, $link), 404);
        } elseif (! $this->isAuthorized($document)) {
            // The same two refusals as the page, in the words this route needs.
            // A reader who was taken off has to be told so wherever they hit
            // the wall, or they learn it on one screen and not the other.
            return $this->unauthorizedRedirect(match ($document->refusalReason(Auth::user())) {
                'removed' => 'You were removed as a viewer of this document, so its file can no longer be opened.',
                default => 'This document is restricted to the people tagged on it, so its file cannot be opened.',
            });
        }

        /*
         * An older version, by number — the same door, not a second one.
         *
         * The gate above is unchanged and is the document's own rule, because
         * a version has no access rule of its own. A number that is not in
         * this file's history is a 404 rather than a fall back to the current
         * bytes: serving one version while recording another is the one
         * outcome a versioned trail must not produce.
         */
        $version = $request->integer('version') ?: null;
        $bytes = $version === null || $document->file === null
            ? null
            : $this->versions->find($document->file, $version);

        abort_if($version !== null && $bytes === null, 404);

        $stored = $bytes !== null && $bytes->versionNumber() !== $document->file?->versionNumber()
            ? ['disk' => $bytes->diskName(), 'path' => $bytes->storagePath(), 'name' => $bytes->downloadName()]
            : $document->storedFile();

        // Checked before anything is written, so a missing file never leaves a
        // download recorded that did not happen.
        if (! $stored || ! Storage::disk($stored['disk'])->exists($stored['path'])) {
            return to_route('documents.view', $document->code)
                ->with('error', 'The stored file for this document is missing.');
        }

        $served = $bytes?->versionNumber() ?? $document->file?->versionNumber();

        $log = DocumentLog::record(
            $document,
            $action === 'view' ? DocumentAction::Viewed : DocumentAction::Downloaded,
            $request->user(),
            null,
            $request->ip(),
            null,
            // Which bytes actually left, which for a superseded version is not
            // what the document currently holds.
            $served,
        );

        $this->recordActivity(
            $document,
            $action === 'view' ? ActivityAction::Previewed : ActivityAction::Downloaded,
            $request,
            $served,
        );

        if ($action === 'download') {
            DocumentDownload::register($document, $request->user(), $log, $request->ip());

            // Stamped with the version, exactly as FileStorage::download()
            // does: this route serves bytes straight off the disk because a
            // document may still carry a legacy public-disk path, so the one
            // rule has to be applied here too rather than inherited.
            return Storage::disk($stored['disk'])->download(
                $stored['path'],
                VersionedFilename::stamp($stored['name'], $served),
            );
        }

        return Storage::disk($stored['disk'])->response($stored['path'], $stored['name'], [
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
    private function recordActivity(
        Document $document,
        ActivityAction $action,
        Request $request,
        ?int $version = null,
    ): void {
        ActivityLog::record(
            $request->user(),
            $action,
            $document->file ?? $document,
            ['code' => $document->code, 'tracked' => true],
            $request,
            $version,
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
    private function showPayload(Document $document, ?ShareLink $link = null): array
    {
        /*
         * A link holder never manages, whatever the link says.
         *
         * `ShareAbility::linkable()` already refuses `manage` on a link, so
         * this is belt and braces — but the uploader-only half of this
         * payload is the share panel, the removed-viewer list and the QR
         * symbol that *is* the credential, and none of those may be handed
         * to somebody whose whole claim is a URL.
         */
        $manages = $link === null && $this->owns($document);

        /*
         * A label has to point at a link, so the uploader's view mints one if
         * the document has none.
         *
         * A side effect on a read, which is normally the wrong shape — so it
         * is narrowed to the one case that needs it: the person who may
         * already create a link, on the page that shows them the label.
         * Documents uploaded before labels encoded links are the reason it is
         * here at all; `DocumentRegistrar` mints at upload, so a new document
         * never reaches this. `ensureLinkFor()` returns the existing link when
         * there is one, and null for a confidential document, which may never
         * have one.
         */
        if ($manages) {
            $this->sharing->ensureLinkFor($document, request()->user());
            $document->unsetRelation('shareLinks');
        }

        // Parsed by the full-history controller, not re-derived here: the
        // panel and that page are one list at two lengths, and two parsers
        // over one query string is how a filter works on one screen and
        // quietly not on the other.
        $request = request();
        $trailFilters = DocumentLogController::filters($request);

        return [
            'document' => $document,
            /*
             * Paginated and filterable, through the same `trail()` scope the
             * full-history page uses — they are one list at two lengths, and
             * two hand-built queries over one table is how a search that works
             * on one screen quietly does not on the other.
             *
             * This used to ship **every** row the document had ever collected.
             * On a long-running document that is a payload nobody reads the
             * bottom of, growing without limit.
             */
            /*
             * Empty for a link holder, and empty rather than absent.
             *
             * A document's travel history is exactly as sensitive as the
             * document — a trail listing who received a case file is a
             * disclosure of its own — and a share link grants *the bytes*,
             * never the record of who else has held them. The same goes for
             * the thread and the version history below.
             *
             * The paginator shape is kept so the page has one contract: a
             * prop that is sometimes a paginator and sometimes null is a
             * second shape for every reader of it to remember.
             */
            'logs' => $document->logs()
                ->when($link !== null, fn ($query) => $query->whereRaw('1 = 0'))
                ->trail($trailFilters)
                ->paginate(DocumentLogController::perPage($request), ['*'], 'logs')
                ->withQueryString(),
            'logFilters' => [...$trailFilters, 'entries' => DocumentLogController::perPage($request)],
            // Only the offices this document's trail actually contains.
            'logOffices' => DocumentLog::officesFor($document->id),
            // Derived from the rows, like the offices: a filter must not offer
            // a version the trail holds nothing against.
            'logVersions' => DocumentLog::versionsFor($document->id),
            // The trail split per version, as the panel's tabs. Counted under
            // the filters in force but not under the version itself: a tab
            // states what choosing it would show.
            'logVersionTabs' => DocumentLog::versionTabsFor($document->id, $trailFilters),
            // Every action a trail can contain, not just the postable ones: a
            // filter that cannot find an upload or a download is a filter that
            // lies about what the history holds.
            'logActions' => DocumentAction::all(),
            'actions' => DocumentAction::options(),
            /*
             * The comments, shipped with the page rather than fetched.
             *
             * Unlike the preview descriptor, a comment carries no URL and
             * no credential — it is a name, a time and a paragraph — so
             * there is nothing here that minting on demand would protect.
             * The whole thread goes, not one version's: the panel narrows
             * to the version on screen in the browser, and sending only
             * that version's would mean a fetch every time somebody looked
             * at a different one.
             */
            'comments' => $document->comments()
                ->when($link !== null, fn ($query) => $query->whereRaw('1 = 0'))
                ->with('user:id,name,profile_image,avatar_seed')
                ->get()
                ->map(fn ($comment): array => [
                    'id' => $comment->id,
                    'version' => $comment->version,
                    'body' => $comment->body,
                    // The snapshot, like every other row that records somebody's
                    // doing: the comment still says who wrote it after the
                    // account is closed.
                    'author' => $comment->actor_name ?? 'Unknown',
                    'author_id' => $comment->user_id,
                    /*
                 * The author's face from the **live** account, which is the
                 * one place this deliberately parts company with the audit
                 * trail beside it.
                 *
                 * `document_logs` snapshots the face because a trail must
                 * read the same way next year as it did the day it was
                 * written. A comment is a conversation: the person people
                 * are talking to is whoever that account is *now*, and a
                 * thread showing someone's face from two profile pictures
                 * ago would be the odd one. Nothing is lost when the
                 * account goes — `avatar.js` derives a distinct, stable
                 * face from `actor_name` alone, which is the snapshot that
                 * matters here.
                 */
                    'author_image' => $comment->user?->profile_image,
                    'author_seed' => $comment->user?->avatar_seed,
                    'created_at' => $comment->created_at,
                    // Resolved here, because who may delete a comment is a
                    // per-record question the page cannot answer.
                    'can_delete' => $comment->user_id !== null
                        && ((int) $comment->user_id === (int) request()->user()?->id || $manages),
                ])->all(),
            // Off for a link holder: a share-link visitor may not comment —
            // an unverified name in a thread of real ones is a statement
            // somebody may act on — and an account reached through a link is
            // still here on the link's authority, not the document's.
            'commentsEnabled' => $link === null && (bool) $document->comments_enabled,
            /*
             * Here on a link rather than on the document's own rule.
             *
             * The page needs it for two different things: to hide what the
             * link does not carry, and to send the token back with the
             * requests that fetch the bytes — the descriptor and Download
             * both resolve through `AccessResolver`, which cannot see a
             * token that is not in the request.
             */
            'viaLink' => $link !== null,
            'linkToken' => $link?->token,
            /*
             * The QR Tracker, as SVG markup for the modal to render inline.
             *
             * **What it encodes depends on who is looking, and that is the
             * whole of it.** The label points at the document's share link, so
             * it opens for the courier holding the folder — which means the
             * symbol is now a credential, exactly like the URL beside it. The
             * uploader gets that one; everybody else gets a symbol for the
             * tracking URL, which opens nothing without an account and is the
             * same string already printed on their screen.
             *
             * Sent with the page rather than fetched because, for the person
             * it is sent to, it is a credential they already hold: the share
             * panel on the same page lists the link in full.
             */
            'qrCodeSvg' => $this->qrLabels->svg($manages
                ? $this->qrLabels->targetFor($document)
                : $this->qrLabels->trackingUrl($document->code)),
            // What the QR above resolves to, printed beside it so somebody can
            // read or copy what they are about to hand out.
            'trackingUrl' => $manages
                ? $this->qrLabels->targetFor($document)
                : $this->qrLabels->trackingUrl($document->code),
            'visibility' => [
                'value' => $document->visibility->value,
                'label' => $document->visibility->label(),
                'description' => $document->visibility->description(),
                'tone' => $document->visibility->tone(),
                'linkable' => $document->visibility->linkable(),
                // Whether this level is a list of names, which is what makes
                // the restricted link term meaningful: on a public document
                // it would mean "only people who may read this", which is
                // everybody, so the dialog does not offer it there.
                'restricted' => $document->visibility->restrictedToNamedPeople(),
            ],
            /*
             * Only to the uploader, and only ever the links themselves — never
             * for a reader. A share link's URL *is* the credential, so listing
             * one to somebody who merely has read access would hand them a way
             * to pass that access on, which is the uploader's decision to make.
             */
            'links' => $manages ? $this->linkPayload($document) : [],
            /*
             * The directory, for the share dialog's recipient picker.
             *
             * To the uploader alone, and for the same reason `links` is:
             * everything on this side of the payload is the uploader's own
             * controls. It is the same list the upload and edit forms carry,
             * from the same `taggablePeople()`, so the three cannot offer
             * different people.
             */
            'taggable' => $manages ? $this->taggablePeople(Auth::user())->all() : [],
            /*
             * The viewers taken off, to the uploader only.
             *
             * It is the uploader's own record of a decision they made, and the
             * screen that lets them remove somebody is the screen that has to
             * show it happened — otherwise the chip simply vanishes and there
             * is nothing to tell them whether they removed the right person.
             * Not sent to a reader: who *used* to be on a document's list is
             * more than reading the document discloses.
             */
            'removedViewers' => $manages
                ? $document->removedViewers()->get(['users.id', 'users.name'])
                    ->map(fn (User $viewer) => [
                        'id' => $viewer->id,
                        'name' => $viewer->name,
                        'removed_at' => $viewer->pivot->revoked_at,
                    ])->values()->all()
                : [],
            'can' => [
                'manage' => $manages,
                /*
                 * The link's own term, where there is one.
                 *
                 * `true` is right for a reader admitted by the document —
                 * `READER_ABILITIES` carries download, and the uploader has
                 * everything — but a **view** link deliberately carries no
                 * copy, and the button has to say so rather than offering
                 * one the stream route then refuses.
                 */
                'download' => $link === null
                    || $this->access->allows(request()->user(), $document, FileAbility::Download, $link),
                // Two conditions, and they are different questions: may this
                // person share at all, and may *this document* be shared by
                // link. A confidential document refuses the second however
                // senior the person is.
                'link' => $manages
                    && $document->visibility->linkable()
                    && Auth::user()?->can('documents.share'),
            ],
        ];
    }

    /**
     * The document's live share links, for the uploader's panel.
     *
     * @return list<array<string, mixed>>
     */
    private function linkPayload(Document $document): array
    {
        return $document->shareLinks()
            ->whereNull('revoked_at')
            ->latest('id')
            ->get()
            ->map(fn (ShareLink $link) => [
                'id' => $link->id,
                'url' => $link->url(),
                'ability' => $link->ability->value,
                'ability_label' => $link->ability->label(),
                'expires_at' => $link->expires_at,
                'max_views' => $link->max_views,
                'view_count' => $link->view_count,
                'usable' => $link->isUsable(),
            ])
            ->values()
            ->all();
    }

    /**
     * Mint a link that opens this document without an account.
     *
     * Its own endpoint rather than the file manager's, because the subject is
     * the **document**: that is what somebody decided to share, what the trail
     * is written about, and what carries the classification the link has to
     * obey. A link minted against the document's `File` row instead would
     * answer to the file's visibility and become exactly the looser second door
     * to a tracked document that AccessResolver::forFile() exists to prevent.
     */
    public function storeLink(Request $request, Document $document): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can share this document.');
        }

        if (! $request->user()->can('documents.share')) {
            return back()->with('error', 'Your role cannot share documents.');
        }

        // The same refusal the service throws, turned into the flash the shell
        // already knows how to render rather than a 500.
        if (! $document->visibility->linkable()) {
            return back()->with('error', 'A confidential document cannot be shared through a link. Tag the person instead.');
        }

        $validated = $request->validate([
            // Only the terms a link may ever carry; SharingService refuses the
            // rest again.
            'ability' => ['required', Rule::in(ShareAbility::linkableValues())],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:10000'],
            // Absent means "leave the list alone"; present means the dialog
            // offered the picker and this is the whole list. See
            // tagFromLinkDialog().
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['integer', 'exists:users,id'],
        ]);

        // One live link per document — the page offers Change when there is
        // one, so arriving here with one already means a stale screen.
        if ($this->sharing->activeLinkFor($document) !== null) {
            return back()->with('error', 'This document already has a share link. Change or revoke that one.');
        }

        $link = $this->sharing->createLink(
            $document,
            ShareAbility::from($validated['ability']),
            $request->user(),
            // `?? null`, not `$validated['expires_at']`: a `nullable` field
            // that was not posted at all is **absent** from the validated
            // array rather than null, so the direct read is an undefined-key
            // error — a 500 on the ordinary path of creating a link with no
            // expiry.
            isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
            $validated['max_views'] ?? null,
        );

        DocumentLog::record($document, DocumentAction::LinkShared, $request->user(), $link->ability->label().' link created', $request->ip());

        $this->refreshLabel($document);

        // After the rows are committed, never inside: a notification sent in
        // a transaction that then rolls back is a message about something
        // that did not happen.
        $notify = $this->tagFromLinkDialog($request, $document, $validated);

        if ($notify->isNotEmpty()) {
            Notification::send($notify, new PrivateDocumentShared($document, $request->user()));
        }

        return back()->with('success', 'Share link created: '.$link->url());
    }

    /**
     * Revoke one.
     *
     * Revoking stays available whatever the document's classification is — a
     * document reclassified as confidential can no longer mint links, and its
     * existing ones stop working at that moment, but the uploader must still be
     * able to see and clear them.
     */
    /**
     * Change the terms of this document's link, keeping its URL.
     *
     * Revoke-and-recreate would break every message the old URL was pasted
     * into, which is not what somebody narrowing a link is asking for.
     */
    public function updateLink(Request $request, Document $document, ShareLink $link): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can share this document.');
        }

        // The link has to belong to this document, for the same reason revoke
        // checks it: otherwise an identifier from one document edits another's.
        abort_unless(
            $link->shareable_type === Document::class && (int) $link->shareable_id === $document->id,
            404,
        );

        // Re-checked rather than assumed from when it was minted: a document
        // can be reclassified confidential afterwards, and widening its link
        // must not be the way back in.
        if (! $document->visibility->linkable()) {
            return back()->with('error', 'A confidential document cannot be shared through a link. Tag the person instead.');
        }

        $validated = $request->validate([
            'ability' => ['required', Rule::in(ShareAbility::linkableValues())],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:10000'],
            // Absent means "leave the list alone"; present means the dialog
            // offered the picker and this is the whole list. See
            // tagFromLinkDialog().
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['integer', 'exists:users,id'],
        ]);

        $this->sharing->updateLink(
            $link,
            ShareAbility::from($validated['ability']),
            $request->user(),
            isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
            $validated['max_views'] ?? null,
        );

        DocumentLog::record($document, DocumentAction::LinkShared, $request->user(), 'Share link terms changed', $request->ip());

        $notify = $this->tagFromLinkDialog($request, $document, $validated);

        if ($notify->isNotEmpty()) {
            Notification::send($notify, new PrivateDocumentShared($document, $request->user()));
        }

        return back()->with('success', 'Share link updated.');
    }

    /**
     * Tag people from the share dialog, when the term needs a list.
     *
     * **This is not a third place to grant access.** The rule is that
     * sharing is granted where the thing is made, and the edit form is that
     * form reopened; this is the same again, for the one moment that
     * *creates the need*. Choosing `restricted` on a public document says
     * "only the people tagged on this" — and a public document usually has
     * nobody tagged, so the control, taken alone, closes the document to its
     * uploader and to literally nobody else. Making somebody leave the
     * dialog, find the edit form and come back is how a document ends up
     * sealed with an empty list and a confused office.
     *
     * The whole list is on screen while they change it, which is the
     * property the old share panel's picker lacked and the reason that one
     * was removed. Everything underneath is the edit form's own path: a
     * **diff**, so a link edit that does not touch the list re-notifies
     * nobody; removals through `revokeViewer()`, so a dropped viewer is
     * revoked rather than deleted and the next refusal can still tell them
     * apart; additions through `DocumentRegistrar::tag()`, which is where
     * clearing `revoked_at` on re-tagging lives.
     *
     * Absent from the payload means "leave the list alone", which is what
     * every other caller of these endpoints sends.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User> the people to notify, after commit
     */
    private function tagFromLinkDialog(Request $request, Document $document, array $validated): \Illuminate\Database\Eloquent\Collection
    {
        if (! array_key_exists('recipients', $validated)) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        $current = $document->recipients()->pluck('users.id')->all();
        $wanted = array_map('intval', $validated['recipients'] ?? []);

        return DB::transaction(fn () => $this->syncRecipients(
            $document,
            array_values(array_diff($wanted, $current)),
            array_values(array_diff($current, $wanted)),
            $request,
        ));
    }

    /**
     * Give this document's link a new address, keeping its terms.
     *
     * The act an uploader wants when a URL has travelled further than they
     * meant it to. Revoking and creating again would do it, and it is the
     * wrong shape for two reasons: it leaves the document briefly with no
     * link at all — which a public document's printed label points at — and
     * it starts a fresh row, so the screen can no longer say what the terms
     * were before. One link, re-addressed.
     *
     * POST rather than PATCH because it is not an edit of the terms: the one
     * thing it changes is the credential, and every copy of the old URL stops
     * working at that moment. That is a hand-off ending, so it is written to
     * the document's own trail as well as to the activity log — a reader
     * holding a dead link is entitled to find out from the document's history
     * why it stopped.
     */
    public function regenerateLink(Request $request, Document $document, ShareLink $link): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can share this document.');
        }

        // The link has to belong to this document, for the same reason revoke
        // checks it: otherwise an identifier from one document re-addresses
        // another's.
        abort_unless(
            $link->shareable_type === Document::class && (int) $link->shareable_id === $document->id,
            404,
        );

        $spent = $link->view_count;

        $this->sharing->regenerateLink($link, $request->user());

        /*
         * The remark says what ended, never the token — old or new. An audit
         * row carrying a live credential is a way to obtain that credential by
         * reading the trail, and the trail is shown to everybody who may read
         * the document.
         */
        DocumentLog::record(
            $document,
            DocumentAction::LinkRegenerated,
            $request->user(),
            'Share link given a new address; the previous one stopped working after '.$spent.' '.($spent === 1 ? 'open' : 'opens'),
            $request->ip(),
        );

        // The stored label encodes the link, so a new address is a new label.
        // Without this the printed PNG points at a URL that now 404s.
        $this->refreshLabel($document);

        return back()->with('success', 'The link has a new address. Anyone holding the old one can no longer open it.');
    }

    public function destroyLink(Request $request, Document $document, ShareLink $link): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can share this document.');
        }

        // The link has to belong to this document. Without it, an identifier
        // from one document would revoke another's.
        abort_unless(
            $link->shareable_type === Document::class && (int) $link->shareable_id === $document->id,
            404,
        );

        $this->sharing->revokeLink($link, $request->user());

        DocumentLog::record($document, DocumentAction::LinkRevoked, $request->user(), 'Share link revoked', $request->ip());

        $this->refreshLabel($document);

        return back()->with('success', 'Link revoked.');
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

    /**
     * The share link this request is standing on, if any.
     *
     * A signed-in person holding a view or download link to a document they
     * are not tagged on is sent **into this module** rather than to the share
     * card — the card is a worse version of the page they can have, with no
     * details and no classification — and the token comes with them so the
     * one thing granting them access is still in the request. The resolver
     * does the whole job from there: `fromLink()` checks the link is usable,
     * that it covers *this* document, that the document may still be linked
     * at all, and what the term means. Nothing here restates any of that.
     *
     * Null for a token that is missing, unknown, revoked, expired, spent,
     * pointed at another document, or restricted and held by somebody it is
     * not for. Every one of those answers the same way, which is the rule a
     * bearer URL lives by.
     */
    private function viewerLink(Request $request, Document $document): ?ShareLink
    {
        $token = $request->string('token')->toString();

        if ($token === '') {
            return null;
        }

        $link = $this->sharing->resolveLink($token);

        if ($link === null) {
            return null;
        }

        return $this->access->allows($request->user(), $document, FileAbility::View, $link)
            ? $link
            : null;
    }

    /**
     * Refuse a read, and — when there is something to ask for — say so.
     *
     * The redirect and the `error` flash are unchanged, because
     * `DocumentAuthorizationTest` asserts exactly that shape and it is the
     * right shape: the person is looking at a page and deserves an
     * explanation, not a 403.
     *
     * What rides alongside is `$askableCode`, carried in its own session key
     * rather than as a third flash key — `success` and `error` being the only
     * two is a rule worth keeping, and this is not a message. It holds the
     * **code only**: never the title, which would disclose the subject of a
     * document to somebody just refused it, and which they do not need because
     * they already have the code.
     */
    /**
     * Why this reader is being refused, in the words that fit their case.
     *
     * Two different facts were being reported with one sentence. Somebody who
     * was never tagged is told the document is restricted and invited to ask —
     * which is the right answer, and the one `AccessRequestTest` asserts.
     * Somebody the uploader *took off* the list is being told the same thing,
     * which reads as an oversight they can clear up, when in fact a decision
     * was made about them. Naming it is not a courtesy: a reader who does not
     * know they were removed will go on asking the wrong person why a link
     * they used yesterday stopped working.
     *
     * It discloses nothing new. Being told you were removed as a viewer is
     * only ever told to somebody who *was* a viewer, so it reveals nothing
     * about the document that they did not already have.
     */
    private function refusalMessage(Document $document): string
    {
        return match ($document->refusalReason(Auth::user())) {
            'removed' => 'You were removed as a viewer of this document. '
                .'Ask the person who uploaded it if you still need access.',
            default => 'This document is restricted to the people tagged on it. '
                .'Ask the person who uploaded it to add you.',
        };
    }

    /**
     * Take a named viewer off a restricted document.
     *
     * Granting access happens where the document is made — that is the rule
     * the upload form and the New folder dialog implement, and this does not
     * reopen it. **Taking access away is the half that has to be possible from
     * wherever you can see it**, which is the same reason the share panel kept
     * its revoke button when it lost its picker. Until now it was possible
     * nowhere at all: nothing in the application could detach a recipient, so
     * a document tagged with somebody by mistake stayed tagged with them.
     *
     * The row is kept and stamped rather than deleted, so the trail can say a
     * removal happened and the next refusal can say which refusal it is.
     */
    public function destroyRecipient(Request $request, Document $document, User $user): RedirectResponse
    {
        if (! $this->owns($document)) {
            return $this->unauthorizedRedirect('Only the uploader can change who this document is shared with.');
        }

        // Asked of the live list, so a second removal of the same person is a
        // no-op rather than a second trail row saying it happened twice.
        if (! $document->recipients()->whereKey($user->getKey())->exists()) {
            return back()->with('error', 'That person is not currently a viewer of this document.');
        }

        $this->revokeViewer($document, $user, $request);

        return back()->with('success', $user->name.' can no longer open this document.');
    }

    /**
     * Take one named viewer off a document.
     *
     * The one place the stamp and its trail row are written, because there are
     * two doors onto this act now — the chip's own remove button on the
     * document page and the edit form's picker — and a removal recorded by one
     * and not the other is a trail that overstates who can read the document.
     *
     * The row is **stamped, not deleted**: a deleted row cannot tell somebody
     * the uploader took off from somebody never tagged, which is the whole
     * reason `revoked_at` exists. See `Document::wasRemovedAsViewer()`.
     *
     * The trail entry is a hand-off in reverse, and the name goes in the
     * remarks because, like every other row there, it must still read
     * correctly after that account is gone.
     */
    private function revokeViewer(Document $document, User $viewer, Request $request): void
    {
        $document->allRecipients()->updateExistingPivot($viewer->getKey(), [
            'revoked_at' => now(),
            'revoked_by' => $request->user()->id,
        ]);

        DocumentLog::record(
            $document,
            DocumentAction::ViewerRemoved,
            $request->user(),
            $viewer->name.' was removed as a viewer.',
            $request->ip(),
        );
    }

    /**
     * Bring a document's recipient list to what the edit form asked for.
     *
     * A diff rather than a sync, and that is the point: `syncWithoutDetaching`
     * through the registrar is what adds, `revokeViewer()` is what removes,
     * and a person already on the list is touched by neither — so saving an
     * unrelated edit cannot re-notify everybody tagged or re-stamp rows that
     * did not change.
     *
     * Adding goes through `DocumentRegistrar::tag()` because that is where
     * clearing `revoked_at` lives: re-tagging somebody who was removed is an
     * *update* of the existing row — `(document_id, user_id)` is unique — and
     * restating that here is how the two come apart.
     *
     * Notifications are the caller's job; see `update()`.
     *
     * @param  list<int>  $additions
     * @param  list<int>  $removals
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function syncRecipients(Document $document, array $additions, array $removals, Request $request): \Illuminate\Database\Eloquent\Collection
    {
        foreach (User::findMany($removals) as $viewer) {
            $this->revokeViewer($document, $viewer, $request);
        }

        $added = User::findMany($additions);

        if ($added->isNotEmpty()) {
            $this->registrar->tag($document, $added, notify: false);

            /*
             * One row per person, with the name in the remarks, matching the
             * removal exactly. A single row saying "three viewers added"
             * would leave the trail unable to answer when a *particular*
             * person was given the document, which is the question it exists
             * for.
             *
             * Tagging at upload writes none of these: the `Uploaded` entry
             * already says the document was filed with the list it carries,
             * and a row per name beside it is the same fact twice.
             */
            foreach ($added as $viewer) {
                DocumentLog::record(
                    $document,
                    DocumentAction::ViewerAdded,
                    $request->user(),
                    $viewer->name.' was added as a viewer.',
                    $request->ip(),
                );
            }
        }

        return $added;
    }

    private function unauthorizedRedirect(string $message, ?string $askableCode = null): RedirectResponse
    {
        $redirect = (Auth::check() ? to_route('dashboard') : to_route('login'))->with('error', $message);

        return $askableCode !== null && Auth::check()
            ? $redirect->with('access_request', ['code' => $askableCode])
            : $redirect;
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
            'tone' => $visibility->tone(),
            // The form shows the recipient picker and the link note from these
            // rather than from a list of level names written into the template.
            // A fourth level would otherwise be correct on the server and
            // invisible in the browser.
            'taggable' => $visibility->taggable(),
            'linkable' => $visibility->linkable(),
        ], DocumentVisibility::cases());
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
