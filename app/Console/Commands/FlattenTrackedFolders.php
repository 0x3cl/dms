<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Files\FileManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empty the `Tracked Documents {year}` folders the register used to invent.
 *
 * Every tracked upload once landed in a folder this application created on
 * the spot, because the register's form had no destination field. The form
 * asks now and defaults to the top of the uploader's own storage, so nothing
 * creates one any more — but the folders that already exist still hold
 * everything filed before that, which means the thing people complained
 * about is still on screen. A default that has been removed is only removed
 * once the rows it produced are gone too.
 *
 * What it does, per owner: move every file out of such a folder into that
 * owner's storage root, then remove the folder if it is empty.
 *
 * Three decisions worth stating.
 *
 * **It moves through `FileManager::move()`** rather than updating
 * `folder_id`, so the ability check, the transaction, the name-collision
 * rule and the `moved` activity row are the ones every other move in this
 * application gets. A migration that rearranges somebody's storage with no
 * trail is indistinguishable, afterwards, from files going missing.
 *
 * **A name already taken at the root is numbered, not refused.** `move()`
 * throws on a collision, which is right for somebody at a screen who can
 * answer — and wrong here, where there is nobody to ask and the alternative
 * is leaving the file in the folder this command exists to empty. So the
 * name is freed first, by a rename through the same service, which is the
 * reasoning `FileManager::restore()` already follows for the same reason.
 *
 * **A folder that is not empty afterwards is left alone**, with a line
 * saying so. Something the sweep did not expect is in it — a sub-folder
 * somebody made, a file it could not move — and deleting a container with
 * contents is not a tidy-up.
 */
class FlattenTrackedFolders extends Command
{
    protected $signature = 'files:flatten-tracked-folders
        {--dry-run : Report what would move without moving it}';

    protected $description = 'Move files out of the old "Tracked Documents {year}" folders and remove them';

    public function handle(FileManager $manager): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Only a folder directly under a storage root, because that is the
         * one this application created. A folder somebody *named* "Tracked
         * Documents 2026" themselves, three levels down, is their filing and
         * none of this command's business.
         */
        $folders = Folder::query()
            ->where('name', 'like', 'Tracked Documents %')
            ->whereHas('parent', fn ($query) => $query->where('is_root', true))
            ->with('owner')
            ->get();

        if ($folders->isEmpty()) {
            $this->info('No "Tracked Documents" folders to flatten.');

            return self::SUCCESS;
        }

        $moved = 0;
        $removed = 0;
        $kept = 0;

        foreach ($folders as $folder) {
            $owner = $folder->owner;

            if (! $owner instanceof User) {
                $this->warn("Skipped {$folder->name}: its owner is gone.");
                $kept++;

                continue;
            }

            $root = $folder->parent;
            $files = File::query()->where('folder_id', $folder->id)->get();

            $this->line("{$owner->name} — {$folder->name}: {$files->count()} file(s)");

            foreach ($files as $file) {
                if ($dryRun) {
                    $this->line("  would move {$file->name} → storage root");
                    $moved++;

                    continue;
                }

                try {
                    $name = $this->freeNameIn($root, $file->name);

                    if ($name !== $file->name) {
                        $this->line("  {$file->name} → {$name} (the root already had that name)");
                        $manager->rename($owner, $file, $name);
                    }

                    $manager->move($owner, $file, $root);
                    $moved++;
                } catch (\Throwable $exception) {
                    // Reported and skipped, never swallowed: a file left
                    // behind is why the folder below is then kept.
                    $this->warn("  could not move {$file->name}: ".$exception->getMessage());
                }
            }

            if ($dryRun) {
                continue;
            }

            // Re-asked rather than assumed: a sub-folder, or a file that
            // refused to move, means this is no longer an empty container.
            $stillInside = File::withTrashed()->where('folder_id', $folder->id)->exists()
                || Folder::withTrashed()->where('parent_id', $folder->id)->exists();

            if ($stillInside) {
                $this->warn("  kept {$folder->name}: something is still in it.");
                $kept++;

                continue;
            }

            DB::transaction(fn () => $folder->forceDelete());
            $removed++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$moved} file(s) would move out of {$folders->count()} folder(s)."
            : "Files moved: {$moved}   folders removed: {$removed}   kept: {$kept}");

        return self::SUCCESS;
    }

    /**
     * A name nothing at the destination is already using.
     *
     * `withTrashed()`, because `(folder_id, name)` is unique over
     * soft-deleted rows too: a file thrown away last week still holds its
     * name, and ignoring that turns this sweep into a failed insert.
     */
    private function freeNameIn(Folder $container, string $name): string
    {
        $taken = fn (string $candidate): bool => File::withTrashed()
            ->where('folder_id', $container->id)
            ->where('name', $candidate)
            ->exists()
            || Folder::withTrashed()
                ->where('parent_id', $container->id)
                ->where('name', $candidate)
                ->exists();

        if (! $taken($name)) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));
        $suffix = $extension === '' ? '' : '.'.$extension;

        for ($n = 2; $n < 1000; $n++) {
            $candidate = "{$stem} ({$n}){$suffix}";

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // A thousand collisions on one name is not a case to invent a rule
        // for; the caller reports the refusal and leaves the file where it is.
        return $name;
    }
}
