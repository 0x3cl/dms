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
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * How a file is previewed, and the one door that opens for it.
 *
 * Images, video, audio — and PDFs — are this application's own: streamed from
 * the private disk through AccessResolver and rendered by the browser. Word,
 * Excel and PowerPoint go through **Microsoft's Office viewer**, which renders
 * them on *their* servers and fetches the file itself, so the bytes have to be
 * reachable without our session for as long as the render takes.
 *
 * That is the only relaxation of the private disk in the system, which is why
 * these tests are about its edges: the URL is minted only after a grant, it is
 * signed and expiring, it names one file, a confidential item never gets one,
 * the item's visibility is re-checked on every fetch rather than trusted to the
 * signature — and **no URL is minted at all when nothing outside this network
 * could fetch it**, which is the normal state on a developer's machine.
 */
class FilePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');

        // A deployed installation, so a viewer URL is minted at all. The
        // default `app.url` in a test run is localhost, which no outside
        // service can fetch from — see the last test in this file.
        config(['app.url' => 'https://doctrack.example.gov.ph']);
    }

    private function root(User $user): Folder
    {
        return app(FolderService::class)->rootFor($user);
    }

    private function fileIn(Folder $folder, User $owner, string $name, string $mime): File
    {
        return app(FileStorage::class)->store(
            UploadedFile::fake()->create($name, 8, $mime),
            $folder,
            $owner,
        );
    }

    private function describe(User $actor, File $file): array
    {
        return $this->actingAs($actor)
            ->getJson("/files/preview?owner={$file->owner->public_id}&file={$file->public_id}")
            ->assertOk()
            ->json();
    }

    public function test_the_viewer_is_chosen_from_the_file_rather_than_by_the_page(): void
    {
        $user = User::factory()->create();
        $root = $this->root($user);

        $modes = [
            // One mode for everything the document viewer takes, which is what
            // makes a PDF and a spreadsheet behave the same way on the page.
            'report.pdf' => ['application/pdf', 'document'],
            'minutes.docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document'],
            'budget.xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'document'],
            'deck.pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'document'],
            'photo.png' => ['image/png', 'image'],
            'clip.mp4' => ['video/mp4', 'video'],
            'note.mp3' => ['audio/mpeg', 'audio'],
            'archive.zip' => ['application/zip', 'none'],
        ];

        foreach ($modes as $name => [$mime, $expected]) {
            $file = $this->fileIn($root, $user, $name, $mime);

            $this->assertSame($expected, $this->describe($user, $file)['mode'], $name);
        }
    }

    /**
     * An Office document hands a third party a signed URL, never the bytes.
     */
    public function test_an_office_document_is_described_with_a_signed_viewer_url(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn(
            $this->root($user),
            $user,
            'minutes.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $descriptor = $this->describe($user, $file);

        $this->assertStringStartsWith(FilePreview::VIEWER.'?src=', $descriptor['viewer_url']);

        // The wrapped URL is ours, points at this one file, and is signed.
        $wrapped = urldecode(substr($descriptor['viewer_url'], strlen(FilePreview::VIEWER.'?src=')));
        $this->assertStringContainsString("/files/viewer/{$user->public_id}/{$file->public_id}", $wrapped);
        $this->assertStringContainsString('signature=', $wrapped);
        $this->assertStringContainsString('expires=', $wrapped);
    }

    public function test_the_signed_viewer_url_serves_the_file_without_a_session(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn(
            $this->root($user),
            $user,
            'minutes.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $signed = URL::temporarySignedRoute('files.viewer', now()->addMinutes(5), [
            'owner' => $user->public_id,
            'file' => $file->public_id,
        ]);

        // Microsoft's servers have no cookie of ours, which is the whole point.
        $this->get($signed)->assertOk();
    }

    public function test_an_unsigned_or_expired_viewer_url_is_refused(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn(
            $this->root($user),
            $user,
            'minutes.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $this->get("/files/viewer/{$user->public_id}/{$file->public_id}")->assertForbidden();

        $expired = URL::temporarySignedRoute('files.viewer', now()->subMinute(), [
            'owner' => $user->public_id,
            'file' => $file->public_id,
        ]);

        $this->get($expired)->assertForbidden();
    }

    /**
     * A confidential item never leaves, and the check is re-run on every fetch.
     *
     * The same rule a share link answers to: a confidential item is reachable
     * only by somebody named on the item itself, and a URL that works without a
     * session is not that. Re-checked on fetch because an item can be
     * reclassified *after* a URL has been handed out — a signature says the URL
     * was issued, not that the item is still shareable.
     */
    public function test_a_confidential_document_never_reaches_the_viewer(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn(
            $this->root($user),
            $user,
            'minutes.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        // A URL issued while it was ordinary.
        $signed = URL::temporarySignedRoute('files.viewer', now()->addMinutes(5), [
            'owner' => $user->public_id,
            'file' => $file->public_id,
        ]);
        $this->get($signed)->assertOk();

        $file->update(['visibility' => FileVisibility::Confidential->value]);

        // It stops working at that moment, not when somebody remembers.
        $this->get($signed)->assertNotFound();

        // And no new one is offered.
        $this->assertSame('none', $this->describe($user, $file)['mode']);
    }

    /**
     * A **PDF never goes to the viewer at all**, confidential or not.
     *
     * The Office viewer does not render PDFs — one sent to it loads a frame
     * that then reports it cannot open the file — and the browser renders them
     * perfectly well. So this is the one place "may this leave the building"
     * and "can this be shown" come apart: a confidential PDF still previews on
     * the screen of the person it was shared with.
     */
    public function test_a_pdf_renders_locally_and_is_never_sent_to_the_viewer(): void
    {
        $user = User::factory()->create();
        $ordinary = $this->fileIn($this->root($user), $user, 'report.pdf', 'application/pdf');

        $descriptor = $this->describe($user, $ordinary);

        $this->assertSame('document', $descriptor['mode']);
        $this->assertNull($descriptor['viewer_url']);
        $this->assertTrue($descriptor['renders_locally']);
        $this->assertStringContainsString('disposition=inline', $descriptor['src']);

        // And classifying it changes none of that.
        $confidential = $this->fileIn($this->root($user), $user, 'secret.pdf', 'application/pdf');
        $confidential->update(['visibility' => FileVisibility::Confidential->value]);

        $classified = $this->describe($user, $confidential);

        $this->assertSame('document', $classified['mode']);
        $this->assertNull($classified['viewer_url']);
        // Not the network's fault, so the page must not say it is.
        $this->assertFalse($classified['viewer_unreachable']);
    }

    /**
     * On an installation nothing outside can reach, no viewer URL is minted at
     * all — and the descriptor says *why*.
     *
     * An embedded viewer is not a renderer in the browser: Microsoft fetches
     * the URL from their own servers. On a laptop serving
     * `http://localhost:8000` the frame can only ever stay empty, however
     * correct the markup is, so the honest answer is to offer none and tell the
     * person what is wrong. This is the single most confusing thing about this
     * feature.
     */
    public function test_no_viewer_url_is_minted_when_the_internet_cannot_reach_us(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $user = User::factory()->create();
        $word = $this->fileIn(
            $this->root($user),
            $user,
            'minutes.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $pdf = $this->fileIn($this->root($user), $user, 'report.pdf', 'application/pdf');

        $wordDescriptor = $this->describe($user, $word);
        $this->assertNull($wordDescriptor['viewer_url']);
        $this->assertTrue($wordDescriptor['viewer_unreachable']);
        $this->assertFalse($wordDescriptor['renders_locally']);

        // A PDF is unaffected: it never needed the viewer.
        $pdfDescriptor = $this->describe($user, $pdf);
        $this->assertSame('document', $pdfDescriptor['mode']);
        $this->assertNull($pdfDescriptor['viewer_url']);
        $this->assertTrue($pdfDescriptor['renders_locally']);
    }

    public function test_a_preview_descriptor_is_refused_for_a_file_you_cannot_see(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner, 'report.pdf', 'application/pdf');

        $this->actingAs(User::factory()->create())
            ->getJson("/files/preview?owner={$owner->public_id}&file={$file->public_id}")
            ->assertNotFound();
    }

    /**
     * A view-only share can preview and cannot download, and the descriptor
     * says so rather than offering a button the stream route then refuses.
     */
    public function test_a_view_only_share_gets_a_preview_without_a_download(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $file = $this->fileIn($this->root($owner), $owner, 'report.pdf', 'application/pdf');

        app(SharingService::class)->share($file, GranteeType::User, $viewer->id, ShareAbility::View, $owner);

        $descriptor = $this->describe($viewer, $file);

        $this->assertSame('document', $descriptor['mode']);
        $this->assertFalse($descriptor['can_download']);
        $this->assertNull($descriptor['download_url']);
    }

    /**
     * The preview is recorded when the descriptor is asked for, and debounced.
     *
     * For an Office document the bytes are fetched by Microsoft, not by the
     * person, so the request that carries an actor is the descriptor — and a
     * row per render would be a forged history anyway.
     */
    public function test_asking_for_a_preview_records_one_entry_not_one_per_request(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'report.pdf', 'application/pdf');

        $this->describe($user, $file);
        $this->describe($user, $file);
        $this->describe($user, $file);

        $this->assertSame(1, ActivityLog::query()
            ->where('action', ActivityAction::Previewed->value)
            ->where('subject_public_id', $file->public_id)
            ->count());
    }

    // ------------------------------------------------- previewing a document

    /**
     * A tracked document is previewed through the same service, and the gate is
     * the document's own rule.
     *
     * The descriptor endpoint is public for the same reason the document page
     * and the byte route are: a signed-out reader holding a printed label is
     * exactly who a public document is for, and gating this on a session would
     * mean the preview worked for everyone except them.
     */
    public function test_a_public_documents_preview_is_described_for_a_signed_out_reader(): void
    {
        $uploader = User::factory()->create();
        $document = $this->document($uploader, 'public');

        $descriptor = $this->getJson("/documents/{$document->id}/preview")
            ->assertOk()
            ->json();

        $this->assertSame('document', $descriptor['mode']);
        $this->assertStringContainsString('disposition=inline', $descriptor['src']);
    }

    public function test_a_private_documents_preview_is_refused_to_a_stranger(): void
    {
        $uploader = User::factory()->create();
        $recipient = User::factory()->create();
        $document = $this->document($uploader, 'private', [$recipient->id]);

        $this->actingAs(User::factory()->create())
            ->getJson("/documents/{$document->id}/preview")
            ->assertNotFound();

        // The named recipient gets it.
        $this->actingAs($recipient)
            ->getJson("/documents/{$document->id}/preview")
            ->assertOk();
    }

    /**
     * Opening the viewer writes the document's own `viewed` entry, debounced.
     *
     * An embedded frame re-requests on every re-render, and for an Office
     * document the bytes are fetched by Microsoft rather than by the person —
     * so the descriptor is both the request that carries an actor and the one
     * that happens once per opening.
     */
    public function test_opening_a_documents_preview_records_one_view(): void
    {
        $uploader = User::factory()->create();
        $reader = User::factory()->create();
        $document = $this->document($uploader, 'public');

        $this->actingAs($reader)->getJson("/documents/{$document->id}/preview")->assertOk();
        $this->actingAs($reader)->getJson("/documents/{$document->id}/preview")->assertOk();

        $this->assertSame(1, $document->logs()
            ->where('action', \App\Enums\DocumentAction::Viewed->value)
            ->where('actor_id', $reader->id)
            ->count());
    }

    /**
     * A document whose file has gone is answered honestly rather than with a
     * 404 for the whole dialog: the record outlives its bytes by design.
     */
    public function test_a_document_with_no_file_reports_no_preview(): void
    {
        $uploader = User::factory()->create();
        $document = $this->document($uploader, 'public');

        $document->update(['file_id' => null, 'file_path' => null]);

        $descriptor = $this->getJson("/documents/{$document->id}/preview")->assertOk()->json();

        $this->assertSame('none', $descriptor['mode']);
        $this->assertFalse($descriptor['can_download']);
    }

    /**
     * @param  list<int>  $recipients
     */
    private function document(User $uploader, string $visibility, array $recipients = []): \App\Models\Document
    {
        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Sample document',
            'document_type_id' => \App\Models\DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => $visibility,
            'recipients' => $recipients,
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertRedirect();

        // Signed out again, so a test asserting what a stranger sees is not
        // quietly acting as the uploader — actingAs persists for the test.
        auth()->logout();

        return \App\Models\Document::query()->latest('id')->sole();
    }
}
