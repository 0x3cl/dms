<?php

namespace App\Http\Controllers;

use App\Enums\DocumentVisibility;
use App\Enums\FileAbility;
use App\Enums\FileCategory;
use App\Enums\FileVisibility;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\AccessSource;
use App\Services\Files\FileManager;
use App\Services\Files\UploadPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browsing storage: your own — which now includes what has been shared with you
 * — other people's, and your trash.
 *
 * Every listing goes through `AccessResolver::scopeVisible()`. Fetching broadly
 * and filtering in PHP afterwards is the leak: the paginator totals come out
 * wrong, and those totals disclose existence even when the row is never
 * rendered.
 *
 * **Shared with Me is a filter here, not a screen.** It used to be its own page
 * on its own permission, which meant a file shared with you lived somewhere you
 * had to go and look rather than in your files. The merge is one rule: at the
 * top of your own storage, the items other people have shared *with you by
 * name, division, section or role* are listed beside your own, and from there
 * they navigate like anything else. Deeper folders list only their contents,
 * because a shared entry point repeated at every level is noise.
 *
 * Each row says which of the two it is — `access`, resolved from the grant's
 * own source, not guessed from ownership — so the badge on the card is the same
 * answer the resolver gave.
 */
class FileManagerController extends Controller
{
    /** Shared by both views, so switching never changes what is on the page. */
    private const ENTRY_OPTIONS = [12, 24, 48];

    private const SORTS = ['name', 'size', 'updated', 'created', 'deleted'];

    private const ACCESS_FILTERS = ['all', 'mine', 'shared'];

    private const KIND_FILTERS = ['all', 'file', 'folder'];

    /**
     * Share rows already looked up while building a page of payloads.
     *
     * A grant carries the id of the share it came from, and the card wants the
     * name of whoever gave it. Memoised because a folder shared with a division
     * is one row behind every file inside it, so twenty cards are one query
     * rather than twenty.
     *
     * @var array<int, ItemShare|null>
     */
    private array $shareMemo = [];

    public function __construct(
        private readonly AccessResolver $access,
        private readonly FileManager $manager,
    ) {}

    /** The signed-in user's own tree, plus what has been shared with them. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $folder = $this->locateFolder($request, $user);

        return Inertia::render('files/index', [
            ...$this->listing($request, $folder, $user),
            'folder' => $this->folderPayload($folder, $user),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'visibilities' => $this->visibilityOptions(),
            'categories' => FileCategory::options(),
            /*
             * What the upload dialog may offer, from the administrator's policy
             * rather than from a literal in the template. The dialog had no
             * `accept` at all, so the browser offered every file on the machine
             * and the policy's refusal arrived only after the upload.
             */
            'limits' => app(UploadPolicy::class)->limits(),
            /*
             * For the recipient picker on the New folder dialog — the same
             * shape documents/form.js gets, so the component is the same one.
             *
             * **Optional**, so it is resolved only when the page asks for it.
             * My Files is the screen people sit on, and shipping the whole
             * directory with every folder navigation is a cost paid constantly
             * for a dialog opened rarely — the same reason the share drawer
             * fetches its own state instead of taking page props.
             */
            'people' => Inertia::optional(fn () => $this->directory($user)),
            /*
             * The document fields the upload dialog offers when "Track this
             * document" is on. Lazy for the same reason `people` is: shipping
             * every type and every access level with each folder navigation is
             * a cost paid constantly for a dialog opened rarely.
             */
            // The same grouped arrangement the upload form offers — one list,
            // one grouping, built once on the server.
            'documentTypes' => Inertia::optional(fn () => DocumentType::grouped()),
            'documentVisibilities' => Inertia::optional(fn () => array_map(
                fn (DocumentVisibility $level): array => [
                    'value' => $level->value,
                    'label' => $level->label(),
                    'description' => $level->description(),
                    'linkable' => $level->linkable(),
                    'taggable' => $level->taggable(),
                ],
                DocumentVisibility::cases(),
            )),
            // Whether to offer the control at all. The route gates
            // `files.create`; issuing a tracking code is a different grant.
            'canTrackDocuments' => $user->can('documents.create'),
            'can' => [
                'create' => $user->can('files.create'),
                'upload' => $user->can('files.create'),
                'edit' => $user->can('files.edit'),
                'delete' => $user->can('files.delete'),
                'share' => $user->can('files.share'),
                'download' => $user->can('files.download'),
            ],
        ]);
    }

    /**
     * Other people's storage.
     *
     * The account list is itself scoped: only users with at least one item this
     * person may see are offered, so the page cannot be used to enumerate who
     * has storage, let alone what is in it.
     *
     * Gated on `files.manage` — the administrator's reach — which is the same
     * permission AccessResolver resolves that reach by, so the screen and the
     * rule cannot be granted apart.
     */
    public function browse(Request $request): Response
    {
        $user = $request->user();
        $ownerPublicId = $request->string('owner')->toString();

        $owners = $this->browsableOwners($user);
        $owner = $ownerPublicId !== ''
            ? $owners->firstWhere('public_id', $ownerPublicId)
            : null;

        $folder = null;
        $listing = [
            'files' => null,
            'folders' => collect(),
            'filters' => $this->filters($request),
            'entriesOptions' => self::ENTRY_OPTIONS,
            'owners' => collect(),
        ];

        if ($owner) {
            $target = User::query()->where('public_id', $owner['public_id'])->firstOrFail();
            $folder = $this->locateFolder($request, $target);
            $listing = $this->listing($request, $folder, $user);
        }

        return Inertia::render('files/browse', [
            ...$listing,
            'owners' => $owners->values(),
            'owner' => $owner,
            'breadcrumbs' => $folder ? $this->breadcrumbs($folder) : [],
            'folder' => $folder ? $this->folderPayload($folder, $user) : null,
        ]);
    }

    /**
     * The user's own trash, which is navigable.
     *
     * Deleting a folder soft-deletes its whole subtree, so a flat list of every
     * trashed row showed a deleted folder's hundred files as a hundred separate
     * entries with no way to tell they were one act. The top level now lists
     * only items whose container is *not* itself trashed — the things that were
     * actually deleted — and a trashed folder can be opened to see what went
     * with it.
     *
     * Everything stays deleted while it is browsed: these listings are
     * `onlyTrashed()`, so nothing here is reachable by its ordinary route and
     * nothing is quietly restored by looking at it.
     */
    public function trash(Request $request): Response
    {
        $user = $request->user();
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        // The trash's own default: what you threw away last is what you came
        // looking for, which is not true of a folder you are browsing.
        $sort = $request->string('sort')->toString() === '' ? 'deleted' : $this->sort($request);
        $direction = $request->string('direction')->toString() === '' && $sort === 'deleted'
            ? 'desc'
            : $this->direction($request);
        $folder = $this->locateTrashedFolder($request, $user);

        $files = File::onlyTrashed()
            ->where('user_id', $user->id)
            ->matching($search)
            ->when(
                $folder,
                fn (Builder $query) => $query->where('folder_id', $folder->id),
                fn (Builder $query) => $query->whereNotIn(
                    'folder_id',
                    Folder::onlyTrashed()->where('user_id', $user->id)->select('id')
                ),
            );

        $folders = Folder::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('is_root', false)
            ->matching($search)
            ->when(
                $folder,
                fn (Builder $query) => $query->where('parent_id', $folder->id),
                // The top of the trash: only folders whose own parent is still
                // there. A subtree appears once, at the folder that was
                // deleted, rather than once per level.
                fn (Builder $query) => $query->whereNotIn(
                    'parent_id',
                    Folder::onlyTrashed()->where('user_id', $user->id)->select('id')
                ),
            );

        return Inertia::render('files/trash', [
            'files' => $this->applySort($files, $sort, $direction)
                ->paginate($entries)
                ->withQueryString()
                ->through(fn (File $file) => $this->trashedFilePayload($file, $user)),
            'folders' => $folders->orderBy('name')->get()
                ->map(fn (Folder $child) => $this->trashedFolderPayload($child, $user)),
            'folder' => $folder ? [
                'public_id' => $folder->public_id,
                'name' => $folder->name,
                'is_root' => false,
                'is_mine' => $folder->user_id === $user->id,
            ] : null,
            'breadcrumbs' => $folder ? $this->trashedBreadcrumbs($folder) : [],
            'filters' => [...$this->filters($request), 'sort' => $sort, 'direction' => $direction],
            'entriesOptions' => self::ENTRY_OPTIONS,
            'can' => [
                'restore' => $user->can('files.edit'),
                'purge' => $user->can('files.delete'),
            ],
        ]);
    }

    /**
     * One folder's contents, as both views need it.
     *
     * Folders are returned whole rather than paginated: a folder list is short,
     * it is the navigation, and paginating it would hide the way out of a deep
     * tree behind a page link.
     *
     * @return array<string, mixed>
     */
    private function listing(Request $request, Folder $folder, User $user): array
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $sort = $this->sort($request);
        $direction = $this->direction($request);
        $access = $this->accessFilter($request);
        $kind = $this->kindFilter($request);
        $category = $this->category($request);
        $ownerId = $this->ownerFilter($request);
        [$from, $to] = $this->dateRange($request);

        /*
         * Shared entry points, and only at the top of the person's own storage.
         *
         * `ItemShare` directly rather than scopeVisible: this is the narrower
         * question of what somebody *chose to give* this person, which is not
         * the same as everything they may see — that would also pull in every
         * public item in the agency and, for an administrator, everyone's
         * storage.
         */
        $atOwnRoot = $folder->is_root && $folder->user_id === $user->id;
        $sharedFileIds = $atOwnRoot ? $this->sharedIds($user, File::class) : collect();
        $sharedFolderIds = $atOwnRoot ? $this->sharedIds($user, Folder::class) : collect();

        $files = $this->access->scopeVisible(File::query(), $user)
            /*
             * Eager-loaded so the tracked badge costs one query, not one per
             * row — `File::isTracked()` runs an exists() each time it is asked.
             *
             * **Do not narrow this to a column list.** `AccessResolver::forFile()`
             * reads `$file->document` and then asks it for `visibility`,
             * `user_id` and its recipients; a partial select leaves those null
             * and the resolver fatals on the first tracked row. A column
             * subset here was a 500 on every listing that contained one.
             */
            ->with('document')
            ->where(fn (Builder $query) => $query
                ->where('folder_id', $folder->id)
                ->when($sharedFileIds->isNotEmpty(), fn (Builder $inner) => $inner->orWhereIn('id', $sharedFileIds)))
            ->matching($search)
            ->when($access === 'mine', fn (Builder $query) => $query->where('user_id', $user->id))
            ->when($access === 'shared', fn (Builder $query) => $query->where('user_id', '!=', $user->id))
            ->when($ownerId, fn (Builder $query) => $query->where('user_id', $ownerId))
            ->when($category, fn (Builder $query) => $category->scope($query))
            ->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->with('owner:id,public_id,name');

        $folders = $this->access->scopeVisible(Folder::query(), $user)
            ->where(fn (Builder $query) => $query
                ->where('parent_id', $folder->id)
                ->when($sharedFolderIds->isNotEmpty(), fn (Builder $inner) => $inner->orWhereIn('id', $sharedFolderIds)))
            ->matching($search)
            ->when($access === 'mine', fn (Builder $query) => $query->where('user_id', $user->id))
            ->when($access === 'shared', fn (Builder $query) => $query->where('user_id', '!=', $user->id))
            ->when($ownerId, fn (Builder $query) => $query->where('user_id', $ownerId))
            // A folder has no extension and no size, so a file-type filter
            // cannot describe one: filtering by type means the person is
            // looking for files, and folders drop out rather than being
            // listed as if they matched.
            ->when($category || $kind === 'file', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($kind === 'folder', fn (Builder $query) => $query)
            ->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->with('owner:id,public_id,name');

        if ($kind === 'folder') {
            $files->whereRaw('1 = 0');
        }

        return [
            'files' => $this->applySort($files, $sort, $direction)
                ->paginate($entries)
                ->withQueryString()
                ->through(fn (File $file) => $this->filePayload($file, $user)),
            'folders' => $folders->orderBy('name')->get()
                ->map(fn (Folder $child) => $this->folderCardPayload($child, $user)),
            'filters' => $this->filters($request),
            'entriesOptions' => self::ENTRY_OPTIONS,
            // Who the filter can narrow to: the people whose items are actually
            // reachable from this view. Offering every account would say who
            // has storage to anyone who opens a dropdown.
            'owners' => $this->listingOwners($user, $sharedFileIds, $sharedFolderIds),
        ];
    }

    /**
     * The ids of one kind of item shared with this person right now.
     *
     * @return Collection<int, int>
     */
    private function sharedIds(User $user, string $type): Collection
    {
        return ItemShare::query()
            ->active()
            ->reaching($user)
            ->where('shareable_type', $type)
            ->pluck('shareable_id');
    }

    /**
     * @param  Collection<int, int>  $sharedFileIds
     * @param  Collection<int, int>  $sharedFolderIds
     * @return Collection<int, array<string, mixed>>
     */
    private function listingOwners(User $user, Collection $sharedFileIds, Collection $sharedFolderIds): Collection
    {
        $ids = File::query()->whereKey($sharedFileIds)->distinct()->pluck('user_id')
            ->merge(Folder::query()->whereKey($sharedFolderIds)->distinct()->pluck('user_id'))
            ->push($user->id)
            ->filter()
            ->unique();

        return User::query()
            ->whereKey($ids)
            ->orderBy('name')
            ->get(['id', 'public_id', 'name'])
            ->map(fn (User $owner) => [
                'public_id' => $owner->public_id,
                'name' => $owner->id === $user->id ? 'Me' : $owner->name,
            ])
            ->values();
    }

    private function applySort(Builder $query, string $sort, string $direction): Builder
    {
        return match ($sort) {
            'size' => $query->orderBy('size_bytes', $direction),
            'updated' => $query->orderBy('updated_at', $direction),
            'created' => $query->orderBy('created_at', $direction),
            // Only the trash sorts by this, and only the trash has rows where
            // it is not null.
            'deleted' => $query->orderBy('deleted_at', $direction),
            default => $query->orderBy('name', $direction),
        };
    }

    /**
     * The folder being viewed, or the owner's root.
     *
     * A folder the viewer may not see is a 404 rather than a silent fall back
     * to the owner's root, which would be a different and more revealing
     * answer. The check is unconditional now: on My Files the folder in view
     * may belong to somebody else, because that is what opening a shared folder
     * is, so ownership was never the right question here either.
     */
    private function locateFolder(Request $request, User $owner): Folder
    {
        $publicId = $request->string('folder')->toString();

        if ($publicId === '') {
            return $this->manager->rootFor($owner);
        }

        $folder = Folder::query()->where('public_id', $publicId)->first();

        abort_if($folder === null, 404);

        /*
         * On My Files the folder may belong to somebody else — that is what
         * opening a shared folder is — so the gate is the ability, not the
         * ownership. A folder the viewer cannot see is a 404 either way.
         */
        abort_unless(
            $this->access->allows($request->user(), $folder, FileAbility::View),
            404,
        );

        return $folder;
    }

    /**
     * A trashed folder being browsed inside the trash.
     *
     * Resolved against ownership rather than through the resolver: a
     * soft-deleted item is out of every listing, so a share on it cannot be the
     * claim — the same rule FileManager::restore() applies.
     */
    private function locateTrashedFolder(Request $request, User $user): ?Folder
    {
        $publicId = $request->string('folder')->toString();

        if ($publicId === '') {
            return null;
        }

        $folder = Folder::onlyTrashed()->where('public_id', $publicId)->first();

        abort_if($folder === null, 404);
        abort_unless($folder->user_id === $user->id || $user->can('files.manage'), 404);

        return $folder;
    }

    /**
     * Accounts with at least one item this person may see.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function browsableOwners(User $user): Collection
    {
        $ids = $this->access->scopeVisible(File::query(), $user)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->distinct()
            ->pluck('user_id')
            ->merge(
                $this->access->scopeVisible(Folder::query(), $user)
                    ->whereNotNull('user_id')
                    ->where('user_id', '!=', $user->id)
                    ->distinct()
                    ->pluck('user_id')
            )
            ->unique();

        return User::query()
            ->whereKey($ids)
            ->orderBy('name')
            ->get(['id', 'public_id', 'name'])
            ->map(fn (User $owner) => [
                'public_id' => $owner->public_id,
                'name' => $owner->name,
            ]);
    }

    /**
     * What a file looks like on the page.
     *
     * The abilities are resolved per row and sent as a `can` object, which is
     * this codebase's convention: a button's visibility comes from the server,
     * never from a template reading `auth.permissions`.
     *
     * @return array<string, mixed>
     */
    private function filePayload(File $file, User $user): array
    {
        $grant = $this->access->grant($user, $file);

        /*
         * A tracked file is badged with its **document's** classification,
         * because that is the one that decides anything.
         *
         * `AccessResolver` answers a file carrying a tracking code by the
         * tracked-document rule and never reaches the file row's own
         * visibility — that is the whole point of the clause, so that the
         * obfuscated file URL cannot be a looser second door to a document.
         * But the badge was still drawn from the file row, which a tracked
         * upload leaves at the folder's `private`. So a document the office
         * had published read PRIVATE in My Files while anyone holding its
         * printed code could open it: the badge stated the opposite of the
         * rule in force, which is worse than no badge at all.
         *
         * The two enums carry the same three values and both answer
         * `label()` and `tone()`, so the row renders identically either way.
         */
        $visibility = $file->relationLoaded('document') && $file->document
            ? $file->document->visibility
            : $this->access->effectiveVisibility($file);

        return [
            'public_id' => $file->public_id,
            'owner_public_id' => $file->owner?->public_id,
            'name' => $file->name,
            'original_name' => $file->original_name,
            /*
             * What a row is *called*, which is the current version's name
             * and not the row's.
             *
             * `files.name` is the folder's label, frozen when the row was
             * created and never rewritten by a replacement — that is
             * deliberate, because it is half of the `(folder_id, name)`
             * unique index and renaming on replace would have to consult
             * `assertNameFree()`, which can refuse. The consequence was that
             * a `.docx` replaced by a PDF kept its old name while the icon,
             * the size and the bytes beside it were all the new version's:
             * one row describing two different files.
             *
             * Every other surface already answers with the version's own
             * name — a download arrives as it, the preview titles with it,
             * the version history lists it — so the listing was the last
             * place still showing the original. `downloadName()` is that one
             * answer, which is why it is read here rather than restated.
             */
            'display_name' => $file->downloadName(),
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size' => $file->size_bytes,
            'owner_name' => $file->owner_name,
            'is_mine' => $file->user_id === $user->id,
            ...$this->accessPayload($grant, $file, $user),
            'visibility' => $visibility->value,
            'visibility_label' => $visibility->label(),
            'visibility_tone' => $visibility->tone(),
            'has_thumbnail' => $file->has_thumbnail,
            /*
             * The version, so a row can badge "v2" and the kebab menu can
             * offer a history only where there is one. One integer rather than
             * the history itself: the entries carry URLs that open bytes, and
             * forty rows must not mint them on every navigation.
             */
            'version' => $file->versionNumber(),
            /*
             * The tracking jacket, when there is one.
             *
             * `File::isTracked()` existed and nothing rendered it, so a
             * tracked document sat in My Files looking like any other file —
             * with no way to reach its trail, its QR Tracker or the people it
             * was shared with. Nothing here is a credential: the code is
             * printed on the label and the title is already on the row.
             *
             * Read off the eager-loaded relation, not `isTracked()`, which
             * runs a query per row.
             */
            'tracked' => $file->relationLoaded('document') && $file->document
                ? [
                    'code' => $file->document->code,
                    'title' => $file->document->title,
                    'url' => route('documents.view', ['code' => $file->document->code]),
                ]
                : null,
            'created_at' => $file->created_at,
            'updated_at' => $file->updated_at,
            'can' => [
                'view' => $grant->allows(FileAbility::View),
                'download' => $grant->allows(FileAbility::Download),
                'copy' => $grant->allows(FileAbility::Copy),
                'rename' => $grant->allows(FileAbility::Rename),
                'move' => $grant->allows(FileAbility::Move),
                'delete' => $grant->allows(FileAbility::Delete),
                'share' => $grant->allows(FileAbility::Share),
                /*
                 * Asked of FileManager rather than derived from `rename` here,
                 * so the button and the refusal cannot disagree: replacing a
                 * tracked document's bytes is the uploader's alone, even for
                 * somebody holding a manage share on the file. Reads the
                 * already-eager-loaded `document` relation, so it costs no
                 * query per row.
                 */
                'replace' => $this->manager->mayReplace($user, $file),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderCardPayload(Folder $folder, User $user): array
    {
        $grant = $this->access->grant($user, $folder);
        $visibility = $this->access->effectiveVisibility($folder);

        return [
            'public_id' => $folder->public_id,
            'name' => $folder->name,
            'owner_name' => $folder->owner_name,
            'is_mine' => $folder->user_id === $user->id,
            ...$this->accessPayload($grant, $folder, $user),
            'visibility' => $visibility->value,
            'visibility_label' => $visibility->label(),
            'visibility_tone' => $visibility->tone(),
            'created_at' => $folder->created_at,
            'updated_at' => $folder->updated_at,
            'can' => [
                'rename' => $grant->allows(FileAbility::Rename),
                'move' => $grant->allows(FileAbility::Move),
                'copy' => $grant->allows(FileAbility::Copy),
                'delete' => $grant->allows(FileAbility::Delete),
                'share' => $grant->allows(FileAbility::Share),
            ],
        ];
    }

    /**
     * Why this row is on the page, in the words the badge uses.
     *
     * Taken from the grant's own `source` rather than inferred from ownership,
     * because they are not the same question: an administrator sees a file
     * through `files.manage` and a colleague sees the same file through a share
     * on the folder above it, and a badge that said "shared with you" for the
     * first would be telling somebody they were given something they were not.
     *
     * @return array<string, mixed>
     */
    private function accessPayload(\App\Services\Access\AccessGrant $grant, File|Folder $item, User $user): array
    {
        $share = $grant->shareId !== null ? $this->share($grant->shareId) : null;

        return [
            'access' => match ($grant->source) {
                AccessSource::Owner, AccessSource::Uploader => 'owner',
                AccessSource::Share, AccessSource::InheritedShare => 'shared',
                AccessSource::PublicItem => 'public',
                AccessSource::ModuleManage => 'administrative',
                default => 'none',
            },
            'access_label' => match ($grant->source) {
                AccessSource::Owner, AccessSource::Uploader => 'Owned by you',
                AccessSource::Share => 'Shared with you',
                AccessSource::InheritedShare => 'Shared folder',
                AccessSource::PublicItem => 'Office-wide',
                AccessSource::ModuleManage => 'Administrator view',
                default => 'No access',
            },
            // Snapshotted on the share row, so "who gave me this" survives that
            // person leaving.
            'shared_by' => $share?->granted_by_name,
            'share_ability' => $share?->ability->value,
        ];
    }

    private function share(int $id): ?ItemShare
    {
        return $this->shareMemo[$id] ??= ItemShare::query()->find($id);
    }

    /**
     * A trashed file, with what it would take to put it back.
     *
     * `restores_to` is the honest part. An item's folder may itself be in the
     * trash, or may have been purged since, so the dialog has to be able to say
     * where the thing will actually land — "back into Reports", or "into your
     * storage root, because Reports is gone".
     *
     * @return array<string, mixed>
     */
    private function trashedFilePayload(File $file, User $user): array
    {
        return [
            'public_id' => $file->public_id,
            'owner_public_id' => $file->owner?->public_id,
            'name' => $file->name,
            // The same rule as the listing: a row is called what its current
            // version is called. The trash is where somebody is deciding
            // whether they want a thing back, so showing them the name of a
            // version it has not held for months is the worst place for it.
            'display_name' => $file->downloadName(),
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size' => $file->size_bytes,
            'owner_name' => $file->owner_name,
            'is_mine' => $file->user_id === $user->id,
            'has_thumbnail' => false,
            'deleted_at' => $file->deleted_at,
            'created_at' => $file->created_at,
            'updated_at' => $file->updated_at,
            ...$this->restoreTarget(Folder::withTrashed()->find($file->folder_id)),
            'can' => $this->trashAbilities($file, $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trashedFolderPayload(Folder $folder, User $user): array
    {
        return [
            'public_id' => $folder->public_id,
            'name' => $folder->name,
            'owner_name' => $folder->owner_name,
            'is_mine' => $folder->user_id === $user->id,
            'deleted_at' => $folder->deleted_at,
            'created_at' => $folder->created_at,
            'updated_at' => $folder->updated_at,
            // What went down with it, so the trash can say "4 folders, 31
            // files" before anything is restored or purged.
            'contains' => [
                'folders' => Folder::withTrashed()->subtreeOf($folder)->count() - 1,
                'files' => File::withTrashed()
                    ->whereIn('folder_id', Folder::withTrashed()->subtreeOf($folder)->select('id'))
                    ->count(),
            ],
            ...$this->restoreTarget(Folder::withTrashed()->find($folder->parent_id)),
            'can' => $this->trashAbilities($folder, $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreTarget(?Folder $container): array
    {
        if (! $container) {
            return ['restores_to' => 'your storage root', 'restores_home' => true];
        }

        if ($container->is_root) {
            return ['restores_to' => 'your storage root', 'restores_home' => false];
        }

        return [
            'restores_to' => $container->name,
            // True when the original folder cannot be rebuilt, which is what
            // makes a restore land somewhere other than where it came from.
            'restores_home' => $this->chainIsBroken($container),
        ];
    }

    /**
     * Is this container's own ancestor chain missing a link?
     *
     * `withTrashed`, because a trashed chain is the normal case in here —
     * deleting a folder trashes everything below it. A *missing* row is the
     * problem: that is a purge that happened while the descendants sat in the
     * trash, and it is why a restore cannot always go home.
     */
    private function chainIsBroken(Folder $container): bool
    {
        $ids = $container->ancestorPublicIds();

        return Folder::withTrashed()->whereIn('public_id', $ids)->count() !== count($ids);
    }

    /**
     * @return array<string, bool>
     */
    private function trashAbilities(File|Folder $item, User $user): array
    {
        $mine = $item->user_id === $user->id || $user->can('files.manage');

        return [
            'restore' => $mine && $user->can('files.edit'),
            'purge' => $mine && $user->can('files.delete'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderPayload(Folder $folder, User $user): array
    {
        return [
            'public_id' => $folder->public_id,
            // A root is never shown by its stored name (`root-{id}`); the
            // owner's name is the crumb.
            'name' => $folder->is_root ? ($folder->owner_name ?? 'Storage') : $folder->name,
            'is_root' => $folder->is_root,
            'is_mine' => $folder->user_id === $user->id,
            'owner_name' => $folder->owner_name,
            'visibility' => $folder->visibility?->value,
            // Whether this folder can be written into at all, which is what
            // decides if New folder and Upload are offered inside somebody
            // else's shared folder.
            'can_write' => $this->access->allows($user, $folder, FileAbility::Rename),
        ];
    }

    /**
     * @return list<array{public_id: string, name: string}>
     */
    private function breadcrumbs(Folder $folder): array
    {
        return $folder->ancestors()
            ->push($folder)
            ->reject(fn (Folder $crumb) => $crumb->is_root)
            ->map(fn (Folder $crumb) => [
                'public_id' => $crumb->public_id,
                'name' => $crumb->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Crumbs inside the trash.
     *
     * `withTrashed` throughout, and the chain stops at the first missing link:
     * a purged ancestor is not a crumb anybody can click, and pretending
     * otherwise would hand out a 404.
     *
     * @return list<array{public_id: string, name: string}>
     */
    private function trashedBreadcrumbs(Folder $folder): array
    {
        $ancestors = Folder::withTrashed()
            ->whereIn('public_id', $folder->ancestorPublicIds())
            ->orderBy('depth')
            ->get();

        return $ancestors
            ->push($folder)
            ->reject(fn (Folder $crumb) => $crumb->is_root || ! $crumb->trashed())
            ->map(fn (Folder $crumb) => [
                'public_id' => $crumb->public_id,
                'name' => $crumb->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        return [
            'entries' => $this->entries($request),
            'search' => $request->string('search')->toString(),
            'sort' => $this->sort($request),
            'direction' => $this->direction($request),
            // In the query string, not in local state: it is what makes the
            // current page, search, sort and filters survive a view switch by
            // construction, and what makes a shared link reproduce what the
            // sender saw.
            'view' => $this->view($request),
            'folder' => $request->string('folder')->toString(),
            'owner' => $request->string('owner')->toString(),
            'owned_by' => $request->string('owned_by')->toString(),
            'access' => $this->accessFilter($request),
            'kind' => $this->kindFilter($request),
            'type' => $this->category($request)?->value ?? '',
            'from' => $from?->toDateString() ?? '',
            'to' => $to?->toDateString() ?? '',
        ];
    }

    private function view(Request $request): string
    {
        return $request->string('view')->toString() === 'table' ? 'table' : 'grid';
    }

    private function sort(Request $request): string
    {
        $sort = $request->string('sort')->toString();

        return in_array($sort, self::SORTS, true) ? $sort : 'name';
    }

    private function direction(Request $request): string
    {
        return $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
    }

    private function accessFilter(Request $request): string
    {
        $access = $request->string('access')->toString();

        return in_array($access, self::ACCESS_FILTERS, true) ? $access : 'all';
    }

    private function kindFilter(Request $request): string
    {
        $kind = $request->string('kind')->toString();

        return in_array($kind, self::KIND_FILTERS, true) ? $kind : 'all';
    }

    private function category(Request $request): ?FileCategory
    {
        return FileCategory::tryFrom($request->string('type')->toString());
    }

    /**
     * The owner filter, as a database id.
     *
     * Taken by `public_id` from the client and resolved here, so the dropdown
     * never carries a primary key and a hand-edited query string cannot filter
     * by one.
     *
     * `owned_by` rather than `owner`, because Browse Storage already uses
     * `owner` for *whose storage am I looking at* — one name for two questions
     * is how a filter starts quietly narrowing a page it was never applied to.
     */
    private function ownerFilter(Request $request): ?int
    {
        $publicId = $request->string('owned_by')->toString();

        if ($publicId === '') {
            return null;
        }

        return User::query()->where('public_id', $publicId)->value('id');
    }

    /**
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    private function dateRange(Request $request): array
    {
        $parse = function (string $key, bool $endOfDay) use ($request): ?\Illuminate\Support\Carbon {
            $value = $request->string($key)->toString();

            if ($value === '') {
                return null;
            }

            try {
                $date = \Illuminate\Support\Carbon::parse($value);
            } catch (\Throwable) {
                // A hand-typed date is user input like any other: an
                // unparseable one is no filter, not a 500.
                return null;
            }

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        };

        return [$parse('from', false), $parse('to', true)];
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 24);

        return in_array($entries, self::ENTRY_OPTIONS, true) ? $entries : 24;
    }

    /**
     * Everyone a folder can be tagged with.
     *
     * A face and an office beside each name, because "Dela Cruz" is not enough
     * to pick the right person out of a list of everybody in the agency.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function directory(User $user): Collection
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->with(['division:id,name', 'section:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'employee_id', 'profile_image', 'avatar_seed', 'division_id', 'section_id'])
            ->map(fn (User $person) => [
                'id' => $person->id,
                'name' => $person->name,
                'employee_id' => $person->employee_id,
                'profile_image' => $person->profile_image,
                'avatar_seed' => $person->avatar_seed,
                'division' => $person->division?->name,
                'section' => $person->section?->name,
            ]);
    }

    /**
     * @return list<array{value: string, label: string, description: string, tone: string}>
     */
    private function visibilityOptions(): array
    {
        return array_map(fn (FileVisibility $visibility): array => [
            'value' => $visibility->value,
            'label' => $visibility->label(),
            'description' => $visibility->description(),
            'tone' => $visibility->tone(),
        ], FileVisibility::cases());
    }
}
