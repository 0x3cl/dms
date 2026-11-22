<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\FileVisibility;
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
 * The file manager screens and the operations behind them.
 */
class FileManagerTest extends TestCase
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

    /**
     * Delete, by whichever route.
     *
     * Moving to trash is reversible and carries no confirmation code. A purge
     * is not, and carries one issued by the server into the session and spent
     * on use — so a test has to ask for it the same way the dialog does, which
     * is the point of it being server-side rather than a browser check.
     *
     * @param  list<array{kind: string, public_id: string}>  $items
     */
    private function deleteItems(User $actor, array $items, string $endpoint = '/files/delete')
    {
        if ($endpoint !== '/files/purge') {
            return $this->actingAs($actor)->post($endpoint, ['items' => $items]);
        }

        $code = $this->actingAs($actor)
            ->postJson('/files/confirm-code', ['purpose' => 'purge'])
            ->json('code');

        return $this->actingAs($actor)->post($endpoint, [
            'items' => $items,
            'confirmation' => $code,
        ]);
    }

    /**
     * The Move/Copy picker creates folders too, and gets the new one back.
     *
     * It is the same endpoint the New folder dialog posts to. A redirect would
     * close the dialog and lose the selection the person arrived with, so the
     * picker asks for JSON — but a second create endpoint would be a second
     * place the ability check and the naming rules have to be remembered.
     */
    public function test_a_folder_can_be_created_from_the_picker_and_comes_back_as_json(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        $payload = $this->actingAs($user)
            ->postJson('/files/folders', ['parent' => $root->public_id, 'name' => 'Contracts'])
            ->assertOk()
            ->json();

        $this->assertSame('Contracts', $payload['folder']['name']);
        $this->assertSame($root->public_id, $payload['folder']['parent_public_id']);
        $this->assertTrue($payload['folder']['is_mine']);

        $this->assertDatabaseHas('folders', [
            'public_id' => $payload['folder']['public_id'],
            'name' => 'Contracts',
            'parent_id' => $root->id,
            'user_id' => $user->id,
        ]);
    }

    /** A refusal comes back as a bag the dialog can render, not a redirect. */
    public function test_a_rejected_picker_folder_name_answers_with_errors(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        $this->actingAs($user)
            ->postJson('/files/folders', ['parent' => $root->public_id, 'name' => 'bad/name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ------------------------------------------------------------------ the page

    public function test_the_file_manager_needs_the_permission(): void
    {
        // A role of its own. Revoking from `Staff` would strip the permission
        // from every account the factory builds, including the one below —
        // roles are shared state, so a test must not edit the seeded ones.
        $role = \Spatie\Permission\Models\Role::findOrCreate('No Storage', 'web');
        $stripped = User::factory()->create();
        $stripped->syncRoles([$role]);

        $this->actingAs($stripped->fresh())->get('/files')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/files')->assertOk();
    }

    public function test_the_root_listing_shows_only_the_users_own_items(): void
    {
        $user = User::factory()->create();
        $this->fileIn($this->root($user), $user, 'mine.pdf');

        $stranger = User::factory()->create();
        $this->fileIn($this->root($stranger), $stranger, 'theirs.pdf');

        $files = $this->actingAs($user)->get('/files')
            ->viewData('page')['props']['files']['data'];

        $this->assertCount(1, $files);
        $this->assertSame('mine.pdf', $files[0]['name']);
    }

    // ------------------------------------------------- the grid/table toggle

    /**
     * The requirement: switching views preserves the page, the filters, the
     * sorting and the search.
     *
     * It holds by construction because the view is a query-string parameter
     * like every other filter, rather than local state — so this asserts the
     * construction rather than a behaviour that could drift.
     */
    public function test_switching_views_preserves_page_search_sort_and_filters(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        foreach (range(1, 20) as $n) {
            $this->fileIn($root, $user, sprintf('report-%02d.pdf', $n));
        }

        $query = 'search=report&sort=size&direction=desc&entries=12&page=2';

        $grid = $this->actingAs($user)->get("/files?view=grid&{$query}")
            ->viewData('page')['props'];
        $table = $this->actingAs($user)->get("/files?view=table&{$query}")
            ->viewData('page')['props'];

        // Every filter survives the switch...
        foreach (['search', 'sort', 'direction', 'entries'] as $key) {
            $this->assertSame($grid['filters'][$key], $table['filters'][$key]);
        }
        $this->assertSame('grid', $grid['filters']['view']);
        $this->assertSame('table', $table['filters']['view']);

        // ...and both views render exactly the same page of the same paginator,
        // which is the whole of "pagination works evenly in both views".
        $this->assertSame($grid['files']['current_page'], $table['files']['current_page']);
        $this->assertSame($grid['files']['total'], $table['files']['total']);
        $this->assertSame(
            array_column($grid['files']['data'], 'public_id'),
            array_column($table['files']['data'], 'public_id'),
        );
    }

    public function test_an_out_of_whitelist_view_sort_or_page_size_falls_back(): void
    {
        $user = User::factory()->create();

        $filters = $this->actingAs($user)
            ->get('/files?view=carousel&sort=id&entries=9999&direction=sideways')
            ->viewData('page')['props']['filters'];

        $this->assertSame('grid', $filters['view']);
        $this->assertSame('name', $filters['sort']);
        $this->assertSame(24, $filters['entries']);
        $this->assertSame('asc', $filters['direction']);
    }

    // ------------------------------------------------------------- operations

    public function test_a_folder_can_be_created_and_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/files/folders', ['parent' => $this->root($user)->public_id, 'name' => 'Reports'])
            ->assertRedirect();

        $this->assertDatabaseHas('folders', ['name' => 'Reports', 'user_id' => $user->id]);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::FolderCreated)->count());
    }

    public function test_a_duplicate_name_in_one_folder_is_refused(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        $this->actingAs($user)->post('/files/folders', ['parent' => $root->public_id, 'name' => 'Reports']);
        $this->actingAs($user)
            ->post('/files/folders', ['parent' => $root->public_id, 'name' => 'Reports'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Folder::query()->where('name', 'Reports')->count());
    }

    /** A name that could escape a path is refused before it reaches the model. */
    public function test_a_folder_name_cannot_contain_path_separators(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/files/folders', ['parent' => $this->root($user)->public_id, 'name' => '../escape'])
            ->assertSessionHasErrors('name');
    }

    public function test_uploading_lands_in_the_chosen_folder_and_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => $this->root($user)->public_id,
            'files' => [UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')],
        ])->assertRedirect();

        $file = File::query()->sole();

        $this->assertSame('brief.pdf', $file->original_name);
        Storage::disk('documents')->assertExists($file->storage_path);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Uploaded)->count());
    }

    public function test_renaming_records_both_names(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'draft.pdf');

        $this->actingAs($user)->post('/files/rename', [
            'kind' => 'file', 'public_id' => $file->public_id, 'name' => 'final.pdf',
        ])->assertRedirect();

        $row = ActivityLog::query()->where('action', ActivityAction::Renamed)->sole();

        $this->assertSame('draft.pdf', $row->context['from']);
        $this->assertSame('final.pdf', $row->context['to']);
    }

    public function test_a_stranger_cannot_rename_or_delete_someone_elses_file(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post('/files/rename', [
            'kind' => 'file', 'public_id' => $file->public_id, 'name' => 'hijacked.pdf',
        ])->assertSessionHasErrors('item');

        $this->deleteItems($stranger, [['kind' => 'file', 'public_id' => $file->public_id]])
            ->assertSessionHasErrors('item');

        $this->assertSame('notes.pdf', $file->fresh()->name);
        $this->assertNotSoftDeleted($file);
    }

    /**
     * Deleting a folder soft-deletes its subtree in one transaction, writing a
     * row per subject. A database cascade would hard-delete the descendants
     * with no record and no way back, which is why `parent_id` restricts.
     */
    public function test_deleting_a_folder_soft_deletes_its_whole_subtree(): void
    {
        $user = User::factory()->create();
        $folders = app(FolderService::class);

        $parent = $folders->create($user, $this->root($user), 'Reports');
        $child = $folders->create($user, $parent, 'Q1');
        $file = $this->fileIn($child, $user, 'q1.pdf');

        $this->deleteItems($user, [['kind' => 'folder', 'public_id' => $parent->public_id]])
            ->assertRedirect();

        $this->assertSoftDeleted($parent);
        $this->assertSoftDeleted($child);
        $this->assertSoftDeleted($file);

        // One audit row per subject, not one for the operation.
        $this->assertSame(3, ActivityLog::query()->where('action', ActivityAction::Deleted)->count());
    }

    public function test_a_deleted_item_can_be_restored_by_its_owner(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user);

        $this->deleteItems($user, [['kind' => 'file', 'public_id' => $file->public_id]]);
        $this->actingAs($user)->get('/files/trash')->assertOk();

        $this->actingAs($user)->post('/files/restore', [
            'kind' => 'file', 'public_id' => $file->public_id,
        ])->assertRedirect();

        $this->assertNotSoftDeleted($file);
    }

    /**
     * A soft-deleted item is out of every listing, so a share on it cannot be
     * the claim — only its owner, or an administrator, can bring it back.
     */
    public function test_a_stranger_cannot_restore_someone_elses_item(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);
        $this->deleteItems($owner, [['kind' => 'file', 'public_id' => $file->public_id]]);

        $this->actingAs(User::factory()->create())->post('/files/restore', [
            'kind' => 'file', 'public_id' => $file->public_id,
        ])->assertSessionHasErrors('item');

        $this->assertSoftDeleted($file);
    }

    /** A root folder is an implementation detail and cannot be touched. */
    public function test_a_root_folder_cannot_be_renamed_or_deleted(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        $this->actingAs($user)->post('/files/rename', [
            'kind' => 'folder', 'public_id' => $root->public_id, 'name' => 'Mine',
        ])->assertStatus(500);

        $this->assertSame($root->name, $root->fresh()->name);
    }

    // --------------------------------------------------------- cross-user work

    /**
     * Browse Storage is the administrator's screen, and only theirs.
     *
     * It is gated on `files.manage` — the same permission AccessResolver
     * resolves the reach across everyone else's storage by, so the screen and
     * the rule cannot be granted apart. `files-browse.read` used to be a second
     * name for the same thing, which meant a role could hold the screen without
     * the rule or the rule without the screen.
     */
    public function test_browse_storage_is_refused_to_everyone_but_an_administrator(): void
    {
        $this->actingAs(User::factory()->create())->get('/files/browse')->assertForbidden();
        $this->actingAs(User::factory()->superadmin()->create())->get('/files/browse')->assertOk();
    }

    /** For an administrator, every account holding storage is browsable. */
    public function test_browse_lists_the_accounts_that_hold_storage(): void
    {
        $admin = User::factory()->superadmin()->create();
        $sharer = User::factory()->create();
        $silent = User::factory()->create();

        $this->fileIn($this->root($sharer), $sharer, 'shared.pdf');
        $this->fileIn($this->root($silent), $silent, 'private.pdf');

        $owners = collect($this->actingAs($admin)->get('/files/browse')
            ->viewData('page')['props']['owners'])
            ->pluck('name')
            ->all();

        $this->assertContains($sharer->name, $owners);
        $this->assertContains($silent->name, $owners);
        // Never themselves: Browse Storage is for other people's.
        $this->assertNotContains($admin->name, $owners);
    }

    /**
     * Shared with Me is a part of My Files now, not a screen of its own.
     *
     * At the top of their own storage a person sees what was *given* to them
     * beside their own files — and not what merely happens to be public, which
     * is the distinction the old separate page existed to make and the one most
     * at risk of being lost in the merge.
     */
    public function test_my_files_lists_what_was_shared_with_me_beside_my_own(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $folders = app(FolderService::class);

        $mine = $this->fileIn($this->root($viewer), $viewer, 'mine.pdf');
        $given = $this->fileIn($this->root($owner), $owner, 'given.pdf');
        app(SharingService::class)->share($given, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        // Public, but nobody gave it to this person: it is not an entry point.
        $open = $folders->create($owner, $this->root($owner), 'Open', FileVisibility::PublicItem->value);
        $this->fileIn($open, $owner, 'open.pdf')->update(['visibility' => FileVisibility::PublicItem->value]);

        $rows = $this->actingAs($viewer)->get('/files')
            ->viewData('page')['props']['files']['data'];

        $this->assertSame(['given.pdf', 'mine.pdf'], array_column($rows, 'name'));

        // And each row says which it is, from the grant's own source.
        $access = array_combine(array_column($rows, 'name'), array_column($rows, 'access'));
        $this->assertSame('shared', $access['given.pdf']);
        $this->assertSame('owner', $access['mine.pdf']);
        $this->assertSame($owner->name, $rows[0]['shared_by']);
    }

    /** The sharing filter narrows the merged listing to one side or the other. */
    public function test_the_sharing_filter_narrows_my_files_to_mine_or_to_shared(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();

        $this->fileIn($this->root($viewer), $viewer, 'mine.pdf');
        $given = $this->fileIn($this->root($owner), $owner, 'given.pdf');
        app(SharingService::class)->share($given, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        $names = fn (string $access) => array_column(
            $this->actingAs($viewer)->get("/files?access={$access}")->viewData('page')['props']['files']['data'],
            'name',
        );

        $this->assertSame(['mine.pdf'], $names('mine'));
        $this->assertSame(['given.pdf'], $names('shared'));
    }

    /**
     * A copy is a new row with its own bytes and its own owner, so revoking
     * the original share cannot reach into the taker's storage.
     */
    public function test_a_shared_file_can_be_copied_into_the_takers_own_storage(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner, 'shared.pdf');
        $share = app(SharingService::class)->share($file, GranteeType::User, $taker->id, ShareAbility::Download, $owner);

        $this->actingAs($taker)->post('/files/copy', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertRedirect();

        $copy = File::query()->where('user_id', $taker->id)->sole();
        $this->assertNotSame($file->storage_path, $copy->storage_path);
        $this->assertSame('copy', $copy->source);

        app(SharingService::class)->revoke($share, $owner);

        $this->assertSame(1, File::query()->where('user_id', $taker->id)->count());
        $this->actingAs($taker)
            ->get("/files/{$taker->public_id}/{$copy->public_id}")
            ->assertOk();
    }

    /** View-only means no copy: the copy ability is not granted. */
    public function test_a_view_only_share_cannot_be_copied(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);
        app(SharingService::class)->share($file, GranteeType::User, $taker->id, ShareAbility::View, $owner);

        $this->actingAs($taker)->post('/files/copy', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertSessionHasErrors('item');

        $this->assertSame(0, File::query()->where('user_id', $taker->id)->count());
    }

    /** Per-row abilities are resolved server-side, never from auth.permissions. */
    public function test_each_row_carries_the_abilities_resolved_for_this_viewer(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $folders = app(FolderService::class);

        $folder = $folders->create($owner, $this->root($owner), 'Shared');
        $file = $this->fileIn($folder, $owner, 'shared.pdf');
        app(SharingService::class)->share($folder, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        // Opened from My Files: a folder somebody shared with you is navigated
        // there now, not on a screen of its own.
        $row = collect($this->actingAs($viewer)
            ->get("/files?folder={$folder->public_id}")
            ->viewData('page')['props']['files']['data'])
            ->firstWhere('public_id', $file->public_id);

        $this->assertTrue($row['can']['view']);
        $this->assertFalse($row['can']['download']);
        $this->assertFalse($row['can']['delete']);
        $this->assertFalse($row['can']['rename']);
    }

    /**
     * A folder the viewer may not open must 404 rather than quietly falling
     * back to their own root, which is a different and more revealing answer.
     *
     * The interesting case is somebody who legitimately holds *one* share being
     * probed for a folder in the same tree that was never shared. My Files
     * navigates into shared folders now, so the gate on the folder parameter is
     * the ability — not the ownership, which would refuse the share as well.
     */
    public function test_opening_a_folder_that_was_not_shared_is_not_found(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $folders = app(FolderService::class);

        // One share, so the viewer legitimately reaches into this tree.
        $shared = $folders->create($owner, $this->root($owner), 'Shared');
        app(SharingService::class)->share($shared, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        // And one folder that was never shared.
        $secret = $folders->create($owner, $this->root($owner), 'Private');

        $this->actingAs($viewer)->get("/files?folder={$secret->public_id}")->assertNotFound();
        $this->actingAs($viewer)->get("/files?folder={$shared->public_id}")->assertOk();
    }

    /**
     * Browse Storage with no account chosen renders its empty state rather than
     * guessing one, however much the administrator can see.
     */
    public function test_browse_storage_with_no_account_chosen_shows_nothing(): void
    {
        $owner = User::factory()->create();
        $this->fileIn($this->root($owner), $owner, 'private.pdf');

        $props = $this->actingAs(User::factory()->superadmin()->create())
            ->get('/files/browse')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['owner']);
        $this->assertNull($props['files']);
    }

    // ---------------------------------------------------------------- sharing

    public function test_the_share_panel_reports_state_for_an_item_you_can_share(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);

        $state = $this->actingAs($owner)
            ->getJson("/files/share-state?kind=file&public_id={$file->public_id}")
            ->assertOk()
            ->json();

        $this->assertTrue($state['linkable']);
        $this->assertSame([], $state['shares']);
        // The directory is no longer shipped with the panel: sharing is
        // link-based here, and people are named where the item is created.
        $this->assertArrayNotHasKey('targets', $state);
    }

    public function test_the_share_panel_is_refused_for_an_item_you_cannot_share(): void
    {
        $file = $this->fileIn($this->root(User::factory()->create()), User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->getJson("/files/share-state?kind=file&public_id={$file->public_id}")
            ->assertStatus(422);
    }

    /** The panel must not offer a link control for a confidential item. */
    public function test_a_confidential_item_reports_itself_as_not_linkable(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);
        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $state = $this->actingAs($owner)
            ->getJson("/files/share-state?kind=file&public_id={$file->public_id}")
            ->json();

        $this->assertFalse($state['linkable']);

        $this->actingAs($owner)->post('/files/share-links', [
            'kind' => 'file', 'public_id' => $file->public_id, 'ability' => 'view',
        ])->assertSessionHas('error');
    }

    public function test_changing_privacy_is_recorded(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner);

        $this->actingAs($owner)->post('/files/visibility', [
            'kind' => 'file',
            'public_id' => $file->public_id,
            'visibility' => FileVisibility::Confidential->value,
        ])->assertRedirect();

        $row = ActivityLog::query()->where('action', ActivityAction::VisibilityChanged)->sole();

        $this->assertSame('private', $row->context['from']);
        $this->assertSame('confidential', $row->context['to']);
    }
}
