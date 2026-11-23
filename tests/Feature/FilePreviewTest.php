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
use App\Support\FileTypes;
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
     * Word and Excel are drawn **in the browser**, from our own stream.
     *
     * They used to go to Microsoft's viewer, which fetches the file from our
     * server — the one relaxation of the private disk anywhere in this system,
     * and the reason these previews rendered nothing at all on localhost or an
     * office LAN. No viewer URL is minted for them now: doing so would hand a
     * third party a credential for bytes that are never going to be sent.
     */
    public function test_word_and_excel_are_rendered_in_the_browser(): void
    {
        $user = User::factory()->create();

        $expected = [
            'minutes.docx' => 'docx',
            'budget.xlsx' => 'xlsx',
            'list.csv' => 'xlsx',
        ];

        foreach ($expected as $name => $renderer) {
            $file = $this->fileIn($this->root($user), $user, $name, 'application/zip');
            $descriptor = $this->describe($user, $file);

            $this->assertSame('document', $descriptor['mode'], $name);
            $this->assertSame($renderer, $descriptor['client_renderer'], $name);
            $this->assertNull($descriptor['viewer_url'], "{$name} must not be handed to an outside viewer.");
            $this->assertFalse($descriptor['viewer_unreachable'], $name);
        }
    }

    /**
     * What no browser library renders still goes to the Office viewer.
     *
     * PowerPoint, and the legacy OLE2 formats `.doc`/`.xls`, which are not
     * OOXML at all. The door narrows; it does not close.
     */
    public function test_powerpoint_and_legacy_office_still_use_the_viewer(): void
    {
        $user = User::factory()->create();

        foreach (['deck.pptx', 'old.doc', 'old.xls'] as $name) {
            $file = $this->fileIn($this->root($user), $user, $name, 'application/octet-stream');
            $descriptor = $this->describe($user, $file);

            $this->assertNull($descriptor['client_renderer'], "{$name} has no in-browser renderer.");
            $this->assertStringStartsWith(FilePreview::VIEWER.'?src=', (string) $descriptor['viewer_url'], $name);

            // The wrapped URL is ours, points at this one file, and is signed.
            $wrapped = urldecode(substr((string) $descriptor['viewer_url'], strlen(FilePreview::VIEWER.'?src=')));
            $this->assertStringContainsString("/files/viewer/{$user->public_id}/{$file->public_id}", $wrapped);
            $this->assertStringContainsString('signature=', $wrapped);
            $this->assertStringContainsString('expires=', $wrapped);
        }
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

    /**
     * The inline list is derived from the catalogue, not hand-written.
     *
     * It used to be a literal array naming `audio/wav`, and the type
     * `finfo` actually produces for a WAV is `audio/x-wav` — so every WAV
     * preview fell through to the download branch and the `<audio>` element
     * was handed an attachment, which plays nothing. The same failure the
     * upload policy had when it kept extensions and MIME types in two
     * lists: a format admitted in one vocabulary and refused in another.
     *
     * Asserted as a property of the catalogue rather than as a list, so it
     * cannot rot: every alias of a permitted image, audio or video type is
     * inline, and SVG never is.
     */
    public function test_every_media_alias_in_the_catalogue_is_served_inline(): void
    {
        $inline = FileStorage::inlineTypes();

        foreach (FileTypes::extensions() as $extension) {
            $group = FileTypes::group($extension);

            if (! in_array($group, [FileTypes::GROUP_IMAGES, FileTypes::GROUP_AUDIO, FileTypes::GROUP_VIDEO], true)) {
                continue;
            }

            foreach (FileTypes::mimeTypes($extension) as $type) {
                if ($extension === 'svg') {
                    $this->assertNotContains($type, $inline, 'An SVG is a document that can carry script.');

                    continue;
                }

                $this->assertContains($type, $inline, "A .{$extension} preview needs {$type} served inline.");
            }
        }

        $this->assertContains('application/pdf', $inline);
        $this->assertNotContains('text/html', $inline);
        $this->assertNotContains('application/zip', $inline);
    }

    /**
     * What the viewer actually receives, and why it is not `inline()`.
     *
     * An Office file is deliberately not an inline type — this application must
     * never render one in place — so the viewer route used to fall through to
     * `download()`: a streamed attachment, with the type sniffed at upload and
     * no length. Microsoft fetches this URL from their own servers, and a
     * fetcher with no `Content-Length` cannot start rendering until it has read
     * the whole body, cannot resume, and may not recognise the format at all.
     */
    public function test_the_viewer_response_is_a_ranged_inline_body_of_the_right_type(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn(
            $this->root($user),
            $user,
            'budget.xlsx',
            // Sniffed as its zip container at upload, as a real one often is.
            'application/zip',
        );

        $signed = URL::temporarySignedRoute('files.viewer', now()->addMinutes(5), [
            'owner' => $user->public_id,
            'file' => $file->public_id,
        ]);

        $response = $this->get($signed)->assertOk();

        // The format's canonical type, from the catalogue — not the container
        // the upload happened to sniff as.
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertNotNull($response->headers->get('Content-Length'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    /**
     * A preview is re-requested on every resize, re-mount and reload, so the
     * second look has to be free — but only for the person who already passed
     * the gate. `private` is what keeps a proxy from holding a confidential
     * document and handing it to whoever asks for the URL next.
     */
    public function test_a_preview_response_is_cacheable_privately_and_carries_a_validator(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'report.pdf', 'application/pdf');

        $response = $this->actingAs($user)
            ->get("/files/{$user->public_id}/{$file->public_id}?disposition=inline")
            ->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=600', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);

        // The validator is the content hash, so it changes if and only if the
        // bytes do.
        $this->assertSame('"'.$file->checksum.'"', $response->headers->get('ETag'));
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

        /*
         * And no new one is offered — but the document *is* previewable now,
         * which is the point of rendering it here instead.
         *
         * Before, a confidential Word file had no preview at all: the only
         * renderer was one that needed a URL reaching outside, and that URL
         * had to be refused. A renderer running in the browser of somebody the
         * resolver has already granted `view` to adds no reach, so the preview
         * costs nothing that was being protected.
         */
        $descriptor = $this->describe($user, $file);
        $this->assertNull($descriptor['viewer_url'], 'A confidential item never gets an outside viewer URL.');
        $this->assertSame('docx', $descriptor['client_renderer']);
        $this->assertSame('document', $descriptor['mode']);
    }

    /** What has no in-browser renderer still has no preview when confidential. */
    public function test_a_confidential_deck_still_has_no_preview(): void
    {
        $user = User::factory()->create();
        $file = $this->fileIn($this->root($user), $user, 'deck.pptx', 'application/octet-stream');
        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $descriptor = $this->describe($user, $file);

        $this->assertNull($descriptor['viewer_url']);
        $this->assertNull($descriptor['client_renderer']);
        $this->assertSame('none', $descriptor['mode']);
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
        $deck = $this->fileIn($this->root($user), $user, 'deck.pptx', 'application/octet-stream');
        $word = $this->fileIn($this->root($user), $user, 'minutes.docx', 'application/zip');
        $pdf = $this->fileIn($this->root($user), $user, 'report.pdf', 'application/pdf');

        // A deck has no in-browser renderer, so the network really is why it
        // cannot be shown, and the empty state should say so.
        $deckDescriptor = $this->describe($user, $deck);
        $this->assertNull($deckDescriptor['viewer_url']);
        $this->assertTrue($deckDescriptor['viewer_unreachable']);

        /*
         * Word is unaffected, which is the practical payoff of the change: it
         * renders on a developer machine and on an office LAN, where it
         * previously showed nothing at all.
         */
        $wordDescriptor = $this->describe($user, $word);
        $this->assertSame('docx', $wordDescriptor['client_renderer']);
        $this->assertFalse($wordDescriptor['viewer_unreachable']);

        // A PDF is unaffected too: it never needed the viewer.
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
    /**
     * The preview endpoint answers a signed-in reader and nobody else.
     *
     * It used to be open, matching the tracking page it serves. Both now
     * require an account: a tracking code is printed on the folder and is not
     * a credential, so somebody outside the office is given a share link
     * instead — which carries its own preview, through this same descriptor.
     */
    public function test_a_public_documents_preview_needs_an_account(): void
    {
        $uploader = User::factory()->create();
        $document = $this->document($uploader, 'public');

        $this->getJson("/documents/{$document->id}/preview")->assertUnauthorized();

        $descriptor = $this->actingAs(User::factory()->create())
            ->getJson("/documents/{$document->id}/preview")
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

        $descriptor = $this->actingAs($uploader)
            ->getJson("/documents/{$document->id}/preview")->assertOk()->json();

        $this->assertSame('none', $descriptor['mode']);
        $this->assertFalse($descriptor['can_download']);
    }

    /**
     * A confidential **document** never reaches the viewer either, and that
     * is a different question from a confidential file.
     *
     * The tests above classify the `files` row. A tracked document does not:
     * its row keeps whatever visibility the folder it was uploaded into gave
     * it — `private`, for anything in personal storage — while the
     * classification that governs those bytes is the **document's**. So
     * `mayLeaveForViewer()` asking `effectiveVisibility($file)` read
     * `private` and minted a URL, and a confidential deck's bytes were
     * fetchable, with no session, by Microsoft and by anybody holding the
     * signed URL. It asks `AccessResolver::linkableNow()` now, which is the
     * same rule a share link answers to and the one place the
     * tracked-document clause is stated.
     *
     * Asserted at the route as well as at the descriptor, because the mint
     * and the fetch are two separate decisions and the fetch is the one that
     * serves bytes.
     */
    public function test_a_confidential_documents_deck_never_reaches_the_viewer(): void
    {
        $uploader = User::factory()->create();
        $document = $this->document($uploader, 'confidential');

        // The upload form takes a PDF; the bytes under the document are
        // replaced with a deck, because PowerPoint is the format that has no
        // in-browser renderer and therefore the only one that could leave.
        $file = $document->file;
        $file->update([
            'original_name' => 'deck.pptx',
            'name' => 'deck.pptx',
            'extension' => 'pptx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ]);
        $file->refresh();

        $this->assertSame(
            FileVisibility::Private,
            $file->visibility,
            'The premise: the files row is private, so a check on it alone says "linkable".',
        );
        $this->assertFalse(
            app(FilePreview::class)->mayLeaveForViewer($file),
            "A confidential document's bytes may never be handed to an outside viewer.",
        );

        $descriptor = $this->describe($uploader, $file);
        $this->assertNull($descriptor['viewer_url']);
        $this->assertSame('none', $descriptor['mode']);

        // And the route refuses a signature issued for it, which is what
        // Microsoft would be fetching.
        auth()->logout();
        $this->get(URL::temporarySignedRoute('files.viewer', now()->addMinutes(5), [
            'owner' => $uploader->public_id,
            'file' => $file->public_id,
        ]))->assertNotFound();
    }

    /**
     * A viewer URL is a copy leaving the server, so it needs `download`.
     *
     * The contradiction this closes: the share page told a view-only holder
     * `can_download: false` and "no copy leaves the server", and carried in
     * its own markup a signed URL that returns the whole file to anybody who
     * fetches it, with no session. The gate was `view`, which is the ability
     * that matches *looking at* bytes we serve — not the one that matches
     * handing them out.
     *
     * Both ends asserted, because a narrowing that also breaks the ordinary
     * case is not a fix: a link that does permit a copy keeps its preview.
     */
    public function test_a_view_only_link_gets_no_viewer_url_for_a_deck(): void
    {
        config(['app.url' => 'https://doctrack.example.gov.ph']);

        $uploader = User::factory()->create();
        $document = $this->document($uploader, 'private');
        $file = $document->file;
        $file->update([
            'original_name' => 'deck.pptx',
            'name' => 'deck.pptx',
            'extension' => 'pptx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ]);
        $file->refresh();

        // A private document is minted a restricted link with the upload, so
        // this widens that one rather than adding a second — there is only
        // ever one live link per item.
        $link = app(SharingService::class)->activeLinkFor($document);
        app(SharingService::class)->updateLink($link, ShareAbility::View, $uploader);

        $viewOnly = app(FilePreview::class)->describe($file, null, $link->refresh());
        $this->assertFalse($viewOnly['can_download']);
        $this->assertNull(
            $viewOnly['viewer_url'],
            'A link that permits no copy must not carry a URL that returns one.',
        );
        $this->assertTrue(
            $viewOnly['viewer_needs_download'],
            'And the page has to be able to say which of the three reasons applies.',
        );

        app(SharingService::class)->updateLink($link->refresh(), ShareAbility::Download, $uploader);

        $widened = app(FilePreview::class)->describe($file, null, $link->refresh());
        $this->assertNotNull($widened['viewer_url'], 'A link that permits a copy keeps its preview.');
        $this->assertFalse($widened['viewer_needs_download']);
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
