<?php

namespace App\Services\Files;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hand everything one account owns to another.
 *
 * Needed because closing an account must not orphan its storage, and because a
 * person leaving an office is the ordinary case rather than an exception. The
 * alternative is what the application did before: the files stayed, pointing at
 * an account nobody could sign into, editable by nobody.
 *
 * What it does **not** touch is the audit trail. Every `activity_logs`,
 * `document_logs` and `document_downloads` row keeps the actor it recorded.
 * Ownership is a fact about now; an audit row is a fact about then, and
 * rewriting one to match the other would be falsifying the record.
 */
class OwnershipTransferService
{
    public function __construct(
        private readonly FolderService $folders,
        private readonly FileEventRecorder $events,
    ) {}

    /**
     * Move `$from`'s storage and documents to `$to`.
     *
     * @return array{folders: int, files: int, documents: int}
     */
    public function transfer(User $from, User $to, ?User $actor = null, ?Request $request = null): array
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'transfer_to' => 'Choose a different account to transfer to.',
            ]);
        }

        return DB::transaction(function () use ($from, $to, $actor, $request): array {
            $sourceRoot = $this->folders->rootFor($from);
            $targetRoot = $this->folders->rootFor($to);

            /*
             * The source's own children are reparented under the target's root,
             * and the source root itself is left in place.
             *
             * Moving the root would give the target two roots, which breaks the
             * one-root rule that `unique(parent_id, name)` depends on — MySQL
             * treats the NULL parent of every root as distinct, so nothing at
             * the database level would catch it.
             */
            $children = Folder::query()
                ->where('parent_id', $sourceRoot->id)
                ->withTrashed()
                ->get();

            $movedFolders = 0;

            foreach ($children as $child) {
                $this->folders->move($child, $targetRoot);
                $movedFolders++;

                $this->events->record(ActivityAction::OwnershipTransferred, $child->refresh(), $actor, $request, [
                    'from' => $from->name,
                    'to' => $to->name,
                ]);
            }

            // Loose files directly in the source root have no folder to carry
            // them across, so they are reparented individually.
            File::query()
                ->where('folder_id', $sourceRoot->id)
                ->withTrashed()
                ->update(['folder_id' => $targetRoot->id]);

            /*
             * Then the ownership columns, across the whole subtree rather than
             * only the roots that moved: a share, a listing and the resolver
             * all read `user_id` on the item itself.
             *
             * `withTrashed()` throughout — a soft-deleted file in the leaver's
             * trash is still theirs, and leaving it behind would make it
             * unrestorable by anyone.
             */
            $folderCount = Folder::query()
                ->withTrashed()
                ->where('user_id', $from->id)
                ->where('is_root', false)
                ->update(['user_id' => $to->id, 'owner_name' => $to->name]);

            $fileCount = File::query()
                ->withTrashed()
                ->where('user_id', $from->id)
                ->update(['user_id' => $to->id, 'owner_name' => $to->name]);

            /*
             * Tracked documents move too, but `uploader_name` is **not**
             * rewritten: it is the snapshot of who filed the document, printed
             * alongside a tracking code, and the new owner did not file it.
             * They can now edit and end it; they did not upload it.
             */
            $documentCount = Document::query()
                ->withTrashed()
                ->where('user_id', $from->id)
                ->update(['user_id' => $to->id]);

            ActivityLog::record($actor, ActivityAction::OwnershipTransferred, null, [
                'from' => $from->name,
                'from_employee_id' => $from->employee_id,
                'to' => $to->name,
                'to_employee_id' => $to->employee_id,
                'folders' => $folderCount,
                'files' => $fileCount,
                'documents' => $documentCount,
            ], $request);

            return [
                'folders' => $folderCount,
                'files' => $fileCount,
                'documents' => $documentCount,
            ];
        });
    }

    /**
     * What this account still owns, for the screen that asks before closing it.
     *
     * @return array{folders: int, files: int, documents: int, total: int}
     */
    public function holdings(User $user): array
    {
        $folders = Folder::query()->where('user_id', $user->id)->where('is_root', false)->count();
        $files = File::query()->where('user_id', $user->id)->count();
        $documents = Document::query()->where('user_id', $user->id)->count();

        return [
            'folders' => $folders,
            'files' => $files,
            'documents' => $documents,
            'total' => $folders + $files + $documents,
        ];
    }
}
