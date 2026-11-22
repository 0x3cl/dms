<?php

namespace Tests\Feature\Acceptance;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance item 12 — is everything that happens actually recorded, and can
 * the reports be read?
 *
 * The suite already covers each table's shape. What this adds is the sweep over
 * a *session*: one person does a dozen things in both modules, and then the
 * three reports are asked what happened. The question item 12 asks is coverage,
 * so the assertions are about coverage — which actions appeared, in which
 * table, under whose name.
 *
 * **The three tables are separate on purpose, and the reason is the audience,
 * not the shape.** A document's trail is rendered to everyone who can read the
 * document; the download register carries the IP address a copy went to; the
 * activity trail reaches across every account's storage. So a `copied` row in a
 * document's travel history would turn it into a disclosure about the file
 * manager, and an IP address on a document page would be a release record shown
 * to every reader. Exactly two actions cross over — `previewed` and
 * `downloaded` — because they are the only two that already mean the same thing
 * in both places.
 */
class AuditCoverageAcceptanceTest extends TestCase
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
     * A working session: a tracked upload, a download, a folder, an upload, a
     * rename, a move, a copy, a visibility change, a share link, a delete, a
     * restore.
     *
     * @return array{User, Document}
     */
    private function workingSession(): array
    {
        $user = User::factory()->superadmin()->create();
        $root = $this->root($user);

        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->actingAs($user)->get("/documents/{$document->id}/file/download")->assertOk();

        $this->actingAs($user)->post('/files/folders', ['parent' => $root->public_id, 'name' => 'Working'])
            ->assertSessionHasNoErrors();
        $folder = Folder::query()->where('name', 'Working')->sole();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => $folder->public_id,
            'files' => [UploadedFile::fake()->create('draft.pdf', 8, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $file = File::query()->where('original_name', 'draft.pdf')->sole();

        $this->actingAs($user)->post('/files/rename', [
            'kind' => 'file', 'public_id' => $file->public_id, 'name' => 'final.pdf',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/files/move', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'target' => $root->public_id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/files/copy', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
            'target' => $folder->public_id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/files/visibility', [
            'kind' => 'file',
            'public_id' => $file->public_id,
            'visibility' => 'public',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/files/share-links', [
            'kind' => 'file', 'public_id' => $file->public_id, 'ability' => 'view',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/files/delete', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/files/restore', [
            'kind' => 'file', 'public_id' => $file->public_id,
        ])->assertSessionHasNoErrors();

        return [$user, $document];
    }

    // ------------------------------------------------ item 12: is it logged

    /**
     * Item 12: every action in that session left a row, under the actor's own
     * name and office.
     */
    public function test_a_whole_session_is_recorded_in_the_activity_trail(): void
    {
        [$user] = $this->workingSession();

        $recorded = ActivityLog::query()->pluck('action')->map(fn (ActivityAction $a) => $a->value)->all();

        $expected = [
            'uploaded', 'folder_created', 'renamed', 'moved', 'copied',
            'visibility_changed', 'link_created', 'deleted', 'restored', 'downloaded',
        ];

        foreach ($expected as $action) {
            $this->assertContains($action, $recorded, "A {$action} left no activity row.");
        }

        // Every row names its actor and their office as a snapshot, so the
        // report reads the same after the org chart changes under it.
        $this->assertSame(0, ActivityLog::query()->whereNull('actor_name')->count());
        $this->assertSame(
            [$user->name],
            ActivityLog::query()->whereNotNull('actor_id')->distinct()->pluck('actor_name')->all(),
        );
    }

    /**
     * Item 12: the document's own trail holds the document's story and only
     * that — the file-manager actions did not leak into it.
     */
    public function test_the_document_trail_holds_the_documents_story_and_no_more(): void
    {
        [, $document] = $this->workingSession();

        $actions = $document->logs()->pluck('action')->map(fn (DocumentAction $a) => $a->value)->all();

        $this->assertContains('uploaded', $actions);
        $this->assertContains('downloaded', $actions);

        /*
         * The link minted *with* the document is deliberately not a
         * `link_shared` entry. `DocumentRegistrar` mints one so the printed
         * label has a real target, which is part of creating the document
         * rather than a hand-off somebody decided on — the entry is written
         * when a person creates or revokes a link, which is what the trail's
         * question ("who was this handed to") is actually about.
         */
        $this->assertNotContains('link_shared', $actions);
        $this->assertSame(1, $document->shareLinks()->count());

        foreach (['copied', 'moved', 'renamed', 'extracted', 'visibility_changed'] as $leak) {
            $this->assertNotContains($leak, $actions, "A file-manager action reached a document's trail: {$leak}.");
        }
    }

    /**
     * Item 12: a download is registered in both places, and the register is
     * the one that carries where the copy went.
     */
    public function test_a_download_is_registered_once_in_each_table_and_tied_together(): void
    {
        [$user, $document] = $this->workingSession();

        $release = DocumentDownload::query()->sole();
        $trailRow = $document->logs()->where('action', DocumentAction::Downloaded)->sole();

        $this->assertSame($trailRow->id, $release->document_log_id, 'The register does not point at its trail entry.');
        $this->assertSame($user->name, $release->user_name);
        $this->assertNotNull($release->ip_address, 'The register exists to record where a copy went.');
        $this->assertNotNull($release->downloaded_at);

        // Each download is a separate copy leaving the server, so each is its
        // own row — unlike a preview, which is debounced.
        $this->actingAs($user)->get("/documents/{$document->id}/file/download")->assertOk();
        $this->assertSame(2, DocumentDownload::query()->count());
    }

    /**
     * Item 12: a preview is debounced and a download is not.
     *
     * An embedded viewer re-requests the same file on a resize, a re-mount or
     * a reload. A row per render is a forged history that says somebody opened
     * a document nine times when they opened it once.
     */
    public function test_repeated_previews_collapse_and_repeated_downloads_do_not(): void
    {
        $user = User::factory()->superadmin()->create();
        $file = $this->fileIn($this->root($user), $user, 'report.pdf');

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)
                ->get("/files/preview?owner={$user->public_id}&file={$file->public_id}")
                ->assertOk();
        }

        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Previewed)->count());

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->get("/files/{$user->public_id}/{$file->public_id}")->assertOk();
        }

        $this->assertSame(3, ActivityLog::query()->where('action', ActivityAction::Downloaded)->count());
    }

    /**
     * Item 12: no audit row ever carries a live credential.
     *
     * A share link's terms are recorded — ability, expiry, view cap — and never
     * its token, because that would make the audit report a way to obtain the
     * credential it was written to monitor.
     */
    public function test_no_row_carries_a_share_links_token(): void
    {
        $user = User::factory()->superadmin()->create();
        $file = $this->fileIn($this->root($user), $user, 'report.pdf');

        $this->actingAs($user)->post('/files/share-links', [
            'kind' => 'file', 'public_id' => $file->public_id, 'ability' => 'view',
        ])->assertSessionHasNoErrors();

        $token = $file->shareLinks()->sole()->token;
        $row = ActivityLog::query()->where('action', ActivityAction::LinkCreated)->sole();

        $this->assertSame('view', $row->context['ability'] ?? null);
        $this->assertStringNotContainsString($token, json_encode($row->context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($token, json_encode(ActivityLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Item 12: a failed structural operation records its own failure, and
     * still fails.
     *
     * The subtlety is *where* that row is written: inside the transaction it
     * would be rolled back along with the work it was recording, which is the
     * trap that makes "log the error in the same transaction" useless precisely
     * when it matters.
     */
    public function test_a_failed_operation_records_itself_outside_the_rollback(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'memo.pdf');

        $this->actingAs($user)->post('/files/delete', [
            'items' => [['kind' => 'file', 'public_id' => $file->public_id]],
        ])->assertSessionHasNoErrors();

        // The disk refuses to let go of the bytes, after the row work has
        // already been done inside the transaction.
        $storage = $this->mock(FileStorage::class);
        $storage->shouldReceive('delete')->andThrow(new \RuntimeException('disk is read-only'));

        try {
            app(\App\Services\Files\FileManager::class)->purge($user->fresh(), $file->fresh());
            $this->fail('The purge was expected to fail.');
        } catch (\RuntimeException) {
            // Re-thrown untouched, so the caller answers exactly as it did.
        }

        $failure = ActivityLog::query()
            ->where('action', ActivityAction::Purged)
            ->latest('id')
            ->sole();

        // Written *after* the rollback and outside it. Inside the transaction
        // this row would be rolled back along with the work it was recording,
        // which is the trap that makes "log the error in the same transaction"
        // useless precisely when it matters.
        $this->assertSame('failed', $failure->context['outcome']);
        $this->assertSame('RuntimeException', $failure->context['error']);
        // And the work did not happen: still trashed rather than half-erased.
        $this->assertSoftDeleted($file);
    }

    // --------------------------------------------- item 12: can it be read

    /**
     * Item 12: the two reports open for an administrator, refuse everybody
     * else, and their filters narrow rather than empty.
     */
    public function test_both_reports_are_gated_and_their_filters_work(): void
    {
        [$user, $document] = $this->workingSession();
        $staff = User::factory()->create();

        foreach (['/activity-trails', '/admin/download-logs'] as $report) {
            $this->actingAs($staff)->get($report)->assertStatus(403);
            $this->actingAs($user)->get($report)->assertOk();
        }

        // The activity trail, narrowed by action and by search.
        $byAction = $this->actingAs($user)->get('/activity-trails?action=renamed')
            ->viewData('page')['props']['entries']['data'];

        $this->assertNotEmpty($byAction);
        $this->assertSame(['renamed'], collect($byAction)->pluck('action')->unique()->values()->all());

        $bySearch = $this->actingAs($user)->get('/activity-trails?search='.urlencode($user->name))
            ->viewData('page')['props']['entries']['data'];

        $this->assertNotEmpty($bySearch);

        // An unknown action is ignored rather than emptying the report — a
        // filter that silently matches nothing reads as "nothing happened".
        $ignored = $this->actingAs($user)->get('/activity-trails?action=not-an-action')
            ->viewData('page')['props']['entries']['data'];

        $this->assertNotEmpty($ignored);

        // The register, searched by the document's code.
        $register = $this->actingAs($user)->get('/admin/download-logs?search='.$document->code)
            ->viewData('page')['props']['downloads']['data'];

        $this->assertCount(1, $register);
        $this->assertSame($user->name, $register[0]['user_name']);
    }

    /**
     * Item 12: both reports still render after their actors and subjects are
     * gone — which is what the snapshots are for.
     *
     * The activity row's subject morph deliberately carries no foreign key, so
     * a row stays readable after the file it refers to has been purged.
     */
    public function test_the_reports_read_after_their_actors_and_subjects_are_erased(): void
    {
        [$user, $document] = $this->workingSession();
        $reader = User::factory()->superadmin()->create();

        $name = $user->name;

        File::query()->get()->each->forceDelete();
        $user->forceDelete();

        $this->actingAs($reader)->get('/activity-trails')->assertOk();
        $this->actingAs($reader)->get('/admin/download-logs')->assertOk();

        /*
         * The register survives its *actor* being erased, which is the part
         * that matters: the person is gone and the row still says who took the
         * copy and where it went. It does not survive the **document** being
         * erased, and that is a deliberate difference —
         * `document_downloads.document_id` cascades, because a release record
         * is a record about a document and an office that erases a document
         * outright is erasing its release history with it. The document's own
         * travel history is the one that outlives it, through `nullOnDelete`
         * beside the uploader-name snapshot.
         */
        $this->assertSame($name, ActivityLog::query()->whereNotNull('actor_name')->first()->actor_name);
        $this->assertSame($name, DocumentDownload::query()->first()->user_name);
        $this->assertNull(DocumentDownload::query()->first()->user_id, 'The actor reference must null rather than hold.');
        $this->assertNull(ActivityLog::query()->first()->actor_id);

        $this->assertNotNull($document->fresh(), 'The document was not what this test erased.');
    }
}
