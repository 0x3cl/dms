<?php

namespace Tests\Feature\Acceptance;

use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance items 10 and 11 — My Files, Browse Storage, and the trash, worked
 * through as one sitting rather than one call at a time.
 *
 * Every action a row offers is exercised against the screen it is offered on,
 * in the order somebody would actually do them: make a folder, upload into it,
 * rename, move, copy, share, delete, find it in the trash, restore it, delete
 * it again, erase it. The claim is that each one does what the menu says *and*
 * leaves a record — the two halves that make a file manager trustworthy rather
 * than merely functional.
 *
 * The trash is the part worth the most attention, and for a specific reason:
 * deleting a folder soft-deletes its whole subtree, so **an item whose own
 * container is also trashed is the normal case there, not an edge one**.
 * Restoring the row alone would leave it inside a folder no listing can reach —
 * present in the table, absent from every screen, and impossible to delete
 * again.
 */
class StorageWorkflowAcceptanceTest extends TestCase
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

    private function fileIn(Folder $folder, User $owner, string $name = 'notes.pdf'): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->create($name, 8, 'application/pdf'),
            $folder,
            $owner,
        );
    }

    /** @param  list<array{kind: string, public_id: string}>  $items */
    private function trash(User $actor, array $items)
    {
        return $this->actingAs($actor)->post('/files/delete', ['items' => $items]);
    }

    private function purge(User $actor, string $kind, string $publicId)
    {
        $code = $this->actingAs($actor)
            ->postJson('/files/confirm-code', ['purpose' => 'purge'])
            ->assertOk()
            ->json('code');

        return $this->actingAs($actor)->post('/files/purge', [
            'items' => [['kind' => $kind, 'public_id' => $publicId]],
            'confirmation' => $code,
        ]);
    }

    /** @return list<string> */
    private function rowNames(array $props): array
    {
        return array_column($props['files']['data'] ?? [], 'name');
    }

    // --------------------------------------------- item 10: the menu works

    /**
     * Item 10: the whole menu, in one pass over one folder.
     *
     * New folder, upload, rename, move, copy — each through its own endpoint,
     * each asserted on the listing afterwards, because an action that succeeds
     * and does not show up is the failure people actually report.
     */
    public function test_every_action_on_a_row_does_what_it_says(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        // New folder.
        $this->actingAs($user)->post('/files/folders', [
            'parent' => $root->public_id,
            'name' => 'Memoranda',
        ])->assertSessionHasNoErrors();

        $folder = Folder::query()->where('name', 'Memoranda')->sole();

        // Upload into it.
        $this->actingAs($user)->post('/files/upload', [
            'folder' => $folder->public_id,
            'files' => [UploadedFile::fake()->create('draft.pdf', 8, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $file = File::query()->where('original_name', 'draft.pdf')->sole();
        $this->assertSame($folder->id, $file->folder_id);

        // Rename.
        $this->actingAs($user)->post('/files/rename', [
            'kind' => 'file',
            'public_id' => $file->public_id,
            'name' => 'final.pdf',
        ])->assertSessionHasNoErrors();

        $this->assertSame('final.pdf', $file->fresh()->name);

        // Move back to the root.
        $this->actingAs($user)->post('/files/move', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'target' => $root->public_id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($root->id, $file->fresh()->folder_id);

        // Copy, which is independent of its source and renamed rather than
        // colliding.
        $this->actingAs($user)->post('/files/copy', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'target' => $root->public_id,
        ])->assertSessionHasNoErrors();

        $names = $this->rowNames($this->actingAs($user)->get('/files')->viewData('page')['props']);

        $this->assertContains('final.pdf', $names);
        $this->assertCount(2, $names, 'The copy did not land beside its source.');
        $this->assertNotSame(
            File::query()->orderBy('id')->first()->storage_path,
            File::query()->orderBy('id')->skip(1)->first()->storage_path,
            'A copy shares its bytes with its source.',
        );

        // And every one of those left a row behind it.
        $recorded = ActivityLog::query()->pluck('action')->map(fn ($action) => $action->value)->all();

        foreach (['folder_created', 'uploaded', 'renamed', 'moved', 'copied'] as $action) {
            $this->assertContains($action, $recorded, "A {$action} left no record.");
        }
    }

    /**
     * Item 10: My Files lists the person's own storage and what was given to
     * them, each badged from the grant's own source.
     *
     * The badge comes from *why* access was allowed, not from ownership —
     * inferring it from `user_id !== $viewer->id` would tell an administrator
     * reading somebody's storage that they had been given it.
     */
    public function test_my_files_lists_my_own_and_what_was_shared_with_me(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();

        $mine = $this->fileIn($this->root($viewer), $viewer, 'mine.pdf');
        $given = $this->fileIn($this->root($owner), $owner, 'given.pdf');
        app(SharingService::class)->share($given, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        // Somebody else's private file, which was never given to this person.
        $this->fileIn($this->root($owner), $owner, 'theirs.pdf');

        $rows = $this->actingAs($viewer)->get('/files')->viewData('page')['props']['files']['data'];
        $names = array_column($rows, 'name');

        $this->assertContains('mine.pdf', $names);
        $this->assertContains('given.pdf', $names);
        $this->assertNotContains('theirs.pdf', $names, 'Somebody else\'s private file was listed.');

        $access = array_combine($names, array_column($rows, 'access'));
        $this->assertSame('owner', $access['mine.pdf']);
        $this->assertSame('shared', $access['given.pdf']);

        $this->assertNotNull($mine);

        // The filter narrows it to one side or the other, which is the whole
        // reason "Shared with Me" is not a screen of its own.
        $onlyMine = $this->rowNames($this->actingAs($viewer)->get('/files?access=mine')->viewData('page')['props']);
        $onlyShared = $this->rowNames($this->actingAs($viewer)->get('/files?access=shared')->viewData('page')['props']);

        $this->assertSame(['mine.pdf'], $onlyMine);
        $this->assertSame(['given.pdf'], $onlyShared);
    }

    /**
     * Item 10: a row carries the abilities resolved for *this* viewer, so the
     * menu a person is offered is the menu they can actually use.
     */
    public function test_a_view_only_row_offers_no_download_and_refuses_one(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner, 'report.pdf');
        app(SharingService::class)->share($file, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        $row = collect($this->actingAs($viewer)->get('/files')->viewData('page')['props']['files']['data'])
            ->firstWhere('name', 'report.pdf');

        $this->assertNotNull($row);
        // `can`, resolved per row for this viewer — not a property of the file.
        $this->assertTrue($row['can']['view']);
        $this->assertFalse($row['can']['download']);
        $this->assertFalse($row['can']['delete']);

        // And the endpoint agrees — the row's menu is a reflection of the
        // rule, never the rule itself.
        $this->actingAs($viewer)
            ->get("/files/{$owner->public_id}/{$file->public_id}")
            ->assertNotFound();
    }

    /**
     * Item 10: Browse Storage is the administrator's read across other
     * people's storage, and nobody else's.
     */
    public function test_browse_storage_is_the_administrators_read_and_is_refused_to_staff(): void
    {
        $admin = User::factory()->superadmin()->create();
        $owner = User::factory()->create();
        $staff = User::factory()->create();

        $this->fileIn($this->root($owner), $owner, 'theirs.pdf');

        $this->actingAs($staff)->get('/files/browse')->assertStatus(403);

        // With no account chosen it shows the accounts holding storage and no
        // rows: a list of everybody's files is not a useful first screen.
        $props = $this->actingAs($admin)->get('/files/browse')->assertOk()->viewData('page')['props'];

        $this->assertNull($props['files']);
        $this->assertContains($owner->public_id, collect($props['owners'])->pluck('public_id')->all());

        // Chosen, it reads that account's storage — and says why it is allowed
        // to, rather than claiming the rows were shared.
        $rows = $this->actingAs($admin)
            ->get("/files/browse?owner={$owner->public_id}")
            ->assertOk()
            ->viewData('page')['props']['files']['data'];

        $this->assertSame(['theirs.pdf'], array_column($rows, 'name'));
        // "Administrator view", from the grant's own source — not "shared
        // with you", which is what inferring it from ownership would say.
        $this->assertSame('administrative', $rows[0]['access']);
        $this->assertSame('Administrator view', $rows[0]['access_label']);
    }

    // -------------------------------------------------- item 11: the trash

    /**
     * Item 11, the whole cycle on a folder with contents: delete, find,
     * restore, and find everything back where it was.
     */
    public function test_a_deleted_folder_is_listed_once_and_restores_with_its_subtree(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);
        $folder = app(FolderService::class)->create($user, $root, 'Case Files');
        $inner = app(FolderService::class)->create($user, $folder, '2026');
        $file = $this->fileIn($inner, $user, 'case.pdf');

        $this->trash($user, [['kind' => 'folder', 'public_id' => $folder->public_id]])
            ->assertRedirect();

        // The whole subtree went.
        $this->assertSoftDeleted($folder);
        $this->assertSoftDeleted($inner);
        $this->assertSoftDeleted($file);

        // The trash lists what was deleted *directly* — a deleted folder's
        // hundred files are not a hundred rows to wade through.
        $props = $this->actingAs($user)->get('/files/trash')->assertOk()->viewData('page')['props'];

        $this->assertSame(['Case Files'], array_column($props['folders'], 'name'));
        $this->assertSame([], $this->rowNames($props));

        // Restore, and the subtree comes back with it — not an empty folder.
        $this->actingAs($user)->post('/files/restore', [
            'kind' => 'folder',
            'public_id' => $folder->public_id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($folder->fresh()->deleted_at);
        $this->assertNull($inner->fresh()->deleted_at);
        $this->assertNull($file->fresh()->deleted_at);
        $this->assertSame($inner->id, $file->fresh()->folder_id, 'The file came back somewhere else.');
    }

    /**
     * Item 11: a restore revives exactly the delete it was part of, and leaves
     * what somebody threw away earlier in the trash.
     *
     * "Revive everything trashed under here" is the wrong rule: a child
     * deleted last week, inside a folder deleted today, must stay in the trash
     * when the folder comes back — nobody asked for that one back.
     */
    public function test_a_restore_leaves_an_earlier_delete_in_the_trash(): void
    {
        $user = User::factory()->create();
        $folder = app(FolderService::class)->create($user, $this->root($user), 'Case Files');
        $early = $this->fileIn($folder, $user, 'early.pdf');
        $later = $this->fileIn($folder, $user, 'later.pdf');

        // Thrown away on its own, first.
        $this->trash($user, [['kind' => 'file', 'public_id' => $early->public_id]]);
        // Then the folder, which takes `later.pdf` with it.
        $this->trash($user, [['kind' => 'folder', 'public_id' => $folder->public_id]]);

        $this->actingAs($user)->post('/files/restore', [
            'kind' => 'folder',
            'public_id' => $folder->public_id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($later->fresh()->deleted_at, 'The folder came back without its contents.');
        $this->assertNotNull($early->fresh()->deleted_at, 'A restore dragged back an earlier delete.');

        // And the earlier one is still reachable in the trash, on its own.
        $props = $this->actingAs($user)->get('/files/trash')->viewData('page')['props'];
        $this->assertSame(['early.pdf'], $this->rowNames($props));
    }

    /**
     * Item 11: the permanent delete — what it asks for, and what it leaves.
     *
     * The code is issued by the **server** and spent on use. A code generated
     * and checked in the browser is a label on a button: the request
     * underneath such a dialog still deletes on its own, so a replayed form or
     * a stale tab bypasses the whole control.
     */
    public function test_a_permanent_delete_is_confirmed_against_the_server_and_leaves_a_record(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'notes.pdf');
        $path = $file->storage_path;

        $this->trash($user, [['kind' => 'file', 'public_id' => $file->public_id]]);

        // No code: refused.
        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertSessionHasErrors();

        // A wrong code: refused.
        $this->actingAs($user)->postJson('/files/confirm-code', ['purpose' => 'purge']);
        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'confirmation' => '0000',
        ])->assertSessionHasErrors();

        $this->assertNotNull(File::withTrashed()->find($file->id));

        // The right one, once.
        $code = $this->actingAs($user)->postJson('/files/confirm-code', ['purpose' => 'purge'])->json('code');

        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'confirmation' => $code,
        ])->assertSessionHasNoErrors();

        // The row *and* the bytes — a purge that drops one leaks the other.
        $this->assertNull(File::withTrashed()->find($file->id));
        $this->assertFalse(Storage::disk(FileStorage::DISK)->exists($path));

        // And the record outlives the file, which is why the activity row's
        // subject morph carries no foreign key.
        $this->assertDatabaseHas('activity_logs', ['action' => 'purged', 'actor_id' => $user->id]);

        // Spent: the same code cannot erase a second thing.
        $other = $this->fileIn($this->root($user), $user, 'second.pdf');
        $this->trash($user, [['kind' => 'file', 'public_id' => $other->public_id]]);

        $this->actingAs($user)->post('/files/purge', [
            'items' => [['kind' => 'file', 'public_id' => $other->public_id]],
            'confirmation' => $code,
        ])->assertSessionHasErrors();

        $this->assertNotNull(File::withTrashed()->find($other->id));
    }

    /**
     * Item 11: the delete preview states the cost before it is paid, and only
     * for a folder the asker may actually see — a count of what is inside a
     * folder is itself a disclosure about the folder.
     */
    public function test_the_delete_preview_counts_the_subtree_and_is_resolved_first(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $folder = app(FolderService::class)->create($user, $this->root($user), 'Case Files');
        $inner = app(FolderService::class)->create($user, $folder, '2026');
        $this->fileIn($inner, $user, 'a.pdf');
        $this->fileIn($inner, $user, 'b.pdf');

        $preview = $this->actingAs($user)
            ->getJson('/files/delete-preview?items[0][kind]=folder&items[0][public_id]='.$folder->public_id)
            ->assertOk()
            ->json();

        $this->assertSame(2, $preview['files']);
        // The selected folder is counted with its descendants, because that is
        // what "will also be deleted" has to mean for the number to be read as
        // the cost of pressing the button.
        $this->assertSame(2, $preview['folders']);

        $this->actingAs($stranger)
            ->getJson('/files/delete-preview?items[0][kind]=folder&items[0][public_id]='.$folder->public_id)
            ->assertNotFound();
    }

    /**
     * Item 11: only a trashed item can be erased, only its owner can erase it,
     * and a root folder is neither renamed nor deleted.
     */
    public function test_the_trash_refuses_what_it_should(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'notes.pdf');

        // Not trashed yet: there is no such row to erase, which is a 404
        // rather than a validation message — the endpoint only ever addresses
        // the trash.
        $this->purge($user, 'file', $file->public_id)->assertNotFound();
        $this->assertNotNull($file->fresh());

        $this->trash($user, [['kind' => 'file', 'public_id' => $file->public_id]]);

        // Somebody else's trash is not theirs to empty, nor to restore from.
        // What matters is the outcome rather than the status: the item is still
        // there either way, and "not yours" and "no such item" are deliberately
        // indistinguishable.
        $this->purge($stranger, 'file', $file->public_id);
        $this->actingAs($stranger)->post('/files/restore', [
            'kind' => 'file',
            'public_id' => $file->public_id,
        ]);

        $this->assertNotNull(File::withTrashed()->find($file->id), "Somebody else's trashed file was erased.");
        // And still in the trash: a stranger's restore must not have let it out.
        $this->assertNotNull(File::onlyTrashed()->find($file->id), "Somebody else's file was restored.");

        // The root is a container nobody sees or sets.
        $root = $this->root($user);

        /*
         * The service refuses it by throwing, which surfaces as a 500 — the
         * same answer `FileManagerTest` records. It is not the refusal a person
         * should ever see, and it does not need to be: the root is a container
         * nobody is shown, so no screen offers either action. What is asserted
         * here is the part that matters, which is that neither one happens.
         */
        $this->actingAs($user)->post('/files/rename', [
            'kind' => 'folder',
            'public_id' => $root->public_id,
            'name' => 'Renamed',
        ])->assertStatus(500);

        $this->assertSame($root->name, $root->fresh()->name);

        $this->trash($user, [['kind' => 'folder', 'public_id' => $root->public_id]]);

        $this->assertNull($root->fresh()->deleted_at, 'A root folder was trashed.');
    }
}
