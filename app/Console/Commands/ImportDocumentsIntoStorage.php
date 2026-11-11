<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Files\FilePathFactory;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Carries documents uploaded before private storage existed into the new tree.
 *
 * Those rows point at `storage/app/public/documents/<hash>.pdf` — a path the
 * web server serves directly, with no authorization check. Each one becomes a
 * `files` row on the private disk with its own opaque identifier, and the
 * document's `file_id` is set to it.
 *
 * `Document::storedFile()` falls back to the old path while this has not run,
 * so the application keeps working throughout and this can be run at leisure.
 * Only once it reports nothing left to do is it safe to delete
 * `storage/app/public/documents`.
 *
 * Idempotent, and transactional per document: a document that fails is
 * reported and skipped, leaving the rest to carry over.
 */
class ImportDocumentsIntoStorage extends Command
{
    protected $signature = 'files:import-documents
        {--dry-run : Report what would move without writing anything}';

    protected $description = 'Move pre-existing documents from the public disk into private file storage';

    public function handle(FolderService $folders, FilePathFactory $paths): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $pending = Document::query()
            ->whereNull('file_id')
            ->whereNotNull('file_path')
            ->with('user')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing to import — every document already points at a stored file.');

            return self::SUCCESS;
        }

        $this->info("{$pending->count()} document(s) to import.".($dryRun ? ' (dry run)' : ''));

        $moved = 0;
        $missing = 0;
        $failed = 0;

        foreach ($pending as $document) {
            $source = $document->file_path;

            if (! Storage::disk('public')->exists($source)) {
                // The row's bytes are already gone. Left alone deliberately:
                // the document record, its code and its trail are still the
                // office's record of what happened, and inventing an empty
                // file row would hide that the file is missing.
                $this->warn("  ! {$document->code}: no file at public/{$source} — skipped");
                $missing++;

                continue;
            }

            if ($dryRun) {
                $this->line("  · {$document->code}: would import {$source}");
                $moved++;

                continue;
            }

            try {
                $this->import($document, $folders, $paths);
                $this->line("  ✓ {$document->code}");
                $moved++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$document->code}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Imported: {$moved}   Missing file: {$missing}   Failed: {$failed}");

        if (! $dryRun && $failed === 0 && $missing === 0) {
            $this->info('Safe to remove storage/app/public/documents once you have a backup.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One document, in one transaction.
     *
     * The bytes are copied rather than moved, and the old file is left in
     * place: this has to be re-runnable, and a half-finished move that has
     * already deleted the source is not recoverable.
     */
    private function import(Document $document, FolderService $folders, FilePathFactory $paths): void
    {
        DB::transaction(function () use ($document, $folders, $paths): void {
            $owner = $document->user ?? $this->unassignedOwner();
            $folder = $this->importFolder($owner, $document, $folders);

            $originalName = $document->file_name ?: basename($document->file_path);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            $file = File::create([
                'user_id' => $document->user_id,
                // The snapshot the audit tables already use: the name as it was,
                // so the row stays readable after the account is gone.
                'owner_name' => $document->uploader_name ?? $owner->name,
                'folder_id' => $folder->id,
                'name' => $originalName,
                'original_name' => $originalName,
                'extension' => $extension,
                'mime_type' => Storage::disk('public')->mimeType($document->file_path) ?: 'application/octet-stream',
                'size_bytes' => Storage::disk('public')->size($document->file_path) ?: 0,
                'disk' => FileStorage::DISK,
                'storage_path' => '',
                // Mirrors the document's own visibility, so the file is no more
                // open than the document it belongs to.
                'visibility' => $document->isPublic() ? 'public' : 'private',
                'source' => 'import',
            ]);

            $path = $paths->for($file);
            Storage::disk(FileStorage::DISK)->writeStream(
                $path,
                Storage::disk('public')->readStream($document->file_path),
            );
            $file->update(['storage_path' => $path]);

            $document->update(['file_id' => $file->id]);

            $this->importQrLabel($document);
        });
    }

    /**
     * The QR label, moved to the same private disk.
     *
     * `qr_code_path` is left exactly as it is — it is a bare filename, and
     * `downloadQr()` matches the request against it before touching a disk.
     * Rows from before the switch to SVG still carry `.png` names and keep
     * working.
     */
    private function importQrLabel(Document $document): void
    {
        if (! $document->qr_code_path) {
            return;
        }

        $source = 'qrcodes/'.basename($document->qr_code_path);

        if (Storage::disk('public')->exists($source) && ! Storage::disk(FileStorage::DISK)->exists($source)) {
            Storage::disk(FileStorage::DISK)->writeStream($source, Storage::disk('public')->readStream($source));
        }
    }

    private function importFolder(User $owner, Document $document, FolderService $folders): Folder
    {
        $root = $folders->rootFor($owner);
        $name = 'Tracked Documents '.$document->created_at?->format('Y') ?? now()->format('Y');

        return Folder::query()
            ->where('parent_id', $root->id)
            ->where('name', $name)
            ->first() ?? $folders->create($owner, $root, $name);
    }

    /**
     * Where a document whose uploader has been deleted is filed.
     *
     * `documents.user_id` is `nullOnDelete`, so an orphaned document is an
     * expected state rather than a corruption. Its bytes still need a folder,
     * and that folder needs an owner — so there is one holding account for
     * them, reachable only by someone who can manage all storage.
     */
    private function unassignedOwner(): User
    {
        return User::firstOrCreate(
            ['employee_id' => 'UNASSIGNED'],
            [
                'first_name' => 'Unassigned',
                'last_name' => 'storage',
                'password' => bcrypt(bin2hex(random_bytes(32))),
            ]
        );
    }
}
