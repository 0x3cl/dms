<?php

namespace App\Services\Files;

use App\Enums\ActivityAction;
use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Every operation the file manager performs.
 *
 * The controllers do no authorization of their own: each method here resolves
 * the ability on the subject — and, for a move or copy, on the destination too
 * — inside the same transaction that writes. That is requirement 7's
 * "revalidate on every access or modification", and it is why no resolver
 * result is cached across a request.
 *
 * Each method also writes its own activity row, so an operation cannot
 * succeed unrecorded.
 */
class FileManager
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly FolderService $folders,
        private readonly FileStorage $storage,
        private readonly FileEventRecorder $events,
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    public function rootFor(User $user): Folder
    {
        return $this->folders->rootFor($user);
    }

    // ----------------------------------------------------------------- folders

    public function createFolder(User $actor, Folder $parent, string $name, ?Request $request = null): Folder
    {
        $this->assert($actor, $parent, FileAbility::Rename, 'You cannot add to that folder.');
        $this->assertNameFree($parent, $name);

        return DB::transaction(function () use ($actor, $parent, $name, $request): Folder {
            $folder = $this->folders->create($actor, $parent, $name);

            $this->events->record(ActivityAction::FolderCreated, $folder, $actor, $request, [
                'parent' => $parent->is_root ? null : $parent->name,
            ]);

            return $folder;
        });
    }

    public function rename(User $actor, File|Folder $item, string $name, ?Request $request = null): File|Folder
    {
        $this->assert($actor, $item, FileAbility::Rename, 'You cannot rename that.');

        if ($item instanceof Folder && $item->is_root) {
            throw new RuntimeException('A root folder cannot be renamed.');
        }

        $from = $item instanceof File ? $item->name : $item->name;
        $this->assertNameFree($this->containerOf($item), $name, $item);

        return DB::transaction(function () use ($actor, $item, $name, $from, $request): File|Folder {
            $item->update(['name' => $name]);

            $this->events->record(ActivityAction::Renamed, $item, $actor, $request, [
                'from' => $from,
                'to' => $name,
            ]);

            return $item;
        });
    }

    // ------------------------------------------------------------------- moving

    /**
     * Move an item into a folder.
     *
     * Both ends are checked: the ability to take the item *out* of where it is,
     * and the ability to put something *into* where it is going. Checking only
     * the subject would let someone with a share on one folder deposit files
     * into another they cannot write.
     */
    public function move(User $actor, File|Folder $item, Folder $target, ?Request $request = null): File|Folder
    {
        $this->assert($actor, $item, FileAbility::Move, 'You cannot move that.');
        $this->assert($actor, $target, FileAbility::Rename, 'You cannot move anything into that folder.');

        $from = $this->containerOf($item);
        $this->assertNameFree($target, $item->name, $item);

        return $this->attempt(function () use ($actor, $item, $target, $from, $request): File|Folder {
            if ($item instanceof Folder) {
                $this->folders->move($item, $target);
            } else {
                $item->update(['folder_id' => $target->id]);
            }

            $this->events->record(ActivityAction::Moved, $item->refresh(), $actor, $request, [
                'from' => $from->is_root ? 'storage root' : $from->name,
                'to' => $target->is_root ? 'storage root' : $target->name,
                // Both ends by public id as well as by name, so the row still
                // identifies the folders after either is renamed.
                'from_id' => $from->public_id,
                'to_id' => $target->public_id,
            ]);

            return $item;
        }, ActivityAction::Moved, $item, $actor, $request);
    }

    /**
     * Copy a file or a whole folder into a folder — possibly from someone
     * else's storage.
     *
     * The copy is a new row with its own bytes and its own owner, so revoking
     * the original's share, or deleting the original outright, cannot reach
     * into the storage of whoever took a copy. That copy is theirs.
     *
     * A folder copies with its contents. That used to be refused — "copying a
     * folder means copying its whole subtree, which belongs with the archive
     * work" — but a file manager whose Copy silently ignores folders is worse
     * than one that does the work: the person selected a folder and nothing
     * happened. The subtree is walked here, in one transaction, with the ability
     * resolved on **every** descendant rather than only on the folder that was
     * clicked. A descendant the actor cannot copy is skipped and counted, never
     * silently included.
     *
     * Unlike a move, a copy does not refuse a name collision: duplicating
     * something into the folder it already sits in is the ordinary case, so the
     * copy is named "report (2).pdf" the same way a second upload of one name
     * is.
     */
    public function copy(User $actor, File|Folder $item, Folder $target, ?Request $request = null): File|Folder
    {
        $this->assert($actor, $item, FileAbility::Copy, 'You cannot take a copy of that.');
        $this->assert($actor, $target, FileAbility::Rename, 'You cannot copy anything into that folder.');

        if ($item instanceof Folder) {
            return $this->copyFolder($actor, $item, $target, $request);
        }

        return $this->attempt(function () use ($actor, $item, $target, $request): File {
            $copy = $this->storage->copy($item, $target, $actor);
            $this->thumbnails->generate($copy);

            $this->events->record(ActivityAction::Copied, $copy, $actor, $request, [
                'from_owner' => $item->owner_name,
                'from' => $item->folder?->is_root ? 'storage root' : $item->folder?->name,
                'to' => $target->is_root ? 'storage root' : $target->name,
                'source' => $item->downloadName(),
            ]);

            return $copy;
        }, ActivityAction::Copied, $item, $actor, $request);
    }

    /**
     * A folder and everything beneath it.
     *
     * Breadth-first by depth, so a child's new parent always exists by the time
     * the child is created. Each descendant's ability is resolved against the
     * *original*, because that is what the actor is being allowed to take a copy
     * of; the copies are theirs outright.
     */
    private function copyFolder(User $actor, Folder $folder, Folder $target, ?Request $request): Folder
    {
        if ($folder->is_root) {
            throw new RuntimeException('A root folder cannot be copied.');
        }

        // Into itself or its own subtree the copy would recurse forever, and
        // the check has to happen before anything is written.
        if ($target->id === $folder->id || str_starts_with($target->path, $folder->childPath())) {
            throw ValidationException::withMessages([
                'target' => 'A folder cannot be copied into itself or one of its own subfolders.',
            ]);
        }

        return $this->attempt(function () use ($actor, $folder, $target, $request): Folder {
            $descendants = Folder::query()->subtreeOf($folder)->orderBy('depth')->get();

            // Original folder id => its copy. Seeded with the folder itself, so
            // the loop below needs no special case for the top of the subtree.
            $copies = [];
            $copiedFolders = 0;
            $copiedFiles = 0;
            $skipped = 0;

            foreach ($descendants as $source) {
                $parent = $source->id === $folder->id ? $target : ($copies[$source->parent_id] ?? null);

                if (! $parent) {
                    // Its parent was skipped, so there is nowhere to put it.
                    $skipped++;

                    continue;
                }

                if ($source->id !== $folder->id && ! $this->access->allows($actor, $source, FileAbility::Copy)) {
                    $skipped++;

                    continue;
                }

                $copies[$source->id] = $this->folders->create(
                    $actor,
                    $parent,
                    $this->freeFolderName($parent, $source->name),
                    // The destination's privacy wins, exactly as it does for a
                    // single file: a public folder copied under a private one
                    // must not drag its openness across.
                    $parent->visibility?->value,
                );
                $copiedFolders++;
            }

            foreach (File::query()->whereIn('folder_id', $descendants->pluck('id'))->get() as $file) {
                $destination = $copies[$file->folder_id] ?? null;

                if (! $destination || ! $this->access->allows($actor, $file, FileAbility::Copy)) {
                    $skipped++;

                    continue;
                }

                $this->thumbnails->generate($this->storage->copy($file, $destination, $actor));
                $copiedFiles++;
            }

            $root = $copies[$folder->id];

            // One row for the operation, on the copy, carrying what it actually
            // did — including what it could not take, so a short copy is
            // visible in the trail rather than looking complete.
            $this->events->record(ActivityAction::Copied, $root, $actor, $request, [
                'from_owner' => $folder->owner_name,
                'from' => $folder->name,
                'to' => $target->is_root ? 'storage root' : $target->name,
                'folders' => $copiedFolders,
                'files' => $copiedFiles,
                'skipped' => $skipped,
            ]);

            return $root;
        }, ActivityAction::Copied, $folder, $actor, $request);
    }

    // ------------------------------------------------------------------ uploads

    public function upload(User $actor, Folder $folder, UploadedFile $upload, ?Request $request = null): File
    {
        $this->assert($actor, $folder, FileAbility::Rename, 'You cannot upload into that folder.');

        return DB::transaction(function () use ($actor, $folder, $upload, $request): File {
            $file = $this->storage->store($upload, $folder, $actor);
            $this->thumbnails->generate($file);

            $this->events->record(ActivityAction::Uploaded, $file, $actor, $request, [
                'size' => $file->size_bytes,
                'type' => $file->mime_type,
            ]);

            return $file;
        });
    }

    // ----------------------------------------------------------------- deleting

    /**
     * Soft-delete an item, and a folder's whole subtree with it.
     *
     * The subtree is walked here rather than left to a database cascade, which
     * is why `folders.parent_id` is `restrictOnDelete`: a cascade would
     * hard-delete descendants with no audit row and no way back. This writes
     * one row per subject and is reversible.
     */
    public function delete(User $actor, File|Folder $item, ?Request $request = null): void
    {
        $this->assert($actor, $item, FileAbility::Delete, 'You cannot delete that.');

        if ($item instanceof Folder && $item->is_root) {
            throw new RuntimeException('A root folder cannot be deleted.');
        }

        $this->attempt(function () use ($actor, $item, $request): void {
            if ($item instanceof Folder) {
                $descendants = Folder::query()->subtreeOf($item)->get();

                File::query()->whereIn('folder_id', $descendants->pluck('id'))->get()
                    ->each(function (File $file) use ($actor, $request): void {
                        $this->events->record(ActivityAction::Deleted, $file, $actor, $request, ['via' => 'folder']);
                        $file->delete();
                    });

                // Deepest first, so a parent is never removed before its child.
                $descendants->sortByDesc('depth')->each(function (Folder $folder) use ($actor, $item, $request): void {
                    if ($folder->id !== $item->id) {
                        $this->events->record(ActivityAction::Deleted, $folder, $actor, $request, ['via' => 'folder']);
                    }
                    $folder->delete();
                });
            } else {
                $item->delete();
            }

            $this->events->record(ActivityAction::Deleted, $item, $actor, $request);
        }, ActivityAction::Deleted, $item, $actor, $request);
    }

    /**
     * Bring a trashed item back to where it was.
     *
     * "Where it was" is the part that is easy to get wrong. An item's parent may
     * itself have been trashed — deleting a folder trashes its whole subtree, so
     * that is the *normal* case, not an edge one — or may have been purged
     * outright since. Restoring the row alone would leave it pointing at a
     * container no listing can reach: present in the table, absent from every
     * screen, and impossible to delete again.
     *
     * So the chain above it is restored with it, and when a link in that chain
     * is genuinely gone the item is reattached to the owner's root and the
     * activity row says so. Nothing is ever left orphaned, and nothing is
     * silently moved without a record of it.
     */
    public function restore(User $actor, File|Folder $item, ?Request $request = null): void
    {
        // Resolved against the owner, not the resolver: a soft-deleted item is
        // out of every listing, so a share on it cannot be the claim. Only
        // whoever owns it — or an administrator — can bring it back.
        if ($item->user_id !== $actor->id && ! $actor->can('files.manage')) {
            throw ValidationException::withMessages(['item' => 'You cannot restore that.']);
        }

        $this->attempt(function () use ($actor, $item, $request): void {
            $container = $this->restoreContainerOf($item, $actor);
            $reattached = false;

            if ($container === null) {
                // Its original home is gone for good. The owner's root always
                // exists, so that is where it lands.
                $container = $this->folders->rootFor($this->ownerOf($item, $actor));
                $reattached = true;
            }

            $originalName = $item->name;
            $name = $this->freeName($container, $originalName, $item);

            if ($item instanceof Folder) {
                $item->restore();

                if ($reattached) {
                    $this->folders->move($item, $container);
                }

                if ($name !== $originalName) {
                    $item->update(['name' => $name]);
                }
            } else {
                $item->restore();
                $item->update([
                    'folder_id' => $container->id,
                    'name' => $name,
                ]);
            }

            $this->events->record(ActivityAction::Restored, $item->refresh(), $actor, $request, [
                'to' => $container->is_root ? 'storage root' : $container->name,
                // Both recorded, because "we could not put it back where it
                // was" and "we had to rename it to fit" are the two things a
                // person needs told after a restore.
                'reattached' => $reattached,
                'renamed_to' => $name !== $originalName ? $name : null,
            ]);
        }, ActivityAction::Restored, $item, $actor, $request);
    }

    /**
     * Permanently remove a trashed item, and a folder's whole subtree with it.
     *
     * Irreversible, so it is deliberately narrow: only a trashed item, only its
     * owner or an administrator, and an activity row per subject written
     * **before** the delete — afterwards there is nothing left to snapshot a
     * name from, and a purge that leaves no record of itself is the one audit
     * gap a purge must not create.
     *
     * The bytes go with the row. A `files` row is the only thing that knows
     * where its bytes are, so deleting it without unlinking them leaks storage
     * nothing can ever find again.
     */
    public function purge(User $actor, File|Folder $item, ?Request $request = null): void
    {
        if ($item->user_id !== $actor->id && ! $actor->can('files.manage')) {
            throw ValidationException::withMessages(['item' => 'You cannot delete that permanently.']);
        }

        if ($item->deleted_at === null) {
            throw ValidationException::withMessages([
                'item' => 'Only an item already in the trash can be deleted permanently.',
            ]);
        }

        if ($item instanceof Folder && $item->is_root) {
            throw new RuntimeException('A root folder cannot be deleted.');
        }

        $this->attempt(function () use ($actor, $item, $request): void {
            if ($item instanceof File) {
                $this->purgeFile($item, $actor, $request);

                return;
            }

            $subtree = Folder::withTrashed()->subtreeOf($item)->get();

            File::withTrashed()->whereIn('folder_id', $subtree->pluck('id'))->get()
                ->each(fn (File $file) => $this->purgeFile($file, $actor, $request, ['via' => 'folder']));

            // Deepest first: `folders.parent_id` is restrictOnDelete, so a
            // parent removed before its child would be refused by the database.
            $subtree->sortByDesc('depth')->each(function (Folder $folder) use ($actor, $item, $request): void {
                $this->events->record(ActivityAction::Purged, $folder, $actor, $request, [
                    'owner' => $folder->owner_name,
                    'trashed_at' => $folder->deleted_at?->toIso8601String(),
                    ...($folder->id === $item->id ? [] : ['via' => 'folder']),
                ]);

                $folder->forceDelete();
            });
        }, ActivityAction::Purged, $item, $actor, $request);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function purgeFile(File $file, User $actor, ?Request $request, array $context = []): void
    {
        $this->events->record(ActivityAction::Purged, $file, $actor, $request, [
            'size' => $file->size_bytes,
            'owner' => $file->owner_name,
            'trashed_at' => $file->deleted_at?->toIso8601String(),
            ...$context,
        ]);

        $this->storage->delete($file);
        $file->forceDelete();
    }

    /**
     * The folder a restored item belongs in, restoring the trashed chain above
     * it on the way — or null when that chain cannot be rebuilt.
     *
     * Shallowest first, because `Folder::ancestors()` reads the materialized
     * path and a folder restored before its own parent would be visible from
     * nowhere in between.
     */
    private function restoreContainerOf(File|Folder $item, User $actor): ?Folder
    {
        $containerId = $item instanceof File ? $item->folder_id : $item->parent_id;

        if ($containerId === null) {
            return null;
        }

        $container = Folder::withTrashed()->find($containerId);

        if (! $container) {
            return null;
        }

        /*
         * `withTrashed()`, and that is the whole point of this method.
         * `Folder::ancestors()` goes through the model's default query, so the
         * soft-delete scope hides exactly the folders that need restoring —
         * deleting a folder trashes its entire subtree, so a trashed chain is
         * the normal case here rather than an unusual one.
         */
        $ancestorIds = $container->ancestorPublicIds();
        $chain = Folder::withTrashed()
            ->whereIn('public_id', $ancestorIds)
            ->orderBy('depth')
            ->get();

        // A gap in the path — an ancestor purged while its descendants sat in
        // the trash — means the chain cannot be rebuilt at all, so the caller
        // reattaches to the root instead of restoring into a folder that is
        // reachable from nowhere.
        if ($chain->count() !== count($ancestorIds)) {
            return null;
        }

        $chain->push($container)
            // Shallowest first: a folder must never be visible before the
            // folder holding it.
            ->sortBy('depth')
            ->filter(fn (Folder $folder) => $folder->trashed())
            ->each(function (Folder $folder) use ($actor): void {
                if ($folder->user_id !== $actor->id && ! $actor->can('files.manage')) {
                    return;
                }

                $folder->restore();
            });

        $container->refresh();

        return $container->trashed() ? null : $container;
    }

    private function ownerOf(File|Folder $item, User $fallback): User
    {
        return $item->owner ?? $fallback;
    }

    // --------------------------------------------------------------- visibility

    public function setVisibility(User $actor, File|Folder $item, FileVisibility $visibility, ?Request $request = null): void
    {
        $this->assert($actor, $item, FileAbility::Share, 'You cannot change the privacy of that.');

        $from = $item->visibility;

        DB::transaction(function () use ($actor, $item, $visibility, $from, $request): void {
            $item->update(['visibility' => $visibility->value]);

            $this->events->record(ActivityAction::VisibilityChanged, $item, $actor, $request, [
                'from' => $from?->value,
                'to' => $visibility->value,
            ]);
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Run a structural operation, recording it whichever way it goes.
     *
     * Everything here already writes a row on success. What this adds is the
     * other half: **a critical operation must not fail silently.** A move that
     * half-happened, a copy that ran out of disk, a purge that could not unlink
     * its bytes — each leaves the trail saying nothing at all, so the first
     * anybody knows is a folder that is not where it was put.
     *
     * Two things about it are load-bearing.
     *
     * The failure row is written **outside** the transaction, after the
     * rollback. Inside, it would be rolled back with the work it was recording
     * — which is the trap that makes "log the error in the same transaction"
     * useless exactly when it matters.
     *
     * And the exception is re-thrown untouched, so the caller still answers the
     * way it did before: a refusal is still a flash, a bug is still a 500. This
     * records, it does not handle.
     *
     * Authorization refusals are deliberately outside this: they are resolved
     * before the transaction opens, and recording them here would let anyone
     * holding an id fill the trail with rows about items they cannot touch.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function attempt(
        callable $work,
        ActivityAction $action,
        File|Folder $subject,
        User $actor,
        ?Request $request,
    ): mixed {
        try {
            return DB::transaction($work);
        } catch (\Throwable $e) {
            $this->events->record($action, $subject, $actor, $request, [
                'outcome' => 'failed',
                // The class, not the stack: the trail is read by people, and a
                // message can carry a path or a query. The log has the rest.
                'error' => class_basename($e),
            ]);

            throw $e;
        }
    }

    /**
     * Resolve an ability, or refuse.
     *
     * A validation exception rather than a 403, so the refusal arrives as a
     * flash the shell already knows how to show — the same shape every other
     * refusal in this application takes.
     */
    private function assert(User $actor, File|Folder $item, FileAbility $ability, string $message): void
    {
        if (! $this->access->allows($actor, $item, $ability)) {
            throw ValidationException::withMessages(['item' => $message]);
        }
    }

    private function containerOf(File|Folder $item): Folder
    {
        $container = $item instanceof File ? $item->folder : $item->parent;

        if (! $container) {
            throw new RuntimeException('That item is not inside a folder.');
        }

        return $container;
    }

    /**
     * `(parent_id, name)` and `(folder_id, name)` are unique, so a collision is
     * a failed insert rather than a message. Checked here so the user is told
     * what is wrong.
     *
     * **`withTrashed`, because the index does not care that a row is
     * soft-deleted.** A name held by something in the trash is still taken as
     * far as MySQL is concerned, so leaving trashed rows out turns a message
     * into a 500 — and the message has to say *why*, or the folder looks empty
     * and the refusal looks like a bug.
     */
    private function assertNameFree(Folder $container, string $name, File|Folder|null $ignore = null): void
    {
        $folderTaken = Folder::withTrashed()
            ->where('parent_id', $container->id)
            ->where('name', $name)
            ->when($ignore instanceof Folder, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->exists();

        $fileTaken = File::withTrashed()
            ->where('folder_id', $container->id)
            ->where('name', $name)
            ->when($ignore instanceof File, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($folderTaken || $fileTaken) {
            throw ValidationException::withMessages([
                'name' => 'Something with that name is already in this folder, or is in the trash waiting to be deleted for good.',
            ]);
        }
    }

    /**
     * A name free inside this container, suffixed if it is not.
     *
     * Where `assertNameFree()` refuses — a move, a rename, where the person
     * named a destination and deserves to be told it is occupied — a copy and a
     * restore must still land. A restore has no second chance to ask: the only
     * alternatives to renaming are leaving the item in the trash or overwriting
     * what is already there.
     */
    private function freeName(Folder $container, string $name, File|Folder|null $ignore = null): string
    {
        // withTrashed, for the same reason assertNameFree() uses it: the
        // unique index is enforced over soft-deleted rows too.
        $taken = function (string $candidate) use ($container, $ignore): bool {
            $folder = Folder::withTrashed()
                ->where('parent_id', $container->id)
                ->where('name', $candidate)
                ->when($ignore instanceof Folder, fn ($q) => $q->whereKeyNot($ignore->getKey()))
                ->exists();

            $file = File::withTrashed()
                ->where('folder_id', $container->id)
                ->where('name', $candidate)
                ->when($ignore instanceof File, fn ($q) => $q->whereKeyNot($ignore->getKey()))
                ->exists();

            return $folder || $file;
        };

        if (! $taken($name)) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $name : pathinfo($name, PATHINFO_FILENAME);
        $suffix = $extension === '' ? '' : ".{$extension}";

        for ($n = 2; $n < 1000; $n++) {
            $candidate = mb_substr($stem, 0, 200)." ({$n}){$suffix}";

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return mb_substr($stem, 0, 200).' ('.now()->format('YmdHis').')'.$suffix;
    }

    private function freeFolderName(Folder $parent, string $name): string
    {
        return $this->freeName($parent, $name);
    }
}
