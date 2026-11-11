<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Files\FileManager;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What happens to a file between being deleted and being gone, and the two
 * operations that move one somewhere else.
 *
 * Four things here are easy to get wrong and are the reason this file exists:
 *
 * - a confirmation code that is only checked in the browser is a label on a
 *   button;
 * - a restore that puts a row back without its folder leaves it reachable from
 *   nowhere;
 * - a permanent delete that drops the row and not the bytes leaks storage
 *   nothing can ever find again;
 * - a folder copy that silently skips the folder does nothing and says it
 *   worked.
 */
class FileLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function root(User $user): Folder
    {
        return app(FolderService::class)->rootFor($user);
    }

    private function folder(User $owner, Folder $parent, string $name): Folder
    {
        return app(FolderService::class)->create($owner, $parent, $name);
    }

    private function fileIn(Folder $folder, User $owner, string $name = 'notes.pdf'): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->create($name, 8, 'application/pdf'),
            $folder,
            $owner,
        );
    }

    private function code(User $actor, string $purpose = 'delete'): string
    {
        return $this->actingAs($actor)
            ->postJson('/files/confirm-code', ['purpose' => $purpose])
            ->assertOk()
            ->json('code');
    }

    /**
     * @param  list<array{kind: string, public_id: string}>  $items
     */
    private function destroy(User $actor, array $items, ?string $code = null)
    {
        return $this->actingAs($actor)->post('/files/delete', [
            'items' => $items,
            'confirmation' => $code ?? $this->code($actor),
        ]);
    }

    // ------------------------------------------------------- the typed code

    public function test_a_delete_without_a_confirmation_code_is_refused(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user);

        $this->actingAs($user)->post('/files/delete', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertSessionHasErrors('confirmation');

        $this->assertNotSoftDeleted($file);
    }

    public function test_a_wrong_confirmation_code_is_refused(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user);

        $issued = $this->code($user);
        $wrong = str_pad((string) ((((int) $issued) + 1) % 10000), 4, '0', STR_PAD_LEFT);

        $this->destroy($user, [['kind' => 'file', 'public_id' => $file->public_id]], $wrong)
            ->assertSessionHasErrors('confirmation');

        $this->assertNotSoftDeleted($file);
    }

    /**
     * A code is spent on use, so it cannot be harvested once and replayed.
     *
     * This is the half a browser-side check cannot have: the second request
     * carries a code that was correct a moment ago and is refused anyway.
     */
    public function test_a_confirmation_code_works_once(): void
    {
        $user = User::factory()->create();
        $first = $this->fileIn($this->root($user), $user, 'one.pdf');
        $second = $this->fileIn($this->root($user), $user, 'two.pdf');

        $code = $this->code($user);

        $this->destroy($user, [['kind' => 'file', 'public_id' => $first->public_id]], $code)
            ->assertRedirect();
        $this->destroy($user, [['kind' => 'file', 'public_id' => $second->public_id]], $code)
            ->assertSessionHasErrors('confirmation');

        $this->assertSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
    }

    /** A code issued for the trash does not satisfy a permanent deletion. */
    public function test_a_delete_code_cannot_confirm_a_permanent_deletion(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user);
        $this->destroy($user, [['kind' => 'file', 'public_id' => $file->public_id]]);

        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'confirmation' => $this->code($user, 'delete'),
        ])->assertSessionHasErrors('confirmation');

        $this->assertSoftDeleted($file);
    }

    /** The dialog can state what a recursive delete would take with it. */
    public function test_the_delete_preview_counts_a_folders_whole_subtree(): void
    {
        $user = User::factory()->create();
        $parent = $this->folder($user, $this->root($user), 'Reports');
        $child = $this->folder($user, $parent, 'Q1');
        $this->fileIn($parent, $user, 'summary.pdf');
        $this->fileIn($child, $user, 'q1.pdf');

        $summary = $this->actingAs($user)
            ->getJson('/files/delete-preview?items[0][kind]=folder&items[0][public_id]='.$parent->public_id)
            ->assertOk()
            ->json();

        // The selected folder counts too: "will also be deleted" has to match
        // what actually disappears.
        $this->assertSame(2, $summary['folders']);
        $this->assertSame(2, $summary['files']);
        $this->assertSame(['Reports'], $summary['names']);
    }

    // --------------------------------------------------------- the trash

    /**
     * Deleting a folder trashes its subtree, and the trash shows that as one
     * entry rather than as every row underneath it.
     */
    public function test_the_trash_lists_what_was_deleted_and_not_its_whole_subtree(): void
    {
        $user = User::factory()->create();
        $parent = $this->folder($user, $this->root($user), 'Reports');
        $child = $this->folder($user, $parent, 'Q1');
        $this->fileIn($child, $user, 'q1.pdf');

        $this->destroy($user, [['kind' => 'folder', 'public_id' => $parent->public_id]]);

        $props = $this->actingAs($user)->get('/files/trash')->assertOk()->viewData('page')['props'];

        $this->assertSame(['Reports'], array_column((array) $props['folders'], 'name'));
        $this->assertSame([], array_column($props['files']['data'], 'name'));

        // And it says what went with it.
        $this->assertSame(1, $props['folders'][0]['contains']['folders']);
        $this->assertSame(1, $props['folders'][0]['contains']['files']);

        // Opening it shows the subtree, still deleted.
        $inside = $this->actingAs($user)
            ->get("/files/trash?folder={$child->public_id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(['q1.pdf'], array_column($inside['files']['data'], 'name'));
    }

    public function test_the_trash_refuses_a_folder_that_is_not_yours(): void
    {
        $owner = User::factory()->create();
        $folder = $this->folder($owner, $this->root($owner), 'Reports');
        $this->destroy($owner, [['kind' => 'folder', 'public_id' => $folder->public_id]]);

        $this->actingAs(User::factory()->create())
            ->get("/files/trash?folder={$folder->public_id}")
            ->assertNotFound();
    }

    // ------------------------------------------------------------ restoring

    /** A restore puts the item back where it came from. */
    public function test_a_restored_file_returns_to_its_original_folder(): void
    {
        $user = User::factory()->create();
        $folder = $this->folder($user, $this->root($user), 'Reports');
        $file = $this->fileIn($folder, $user, 'q1.pdf');

        $this->destroy($user, [['kind' => 'file', 'public_id' => $file->public_id]]);
        $this->actingAs($user)->post('/files/restore', [
            'kind' => 'file', 'public_id' => $file->public_id,
        ])->assertRedirect();

        $this->assertNotSoftDeleted($file);
        $this->assertSame($folder->id, $file->fresh()->folder_id);
    }

    /**
     * Restoring one file out of a deleted folder brings the folder back with
     * it.
     *
     * Deleting a folder soft-deletes its whole subtree, so a file whose own
     * folder is also trashed is the *normal* case in the trash. Restoring the
     * row alone would leave it inside a folder no listing can reach — present
     * in the table, absent from every screen.
     */
    public function test_restoring_a_file_revives_the_trashed_folders_above_it(): void
    {
        $user = User::factory()->create();
        $parent = $this->folder($user, $this->root($user), 'Reports');
        $child = $this->folder($user, $parent, 'Q1');
        $file = $this->fileIn($child, $user, 'q1.pdf');

        $this->destroy($user, [['kind' => 'folder', 'public_id' => $parent->public_id]]);
        $this->actingAs($user)->post('/files/restore', [
            'kind' => 'file', 'public_id' => $file->public_id,
        ])->assertRedirect();

        $this->assertNotSoftDeleted($file);
        $this->assertNotSoftDeleted($parent);
        $this->assertNotSoftDeleted($child);
        $this->assertSame($child->id, $file->fresh()->folder_id);
    }

    /**
     * A restore that cannot go home lands in the restorer's own root, and the
     * activity row says so.
     *
     * The reachable case is a file of *yours* sitting in a folder of somebody
     * *else's* — which is what taking a copy into a folder they shared with you
     * produces. They delete the folder, which trashes your file with it. You
     * restore your file: their folders are not yours to revive, so there is no
     * container to put it back into, and the only alternative to a root that
     * always exists is a row pointing at a folder no listing can reach.
     *
     * The other way a chain can break — the folder being erased outright while
     * the file sits in the trash — is prevented by the database rather than
     * handled here: `files.folder_id` and `folders.parent_id` are
     * `restrictOnDelete`, so nothing can be purged out from under a descendant.
     * The fall back below is the fail-safe for the case the schema does not
     * already make impossible.
     */
    public function test_a_restore_falls_back_to_the_root_when_the_folder_is_not_yours_to_revive(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();

        $shared = $this->folder($owner, $this->root($owner), 'Shared');
        app(SharingService::class)->share($shared, GranteeType::User, $taker->id, ShareAbility::Manage, $owner);

        // The taker's own file, in the owner's folder.
        $this->actingAs($taker)->post('/files/copy', [
            'target' => $shared->public_id,
            'items' => [['kind' => 'file', 'public_id' => $this->fileIn($this->root($taker), $taker, 'memo.pdf')->public_id]],
        ])->assertRedirect();

        $copy = File::query()->where('folder_id', $shared->id)->sole();
        $this->assertSame($taker->id, $copy->user_id);

        // The owner deletes their folder, which trashes the taker's file too.
        $this->destroy($owner, [['kind' => 'folder', 'public_id' => $shared->public_id]]);
        $this->assertSoftDeleted($copy);

        $this->actingAs($taker)->post('/files/restore', [
            'kind' => 'file', 'public_id' => $copy->public_id,
        ])->assertRedirect();

        $restored = $copy->fresh();
        $this->assertNotSoftDeleted($copy);
        $this->assertSame($this->root($taker)->id, $restored->folder_id);
        // The owner's folder stays deleted: restoring your file is not a way to
        // revive somebody else's folder.
        $this->assertSoftDeleted($shared);

        $row = ActivityLog::query()->where('action', ActivityAction::Restored->value)->latest('id')->first();
        $this->assertTrue($row->context['reattached']);
    }

    /**
     * And when that root already holds the name, the restore is renamed rather
     * than refused.
     *
     * A restore has no second chance to ask: the only alternatives to renaming
     * are leaving the item in the trash forever or overwriting what is there.
     */
    public function test_a_restore_renames_rather_than_colliding(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();

        $shared = $this->folder($owner, $this->root($owner), 'Shared');
        app(SharingService::class)->share($shared, GranteeType::User, $taker->id, ShareAbility::Manage, $owner);

        $original = $this->fileIn($this->root($taker), $taker, 'memo.pdf');
        $this->actingAs($taker)->post('/files/copy', [
            'target' => $shared->public_id,
            'items' => [['kind' => 'file', 'public_id' => $original->public_id]],
        ])->assertRedirect();

        $copy = File::query()->where('folder_id', $shared->id)->sole();
        $this->destroy($owner, [['kind' => 'folder', 'public_id' => $shared->public_id]]);

        $this->actingAs($taker)->post('/files/restore', [
            'kind' => 'file', 'public_id' => $copy->public_id,
        ])->assertRedirect();

        // `memo.pdf` is still in the taker's root, so the returning copy is
        // numbered beside it.
        $this->assertSame('memo (2).pdf', $copy->fresh()->name);
        $this->assertSame('memo.pdf', $original->fresh()->name);
    }

    /**
     * An ordinary upload of a name that is sitting in the trash is numbered,
     * not refused.
     *
     * `(folder_id, name)` is unique over soft-deleted rows too, so this was a
     * 500 on a perfectly reasonable action: deleting `notes.pdf` and uploading
     * another one the same afternoon.
     */
    public function test_a_name_held_by_a_trashed_file_is_numbered_not_refused(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);
        $first = $this->fileIn($root, $user, 'notes.pdf');

        $this->destroy($user, [['kind' => 'file', 'public_id' => $first->public_id]]);

        $second = $this->fileIn($root, $user, 'notes.pdf');

        $this->assertSame('notes (2).pdf', $second->name);
    }

    // ----------------------------------------------------- permanent delete

    public function test_a_permanent_delete_removes_the_row_the_bytes_and_leaves_a_record(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user);
        $path = $file->storage_path;

        $this->destroy($user, [['kind' => 'file', 'public_id' => $file->public_id]]);

        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'confirmation' => $this->code($user, 'purge'),
        ])->assertRedirect();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        // The bytes go with the row: a `files` row is the only thing that knows
        // where they are.
        Storage::disk(FileStorage::DISK)->assertMissing($path);
        // And the purge is on record, snapshotted while there was still a name
        // to snapshot.
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityAction::Purged->value,
            'subject_public_id' => $file->public_id,
        ]);
    }

    public function test_only_a_trashed_item_can_be_permanently_deleted(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user);

        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'confirmation' => $this->code($user, 'purge'),
        ])->assertNotFound();

        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_a_stranger_cannot_permanently_delete_someone_elses_item(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);
        $this->destroy($owner, [['kind' => 'file', 'public_id' => $file->public_id]]);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'confirmation' => $this->code($stranger, 'purge'),
        ])->assertSessionHasErrors('item');

        $this->assertSoftDeleted($file);
    }

    public function test_permanently_deleting_a_folder_takes_its_subtree(): void
    {
        $user = User::factory()->create();
        $parent = $this->folder($user, $this->root($user), 'Reports');
        $child = $this->folder($user, $parent, 'Q1');
        $file = $this->fileIn($child, $user, 'q1.pdf');

        $this->destroy($user, [['kind' => 'folder', 'public_id' => $parent->public_id]]);

        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'folder', 'public_id' => $parent->public_id]],
            'confirmation' => $this->code($user, 'purge'),
        ])->assertRedirect();

        $this->assertDatabaseMissing('folders', ['id' => $parent->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);

        // One row per subject, so the trail accounts for everything that went.
        $this->assertSame(3, ActivityLog::query()->where('action', ActivityAction::Purged->value)->count());
    }

    // -------------------------------------------------------- move and copy

    public function test_a_move_lands_in_the_chosen_folder(): void
    {
        $user = User::factory()->create();
        $from = $this->folder($user, $this->root($user), 'Inbox');
        $to = $this->folder($user, $this->root($user), 'Archive');
        $file = $this->fileIn($from, $user, 'memo.pdf');

        $this->actingAs($user)->post('/files/move', [
            'target' => $to->public_id,
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertRedirect();

        $this->assertSame($to->id, $file->fresh()->folder_id);

        $row = ActivityLog::query()->where('action', ActivityAction::Moved->value)->sole();
        $this->assertSame('Inbox', $row->context['from']);
        $this->assertSame('Archive', $row->context['to']);
    }

    /**
     * A folder copies with its contents.
     *
     * This used to be skipped outright — the endpoint ignored anything that was
     * not a file — so selecting a folder and choosing Copy did nothing and
     * reported success.
     */
    public function test_copying_a_folder_takes_its_contents(): void
    {
        $user = User::factory()->create();
        $source = $this->folder($user, $this->root($user), 'Reports');
        $inner = $this->folder($user, $source, 'Q1');
        $this->fileIn($source, $user, 'summary.pdf');
        $this->fileIn($inner, $user, 'q1.pdf');
        $target = $this->folder($user, $this->root($user), 'Archive');

        $this->actingAs($user)->post('/files/copy', [
            'target' => $target->public_id,
            'items' => [['kind' => 'folder', 'public_id' => $source->public_id]],
        ])->assertRedirect();

        $copy = Folder::query()->where('parent_id', $target->id)->where('name', 'Reports')->sole();
        $copiedInner = Folder::query()->where('parent_id', $copy->id)->where('name', 'Q1')->sole();

        $this->assertSame(1, File::query()->where('folder_id', $copy->id)->count());
        $this->assertSame(1, File::query()->where('folder_id', $copiedInner->id)->count());

        // The originals are untouched.
        $this->assertSame(1, File::query()->where('folder_id', $source->id)->count());

        $row = ActivityLog::query()->where('action', ActivityAction::Copied->value)->sole();
        $this->assertSame(2, $row->context['folders']);
        $this->assertSame(2, $row->context['files']);
    }

    /** Copying into the folder it already sits in duplicates rather than failing. */
    public function test_a_copy_into_the_same_folder_is_renamed(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);
        $file = $this->fileIn($root, $user, 'memo.pdf');

        $this->actingAs($user)->post('/files/copy', [
            'target' => $root->public_id,
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertRedirect();

        $this->assertSame(
            ['memo (2).pdf', 'memo.pdf'],
            File::query()->where('folder_id', $root->id)->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_a_folder_cannot_be_copied_into_itself(): void
    {
        $user = User::factory()->create();
        $source = $this->folder($user, $this->root($user), 'Reports');
        $inner = $this->folder($user, $source, 'Q1');

        $this->actingAs($user)->post('/files/copy', [
            'target' => $inner->public_id,
            'items' => [['kind' => 'folder', 'public_id' => $source->public_id]],
        ])->assertSessionHasErrors('target');

        $this->assertSame(0, Folder::query()->where('parent_id', $inner->id)->count());
    }

    // ----------------------------------------------------- recording failures

    /**
     * A critical operation that fails says so in the trail, and still fails.
     *
     * A purge that could not unlink its bytes, or a move that threw halfway,
     * used to leave the history saying nothing at all — so the first anybody
     * knew was a folder that was not where it had been put. The row is written
     * **after** the rollback: inside the transaction it would be rolled back
     * with the work it was recording, which is the trap that makes this kind of
     * logging useless exactly when it matters.
     *
     * Driven by making the byte write itself throw, because that is the real
     * shape of the problem — the database half of these operations is
     * transactional and the disk half is not.
     */
    public function test_a_failed_operation_leaves_a_row_saying_so_and_still_fails(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'memo.pdf');

        $this->destroy($user, [['kind' => 'file', 'public_id' => $file->public_id]]);

        // The disk refuses to let go of the bytes.
        $storage = $this->mock(FileStorage::class);
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('disk is read-only'));

        $this->expectException(\RuntimeException::class);

        try {
            app(FileManager::class)->purge($user->fresh(), $file->fresh());
        } finally {
            // The row is there, and the work is not: the file is still trashed
            // rather than half-erased.
            $this->assertDatabaseHas('activity_logs', [
                'action' => ActivityAction::Purged->value,
                'subject_public_id' => $file->public_id,
            ]);

            $failure = ActivityLog::query()
                ->where('action', ActivityAction::Purged->value)
                ->latest('id')
                ->sole();

            $this->assertSame('failed', $failure->context['outcome']);
            $this->assertSame('RuntimeException', $failure->context['error']);
            $this->assertSoftDeleted($file);
        }
    }

    // -------------------------------------------------------- the destination list

    /**
     * The picker offers only folders this person may write into.
     *
     * A destination list is a listing like any other, so it goes through the
     * resolver — and a read-only share is not a destination. Offering one would
     * mean a dialog whose choice the move then refuses.
     */
    public function test_the_destination_list_offers_only_folders_you_can_write_into(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();

        $mine = $this->folder($user, $this->root($user), 'Mine');
        $readOnly = $this->folder($owner, $this->root($owner), 'Read only');
        $writable = $this->folder($owner, $this->root($owner), 'Writable');

        app(SharingService::class)->share($readOnly, GranteeType::User, $user->id, ShareAbility::View, $owner);
        app(SharingService::class)->share($writable, GranteeType::User, $user->id, ShareAbility::Manage, $owner);

        $names = array_column(
            $this->actingAs($user)->getJson('/files/destinations')->assertOk()->json('folders'),
            'name',
        );

        $this->assertContains('Mine', $names);
        $this->assertContains('Writable', $names);
        $this->assertNotContains('Read only', $names);

        // The root is the picker's own top level and is offered as a target.
        $this->assertSame(
            $this->root($user)->public_id,
            $this->actingAs($user)->getJson('/files/destinations')->json('root.public_id'),
        );
        $this->assertNotNull($mine);
    }
}
