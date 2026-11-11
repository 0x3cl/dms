<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Section;
use App\Models\User;
use App\Services\Files\FileEventRecorder;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The general activity log, and its relationship to the two document audit
 * tables it sits beside.
 */
class ActivityTrailTest extends TestCase
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

    private function streamUrl(File $file): string
    {
        return "/files/{$file->owner->public_id}/{$file->public_id}";
    }

    // ------------------------------------------------------------- the snapshot

    /**
     * Every row carries who acted and which office they sat in **at the time**.
     * An audit trail that changes when the org chart changes is not an audit
     * trail.
     */
    public function test_a_row_snapshots_the_actor_and_their_office(): void
    {
        $division = Division::factory()->create(['name' => 'Research Division']);
        $section = Section::factory()->create(['division_id' => $division->id, 'name' => 'Textiles']);
        $user = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'division_id' => $division->id,
            'section_id' => $section->id,
        ]);

        $file = $this->fileFor($user);
        app(FileEventRecorder::class)->record(ActivityAction::Downloaded, $file, $user);

        $row = ActivityLog::query()->where('action', ActivityAction::Downloaded)->sole();

        $this->assertSame('Ana Cruz', $row->actor_name);
        $this->assertSame('Research Division', $row->division_name);
        $this->assertSame('Textiles', $row->section_name);
        $this->assertSame($user->employee_id, $row->actor_employee_id);
    }

    public function test_renaming_an_office_does_not_rewrite_history(): void
    {
        $division = Division::factory()->create(['name' => 'Research Division']);
        $user = User::factory()->create(['division_id' => $division->id]);

        app(FileEventRecorder::class)->record(ActivityAction::Uploaded, $this->fileFor($user), $user);

        $division->update(['name' => 'Science Division']);

        $this->assertSame('Research Division', ActivityLog::query()->sole()->division_name);
    }

    public function test_a_row_outlives_its_actor_and_its_subject(): void
    {
        $user = User::factory()->create(['first_name' => 'Departed', 'last_name' => 'Person']);
        $file = $this->fileFor($user, 'important.pdf');

        app(FileEventRecorder::class)->record(ActivityAction::Downloaded, $file, $user);

        // Both forced: this asserts survival of a *permanent* erasure, which
        // is the guarantee `nullOnDelete` beside a snapshot exists for. A soft
        // delete keeps the keys intact and is covered separately.
        $file->forceDelete();
        $user->forceDelete();

        $row = ActivityLog::query()->sole();

        $this->assertSame('Departed Person', $row->actor_name);
        $this->assertSame('important.pdf', $row->subject_label);
        $this->assertNull($row->actor_id, 'The key is nullOnDelete; the snapshot is what the page renders.');
    }

    /** A row with no actor is still a row about something that happened. */
    public function test_an_actorless_row_is_recorded_as_system(): void
    {
        $file = $this->fileFor(User::factory()->create());

        ActivityLog::record(null, ActivityAction::Purged, $file);

        $this->assertSame('System', ActivityLog::query()->sole()->actor_name);
    }

    // --------------------------------------- the boundary with document_logs

    /**
     * The invariant this table exists for.
     *
     * `document_logs` is rendered to **everyone who can read the document**, so
     * a file-manager event appearing in it would turn a document's travel
     * history into a disclosure about the file manager.
     */
    public function test_file_manager_events_do_not_leak_into_a_documents_trail(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);
        $document = $this->trackedDocument($owner, $file);

        $before = DocumentLog::query()->where('document_id', $document->id)->count();

        $recorder = app(FileEventRecorder::class);
        foreach ([ActivityAction::Copied, ActivityAction::Extracted, ActivityAction::LinkCreated, ActivityAction::Renamed, ActivityAction::Moved] as $action) {
            $recorder->record($action, $file, $owner);
        }

        $this->assertSame(
            $before,
            DocumentLog::query()->where('document_id', $document->id)->count(),
            'Only the actions that already mean something in a document history may cross over.',
        );
        $this->assertSame(5, ActivityLog::query()->count());
    }

    /** The two that do mean the same thing cross over. */
    public function test_a_tracked_files_download_writes_both_trails(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);
        $document = $this->trackedDocument($owner, $file);

        app(FileEventRecorder::class)->record(ActivityAction::Downloaded, $file, $owner);

        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Downloaded)->count());
        $this->assertSame(1, DocumentLog::query()
            ->where('document_id', $document->id)
            ->where('action', DocumentAction::Downloaded)
            ->count());
        // The register behind /admin/download-logs is document-scoped.
        $this->assertSame(1, DocumentDownload::query()->where('document_id', $document->id)->count());
    }

    /**
     * `document_downloads.document_id` is not nullable, so an untracked file's
     * download has no row there — and does not need one.
     */
    public function test_an_untracked_files_download_writes_only_the_activity_log(): void
    {
        $owner = User::factory()->create();

        app(FileEventRecorder::class)->record(ActivityAction::Downloaded, $this->fileFor($owner), $owner);

        $this->assertSame(1, ActivityLog::query()->count());
        $this->assertSame(0, DocumentLog::query()->count());
        $this->assertSame(0, DocumentDownload::query()->count());
    }

    // ----------------------------------------------------------- the debounce

    /**
     * An embedded preview iframe re-requests the same file on a resize, a
     * re-mount or a reload. One row per render is a forged history: it says the
     * person opened the document nine times when they opened it once.
     */
    public function test_repeated_previews_are_recorded_once_within_the_window(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);
        $url = $this->streamUrl($file).'?disposition=inline';

        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($owner)->get($url)->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Previewed)->count());
    }

    /** A download is never debounced: each one is a separate copy leaving. */
    public function test_each_download_is_recorded_separately(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);

        $this->actingAs($owner)->get($this->streamUrl($file))->assertOk();
        $this->actingAs($owner)->get($this->streamUrl($file))->assertOk();

        $this->assertSame(2, ActivityLog::query()->where('action', ActivityAction::Downloaded)->count());
    }

    /** A different person previewing is a different event. */
    public function test_the_debounce_is_per_person(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $file = $this->fileFor($owner);
        app(SharingService::class)->share($file, GranteeType::User, $friend->id, ShareAbility::View, $owner);

        $url = $this->streamUrl($file).'?disposition=inline';
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($friend)->get($url)->assertOk();

        $this->assertSame(2, ActivityLog::query()->where('action', ActivityAction::Previewed)->count());
    }

    /** A refused request must not be recorded as a preview that happened. */
    public function test_a_refused_request_records_nothing(): void
    {
        $file = $this->fileFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get($this->streamUrl($file))
            ->assertNotFound();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    /**
     * The existence check runs before the record, so a file that has gone
     * missing off the disk leaves no record of a release that never happened.
     */
    public function test_a_missing_file_records_nothing(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);
        Storage::disk('documents')->delete($file->storage_path);

        $this->actingAs($owner)->get($this->streamUrl($file))->assertNotFound();

        $this->assertSame(0, ActivityLog::query()->where('action', ActivityAction::Downloaded)->count());
    }

    // -------------------------------------------------------------- sharing

    public function test_sharing_and_revoking_are_recorded_with_the_grantee(): void
    {
        $division = Division::factory()->create(['name' => 'Research Division']);
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);

        $sharing = app(SharingService::class);
        $share = $sharing->share($file, GranteeType::Division, $division->id, ShareAbility::Download, $owner);
        $sharing->revoke($share, $owner);

        $shared = ActivityLog::query()->where('action', ActivityAction::Shared)->sole();
        $this->assertSame('Research Division', $shared->context['grantee']);
        $this->assertSame('download', $shared->context['ability']);

        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::ShareRevoked)->count());
    }

    /**
     * An audit row must never carry a live credential: that would make the
     * audit report a way to *obtain* the credential, a strictly worse
     * disclosure than the one it was written to detect.
     */
    public function test_a_link_is_recorded_without_its_token(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);

        $link = app(SharingService::class)->createLink($file, ShareAbility::Download, $owner);

        $row = ActivityLog::query()->where('action', ActivityAction::LinkCreated)->sole();

        $this->assertStringNotContainsString($link->token, json_encode($row->context));
        $this->assertSame('download', $row->context['ability']);
    }

    /** Opening a link is recorded, with no actor — nobody is signed in. */
    public function test_opening_a_share_link_is_recorded(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileFor($owner);
        $link = app(SharingService::class)->createLink($file, ShareAbility::Download, $owner);
        Auth::logout();

        $this->get("/s/{$link->token}")->assertOk();

        $row = ActivityLog::query()->where('action', ActivityAction::LinkAccessed)->sole();

        $this->assertNull($row->actor_id);
        $this->assertSame('System', $row->actor_name);
        $this->assertSame($owner->name, $row->context['created_by']);
    }

    // ---------------------------------------------------------------- the page

    public function test_the_report_is_refused_without_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/activity-trails')->assertForbidden();
        $this->actingAs(User::factory()->superadmin()->create())->get('/activity-trails')->assertOk();
    }

    public function test_the_report_filters_by_action_and_search(): void
    {
        $owner = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $other = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Santos']);
        $recorder = app(FileEventRecorder::class);

        $recorder->record(ActivityAction::Downloaded, $this->fileFor($owner, 'a.pdf'), $owner);
        $recorder->record(ActivityAction::Renamed, $this->fileFor($other, 'b.pdf'), $other);

        $admin = User::factory()->superadmin()->create();

        $byAction = $this->actingAs($admin)->get('/activity-trails?action=renamed')
            ->viewData('page')['props']['entries']['data'];
        $this->assertCount(1, $byAction);
        $this->assertSame('Ben Santos', $byAction[0]['actor_name']);

        $bySearch = $this->actingAs($admin)->get('/activity-trails?search=Ana')
            ->viewData('page')['props']['entries']['data'];
        $this->assertCount(1, $bySearch);
        $this->assertSame('Ana Cruz', $bySearch[0]['actor_name']);
    }

    /** The report needs no eager loading — the rows carry their own names. */
    public function test_the_report_renders_after_its_actors_are_deleted(): void
    {
        $owner = User::factory()->create();
        app(FileEventRecorder::class)->record(ActivityAction::Downloaded, $this->fileFor($owner), $owner);
        $owner->delete();

        $rows = $this->actingAs(User::factory()->superadmin()->create())
            ->get('/activity-trails')
            ->assertOk()
            ->viewData('page')['props']['entries']['data'];

        $this->assertCount(1, $rows);
        $this->assertNotSame('', $rows[0]['actor_name']);
    }

    private function trackedDocument(User $owner, File $file): Document
    {
        return Document::create([
            'code' => 'MEMO-20260101-0001',
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
}
