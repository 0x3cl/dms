<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Files\FileManager;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Emptying the `Tracked Documents {year}` folders the register used to make.
 *
 * Removing the default only half-removed it: nothing creates such a folder
 * any more, but every file filed before that is still sitting in one, which
 * is the thing actually on screen. This command is the other half.
 */
class FlattenTrackedFoldersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /** @return array{User, Folder, Folder} owner, root, the legacy folder */
    private function legacyScene(): array
    {
        $owner = User::factory()->create();
        $folders = app(FolderService::class);
        $root = $folders->rootFor($owner);
        $legacy = $folders->create($owner, $root, 'Tracked Documents '.now()->year);

        return [$owner, $root, $legacy];
    }

    private function fileIn(Folder $folder, User $owner, string $name = 'memo.pdf'): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->create($name, 16, 'application/pdf'),
            $folder,
            $owner,
        );
    }

    public function test_it_moves_the_files_out_and_removes_the_folder(): void
    {
        [$owner, $root, $legacy] = $this->legacyScene();
        $file = $this->fileIn($legacy, $owner);

        $this->artisan('files:flatten-tracked-folders')->assertSuccessful();

        $this->assertSame($root->id, $file->fresh()->folder_id);
        $this->assertNull(Folder::withTrashed()->find($legacy->id));
    }

    /** A move is a move: it goes through the service, so the trail records it. */
    public function test_each_move_is_recorded_on_the_activity_trail(): void
    {
        [$owner, , $legacy] = $this->legacyScene();
        $this->fileIn($legacy, $owner);

        $this->artisan('files:flatten-tracked-folders')->assertSuccessful();

        $this->assertSame(
            1,
            ActivityLog::query()->where('action', ActivityAction::Moved->value)->count(),
            'a sweep that rearranges storage with no trail is indistinguishable from files going missing',
        );
    }

    /** A name already taken at the root is numbered, because this cannot ask. */
    public function test_a_name_collision_at_the_root_is_numbered(): void
    {
        [$owner, $root, $legacy] = $this->legacyScene();
        $this->fileIn($root, $owner, 'memo.pdf');
        $moved = $this->fileIn($legacy, $owner, 'memo.pdf');

        $this->artisan('files:flatten-tracked-folders')->assertSuccessful();

        $moved = $moved->fresh();

        $this->assertSame($root->id, $moved->folder_id);
        $this->assertNotSame('memo.pdf', $moved->name);
    }

    /**
     * A folder with a trashed file still pointing at it is kept.
     *
     * `files.folder_id` is `restrictOnDelete` and a restore needs its
     * container, so removing it would either fail or strand the restore.
     */
    public function test_a_folder_holding_trashed_files_is_kept(): void
    {
        [$owner, , $legacy] = $this->legacyScene();
        $file = $this->fileIn($legacy, $owner);

        app(FileManager::class)->delete($owner, $file);

        $this->artisan('files:flatten-tracked-folders')->assertSuccessful();

        $this->assertNotNull(Folder::find($legacy->id));
    }

    /** Somebody's own folder of that name, deeper in their tree, is not touched. */
    public function test_a_folder_the_person_made_themselves_is_left_alone(): void
    {
        $owner = User::factory()->create();
        $folders = app(FolderService::class);
        $root = $folders->rootFor($owner);
        $mine = $folders->create($owner, $root, 'Records');
        $theirs = $folders->create($owner, $mine, 'Tracked Documents '.now()->year);
        $file = $this->fileIn($theirs, $owner);

        $this->artisan('files:flatten-tracked-folders')->assertSuccessful();

        $this->assertSame($theirs->id, $file->fresh()->folder_id);
        $this->assertNotNull(Folder::find($theirs->id));
    }

    /** The dry run reports and changes nothing. */
    public function test_a_dry_run_moves_nothing(): void
    {
        [$owner, , $legacy] = $this->legacyScene();
        $file = $this->fileIn($legacy, $owner);

        $this->artisan('files:flatten-tracked-folders --dry-run')->assertSuccessful();

        $this->assertSame($legacy->id, $file->fresh()->folder_id);
        $this->assertNotNull(Folder::find($legacy->id));
    }
}
