<?php

namespace App\Services\Files;

use App\Enums\FileVisibility;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The folder tree's invariants.
 *
 * Everything that creates or moves a folder goes through here, because `path`
 * and `depth` are derived columns: set them in two places and they drift, and a
 * drifted `path` silently breaks visibility inheritance — which fails *open*
 * for a confidential subtree if the ancestor chain is wrong.
 */
class FolderService
{
    /**
     * The account's root folder, created on first use.
     *
     * Exactly one per account. The `(parent_id, name)` unique index cannot
     * enforce that, because MySQL treats the NULL parent of every root as
     * distinct — so this method is the rule, and it takes a lock rather than
     * trusting `firstOrCreate` not to race two simultaneous first uploads.
     */
    public function rootFor(User $user): Folder
    {
        return DB::transaction(function () use ($user): Folder {
            $existing = Folder::query()
                ->where('user_id', $user->id)
                ->where('is_root', true)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return Folder::create([
                'user_id' => $user->id,
                'owner_name' => $user->name,
                'parent_id' => null,
                'is_root' => true,
                // Never shown — the UI renders the owner's name as the crumb.
                'name' => "root-{$user->id}",
                'path' => '/',
                'depth' => 0,
                // Set explicitly rather than left to the column default: a
                // default lives in the database, so the model returned by
                // create() would carry null and the next read of
                // ->visibility->value would fail on it.
                'visibility' => FileVisibility::Private->value,
            ]);
        });
    }

    /**
     * A child folder, with its derived columns taken from its parent.
     */
    public function create(User $owner, Folder $parent, string $name, ?string $visibility = null): Folder
    {
        return Folder::create([
            'user_id' => $owner->id,
            'owner_name' => $owner->name,
            'parent_id' => $parent->id,
            'is_root' => false,
            'name' => $name,
            'path' => $parent->childPath(),
            'depth' => $parent->depth + 1,
            'visibility' => $visibility ?? ($parent->visibility?->value ?? FileVisibility::Private->value),
        ]);
    }

    /**
     * Move a folder, rewriting the subtree's derived columns.
     *
     * One UPDATE per descendant level rather than per descendant: the whole
     * subtree shares a path prefix, so the rewrite is a string replacement on
     * the column.
     */
    public function move(Folder $folder, Folder $target): Folder
    {
        if ($folder->is_root) {
            throw new RuntimeException('A root folder cannot be moved.');
        }

        $this->assertNotIntoOwnSubtree($folder, $target);

        return DB::transaction(function () use ($folder, $target): Folder {
            $oldPrefix = $folder->childPath();

            $folder->parent_id = $target->id;
            $folder->path = $target->childPath();
            $folder->depth = $target->depth + 1;
            $folder->save();

            $newPrefix = $folder->childPath();
            $shift = $folder->depth + 1 - ($this->depthOf($oldPrefix));

            Folder::query()
                ->where('path', 'like', $oldPrefix.'%')
                ->update([
                    'path' => DB::raw(sprintf(
                        'CONCAT(%s, SUBSTRING(path, %d))',
                        DB::getPdo()->quote($newPrefix),
                        strlen($oldPrefix) + 1,
                    )),
                    'depth' => DB::raw(sprintf('depth + %d', $shift)),
                ]);

            return $folder->refresh();
        });
    }

    /**
     * A folder cannot be moved inside itself or any of its own descendants.
     *
     * Without this the subtree is detached from the root entirely: its path
     * still claims an ancestor chain that no longer reaches a root, so it
     * vanishes from every listing while its rows stay in the table.
     */
    private function assertNotIntoOwnSubtree(Folder $folder, Folder $target): void
    {
        if ($target->id === $folder->id || str_starts_with($target->path, $folder->childPath())) {
            throw new RuntimeException('A folder cannot be moved into itself or one of its own subfolders.');
        }
    }

    private function depthOf(string $path): int
    {
        return count(array_filter(explode('/', $path)));
    }
}
