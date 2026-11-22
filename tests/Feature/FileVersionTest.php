<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Enums\DocumentVisibility;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\User;
use App\Services\Documents\DocumentRegistrar;
use App\Services\Files\FileManager;
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use App\Services\Files\FileVersionService;
use App\Services\Files\FolderService;
use App\Services\Files\GuestIdentity;
use App\Services\Files\SharingService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A file is a sequence of versions, and this is what that has to mean.
 *
 * Four properties here are the ones worth protecting, because each of them is
 * something a plausible refactor would break without failing anything else:
 *
 * - **the `files` row stays the file.** Its `public_id`, its name, its URL, its
 *   shares, its links and the `documents` row pointing at it all survive a
 *   replacement, which is the whole reason the current version lives on that
 *   row rather than in a row of its own;
 * - **a superseded version's bytes are never touched** — not by the
 *   replacement, not by the thumbnail, not by a rollback;
 * - **a version adds no reach.** Version 1 answers exactly what the current
 *   version answers, for every kind of viewer. That is asserted
 *   differentially rather than by hand, so it cannot rot;
 * - **a purge takes every version's bytes**, which is the one leak this design
 *   makes possible and the one it must not have.
 */
class FileVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    // ------------------------------------------------------------- scaffolding

    private function staff(string $employeeId = '1001'): User
    {
        $user = User::factory()->create(['employee_id' => $employeeId]);
        $user->syncRoles([Roles::STAFF]);

        return $user;
    }

    private function root(User $user): Folder
    {
        return app(FolderService::class)->rootFor($user);
    }

    private function fileFor(User $owner, string $name = 'notes.pdf', string $body = 'first'): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->createWithContent($name, $body),
            $this->root($owner),
            $owner,
        );
    }

    private function replacement(string $name = 'notes.pdf', string $body = 'second'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function manager(): FileManager
    {
        return app(FileManager::class);
    }

    /** A tracked document over a real stored file, through the one registrar. */
    private function trackedDocument(User $owner, string $visibility = 'public'): Document
    {
        $file = $this->fileFor($owner, 'order.pdf');

        return app(DocumentRegistrar::class)->register(
            $file,
            DocumentType::query()->where('is_active', true)->firstOrFail(),
            'Office Order',
            DocumentVisibility::from($visibility),
            [],
            $owner,
        );
    }

    // ------------------------------------------------------------- the sequence

    public function test_a_first_upload_is_version_one(): void
    {
        $file = $this->fileFor($this->staff());

        $this->assertSame(1, $file->version);
        $this->assertSame(0, $file->versions()->count());
        // Version 1's path is what it always was, so nothing needed rewriting.
        $this->assertStringNotContainsString('-v', $file->storage_path);
    }

    public function test_a_replacement_becomes_version_two_on_the_same_row(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);
        $originalId = $file->id;
        $originalPublicId = $file->public_id;

        $this->manager()->replace($owner, $file, $this->replacement());

        $file->refresh();

        $this->assertSame(2, $file->version);
        $this->assertSame($originalId, $file->id);
        $this->assertSame($originalPublicId, $file->public_id);
        // One row in `files`, not two: a version is not a listed item.
        $this->assertSame(1, File::query()->count());
        $this->assertSame(1, $file->versions()->count());
        $this->assertSame(1, $file->versions()->first()->version);
    }

    public function test_a_replacement_keeps_the_name_the_shares_and_the_link(): void
    {
        $owner = $this->staff();
        $reader = $this->staff('1002');
        $file = $this->fileFor($owner, 'notes.pdf');

        $sharing = app(SharingService::class);
        $sharing->share($file, GranteeType::User, $reader->id, ShareAbility::Download, $owner);
        $link = $sharing->createLink($file, ShareAbility::View, $owner);

        $this->manager()->replace($owner, $file, $this->replacement('revised.pdf'));

        $file->refresh();

        $this->assertSame('notes.pdf', $file->name, 'the listing name must not change under the reader');
        $this->assertSame('revised.pdf', $file->original_name, 'the download name follows the bytes');
        $this->assertSame(1, $file->shares()->count());
        $this->assertSame($link->token, $file->shareLinks()->first()->token);
    }

    public function test_a_superseded_versions_bytes_are_kept(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');
        $firstPath = $file->storage_path;

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));

        $file->refresh();

        $this->assertNotSame($firstPath, $file->storage_path);
        Storage::disk(FileStorage::DISK)->assertExists($firstPath);
        Storage::disk(FileStorage::DISK)->assertExists($file->storage_path);
        $this->assertSame('first', Storage::disk(FileStorage::DISK)->get($firstPath));
        $this->assertSame('second', Storage::disk(FileStorage::DISK)->get($file->storage_path));
    }

    public function test_a_version_carries_its_own_author_note_and_dates(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->manager()->replace($owner, $file, $this->replacement(), 'Corrected the annexes');

        $file->refresh();
        $superseded = $file->versions()->first();

        // The current version's descriptor is on the file...
        $this->assertSame($owner->id, $file->version_created_by_id);
        $this->assertSame($owner->name, $file->version_created_by_name);
        $this->assertSame('Corrected the annexes', $file->version_note);
        $this->assertNotNull($file->version_created_at);

        // ...and version 1's says honestly that nobody recorded its author,
        // rather than borrowing the owner's name for a snapshot nobody took.
        $this->assertNull($superseded->created_by_name);
        $this->assertNotNull($superseded->became_current_at);
        $this->assertNotNull($superseded->superseded_at);
    }

    public function test_each_version_keeps_its_own_thumbnail(): void
    {
        $owner = $this->staff();
        $file = app(FileStorage::class)->store(
            UploadedFile::fake()->image('chart.png', 40, 40),
            $this->root($owner),
            $owner,
        );
        app(FileManager::class);
        $file->update(['has_thumbnail' => true]);

        $this->manager()->replace($owner, $file, UploadedFile::fake()->image('chart.png', 60, 60));

        $file->refresh();
        $superseded = $file->versions()->first();

        // Different paths, so a replacement cannot make the history list render
        // the newest picture for every older version.
        $this->assertNotSame($superseded->thumbnailPath(), $file->thumbnailPath());
    }

    /**
     * Allowed, and labelled.
     *
     * This was a refusal, and the refusal was wrong twice: re-uploading the
     * same bytes under a different name still changes `original_name` — a
     * browser hands back `template(1).docx` for `template.docx` — and it
     * fired from the document edit form, where the file picker sits beside
     * the title and failed the whole save for holding the file it already
     * held. The history is kept truthful by saying the bytes are identical
     * rather than by refusing to record what happened.
     */
    public function test_a_byte_identical_replacement_is_allowed_and_recorded_as_identical(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'same');

        $this->manager()->replace($owner, $file, $this->replacement(body: 'same'));

        $file->refresh();

        $this->assertSame(2, $file->versionNumber());
        $this->assertSame(1, $file->versions()->count());

        $entry = ActivityLog::query()
            ->where('action', ActivityAction::VersionReplaced->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->context['identical_bytes'] ?? false);
    }

    public function test_a_changed_replacement_is_not_labelled_identical(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));

        $entry = ActivityLog::query()
            ->where('action', ActivityAction::VersionReplaced->value)
            ->latest('id')
            ->first();

        $this->assertArrayNotHasKey('identical_bytes', $entry->context ?? []);
    }

    /**
     * Same bytes, different name: the case the old refusal got wrong.
     */
    public function test_a_replacement_may_keep_the_bytes_and_change_the_name(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'template.docx', 'same');

        $this->manager()->replace($owner, $file, $this->replacement('template(1).docx', body: 'same'));

        $file->refresh();

        $this->assertSame('template(1).docx', $file->original_name);
        $this->assertSame(2, $file->versionNumber());
    }

    public function test_a_replacement_may_be_a_different_file_type(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'report.pdf');

        $this->manager()->replace($owner, $file, $this->replacement('report.docx'));

        $file->refresh();

        $this->assertSame('docx', $file->extension);
        $this->assertSame('report.pdf', $file->name, 'renaming could collide and so refuse a replacement');
        $this->assertSame('pdf', $file->versions()->first()->extension);
    }

    /**
     * Downloading while reading an old version must hand over *that* version.
     *
     * The page's Download button was a bare
     * `/documents/{id}/file/download`, which always served the current
     * bytes — so a reader on version 1, with the page warned and
     * watermarked to say so, pressed Download and silently received
     * version 3. That is the exact failure the warning exists to prevent,
     * handed out by the control beside it.
     */
    public function test_downloading_a_named_version_serves_that_version(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement('order.pdf', body: 'second'));

        $current = $this->actingAs($owner)->get("/documents/{$document->id}/file/download");
        $current->assertOk();
        $this->assertSame('second', $current->streamedContent());

        $old = $this->actingAs($owner)->get("/documents/{$document->id}/file/download?version=1");
        $old->assertOk();
        $this->assertSame('first', $old->streamedContent());
    }

    /**
     * And it arrives under a name that says which version it is.
     *
     * Two downloads of one file used to land as the same filename, which is
     * the problem versioning exists to solve reproduced in the one place a
     * reader keeps the file.
     */
    public function test_a_download_is_named_after_its_version(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement('order.pdf', body: 'second'));

        $this->actingAs($owner)
            ->get("/documents/{$document->id}/file/download?version=1")
            ->assertDownload('order (v1).pdf');

        $this->actingAs($owner)
            ->get("/documents/{$document->id}/file/download")
            ->assertDownload('order (v2).pdf');
    }

    /**
     * The stamp is applied where a download is served, never on the model.
     *
     * `StoredBytes::downloadName()` is the file's name, and
     * `ActivityLog::record()` snapshots it as `subject_label` — stamping the
     * model put "(v3)" into the trail's record of what a file was called,
     * which is a presentation choice rewriting history. This asserts the
     * separation that bug produced.
     */
    public function test_the_version_stamp_does_not_reach_the_audit_label(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement('notes.pdf', body: 'second'));

        $this->assertSame('notes.pdf', $file->fresh()->downloadName());
        $this->assertSame(
            'notes.pdf',
            ActivityLog::query()->where('action', ActivityAction::VersionReplaced->value)->latest('id')->value('subject_label'),
        );
    }

    /**
     * A replacement must change the URL the preview is fetched from.
     *
     * `/files/{owner}/{file}` is deliberately stable across a replacement —
     * `public_id` never changes, which is the property the tracking code,
     * the stored QR label and the share link all rest on — and the stream
     * response is cacheable for ten minutes. So a descriptor that named the
     * version only when it was superseded served the *previous* bytes at
     * exactly the URL the new ones live at: replace an image with a PDF and
     * the page draws the old image inside the new descriptor's mode.
     *
     * An ETag does not help. `max-age` means "do not even ask", so the
     * request that would have revalidated is never made. Only a different
     * URL can be acted on by a cache, which is what this asserts.
     */
    public function test_a_replacement_changes_the_url_the_preview_is_fetched_from(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');
        $preview = app(FilePreview::class);

        $before = $preview->describe($file->fresh(), $owner)['src'];

        $this->manager()->replace($owner, $file, $this->replacement('notes.pdf', body: 'second'));

        $after = $preview->describe($file->fresh(), $owner)['src'];

        $this->assertNotSame($before, $after, 'A cached copy of the old bytes would answer the new request.');
        $this->assertStringContainsString('version=1', $before);
        $this->assertStringContainsString('version=2', $after);
    }

    /**
     * The preview names the bytes it is drawing.
     *
     * `files.name` is deliberately never rewritten by a replacement — a
     * version is the same file and a rename could be refused by
     * `assertNameFree()` — so it is the folder's label, frozen at creation.
     * The descriptor used it, which meant a `.wav` uploaded over a `.docx`
     * previewed as an audio player titled "template.docx": every other
     * field was the new version's and only the name was the old one's.
     */
    public function test_the_preview_is_named_after_the_version_it_draws(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'template.docx', 'first');
        $preview = app(FilePreview::class);

        $this->manager()->replace($owner, $file, $this->replacement('notification.wav', body: 'second'));

        $file->refresh();

        $this->assertSame('template.docx', $file->name, 'the listing name is deliberately frozen');
        $this->assertSame('notification.wav', $preview->describe($file, $owner)['name']);
        $this->assertSame(
            'template.docx',
            $preview->describe($file, $owner, version: app(FileVersionService::class)->find($file, 1))['name'],
        );
    }

    /**
     * A share link reaches the current version, and only the current one.
     *
     * Three things in one scene, because they are one rule seen from three
     * sides:
     *
     * - the link keeps working after a replacement and serves the **new**
     *   bytes, since the token is minted against the item and
     *   `documents.file_id` / the `files` row never move;
     * - naming an earlier version on the link's own stream route is a 404,
     *   like every other refusal there, so a holder cannot walk back
     *   through history;
     * - the landing page's descriptor asks for no version at all, so the
     *   page cannot hand one to a visitor by accident.
     *
     * The middle one is the load-bearing one. Letting a token reach earlier
     * versions would *widen every link already in circulation* on the day
     * versioning shipped — including ones sent before the feature existed —
     * which is the exact inverse of this module's rule that a link's reach
     * is re-evaluated on every redemption and only ever narrowed.
     */
    public function test_a_share_link_reaches_the_current_version_only(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');
        $link = app(SharingService::class)->createLink($file, ShareAbility::Download, $owner);

        $this->manager()->replace($owner, $file, $this->replacement('notes.pdf', body: 'second'));

        auth()->logout();

        /*
         * The bytes sit behind the same question the landing page asks, so
         * the visitor answers it first — "stay anonymous" is an answer. The
         * cookie is taken *decrypted* off the response and handed back
         * through `withCookies()`, because the client encrypts whatever it
         * is given and passing ciphertext back encrypts it twice.
         */
        $identified = $this->post("/s/{$link->token}/identify", ['name' => null]);

        $this->withCookies([
            GuestIdentity::COOKIE => $identified->getCookie(GuestIdentity::COOKIE)->getValue(),
        ]);

        // The new bytes, through the same unchanged token.
        $current = $this->get("/s/{$link->token}/file?disposition=inline");
        $current->assertOk();
        $this->assertSame('second', $current->streamedContent());

        // The old ones are not reachable by naming them.
        $this->get("/s/{$link->token}/file?disposition=inline&version=1")->assertNotFound();

        // Nor by the page offering them: a link's descriptor names no version.
        $descriptor = app(FilePreview::class)->describe($file->fresh(), null, $link->fresh());

        $this->assertStringNotContainsString('version=', $descriptor['src']);
    }

    // ------------------------------------------------------------- reverting

    public function test_reverting_copies_an_old_version_forward(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));
        $this->manager()->revertTo($owner, $file->refresh(), 1);

        $file->refresh();

        $this->assertSame(3, $file->version, 'forward, never backward');
        $this->assertSame(1, $file->version_reverted_from);
        $this->assertSame('first', Storage::disk(FileStorage::DISK)->get($file->storage_path));
        $this->assertSame(2, $file->versions()->count());
    }

    public function test_reverting_to_the_current_version_is_refused(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->expectException(ValidationException::class);

        $this->manager()->revertTo($owner, $file, 1);
    }

    public function test_reverting_to_a_version_that_is_not_in_the_history_is_refused(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->expectException(ValidationException::class);

        $this->manager()->revertTo($owner, $file, 7);
    }

    // ------------------------------------------------------------- the trails

    public function test_a_replacement_is_recorded_against_the_new_version(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->manager()->replace($owner, $file, $this->replacement(), 'New annexes');

        $row = ActivityLog::query()->where('action', ActivityAction::VersionReplaced->value)->firstOrFail();

        $this->assertSame(2, $row->subject_version);
        $this->assertSame(2, $row->context['version']);
        $this->assertSame(1, $row->context['from_version']);
        $this->assertSame($owner->name, $row->actor_name);
    }

    public function test_replacing_a_tracked_documents_file_writes_the_documents_trail(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement('order-v2.pdf'), 'Signed copy');

        $row = DocumentLog::query()
            ->where('document_id', $document->id)
            ->where('action', DocumentAction::FileReplaced->value)
            ->firstOrFail();

        $this->assertSame(2, $row->version);
        $this->assertStringContainsString('Signed copy', (string) $row->remarks);
        // The register prints this beside the tracking code.
        $this->assertSame('order-v2.pdf', $document->refresh()->file_name);
    }

    public function test_a_revert_reads_as_a_replacement_in_the_documents_trail(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement(body: 'second'));
        $this->manager()->revertTo($owner, $document->refresh()->file, 1);

        $rows = DocumentLog::query()
            ->where('document_id', $document->id)
            ->where('action', DocumentAction::FileReplaced->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows, 'a revert is a replacement to a reader');
        $this->assertSame(3, $rows->last()->version);
        $this->assertStringContainsString('Reverted to version 1', (string) $rows->last()->remarks);

        // The mechanics stay in the activity log, where they belong.
        $this->assertSame(
            1,
            ActivityLog::query()->where('action', ActivityAction::VersionReverted->value)->count(),
        );
        $this->assertSame(
            0,
            DocumentLog::query()->where('action', 'version_reverted')->count(),
        );
    }

    public function test_a_replacement_never_issues_a_tracking_code(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);
        $code = $document->code;

        $this->manager()->replace($owner, $document->file, $this->replacement());

        $this->assertSame($code, $document->refresh()->code);
        $this->assertSame(1, Document::query()->count());
    }

    public function test_the_trail_can_be_filtered_by_version(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement());

        $this->assertSame([2, 1], DocumentLog::versionsFor($document->id));
        $this->assertSame(
            1,
            $document->logs()->trail(['version' => 2])->count(),
            'the replacement is the only row against version 2',
        );
        $this->assertGreaterThan(0, $document->logs()->trail(['version' => 1])->count());
    }

    public function test_the_trail_splits_into_one_tab_per_version(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement());

        $tabs = DocumentLog::versionTabsFor($document->id);
        $whole = $document->logs()->count();

        $this->assertSame([2, 1], array_column($tabs, 'version'), 'newest version first');
        $this->assertSame(
            $whole,
            array_sum(array_column($tabs, 'entries')),
            'the tabs account for every row, or the counts disagree with the trail',
        );

        // Each tab says what choosing it would show.
        foreach ($tabs as $tab) {
            $this->assertSame(
                $tab['entries'],
                $document->logs()->trail(['version' => $tab['version']])->count(),
            );
        }
    }

    public function test_a_tabs_count_is_narrowed_by_the_filters_in_force_but_not_by_the_version(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement());

        // A filter the replacement row cannot match, so version 2's tab has
        // to report zero rather than reporting the whole of version 2.
        $filters = ['action' => DocumentAction::Viewed->value, 'version' => 2];
        $tabs = collect(DocumentLog::versionTabsFor($document->id, $filters))
            ->keyBy('version');

        $this->assertSame(0, $tabs[2]['entries'] ?? 0);
        $this->assertSame(
            $document->logs()->trail(['action' => DocumentAction::Viewed->value])->count(),
            (int) $tabs->sum('entries'),
            'counting must ignore the version in force, or every tab but the open one reads zero',
        );
    }

    public function test_the_unversioned_rows_are_their_own_tab(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        // A row written before the version column existed carries null, and
        // null is a tab rather than a silent remainder.
        $document->logs()->latest('id')->first()->forceFill(['version' => null])->save();

        $tabs = DocumentLog::versionTabsFor($document->id);

        $this->assertSame(null, end($tabs)['version'], 'the unversioned bucket sorts last');
        $this->assertSame(
            end($tabs)['entries'],
            $document->logs()->trail(['version' => 'none'])->count(),
        );
    }

    public function test_the_version_filter_offers_only_versions_the_trail_holds(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->assertSame([1], DocumentLog::versionsFor($document->id));
    }

    // ------------------------------------------------------------- the invariants

    public function test_only_the_uploader_may_replace_a_tracked_documents_file(): void
    {
        $owner = $this->staff();
        $other = $this->staff('1002');
        $document = $this->trackedDocument($owner);

        // A *manage* share carries FileAbility::Rename, which is exactly the
        // side door this guard closes: the bytes a printed code names must not
        // be swappable by somebody the uploader shared the file with.
        app(SharingService::class)->share(
            $document->file,
            GranteeType::User,
            $other->id,
            ShareAbility::Manage,
            $owner,
        );

        $this->assertFalse($this->manager()->mayReplace($other, $document->file));

        $this->expectException(ValidationException::class);

        $this->manager()->replace($other, $document->file, $this->replacement());
    }

    public function test_a_manage_share_may_replace_an_untracked_file(): void
    {
        $owner = $this->staff();
        $other = $this->staff('1002');
        $file = $this->fileFor($owner);

        app(SharingService::class)->share($file, GranteeType::User, $other->id, ShareAbility::Manage, $owner);

        $this->assertTrue($this->manager()->mayReplace($other, $file));
    }

    public function test_a_stranger_may_not_replace_a_file(): void
    {
        $owner = $this->staff();
        $stranger = $this->staff('1002');
        $file = $this->fileFor($owner);

        $this->assertFalse($this->manager()->mayReplace($stranger, $file));

        $this->expectException(ValidationException::class);

        $this->manager()->replace($stranger, $file, $this->replacement());
    }

    public function test_a_purge_removes_every_versions_bytes(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));
        $this->manager()->replace($owner, $file->refresh(), $this->replacement(body: 'third'));

        $file->refresh();
        $paths = $file->versions()->pluck('storage_path')->push($file->storage_path);

        $this->assertCount(3, $paths);

        $this->manager()->delete($owner, $file);
        $this->manager()->purge($owner, $file->fresh());

        foreach ($paths as $path) {
            Storage::disk(FileStorage::DISK)->assertMissing($path);
        }

        $this->assertSame(0, FileVersion::query()->count());
    }

    public function test_a_failed_replacement_leaves_no_new_version_and_no_stray_bytes(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');
        $firstPath = $file->storage_path;

        $before = Storage::disk(FileStorage::DISK)->allFiles();

        // The document row is what the version writer touches last; deleting
        // the file's folder row out from under the transaction is not available,
        // so force the failure the way ArchiveSafetyTest does — by making the
        // write throw inside the transaction.
        $this->mock(FileVersionService::class, function ($mock) {
            $mock->shouldReceive('isUnchanged')->andReturn(false);
            $mock->shouldReceive('supersede')->andThrow(new \RuntimeException('disk gave out'));
        });

        try {
            app(FileManager::class)->replace($owner, $file, $this->replacement());
            $this->fail('the replacement should have thrown');
        } catch (\RuntimeException) {
            // expected
        }

        $file->refresh();

        $this->assertSame(1, $file->version);
        $this->assertSame(0, FileVersion::query()->count());
        $this->assertSame('first', Storage::disk(FileStorage::DISK)->get($firstPath));
        $this->assertSame($before, Storage::disk(FileStorage::DISK)->allFiles(), 'no bytes left behind');

        // The failure itself is on record, written after the rollback.
        $this->assertSame(
            'failed',
            ActivityLog::query()
                ->where('action', ActivityAction::VersionReplaced->value)
                ->firstOrFail()
                ->context['outcome'],
        );
    }

    // ------------------------------------------------------------- over HTTP

    private function streamUrl(File $file): string
    {
        return "/files/{$file->owner->public_id}/{$file->public_id}";
    }

    /** Real content, over the policy's size floor: `fake()->create()` writes an empty file. */
    private function upload(string $name, string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_repeat($body.' ', 400));
    }

    public function test_the_replace_endpoint_saves_a_new_version(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->actingAs($owner)
            ->post('/files/replace', [
                'public_id' => $file->public_id,
                'note' => 'Signed copy',
                'file' => $this->upload('notes.pdf', 'second'),
            ])
            ->assertSessionHasNoErrors();

        $file->refresh();

        $this->assertSame(2, $file->version);
        $this->assertSame('Signed copy', $file->version_note);
    }

    public function test_the_replace_endpoint_goes_through_the_upload_policy(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->actingAs($owner)
            ->post('/files/replace', [
                'public_id' => $file->public_id,
                'file' => $this->upload('payload.php', '<?php'),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, $file->refresh()->version);
        $this->assertSame(0, FileVersion::query()->count());
    }

    public function test_the_revert_endpoint_brings_a_version_forward(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));

        $this->actingAs($owner)
            ->post('/files/revert', ['public_id' => $file->public_id, 'version' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $file->refresh()->version);
    }

    public function test_the_versions_endpoint_lists_the_history_for_a_reader(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->manager()->replace($owner, $file, $this->replacement(), 'Revised');

        $payload = $this->actingAs($owner)
            ->getJson("/files/versions?owner={$owner->public_id}&file={$file->public_id}")
            ->assertOk()
            ->json();

        $this->assertSame(2, $payload['current']);
        $this->assertTrue($payload['can_revert']);
        $this->assertCount(2, $payload['versions']);
        // The current version's URL names no version; an older one must.
        $this->assertStringNotContainsString('version=', $payload['versions'][0]['view_url']);
        $this->assertStringContainsString('version=1', $payload['versions'][1]['view_url']);
    }

    public function test_an_unknown_version_is_a_404(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner);

        $this->actingAs($owner)
            ->get($this->streamUrl($file).'?disposition=inline&version=9')
            ->assertNotFound();
    }

    /**
     * **A version adds no reach.**
     *
     * Asserted differentially rather than by hand: for every kind of viewer,
     * asking for version 1 must answer exactly what asking for the current
     * version answers. It states the property rather than an expected list of
     * codes, so it cannot rot — and it is what would catch any future resolver
     * change that forgot versions existed.
     */
    public function test_a_superseded_version_answers_what_the_current_version_answers(): void
    {
        $owner = $this->staff();
        $reader = $this->staff('1002');
        $stranger = $this->staff('1003');
        $admin = $this->staff('1004');
        $admin->syncRoles([Roles::SUPERADMIN]);

        $file = $this->fileFor($owner, 'notes.pdf', 'first');
        app(SharingService::class)->share($file, GranteeType::User, $reader->id, ShareAbility::Download, $owner);

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));
        $file->refresh();

        foreach ([$owner, $reader, $stranger, $admin] as $viewer) {
            foreach (['inline', 'attachment'] as $disposition) {
                $current = $this->actingAs($viewer)
                    ->get($this->streamUrl($file)."?disposition={$disposition}")
                    ->getStatusCode();

                $superseded = $this->actingAs($viewer)
                    ->get($this->streamUrl($file)."?disposition={$disposition}&version=1")
                    ->getStatusCode();

                $this->assertSame(
                    $current,
                    $superseded,
                    "viewer {$viewer->employee_id} got {$current} for the current version and {$superseded} for version 1",
                );
            }
        }
    }

    public function test_a_share_link_cannot_reach_an_old_version(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));

        $link = app(SharingService::class)->createLink($file->refresh(), ShareAbility::Download, $owner);

        $this->actingAs($owner)->get("/s/{$link->token}/file")->assertOk();
        // A link is a credential minted for a thing at a moment; its terms say
        // nothing about history, and every token already sent would otherwise
        // widen the day this shipped.
        $this->actingAs($owner)->get("/s/{$link->token}/file?version=1")->assertNotFound();
    }

    public function test_a_download_of_an_old_version_is_recorded_against_that_version(): void
    {
        $owner = $this->staff();
        $document = $this->trackedDocument($owner);

        $this->manager()->replace($owner, $document->file, $this->replacement(body: 'second'));

        $this->actingAs($owner)
            ->get($this->streamUrl($document->file->refresh()).'?disposition=attachment&version=1')
            ->assertOk();

        $row = DocumentLog::query()
            ->where('document_id', $document->id)
            ->where('action', DocumentAction::Downloaded->value)
            ->sole();

        $this->assertSame(1, $row->version, 'the trail must name the bytes that actually left');
        // The release register still gets its row, whichever version it was.
        $this->assertSame(1, $document->downloads()->count());
    }

    public function test_previewing_two_versions_inside_the_debounce_window_records_both(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement(body: 'second'));
        $file->refresh();

        $this->actingAs($owner)->get($this->streamUrl($file).'?disposition=inline')->assertOk();
        $this->actingAs($owner)->get($this->streamUrl($file).'?disposition=inline&version=1')->assertOk();
        // The same version again inside the window is still one row.
        $this->actingAs($owner)->get($this->streamUrl($file).'?disposition=inline&version=1')->assertOk();

        $rows = ActivityLog::query()
            ->where('action', ActivityAction::Previewed->value)
            ->pluck('subject_version')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2], $rows, 'two versions are two things being opened');
    }

    public function test_the_document_versions_endpoint_is_refused_to_a_stranger(): void
    {
        $owner = $this->staff();
        $stranger = $this->staff('1002');
        $document = $this->trackedDocument($owner, 'confidential');

        $this->actingAs($owner)->getJson("/documents/{$document->id}/versions")->assertOk();
        $this->actingAs($stranger)->getJson("/documents/{$document->id}/versions")->assertNotFound();
    }

    public function test_the_history_reads_the_file_row_and_its_versions_as_one_list(): void
    {
        $owner = $this->staff();
        $file = $this->fileFor($owner, 'notes.pdf', 'first');

        $this->manager()->replace($owner, $file, $this->replacement('notes-2.pdf', 'second'), 'Revised');

        $history = app(FileVersionService::class)->history($file->refresh());

        $this->assertCount(2, $history);
        $this->assertSame(2, $history[0]['version']);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('Revised', $history[0]['note']);
        $this->assertSame(1, $history[1]['version']);
        $this->assertFalse($history[1]['is_current']);
        $this->assertNotNull($history[1]['superseded_at']);
        // Twelve characters is enough to compare two versions by eye.
        $this->assertSame(12, strlen((string) $history[0]['checksum']));
    }
}
