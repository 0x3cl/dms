<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\Section;
use App\Models\User;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\OwnershipTransferService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Requirement 10: deleting or changing one record must not destroy another.
 *
 * The core claim is stated once: **no deletion anywhere in the organisation
 * chart removes a file, a folder, a document or an audit row.**
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function fileFor(User $owner, string $name = 'notes.pdf'): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->create($name, 8, 'application/pdf'),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
    }

    private function documentFor(User $owner, File $file): Document
    {
        return Document::create([
            'code' => 'MEMO-20260101-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'title' => 'Memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'type' => 'Memo',
            'file_id' => $file->id,
            'user_id' => $owner->id,
            'uploader_name' => $owner->name,
            'status' => 'active',
            'visibility' => 'public',
        ]);
    }

    // ------------------------------------------- the organisation chart

    /**
     * The requirement's own example: deleting a division must not delete its
     * users' files, folders, document records or sharing history.
     */
    public function test_removing_a_division_destroys_no_storage_and_no_history(): void
    {
        $division = Division::factory()->create();
        $section = Section::factory()->create(['division_id' => $division->id]);
        $owner = User::factory()->create([
            'division_id' => $division->id,
            'section_id' => $section->id,
        ]);
        $other = User::factory()->create();

        $file = $this->fileFor($owner);
        $document = $this->documentFor($owner, $file);
        $share = app(SharingService::class)->share($file, GranteeType::User, $other->id, ShareAbility::View, $owner);
        $logs = ActivityLog::query()->count();

        // Occupied, so the controller refuses — the first line of defence.
        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/divisions/{$division->id}")
            ->assertRedirect();
        $this->assertModelExists($division);

        // And if it is erased anyway, directly, nothing it pointed at goes
        // with it. `nullOnDelete` everywhere, never a cascade.
        $section->forceDelete();
        $division->forceDelete();

        $this->assertModelExists($file);
        $this->assertModelExists($document);
        $this->assertModelExists($share);
        $this->assertModelExists($owner);
        $this->assertSame($logs, ActivityLog::query()->count());

        Storage::disk('documents')->assertExists($file->fresh()->storage_path);

        // The account simply has no office any more.
        $this->assertNull($owner->fresh()->division_id);
    }

    public function test_removing_a_section_destroys_no_storage(): void
    {
        $section = Section::factory()->create();
        $owner = User::factory()->create([
            'division_id' => $section->division_id,
            'section_id' => $section->id,
        ]);
        $file = $this->fileFor($owner);

        $section->forceDelete();

        $this->assertModelExists($file);
        $this->assertNull($owner->fresh()->section_id);
        Storage::disk('documents')->assertExists($file->fresh()->storage_path);
    }

    /**
     * A share whose grantee is gone grants access to nobody, so the row goes
     * with it — unlike an audit row, which is a record of something that
     * happened and must outlive its subject.
     */
    public function test_a_share_to_a_deleted_division_is_removed_but_the_file_is_not(): void
    {
        $division = Division::factory()->create();
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);

        app(SharingService::class)->share($file, GranteeType::Division, $division->id, ShareAbility::View, $owner);
        $this->assertSame(1, ItemShare::query()->count());

        $division->forceDelete();

        $this->assertSame(0, ItemShare::query()->count(), 'A void grant must not linger as a half-row.');
        $this->assertModelExists($file);
        // And the fact that it was shared is still on record.
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Shared)->count());
    }

    // ------------------------------------------------------- closing accounts

    public function test_an_account_holding_files_cannot_be_closed_without_a_transfer(): void
    {
        $user = User::factory()->create();
        $this->fileFor($user);

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('transfer_to', null, 'userDeletion');

        $this->assertNotSoftDeleted($user);
    }

    public function test_an_account_holding_nothing_can_be_closed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertSoftDeleted($user);
    }

    public function test_closing_an_account_with_a_transfer_moves_everything(): void
    {
        $leaver = User::factory()->create();
        $successor = User::factory()->create();

        $folder = app(FolderService::class)->create($leaver, app(FolderService::class)->rootFor($leaver), 'Reports');
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->create('q1.pdf', 8, 'application/pdf'),
            $folder,
            $leaver,
        );
        $document = $this->documentFor($leaver, $this->fileFor($leaver, 'memo.pdf'));

        $this->actingAs($leaver)
            ->delete('/profile', ['password' => 'password', 'transfer_to' => $successor->id])
            ->assertRedirect('/');

        $this->assertSoftDeleted($leaver);
        $this->assertSame($successor->id, $file->fresh()->user_id);
        $this->assertSame($successor->name, $file->fresh()->owner_name);
        $this->assertSame($successor->id, $folder->fresh()->user_id);
        $this->assertSame($successor->id, $document->fresh()->user_id);

        // The successor can reach it in their own tree.
        $this->actingAs($successor)->get('/files')->assertOk();
        Storage::disk('documents')->assertExists($file->fresh()->storage_path);
    }

    /**
     * A closed account keeps its links, so nothing it owned becomes ownerless
     * the moment somebody closes their account — the state the application was
     * in before, where a document survived its uploader and immediately had
     * nobody able to edit or end it.
     */
    public function test_a_closed_account_cannot_sign_in_but_keeps_its_links(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-7777']);
        $file = $this->fileFor($user);

        $user->delete();

        $this->assertSame($user->id, $file->fresh()->user_id);

        $this->post('/login', ['employee_id' => 'EMP-7777', 'password' => 'password'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    // ----------------------------------------------------- ownership transfer

    public function test_a_transfer_does_not_rewrite_the_audit_trail(): void
    {
        $leaver = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $successor = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Santos']);
        $file = $this->fileFor($leaver);

        app(\App\Services\Files\FileEventRecorder::class)
            ->record(ActivityAction::Downloaded, $file, $leaver);

        app(OwnershipTransferService::class)->transfer($leaver, $successor, $leaver);

        $download = ActivityLog::query()->where('action', ActivityAction::Downloaded)->sole();

        // Ownership is a fact about now; an audit row is a fact about then.
        $this->assertSame('Ana Cruz', $download->actor_name);
        $this->assertSame($successor->id, $file->fresh()->user_id);
    }

    /**
     * `uploader_name` is the snapshot of who filed the document, printed
     * alongside a tracking code. The new owner can edit and end it; they did
     * not upload it.
     */
    public function test_a_transfer_does_not_rewrite_who_uploaded_a_document(): void
    {
        $leaver = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $successor = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Santos']);
        $document = $this->documentFor($leaver, $this->fileFor($leaver));

        app(OwnershipTransferService::class)->transfer($leaver, $successor, $leaver);

        $this->assertSame($successor->id, $document->fresh()->user_id);
        $this->assertSame('Ana Cruz', $document->fresh()->uploader_name);
    }

    /** The leaver keeps exactly one root, and the successor does too. */
    public function test_a_transfer_leaves_one_root_each(): void
    {
        $leaver = User::factory()->create();
        $successor = User::factory()->create();
        app(FolderService::class)->create($leaver, app(FolderService::class)->rootFor($leaver), 'Reports');

        app(OwnershipTransferService::class)->transfer($leaver, $successor, $leaver);

        foreach ([$leaver, $successor] as $user) {
            $this->assertSame(
                1,
                Folder::query()->where('user_id', $user->id)->where('is_root', true)->count(),
                'Two roots would break the uniqueness the folder tree depends on.',
            );
        }
    }

    public function test_a_transfer_carries_trashed_items_too(): void
    {
        $leaver = User::factory()->create();
        $successor = User::factory()->create();
        $file = $this->fileFor($leaver);
        $file->delete();

        app(OwnershipTransferService::class)->transfer($leaver, $successor, $leaver);

        // A soft-deleted file in the leaver's trash is still theirs; leaving it
        // behind would make it unrestorable by anyone.
        $this->assertSame($successor->id, File::withTrashed()->find($file->id)->user_id);
    }

    public function test_transferring_to_the_same_account_is_refused(): void
    {
        $user = User::factory()->create();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OwnershipTransferService::class)->transfer($user, $user, $user);
    }

    public function test_a_transfer_is_recorded(): void
    {
        $leaver = User::factory()->create();
        $successor = User::factory()->create();
        $this->fileFor($leaver);

        app(OwnershipTransferService::class)->transfer($leaver, $successor, $leaver);

        $row = ActivityLog::query()
            ->where('action', ActivityAction::OwnershipTransferred)
            ->whereNull('subject_id')
            ->sole();

        $this->assertSame($leaver->name, $row->context['from']);
        $this->assertSame($successor->name, $row->context['to']);
        $this->assertSame(1, $row->context['files']);
    }

    // ---------------------------------------------------------------- purging

    public function test_the_purge_keeps_recently_trashed_items(): void
    {
        $user = User::factory()->create();
        $file = $this->fileFor($user);
        $file->delete();

        $this->artisan('files:purge --days=30')->assertSuccessful();

        $this->assertNotNull(File::withTrashed()->find($file->id));
    }

    public function test_the_purge_removes_long_trashed_items_and_records_it(): void
    {
        $user = User::factory()->create();
        $file = $this->fileFor($user);
        $path = $file->storage_path;
        $file->delete();
        $file->forceFill(['deleted_at' => now()->subYear()])->saveQuietly();

        $this->artisan('files:purge --days=30')->assertSuccessful();

        $this->assertNull(File::withTrashed()->find($file->id));
        Storage::disk('documents')->assertMissing($path);

        // The row is written before the delete, while there is still a name to
        // snapshot. A purge that leaves no record of itself is the one audit
        // gap a purge must not create.
        $row = ActivityLog::query()->where('action', ActivityAction::Purged)->sole();
        $this->assertSame('notes.pdf', $row->subject_label);
    }

    /**
     * A tracking code is printed on paper. Removing the bytes it points at
     * would leave the code pointing at nothing.
     */
    public function test_the_purge_keeps_a_file_a_tracked_document_points_at(): void
    {
        $user = User::factory()->create();
        $file = $this->fileFor($user);
        $this->documentFor($user, $file);

        $file->delete();
        $file->forceFill(['deleted_at' => now()->subYear()])->saveQuietly();

        $this->artisan('files:purge --days=30')->assertSuccessful();

        $this->assertNotNull(File::withTrashed()->find($file->id));
    }

    public function test_the_purge_keeps_a_shared_file(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $file = $this->fileFor($owner);
        app(SharingService::class)->share($file, GranteeType::User, $other->id, ShareAbility::View, $owner);

        $file->delete();
        $file->forceFill(['deleted_at' => now()->subYear()])->saveQuietly();

        $this->artisan('files:purge --days=30')->assertSuccessful();

        $this->assertNotNull(File::withTrashed()->find($file->id));
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        $user = User::factory()->create();
        $file = $this->fileFor($user);
        $file->delete();
        $file->forceFill(['deleted_at' => now()->subYear()])->saveQuietly();

        $this->artisan('files:purge --days=30 --dry-run')->assertSuccessful();

        $this->assertNotNull(File::withTrashed()->find($file->id));
    }

    /**
     * The standing leak: replacing a document's file left the superseded row
     * and its bytes in place forever, and nothing pruned them.
     */
    public function test_the_purge_removes_a_superseded_document_upload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('first.pdf', 8, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $superseded = $document->file;

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => 'Memo',
            'document_type_id' => $document->document_type_id,
            'file' => UploadedFile::fake()->create('second.pdf', 8, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertNotSame($superseded->id, $document->fresh()->file_id);
        $this->assertSame(2, File::query()->count(), 'The old row is still there before the purge.');

        $this->artisan('files:purge --orphans')->assertSuccessful();

        $this->assertNull(File::withTrashed()->find($superseded->id));
        // The document's current file is untouched.
        $this->assertNotNull(File::find($document->fresh()->file_id));
    }
}
