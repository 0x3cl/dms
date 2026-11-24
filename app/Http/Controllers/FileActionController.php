<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStamp;
use App\Enums\DocumentVisibility;
use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Documents\DocumentRegistrar;
use App\Services\Documents\DocumentStampService;
use App\Services\Files\ArchiveService;
use App\Services\Files\DeletionConfirmation;
use App\Services\Files\FileManager;
use App\Services\Files\SharingService;
use App\Services\Files\UploadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating, renaming, moving, copying and deleting.
 *
 * Every method hands straight to App\Services\Files\FileManager, which does the
 * authorization, the transaction and the audit row. There is deliberately no
 * permission logic here: a controller that decides access is a second place the
 * rule lives.
 *
 * Batch endpoints take arrays of `public_id`, revalidate **each item
 * individually**, and run inside one transaction, so a partial failure writes
 * nothing.
 */
class FileActionController extends Controller
{
    public function __construct(
        private readonly FileManager $manager,
        private readonly UploadPolicy $policy,
        private readonly ArchiveService $archives,
        private readonly AccessResolver $access,
        private readonly DocumentStampService $stamps,
        private readonly DeletionConfirmation $confirmation,
        private readonly SharingService $sharing,
        private readonly DocumentRegistrar $registrar,
    ) {}

    /**
     * Create a folder, and tag the people it is for.
     *
     * Naming recipients at creation is the point: it is the one moment somebody
     * is actually thinking about who a folder is for, and a share added later
     * from a drawer is a second trip they have to remember to make. Each tag is
     * an ordinary `item_shares` row, so everything downstream — inheritance into
     * whatever is put in the folder, the badge on the card, the revoke list —
     * already understands it.
     *
     * Sharing is its own permission, so a tag from somebody who cannot share is
     * refused rather than quietly dropped: a folder that reports itself as
     * shared with four people who cannot open it is worse than an error.
     *
     * **Answers JSON when asked**, because the Move and Copy picker creates
     * folders too — "put it in a new folder" is a thought people have while
     * choosing a destination, and sending them away to make one first loses
     * the selection they came with. A redirect would close that dialog, so the
     * picker asks for JSON and gets the new folder back to navigate into. It
     * is the same method either way: a second create endpoint would be a
     * second place the recipient rules and the ability check have to be
     * remembered.
     */
    public function storeFolder(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'parent' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\:*?"<>|]+$/'],
            'recipients' => ['nullable', 'array', 'max:100'],
            'recipients.*' => ['distinct', 'integer', 'exists:users,id'],
        ]);

        $recipients = array_values(array_filter(
            $validated['recipients'] ?? [],
            fn ($id) => (int) $id !== $request->user()->id,
        ));

        if ($recipients !== [] && ! $request->user()->can('files.share')) {
            throw ValidationException::withMessages([
                'recipients' => 'Your role cannot share a folder with other people.',
            ]);
        }

        $created = null;

        DB::transaction(function () use ($request, $validated, $recipients, &$created): void {
            $folder = $this->manager->createFolder(
                $request->user(),
                $this->folder($validated['parent']),
                trim($validated['name']),
                $request,
            );

            $created = $folder;

            foreach (User::findMany($recipients) as $recipient) {
                $this->sharing->share(
                    $folder,
                    GranteeType::User,
                    $recipient->id,
                    // View and take a copy: the useful default for a folder
                    // somebody is being given, and short of letting them
                    // rename or delete what is in it.
                    ShareAbility::Download,
                    $request->user(),
                );
            }
        });

        $message = $recipients === []
            ? 'Folder created.'
            : 'Folder created and shared with '.count($recipients).' '.(count($recipients) === 1 ? 'person' : 'people').'.';

        if ($request->wantsJson()) {
            // The same shape `destinations()` lists, so the picker can drop it
            // straight into its tree without a second round trip.
            return response()->json([
                'folder' => [
                    'public_id' => $created->public_id,
                    'name' => $created->name,
                    'parent_public_id' => $validated['parent'],
                    'is_mine' => true,
                    'owner_name' => $request->user()->name,
                ],
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Upload into a folder, optionally giving the file a tracking jacket.
     *
     * **Tracking is opt-in and takes exactly one file.** A tracking code names
     * one document — `documents.file_id` is singular and the QR Tracker is
     * named after one code — so three files would mean either three sets of
     * metadata in a dialog that cannot hold them, or one code over a set the
     * schema cannot express. A batch that genuinely is one document is a zip,
     * which `ArchiveService` already handles.
     *
     * **The file stays in the folder the person is looking at.** They chose it
     * by navigating there; filing it somewhere else — as the document register
     * does, because its form has no folder field — would read as a failed
     * upload. Nothing downstream depends on the path.
     *
     * Two capability checks live here rather than on the route, because the
     * route gates `files.create` and these are different grants: somebody who
     * cannot upload a document from the Documents module must not obtain a
     * tracking code from this one, and naming recipients is sharing.
     */
    public function upload(Request $request): RedirectResponse
    {
        $tracking = $request->boolean('track');

        $validated = $request->validate([
            'folder' => ['required', 'string', 'size:26'],
            // Count and size come from the administrator's policy, not from a
            // literal written at code time.
            ...$this->policy->rules(),
            ...($tracking ? [
                // One document, one code.
                'files' => [...($this->policy->rules()['files'] ?? ['required', 'array']), 'max:1'],
                'title' => ['required', 'string', 'max:255'],
                'document_type_id' => [
                    'required',
                    /*
                     * `whereNull('deleted_at')` is not redundant: Rule::exists
                     * builds its own query straight against the table, so the
                     * model's soft-delete scope does not apply and a deleted
                     * type would otherwise still validate.
                     */
                    Rule::exists('document_types', 'id')->where('is_active', true)->whereNull('deleted_at'),
                ],
                'visibility' => ['required', Rule::enum(DocumentVisibility::class)],
                // Optional at every level, confidential included: a
                // document nobody else can open yet is what filing a
                // confidential document looks like, and requiring a name
                // here only made people tag whoever came to mind to get past
                // the form. See DocumentController::store().
                'recipients' => ['array'],
                'recipients.*' => ['distinct', 'exists:users,id'],
            ] : []),
        ]);

        $user = $request->user();
        $folder = $this->folder($validated['folder']);

        $visibility = $tracking ? DocumentVisibility::from($validated['visibility']) : null;

        if ($tracking) {
            if (! $user->can('documents.create')) {
                throw ValidationException::withMessages([
                    'track' => 'Your role cannot create tracked documents.',
                ]);
            }

            if ($visibility->taggable() && ! $user->can('documents.share')) {
                throw ValidationException::withMessages([
                    'recipients' => 'Your role cannot share a document with named recipients.',
                ]);
            }
        }

        foreach ($request->file('files') as $upload) {
            // Extension and sniffed MIME type, against the current policy row.
            // Checked before the first byte is stored, so a rejected batch
            // stores nothing.
            $this->policy->assertPermitted($upload);
        }

        $count = 0;
        $stored = null;
        $filed = [];

        foreach ($request->file('files') as $upload) {
            $file = $this->manager->upload($user, $folder, $upload, $request);
            $count++;

            /*
             * Every uploaded file is filed, and the deliberate one is filed
             * by the branch below instead.
             *
             * This is the office's rule — every file carries a tracking code
             * and a QR — applied where files actually arrive. It is the same
             * `DocumentRegistrar` the register's own form calls, with the
             * type inferred and the title taken from the filename, because
             * neither was asked for here.
             *
             * An automatic filing does not govern who may read the file; see
             * `File::governingDocument()`. Without that this loop would cut
             * every folder-share grantee off from the files inside the folder
             * shared with them.
             *
             * The deliberate branch is skipped rather than tested per upload:
             * `track` validates `files` to exactly one, so a tracked request
             * has a single file and it is filed below, with the title, type
             * and classification the person actually chose.
             */
            if (! $tracking) {
                $wantsStamp = $request->boolean('stamp', true);

                $document = $this->registrar->registerAutomatically(
                    $file,
                    $user,
                    $request,
                    wantsStamp: $wantsStamp,
                );

                if ($document) {
                    // The code into the bytes, where the format allows one
                    // and the uploader wants one. After the document exists,
                    // because the stamp contains its code.
                    $this->registrar->stampFile($document, $file, $wantsStamp);

                    $filed[] = $document->code;
                }
            }

            $stored = $file;
        }

        if ($tracking && $stored !== null) {
            $document = $this->registrar->register(
                $stored,
                DocumentType::query()->findOrFail($validated['document_type_id']),
                $validated['title'],
                $visibility,
                $validated['recipients'] ?? [],
                $user,
                $request,
            );

            return back()->with('success', "Uploaded and tracked as {$document->code}.");
        }

        if (count($filed) === 1) {
            return back()->with('success', "File uploaded and filed as {$filed[0]}.");
        }

        return back()->with('success', $count === 1
            ? 'File uploaded.'
            : "{$count} files uploaded".($filed === [] ? '.' : ', each with its own tracking code.'));
    }

    /**
     * Finish an upload by accepting the stamp the browser drew.
     *
     * A PDF is the one format this server cannot draw into — there is no PDF
     * library here and the documented constraint is that there will not be
     * one — so `pdf-lib` draws the code onto every page in the browser and
     * posts the result back. The bytes are written over the stored file
     * exactly as the image path writes over its own.
     *
     * **This is a one-shot completion of an upload, not a way to rewrite a
     * file.** Accepting bytes that overwrite stored content is precisely the
     * capability `FileManager::replace()` exists to version and audit, so
     * every one of these has to hold, and the refusal is a 404 like every
     * other refusal on a route that takes an identifier:
     *
     * - the actor **owns** the file — not a manage share, which would make
     *   this a quieter `replace()` for anybody holding one;
     * - the format **plans as a PDF**, so this cannot be used to put
     *   arbitrary bytes behind a `.docx`;
     * - the document's stamp is still `None`, so it works once;
     * - the document was filed **minutes ago**, so it closes behind the
     *   upload it belongs to rather than staying open on every file forever.
     *
     * What it cannot check is that the bytes really are the uploaded file
     * with a stamp on them. That is inherent to drawing in the browser and
     * is the cost of the only PDF path available here — which is why the
     * window, the ownership and the one-shot are as narrow as they are.
     */
    public function stamp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // The tracking code, not the file's id: both upload paths have
            // the code to hand — My Files reads it off the row it just
            // created, and the register's success page is rendered with it —
            // where only one of them ever sees a `files` public id.
            'code' => ['required', 'string', 'max:64'],
            'stamped' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.(25 * 1024)],
        ]);

        $user = $request->user();

        $document = Document::query()->where('code', $validated['code'])->first();

        abort_if($document === null, 404);
        abort_unless((int) $document->user_id === (int) $user->id, 404);

        $file = $document->file;

        abort_if($file === null, 404);
        abort_unless($document->stamp === DocumentStamp::None, 404);
        abort_unless(
            $this->stamps->plan((string) $file->extension, (string) $file->mime_type) === DocumentStamp::Pdf,
            404,
        );
        /*
         * Minutes since the **bytes** last changed, not since the document
         * was filed.
         *
         * It was the document's age, which closed the window for the case
         * this most needs to stay open for: replacing the file of a document
         * filed last year is exactly when a new version arrives needing its
         * mark, and the document is months old at that moment. The file's
         * own timestamp moves with every upload and every replacement, which
         * is the act this is the second half of.
         */
        abort_unless($file->updated_at?->gt(now()->subMinutes(10)) ?? false, 404);

        $this->registrar->acceptStamp(
            $document,
            $file,
            (string) file_get_contents($validated['stamped']->getRealPath()),
        );

        return back()->with('success', "Tracking code stamped onto {$document->code}.");
    }

    public function rename(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['file', 'folder'])],
            'public_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\:*?"<>|]+$/'],
        ]);

        $this->manager->rename(
            $request->user(),
            $this->item($validated['kind'], $validated['public_id']),
            trim($validated['name']),
            $request,
        );

        return back()->with('success', 'Renamed.');
    }

    /**
     * Put new bytes behind an existing file: version N becomes N+1.
     *
     * The upload policy is applied here, before the manager opens its
     * transaction, because that is how every other upload path does it — a
     * refused file must store nothing rather than be rolled back after its
     * bytes are on the disk.
     *
     * No authorization in this controller. `FileManager::replace()` resolves
     * the ability on the file *and* refuses a tracked document to anyone but
     * its uploader, which is the one rule a second door into personal storage
     * must not widen: the bytes a printed tracking code names are the
     * uploader's to change.
     */
    public function replace(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'public_id' => ['required', 'string', 'size:26'],
            'note' => ['nullable', 'string', 'max:255'],
            'file' => $this->policy->singleRules(),
        ]);

        $upload = $request->file('file');

        $this->policy->assertPermitted($upload, 'file');

        $file = File::query()->where('public_id', $validated['public_id'])->firstOrFail();

        $updated = $this->manager->replace(
            $request->user(),
            $file,
            $upload,
            $validated['note'] ?? null,
            $request,
        );

        /*
         * The new version carries the same tracking code, so it needs the
         * same mark — and the row must stop claiming the one drawn into the
         * bytes this just superseded.
         */
        if ($updated->document) {
            $this->registrar->restampAfterReplacement(
                $updated->document,
                $updated,
                $request->boolean('stamp', true),
            );
        }

        return back()->with('success', "Saved as version {$updated->versionNumber()}.");
    }

    /**
     * Bring an earlier version back, as a new version.
     *
     * Forward rather than backward, so the history stays append-only — see
     * FileVersionService. No upload-policy check: these bytes already
     * satisfied it once, and re-checking would let a policy tightened since
     * make a file's own history unrevertable.
     */
    public function revert(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'public_id' => ['required', 'string', 'size:26'],
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $file = File::query()->where('public_id', $validated['public_id'])->firstOrFail();

        $updated = $this->manager->revertTo(
            $request->user(),
            $file,
            $validated['version'],
            $validated['note'] ?? null,
            $request,
        );

        return back()->with(
            'success',
            "Version {$validated['version']} is current again, as version {$updated->versionNumber()}.",
        );
    }

    public function move(Request $request): RedirectResponse
    {
        $validated = $this->validateBatch($request);
        $target = $this->folder($validated['target']);

        DB::transaction(function () use ($request, $validated, $target): void {
            foreach ($validated['items'] as $item) {
                $this->manager->move(
                    $request->user(),
                    $this->item($item['kind'], $item['public_id']),
                    $target,
                    $request,
                );
            }
        });

        return back()->with('success', 'Moved.');
    }

    /**
     * Copy a selection into a folder the person picked.
     *
     * "Copy to my storage" used to be the only shape this had: no destination,
     * everything landed in the taker's root. That is a one-way door — it works
     * for taking something out of somebody else's tree and is useless for
     * duplicating a file next to itself, which is what people actually asked
     * for. The destination is now chosen in the same picker Move uses.
     *
     * Omitting it still means the root, so the one case that genuinely has no
     * folder in view — taking a copy from Browse Storage — keeps working.
     *
     * Folders copy with their contents. FileManager walks the subtree and
     * resolves the ability on every descendant.
     */
    public function copy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['nullable', 'string', 'size:26'],
            'items' => ['required', 'array', 'max:100'],
            'items.*.kind' => ['required', Rule::in(['file', 'folder'])],
            'items.*.public_id' => ['required', 'string', 'size:26'],
        ]);

        $target = ($validated['target'] ?? null)
            ? $this->folder($validated['target'])
            : $this->manager->rootFor($request->user());

        $copied = DB::transaction(function () use ($request, $validated, $target): int {
            $count = 0;

            foreach ($validated['items'] as $item) {
                $this->manager->copy(
                    $request->user(),
                    $this->item($item['kind'], $item['public_id']),
                    $target,
                    $request,
                );
                $count++;
            }

            return $count;
        });

        $where = $target->is_root ? 'your storage' : $target->name;

        return back()->with('success', $copied === 1
            ? "Copied to {$where}."
            : "{$copied} items copied to {$where}.");
    }

    /**
     * Move a selection to the trash.
     *
     * **No confirmation code.** This used to demand the same typed four digits
     * `purge()` does, which made the reversible act as heavy on screen as the
     * irreversible one and taught people to type a code without reading the
     * dialog it was guarding — the exact habit the code exists to interrupt.
     * Friction spent on something Trash can undo is friction unavailable for
     * the thing it cannot, so it is spent only there now.
     *
     * Nothing else is relaxed: the ability is still resolved on every subject
     * by AccessResolver inside FileManager, in the transaction that writes.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.kind' => ['required', Rule::in(['file', 'folder'])],
            'items.*.public_id' => ['required', 'string', 'size:26'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            foreach ($validated['items'] as $item) {
                $this->manager->delete(
                    $request->user(),
                    $this->item($item['kind'], $item['public_id']),
                    $request,
                );
            }
        });

        return back()->with('success', 'Moved to trash.');
    }

    /**
     * Permanently delete a selection out of the trash.
     *
     * **The one operation behind a typed confirmation code**, issued by the
     * server, held in the session and spent on use — see
     * App\Services\Files\DeletionConfirmation for why it is not a browser-side
     * check. Verified before anything is resolved, so a wrong code costs
     * nothing and reveals nothing about what the ids point at.
     *
     * `PURPOSE_PURGE` survives as a distinct purpose even though it is now the
     * only one minted, because the purpose is what binds a code to the act it
     * was issued for. Dropping it would let any code satisfy any future
     * confirmation.
     */
    public function purge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.kind' => ['required', Rule::in(['file', 'folder'])],
            'items.*.public_id' => ['required', 'string', 'size:26'],
            'confirmation' => ['required', 'string', 'size:4'],
        ]);

        $this->confirmation->assert($request, DeletionConfirmation::PURPOSE_PURGE, $validated['confirmation']);

        DB::transaction(function () use ($request, $validated): void {
            foreach ($validated['items'] as $item) {
                $this->manager->purge(
                    $request->user(),
                    $this->trashedItem($item['kind'], $item['public_id']),
                    $request,
                );
            }
        });

        return back()->with('success', 'Permanently deleted.');
    }

    /**
     * Mint the confirmation code the dialog displays.
     *
     * A POST because it changes server state — it writes the code into the
     * session — and because a GET would be prefetchable, which would hand out
     * codes the person never saw.
     */
    public function confirmCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Only the irreversible act is confirmed by code, so this is the
            // only purpose there is. Kept as a validated parameter rather than
            // assumed, because the purpose is what binds a code to its act.
            'purpose' => ['required', Rule::in([DeletionConfirmation::PURPOSE_PURGE])],
        ]);

        return response()->json([
            'code' => $this->confirmation->issue($request, $validated['purpose']),
        ]);
    }

    /**
     * What a delete would actually take with it.
     *
     * A folder delete is recursive, and "Delete folder?" does not say that.
     * This counts the subtree — folders, files and bytes — and names a few of
     * them, so the dialog can state the cost before it is paid.
     *
     * Every subject is resolved through the resolver first: a count of what is
     * inside a folder is itself a disclosure about that folder.
     */
    public function deletePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.kind' => ['required', Rule::in(['file', 'folder'])],
            'items.*.public_id' => ['required', 'string', 'size:26'],
            'trashed' => ['nullable', 'boolean'],
        ]);

        $trashed = (bool) ($validated['trashed'] ?? false);
        $folders = 0;
        $files = 0;
        $bytes = 0;
        $names = [];

        foreach ($validated['items'] as $entry) {
            $item = $trashed
                ? $this->trashedItem($entry['kind'], $entry['public_id'])
                : $this->item($entry['kind'], $entry['public_id']);

            $mayRead = $trashed
                ? ($item->user_id === $request->user()->id || $request->user()->can('files.manage'))
                : $this->access->allows($request->user(), $item, FileAbility::View);

            abort_unless($mayRead, 404);

            $names[] = $item->name;

            if ($item instanceof File) {
                $files++;
                $bytes += (int) $item->size_bytes;

                continue;
            }

            $subtree = Folder::withTrashed()->subtreeOf($item)->pluck('id');
            $folders += $subtree->count();

            $contained = File::withTrashed()->whereIn('folder_id', $subtree);
            $files += (clone $contained)->count();
            $bytes += (int) (clone $contained)->sum('size_bytes');
        }

        return response()->json([
            // The folders that were selected are included in the count, which
            // is what "will also be deleted" has to mean for the number to
            // match what disappears.
            'folders' => $folders,
            'files' => $files,
            'bytes' => $bytes,
            'names' => array_slice($names, 0, 5),
            'more' => max(0, count($names) - 5),
        ]);
    }

    /**
     * The folders a move or a copy may aim at.
     *
     * Only folders this person may actually write into — resolved one at a time
     * through AccessResolver, because "my own tree" is not the answer: a folder
     * shared with edit is a legitimate destination, and a folder shared
     * read-only is not. The picker is a listing, so it goes through the same
     * gate every other listing does.
     *
     * Returned flat, with each row naming its parent, so the dialog can walk
     * the tree without a request per level.
     */
    public function destinations(Request $request): JsonResponse
    {
        $user = $request->user();

        $folders = $this->access->scopeVisible(Folder::query(), $user)
            ->orderBy('depth')
            ->orderBy('name')
            ->get();

        $root = $this->manager->rootFor($user);

        $rows = $folders
            ->filter(fn (Folder $folder) => ! $folder->is_root
                && $this->access->allows($user, $folder, FileAbility::Rename))
            ->map(fn (Folder $folder) => [
                'public_id' => $folder->public_id,
                'name' => $folder->name,
                // The owner's root is the picker's own top level, so a folder
                // sitting directly under it reports no parent.
                'parent_public_id' => $folder->parent_id === $root->id
                    ? null
                    : $folders->firstWhere('id', $folder->parent_id)?->public_id,
                'owner_name' => $folder->owner_name,
                'is_mine' => $folder->user_id === $user->id,
            ])
            ->values();

        return response()->json([
            'root' => [
                'public_id' => $root->public_id,
                'name' => 'My Files',
            ],
            'folders' => $rows,
        ]);
    }

    public function restore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['file', 'folder'])],
            'public_id' => ['required', 'string', 'size:26'],
        ]);

        $this->manager->restore(
            $request->user(),
            $this->trashedItem($validated['kind'], $validated['public_id']),
            $request,
        );

        return back()->with('success', 'Restored.');
    }

    /**
     * Download a selection as one archive.
     *
     * A file response, so it must be reached by a real form post or
     * window.location — an Inertia XHR visit cannot follow one.
     */
    public function zip(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*' => ['required', 'string', 'size:26'],
        ]);

        $files = File::query()->whereIn('public_id', $validated['items'])->get();

        return $this->archives->zip($request->user(), $files->all(), $request);
    }

    public function extract(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'public_id' => ['required', 'string', 'size:26'],
            'target' => ['nullable', 'string', 'size:26'],
        ]);

        $archive = File::query()->where('public_id', $validated['public_id'])->firstOrFail();
        $target = $validated['target'] ?? null
            ? $this->folder($validated['target'])
            : $archive->folder;

        $created = $this->archives->extract($request->user(), $archive, $target, $request);

        return back()->with('success', count($created).' file(s) extracted.');
    }

    public function visibility(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['file', 'folder'])],
            'public_id' => ['required', 'string', 'size:26'],
            'visibility' => ['required', Rule::enum(FileVisibility::class)],
        ]);

        $this->manager->setVisibility(
            $request->user(),
            $this->item($validated['kind'], $validated['public_id']),
            FileVisibility::from($validated['visibility']),
            $request,
        );

        return back()->with('success', 'Privacy updated.');
    }

    /**
     * @return array{target: string, items: list<array{kind: string, public_id: string}>}
     */
    private function validateBatch(Request $request): array
    {
        return $request->validate([
            'target' => ['required', 'string', 'size:26'],
            'items' => ['required', 'array', 'max:100'],
            'items.*.kind' => ['required', Rule::in(['file', 'folder'])],
            'items.*.public_id' => ['required', 'string', 'size:26'],
        ]);
    }

    private function item(string $kind, string $publicId): File|Folder
    {
        return $kind === 'file'
            ? File::query()->where('public_id', $publicId)->firstOrFail()
            : Folder::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** The trash's own lookup: only an item that is actually in it. */
    private function trashedItem(string $kind, string $publicId): File|Folder
    {
        return $kind === 'file'
            ? File::onlyTrashed()->where('public_id', $publicId)->firstOrFail()
            : Folder::onlyTrashed()->where('public_id', $publicId)->firstOrFail();
    }

    private function folder(string $publicId): Folder
    {
        return Folder::query()->where('public_id', $publicId)->firstOrFail();
    }
}
