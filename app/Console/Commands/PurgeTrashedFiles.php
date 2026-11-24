<?php

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Services\Files\FilePathFactory;
use App\Services\Files\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Permanently remove long-trashed files, and the files nothing points at.
 *
 * Two jobs, and the second is the one that was a standing leak: replacing a
 * document's file left the superseded `files` row and its bytes in place
 * forever, and nothing pruned them. They are not web-reachable — that stopped
 * being true when storage moved to the private disk — so it is wasted space
 * rather than an exposure, but it grows without bound.
 *
 * Deliberately conservative. It will not touch:
 *
 * - anything still soft-deleted for less than `--days`;
 * - a file a `documents` row still points at, however old;
 * - a file that is shared, or that has a live share link.
 *
 * The audit rows are never touched. They snapshot their subject's name and
 * public id and carry no foreign key to it, so the history still reads after
 * the file is gone — which is the whole reason that table is shaped that way.
 */
class PurgeTrashedFiles extends Command
{
    protected $signature = 'files:purge
        {--days=30 : Only purge items trashed at least this many days ago}
        {--orphans : Also remove files no document and no folder points at}
        {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Permanently remove long-trashed files and folders, and optionally orphaned ones';

    public function handle(FileStorage $storage, FilePathFactory $paths): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Purging items trashed before {$cutoff->toDateTimeString()}".($dryRun ? ' (dry run)' : '').'.');

        $files = File::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->with('document')
            ->get();

        $purged = 0;
        $kept = 0;

        foreach ($files as $file) {
            $reason = $this->reasonToKeep($file);

            if ($reason !== null) {
                $this->line("  · keeping {$file->name}: {$reason}");
                $kept++;

                continue;
            }

            if ($dryRun) {
                $this->line("  · would purge {$file->name}");
                $purged++;

                continue;
            }

            $this->purge($file, $storage);
            $this->line("  ✓ purged {$file->name}");
            $purged++;
        }

        // Folders last, and only once empty: a folder holding a file that was
        // kept must stay, or the file has no container.
        $folders = Folder::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->where('is_root', false)
            ->orderByDesc('depth')
            ->get();

        $purgedFolders = 0;

        foreach ($folders as $folder) {
            $hasChildren = Folder::withTrashed()->where('parent_id', $folder->id)->exists()
                || File::withTrashed()->where('folder_id', $folder->id)->exists();

            if ($hasChildren) {
                continue;
            }

            if (! $dryRun) {
                $folder->forceDelete();
            }

            $purgedFolders++;
        }

        if ($this->option('orphans')) {
            $purged += $this->purgeOrphans($storage, $dryRun);
        }

        $this->newLine();
        $this->info("Files purged: {$purged}   kept: {$kept}   folders purged: {$purgedFolders}");

        return self::SUCCESS;
    }

    /**
     * Why this file should survive the purge, or null to remove it.
     */
    private function reasonToKeep(File $file): ?string
    {
        /*
         * A **deliberately filed** document's bytes. `documents.file_id` is
         * `nullOnDelete`, so removing these would leave a document reporting
         * its file as missing — and a tracking code printed on paper
         * pointing at nothing.
         *
         * Narrowed to deliberate filings, because every uploaded file
         * carries a jacket now and the unnarrowed test would keep every file
         * forever: the sweep would stop collecting anything at all. The
         * reasoning the guard was written from is a fact about something
         * somebody chose to file and may have printed; an automatic filing
         * is a jacket on the bytes, so the bytes going means the jacket
         * goes. `FileManager::purge()` withdraws it.
         */
        $filed = $file->document()->withTrashed()->first();

        if ($filed && $filed->filed->governsItsFile()) {
            return 'a tracked document still points at it';
        }

        if ($file->shares()->exists()) {
            return 'it is still shared';
        }

        if ($file->shareLinks()->whereNull('revoked_at')->exists()) {
            return 'it still has a share link';
        }

        return null;
    }

    /**
     * Files nothing points at.
     *
     * A superseded document upload is the case this exists for: `update()`
     * repointed `documents.file_id` at the replacement and left the old row
     * behind, owned by nobody's folder tree in any meaningful sense.
     *
     * Only files whose `source` says they came from a document upload and that
     * no document references — a file in someone's storage that simply has no
     * shares is not an orphan, it is a private file.
     */
    private function purgeOrphans(FileStorage $storage, bool $dryRun): int
    {
        $orphans = File::query()
            ->whereDoesntHave('document')
            ->whereIn('source', ['upload', 'import'])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('documents')
                ->whereColumn('documents.file_id', 'files.id'))
            // Only ones filed under a Tracked Documents folder: those are
            // document uploads, so one with no document is genuinely superseded.
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('folders')
                ->whereColumn('folders.id', 'files.folder_id')
                ->where('folders.name', 'like', 'Tracked Documents%'))
            ->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        $this->newLine();
        $this->info("{$orphans->count()} superseded document upload(s) with no document pointing at them.");

        foreach ($orphans as $file) {
            if ($dryRun) {
                $this->line("  · would purge orphan {$file->name}");

                continue;
            }

            $this->purge($file, $storage);
            $this->line("  ✓ purged orphan {$file->name}");
        }

        return $orphans->count();
    }

    /**
     * Remove the row and the bytes, recording it.
     *
     * The activity row is written **before** the delete, while the file is
     * still there to snapshot its name from — afterwards there would be nothing
     * to name, and a purge that leaves no record of itself is the one audit gap
     * a purge must not create.
     */
    private function purge(File $file, FileStorage $storage): void
    {
        DB::transaction(function () use ($file, $storage): void {
            ActivityLog::record(null, ActivityAction::Purged, $file, [
                'size' => $file->size_bytes,
                'owner' => $file->owner_name,
                'trashed_at' => $file->deleted_at?->toIso8601String(),
            ]);

            $storage->delete($file);
            $file->forceDelete();
        });
    }
}
