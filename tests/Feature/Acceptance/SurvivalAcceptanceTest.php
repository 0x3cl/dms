<?php

namespace Tests\Feature\Acceptance;

use App\Enums\DocumentAction;
use App\Models\ActivityLog;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\DocumentTypeGroup;
use App\Models\File;
use App\Models\Section;
use App\Models\User;
use App\Services\Files\FileStorage;
use App\Services\Files\OwnershipTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Acceptance items 13 and 14 — nothing anybody deletes takes a document, a
 * file or an audit row with it.
 *
 * One claim, stated once and then asked of every relationship a document has:
 * **a document is a record, and everything it points at is a lookup it can
 * outlive.** The type it was filed as, the group that type sat in, the division
 * and section its uploader worked in, the role that uploader held, the people
 * tagged on it, the folder the bytes sit in — each one is deleted here, and
 * after each the document still opens, still downloads, and still has its
 * history.
 *
 * Which strategy a foreign key uses is a statement rather than a preference:
 *
 * - `nullOnDelete` for anything that is a record of something that happened, or
 *   a live record that outlives its office — it has to survive what it points
 *   at, and the **snapshot** beside the key is what the page renders;
 * - `cascadeOnDelete` only where the child is meaningless without the parent;
 * - `restrictOnDelete` on the folder tree, because deleting a tree is a service
 *   operation that soft-deletes the subtree and writes a row per subject, where
 *   a database cascade would hard-delete descendants with no record and no way
 *   back.
 *
 * `DataIntegrityTest` holds the division and section cases and the transfer
 * mechanics. This is the sweep across every relationship at once, and the
 * many-files case item 14 asks for.
 */
class SurvivalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /**
     * An uploader with an office and a role, one tagged reader, one filed
     * document that has been read and downloaded.
     *
     * @return array{Document, User, User, Section}
     */
    private function scene(): array
    {
        $division = Division::create(['code' => 'ERDD', 'name' => 'Research Division']);
        $section = Section::create(['division_id' => $division->id, 'code' => 'REC', 'name' => 'Records']);

        $uploader = User::factory()->superadmin()->inSection($section)->create();
        $tagged = User::factory()->inSection($section)->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'private',
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
            'recipients' => [$tagged->id],
        ])->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->actingAs($tagged)->get("/documents/view/{$document->code}")->assertOk();
        $this->actingAs($tagged)->get("/documents/{$document->id}/file/download")->assertOk();

        return [$document, $uploader, $tagged, $section];
    }

    /**
     * The document still works, from end to end.
     *
     * Called after each deletion below, so each test says what it deleted and
     * this says what "unaffected" means — once.
     */
    private function assertStillWholeFor(Document $document, User $reader, string $after): void
    {
        $fresh = Document::query()->find($document->id);

        $this->assertNotNull($fresh, "The document was deleted with its {$after}.");
        $this->assertNotNull($fresh->file, "The file row went with the {$after}.");
        $this->assertTrue(
            Storage::disk(FileStorage::DISK)->exists($fresh->file->storage_path),
            "The bytes went with the {$after}.",
        );

        $this->actingAs($reader)->get("/documents/view/{$fresh->code}")
            ->assertOk("The document stopped opening after its {$after} was deleted.");
        $this->actingAs($reader)->get("/documents/{$fresh->id}/file/download")
            ->assertOk("The file stopped downloading after its {$after} was deleted.");

        // And the history is intact and still readable.
        $this->assertGreaterThanOrEqual(3, $fresh->logs()->count(), "The trail shrank with the {$after}.");
        $this->actingAs($reader)->get("/documents/{$fresh->id}/logs")->assertOk();
    }

    // -------------------------------------------- item 13: every lookup

    /** Item 13: the type it was filed as. */
    public function test_deleting_the_document_type_affects_nothing(): void
    {
        [$document, $uploader] = $this->scene();
        $filedAs = $document->type;

        DocumentType::query()->where('code', 'MEMO')->sole()->forceDelete();

        $this->assertStillWholeFor($document, $uploader, 'type');
        // The snapshot is what the page renders, so it still says what it was
        // filed as rather than going blank.
        $this->assertSame($filedAs, $document->fresh()->type);
        $this->assertNull($document->fresh()->document_type_id);
    }

    /** Item 13: the group that type sat in — one level further out. */
    public function test_deleting_the_type_group_affects_nothing(): void
    {
        [$document, $uploader] = $this->scene();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();

        DocumentTypeGroup::query()->whereKey($type->group_id)->delete();

        $this->assertStillWholeFor($document, $uploader, 'type group');
        // The type survived too: a group arranges a picker, a type is part of
        // the record.
        $this->assertNull($type->fresh()->group_id);
        $this->assertNotNull($type->fresh());
    }

    /** Item 13: the division and the section its uploader worked in. */
    public function test_deleting_the_office_affects_nothing_and_does_not_rewrite_history(): void
    {
        [$document, $uploader, , $section] = $this->scene();

        $recordedOffice = DocumentLog::query()->where('document_id', $document->id)
            ->whereNotNull('division_name')->first()->division_name;

        $section->delete();
        Division::query()->whereKey($section->division_id)->delete();

        $this->assertStillWholeFor($document, $uploader, 'office');

        // The people in it were not deleted with it — `users.section_id` nulls.
        $this->assertNotNull($uploader->fresh(), 'Deleting a section deleted the people in it.');

        /*
         * And the trail still says where the actor sat *when it happened*. An
         * audit trail that changes when the org chart changes is not an audit
         * trail, which is why every row carries a snapshot beside its key.
         */
        $row = DocumentLog::query()->where('document_id', $document->id)
            ->whereNotNull('division_name')->first();

        $this->assertSame($recordedOffice, $row->division_name);
    }

    /** Item 13: the role the uploader held. */
    public function test_deleting_a_role_affects_nothing(): void
    {
        [$document, $uploader] = $this->scene();
        $role = Role::create(['name' => 'Records Officer', 'guard_name' => 'web']);
        $role->syncPermissions(['documents.read', 'documents.download']);
        $uploader->syncRoles([$role]);

        $before = DocumentLog::query()->where('document_id', $document->id)->count();

        $role->delete();

        // The uploader is still there and still owns their document; what they
        // can *do* is a separate question from what they hold.
        $this->assertNotNull($uploader->fresh());
        $this->assertSame($uploader->id, $document->fresh()->user_id);
        $this->assertNotNull(Document::query()->find($document->id));
        $this->assertNotNull($document->fresh()->file);
        $this->assertSame(
            $before,
            DocumentLog::query()->where('document_id', $document->id)->count(),
            'Deleting a role took rows off a document trail.',
        );
    }

    /** Item 13: a tagged reader's account, with rows on the document's trail. */
    public function test_erasing_a_tagged_reader_affects_nothing_and_keeps_their_rows(): void
    {
        [$document, $uploader, $tagged] = $this->scene();
        $readerName = $tagged->name;

        $tagged->forceDelete();

        $this->assertStillWholeFor($document, $uploader, 'tagged reader');

        // Their trail rows survive, naming them — the key nulls, the snapshot
        // is what the page renders.
        $rows = DocumentLog::query()->where('document_id', $document->id)
            ->where('actor_name', $readerName)->get();

        $this->assertNotEmpty($rows, "A reader's history went with their account.");
        $this->assertTrue($rows->every(fn (DocumentLog $row) => $row->actor_id === null));

        // The recipient row itself *does* go — a recipient row for an account
        // that no longer exists grants access to nobody, and must not linger as
        // a half-row every resolver has to special-case.
        $this->assertSame(0, $document->allRecipients()->count());
    }

    /** Item 13: the folder the bytes sit in cannot be pulled out from under them. */
    public function test_a_folder_cannot_be_erased_out_from_under_a_file(): void
    {
        [$document, $uploader] = $this->scene();
        $folder = $document->file->folder;

        $this->assertNotNull($folder);

        // `files.folder_id` is restrictOnDelete, so the database refuses —
        // deleting a tree is a service operation that soft-deletes the subtree
        // and writes a row per subject, and a cascade here would hard-delete
        // descendants with no record and no way back.
        try {
            $folder->forceDelete();
            $this->fail('A folder was erased while a file still pointed at it.');
        } catch (\Illuminate\Database\QueryException) {
            // Which is the point.
        }

        $this->assertStillWholeFor($document, $uploader, 'folder');
    }

    // ------------------------------------------- item 14: the uploader goes

    /**
     * Item 14: an account holding many files cannot simply be closed — it has
     * to be handed over.
     *
     * Before soft deletes, a hard delete preserved the document row and
     * destroyed its link, so a document survived its uploader and immediately
     * had *no owner*: nobody could edit or delete it.
     */
    public function test_an_account_holding_many_files_is_not_closed_without_a_transfer(): void
    {
        [$document, $uploader] = $this->scene();
        $admin = User::factory()->superadmin()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($uploader)->post('/files/upload', [
                'folder' => app(\App\Services\Files\FolderService::class)->rootFor($uploader)->public_id,
                'files' => [UploadedFile::fake()->create("note-{$i}.pdf", 8, 'application/pdf')],
            ])->assertSessionHasNoErrors();
        }

        // Refused with a flash rather than a validation error: the screen has
        // to say what is being held and offer somebody to hand it to.
        $this->actingAs($admin)->delete("/users/{$uploader->id}")
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($uploader);
        $this->assertSame(6, File::query()->where('user_id', $uploader->id)->count());
        $this->assertNotNull($document->fresh());
    }

    /**
     * Item 14: handed over, everything moves and nothing is rewritten.
     *
     * The transfer pointedly leaves two things alone: the audit trail, because
     * ownership is a fact about now and an audit row is a fact about then; and
     * `documents.uploader_name`, because that is the snapshot of who filed a
     * document and is printed beside a tracking code — the successor may edit
     * and withdraw it, they did not upload it.
     */
    public function test_a_transfer_moves_the_storage_and_rewrites_no_history(): void
    {
        [$document, $uploader] = $this->scene();
        $successor = User::factory()->superadmin()->create();
        $admin = User::factory()->superadmin()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($uploader)->post('/files/upload', [
                'folder' => app(\App\Services\Files\FolderService::class)->rootFor($uploader)->public_id,
                'files' => [UploadedFile::fake()->create("note-{$i}.pdf", 8, 'application/pdf')],
            ])->assertSessionHasNoErrors();
        }

        $filedBy = $document->uploader_name;
        $trailBefore = DocumentLog::query()->where('document_id', $document->id)
            ->orderBy('id')->pluck('actor_name')->all();

        $this->actingAs($admin)
            ->delete("/users/{$uploader->id}", ['transfer_to' => $successor->id])
            ->assertRedirect('/users');

        $this->assertSoftDeleted($uploader);

        // Everything it owned moved.
        $this->assertSame(0, File::query()->where('user_id', $uploader->id)->count());
        $this->assertSame(6, File::query()->where('user_id', $successor->id)->count());
        $this->assertSame($successor->id, $document->fresh()->user_id);

        // The history did not.
        $this->assertSame(
            $trailBefore,
            DocumentLog::query()->where('document_id', $document->id)->orderBy('id')->pluck('actor_name')->all(),
            'A transfer rewrote the audit trail.',
        );
        $this->assertSame($filedBy, $document->fresh()->uploader_name, 'A transfer rewrote who filed the document.');

        // A closed account cannot sign in, and still owns its history.
        $this->assertFalse($this->post('/login', [
            'login' => $uploader->employee_id,
            'password' => 'password',
        ])->isRedirect('/dashboard'));
    }

    /**
     * Item 14, the far end: the account is **erased**, not closed.
     *
     * Every file, every document, every audit row and every release record it
     * left behind survives, and each one still names the person. This is the
     * difference the two kinds of deletion draw: a soft delete protects the
     * *link*, a hard delete protects the *history*.
     */
    public function test_erasing_an_uploader_leaves_every_file_document_and_row_standing(): void
    {
        [$document, $uploader, $tagged] = $this->scene();
        $reader = User::factory()->superadmin()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($uploader)->post('/files/upload', [
                'folder' => app(\App\Services\Files\FolderService::class)->rootFor($uploader)->public_id,
                'files' => [UploadedFile::fake()->create("note-{$i}.pdf", 8, 'application/pdf')],
            ])->assertSessionHasNoErrors();
        }

        $name = $uploader->name;
        $fileIds = File::query()->where('user_id', $uploader->id)->pluck('id')->all();
        $paths = File::query()->whereIn('id', $fileIds)->pluck('storage_path')->all();

        $uploader->forceDelete();

        // The files, the rows and the bytes.
        $this->assertSame(count($fileIds), File::query()->whereIn('id', $fileIds)->count(), 'Erasing an account deleted its files.');
        $this->assertSame([null], File::query()->whereIn('id', $fileIds)->distinct()->pluck('user_id')->all());

        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk(FileStorage::DISK)->exists($path), 'Erasing an account deleted the bytes.');
        }

        // The document, which now has no owner — readable by its tagged
        // reader exactly as before, because the recipient list is untouched.
        $fresh = Document::query()->find($document->id);

        $this->assertNotNull($fresh, 'Erasing an uploader deleted their document.');
        $this->assertNull($fresh->user_id);
        $this->assertSame($name, $fresh->uploader_name, 'The uploader snapshot was lost.');
        $this->actingAs($tagged)->get("/documents/view/{$fresh->code}")->assertOk();

        // The trail, naming them.
        $rows = DocumentLog::query()->where('document_id', $fresh->id)->where('actor_name', $name)->get();

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn (DocumentLog $row) => $row->actor_id === null));
        $this->assertSame(DocumentAction::Uploaded, $rows->first()->action);

        // The activity trail and the release register, both still readable.
        $this->actingAs($reader)->get('/activity-trails')->assertOk();
        $this->actingAs($reader)->get('/admin/download-logs')->assertOk();

        $this->assertSame(
            $name,
            ActivityLog::query()->where('actor_name', $name)->first()?->actor_name,
            'The activity trail forgot who it was.',
        );
        $this->assertNotNull(DocumentDownload::query()->first(), 'The release register lost its row.');
    }

    /**
     * Item 14: and the successor holds the live record without inheriting the
     * history — they can withdraw the document, and they did not upload it.
     */
    public function test_a_successor_can_act_on_the_document_without_inheriting_who_filed_it(): void
    {
        [$document, $uploader] = $this->scene();
        $successor = User::factory()->superadmin()->create();

        app(OwnershipTransferService::class)->transfer($uploader->fresh(), $successor);

        $this->assertSame($successor->id, $document->fresh()->user_id);
        $this->assertSame($uploader->name, $document->fresh()->uploader_name);

        // The live owner may edit and withdraw it.
        $this->actingAs($successor)->get("/documents/{$document->id}/edit")->assertOk();
        $this->actingAs($successor)->delete("/documents/{$document->id}")->assertRedirect('/documents');

        $this->assertSoftDeleted($document);

        // And the history outlives the withdrawal, because the trail is
        // nullOnDelete beside a name snapshot.
        $this->assertSame($uploader->name, DocumentLog::query()
            ->where('document_id', $document->id)
            ->where('action', DocumentAction::Uploaded)
            ->sole()
            ->actor_name);
    }
}
