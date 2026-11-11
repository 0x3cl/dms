<?php

namespace App\Http\Controllers;

use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\File;
use App\Models\Folder;
use App\Models\UploadSetting;
use App\Models\User;
use App\Services\Access\AccessResolver;
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
        private readonly DeletionConfirmation $confirmation,
        private readonly SharingService $sharing,
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
     */
    public function storeFolder(Request $request): RedirectResponse
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

        DB::transaction(function () use ($request, $validated, $recipients): void {
            $folder = $this->manager->createFolder(
                $request->user(),
                $this->folder($validated['parent']),
                trim($validated['name']),
                $request,
            );

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

        return back()->with('success', $recipients === []
            ? 'Folder created.'
            : 'Folder created and shared with '.count($recipients).' '.(count($recipients) === 1 ? 'person' : 'people').'.');
    }

    public function upload(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'folder' => ['required', 'string', 'size:26'],
            // Count and size come from the administrator's policy, not from a
            // literal written at code time.
            ...$this->policy->rules(UploadSetting::SCOPE_FILES),
        ]);

        $folder = $this->folder($validated['folder']);
        $count = 0;

        foreach ($request->file('files') as $upload) {
            // Extension and sniffed MIME type, against the current policy row.
            // Checked before the first byte is stored, so a rejected batch
            // stores nothing.
            $this->policy->assertPermitted($upload, UploadSetting::SCOPE_FILES);
        }

        foreach ($request->file('files') as $upload) {
            $this->manager->upload($request->user(), $folder, $upload, $request);
            $count++;
        }

        return back()->with('success', $count === 1 ? 'File uploaded.' : "{$count} files uploaded.");
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
     * Move a selection to the trash, behind a typed confirmation code.
     *
     * The code is checked here, on the server, before anything is resolved —
     * see App\Services\Files\DeletionConfirmation for why it is not a browser
     * -side check. Verified first so a wrong code costs nothing and reveals
     * nothing about what the ids point at.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.kind' => ['required', Rule::in(['file', 'folder'])],
            'items.*.public_id' => ['required', 'string', 'size:26'],
            'confirmation' => ['required', 'string', 'size:4'],
        ]);

        $this->confirmation->assert($request, DeletionConfirmation::PURPOSE_DELETE, $validated['confirmation']);

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
     * Its own purpose for the confirmation code, so a code issued for "move to
     * trash" cannot satisfy this: one is reversible and the other is not.
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
            'purpose' => ['required', Rule::in([DeletionConfirmation::PURPOSE_DELETE, DeletionConfirmation::PURPOSE_PURGE])],
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
