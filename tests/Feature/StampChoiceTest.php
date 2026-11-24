<?php

namespace Tests\Feature;

use App\Enums\DocumentStamp;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Declining the mark.
 *
 * Stamping is on by default — the office asked for every file to carry its
 * code, and a default of off would make that a thing somebody has to
 * remember on every upload. It is a *choice* rather than a rule because the
 * act is irreversible: an image is re-encoded and a PDF gains a page, and
 * there are documents nobody wants altered.
 *
 * **The server honours it, not just the form.** A tickbox a page merely
 * hides is a rule only the page keeps, and this one decides whether somebody
 * else's bytes are rewritten.
 */
class StampChoiceTest extends TestCase
{
    use RefreshDatabase;

    private function photograph(int $side = 1600): UploadedFile
    {
        $image = imagecreatetruecolor($side, $side);
        imagefilledrectangle($image, 0, 0, $side, $side, imagecolorallocate($image, 150, 152, 156));
        ob_start();
        imagejpeg($image, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'ph').'.jpg';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'scan.jpg', 'image/jpeg', null, true);
    }

    public function test_my_files_leaves_the_bytes_alone_when_the_mark_is_declined(): void
    {
        $user = User::factory()->create();
        $upload = $this->photograph();
        $original = file_get_contents($upload->getRealPath());

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$upload],
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        // Still filed, still coded — only the bytes are untouched.
        $this->assertNotNull($document->code);
        $this->assertSame(DocumentStamp::None, $document->stamp);
        $this->assertSame(
            $original,
            Storage::disk($document->file->disk)->get($document->file->storage_path),
        );
    }

    public function test_the_register_leaves_the_bytes_alone_when_the_mark_is_declined(): void
    {
        $user = User::factory()->create();
        $upload = $this->photograph();
        $original = file_get_contents($upload->getRealPath());

        $this->actingAs($user)->post('/documents', [
            'title' => 'Scanned memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'private',
            'file' => $upload,
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->assertSame(DocumentStamp::None, $document->stamp);
        $this->assertSame(
            $original,
            Storage::disk($document->file->disk)->get($document->file->storage_path),
        );
    }

    /** Absent means yes, so every existing caller keeps stamping. */
    public function test_the_default_is_to_stamp(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
        ])->assertRedirect();

        $this->assertSame(DocumentStamp::Image, Document::query()->sole()->stamp);
    }

    /** And a replacement can decline it too, leaving the new version plain. */
    public function test_a_replacement_can_decline_the_mark(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
        ])->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Image, $document->stamp);

        $replacement = $this->photograph();
        $original = file_get_contents($replacement->getRealPath());

        $this->actingAs($user)->post('/files/replace', [
            'public_id' => $document->file->public_id,
            'file' => $replacement,
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();

        $this->assertSame(DocumentStamp::None, $document->stamp);
        $this->assertSame(
            $original,
            Storage::disk($document->file->disk)->get($document->file->refresh()->storage_path),
        );
    }

    /**
     * A file already in storage can be stamped from the edit form.
     *
     * This is the answer to "do I have to re-upload?". A document filed
     * before stamping existed, or one whose uploader declined it at the
     * time, is marked by opening Edit and saving — no new bytes, no new
     * version, and the same printed code.
     */
    public function test_an_existing_file_can_be_stamped_from_the_edit_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
            'stamp' => false,
        ])->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::None, $document->stamp);

        $before = Storage::disk($document->file->disk)->get($document->file->storage_path);
        $version = $document->file->versionNumber();

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'stamp' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();

        $this->assertSame(DocumentStamp::Image, $document->stamp);
        $this->assertNotSame(
            $before,
            Storage::disk($document->file->disk)->get($document->file->refresh()->storage_path),
        );
        // No new version: this is a mark on the bytes, not a replacement.
        $this->assertSame($version, $document->file->refresh()->versionNumber());
    }

    /**
     * And saving an edit does not silently re-stamp a file that already
     * carries its code.
     *
     * Re-encoding an image for nothing loses a little quality every time,
     * and a second cover page is not a correction of the first.
     */
    public function test_an_edit_does_not_redraw_a_mark_that_is_already_there(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
        ])->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Image, $document->stamp);

        $before = Storage::disk($document->file->disk)->get($document->file->storage_path);

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => 'A corrected title',
            'document_type_id' => $document->document_type_id,
            'stamp' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            $before,
            Storage::disk($document->file->disk)->get($document->file->refresh()->storage_path),
            'A file that already carries its code must not be redrawn on every edit.',
        );
    }

    /**
     * A replacement uploaded through the **edit form** is stamped.
     *
     * The other replacement path, `/files/replace`, was already covered; this
     * is the one the office actually used, and it is a different controller
     * with its own wiring. Worth its own test for exactly that reason: the
     * two look alike and nothing makes them agree.
     */
    public function test_a_replacement_through_the_edit_form_is_stamped(): void
    {
        $user = User::factory()->create();

        // Filed as something that cannot carry a mark, so the starting state
        // is unambiguous.
        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        ])->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Unsupported, $document->stamp);

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'file' => $this->photograph(),
            'stamp' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();
        $file = $document->file->refresh();

        $this->assertSame(2, $file->versionNumber());
        $this->assertSame(DocumentStamp::Image, $document->stamp);
    }

    /**
     * And a PDF replaced through the edit form is left for the browser with
     * its window open.
     *
     * The server cannot draw into a PDF, so its part is to reset the record
     * and touch the file — which is what `files.stamp` checks before it
     * accepts bytes. Without the touch, a document filed long ago would have
     * its replacement refused by the very window that makes that endpoint
     * safe.
     */
    public function test_a_pdf_replaced_through_the_edit_form_is_ready_for_the_browser(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        ])->assertRedirect();

        $document = Document::query()->sole();

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
            'stamp' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();

        // Not yet stamped, and honestly recorded as such.
        $this->assertSame(DocumentStamp::None, $document->stamp);

        // The browser is given what it needs, and the endpoint accepts it.
        $this->assertNotNull(
            $this->actingAs($user)->getJson("/documents/{$document->id}/qr-label")->json('stamp_png'),
        );

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => UploadedFile::fake()->createWithContent(
                'replacement.pdf',
                "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
            ),
        ])->assertRedirect();

        $this->assertSame(DocumentStamp::Pdf, $document->fresh()->stamp);
    }

    /**
     * The edit form is told what the file carries, so its switch can show it.
     *
     * The switch is a **state**, not an action: it opened off on a file that
     * was already stamped, because it was read as "draw it now" and there
     * was nothing to draw — so somebody looking at a stamped document saw a
     * switch saying the file was unmarked, directly above a note saying it
     * was marked. It reads `document.stamp` now, which is why that value has
     * to be in the payload and has to be the file's own.
     */
    public function test_the_edit_form_is_told_whether_the_file_carries_its_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
        ])->assertRedirect();

        $document = Document::query()->sole();

        $this->actingAs($user)->get("/documents/{$document->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/form', false)
                ->where('document.stamp', 'image')
                ->where('document.carries_the_code', true)
                ->where('document.can_stamp', true)
                ->where('document.needs_browser_stamp', false));
    }

    /**
     * And an unstamped file says so, so the switch opens off rather than
     * silently rewriting bytes on the next title correction.
     */
    public function test_the_edit_form_reports_an_unstamped_file_as_unstamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
            'stamp' => false,
        ])->assertRedirect();

        $document = Document::query()->sole();

        $this->actingAs($user)->get("/documents/{$document->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/form', false)
                ->where('document.stamp', 'none')
                ->where('document.carries_the_code', false)
                ->where('document.can_stamp', true));
    }

    /**
     * A cover page counts as carrying the code, and the form is told so.
     *
     * The page worked this out from the value — `pdf` or `image` — which
     * was every format stamping had when it was written. A Word, Excel or
     * PowerPoint document therefore read as unstamped: the switch opened
     * off above a file that already carries a cover, and turning it on
     * saved nothing, because the server will not draw a second one. The
     * answer comes from `DocumentStamp::carriesTheCode()` now, so a format
     * added next is handled without this page being told about it.
     */
    public function test_a_word_document_with_a_cover_page_is_reported_as_carrying_its_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->createWithContent('memo.docx', $this->word())],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Docx, $document->stamp);

        $this->actingAs($user)->get("/documents/{$document->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/form', false)
                ->where('document.stamp', 'docx')
                ->where('document.carries_the_code', true)
                ->where('document.needs_browser_stamp', false));
    }

    /**
     * And the other half of the same complaint: a Word document filed
     * without a cover gets one from the edit form, with no re-upload.
     *
     * The image path was covered; this one was not, and it is the one the
     * office hit — the formats added after stamping shipped.
     */
    public function test_an_existing_word_document_can_be_stamped_from_the_edit_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->createWithContent('memo.docx', $this->word())],
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::None, $document->stamp);

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'stamp' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();

        $this->assertSame(DocumentStamp::Docx, $document->stamp);
        $this->assertStringContainsString(
            $document->code,
            (string) $this->part(
                (string) Storage::disk($document->file->disk)->get($document->file->refresh()->storage_path),
                'word/document.xml',
            ),
        );
    }

    /**
     * A file that cannot be drawn into says so, instead of reporting success.
     *
     * `stampFile()` refuses a picture with no room for a readable mark, and
     * the refusal was silent under a flash that said "Document updated." —
     * so the switch went on, the save was reported as working, and the file
     * carried nothing. Whether bytes carry their code is the one thing
     * nobody can check by looking at them.
     */
    public function test_an_edit_says_so_when_the_code_could_not_be_drawn(): void
    {
        $user = User::factory()->create();

        // Under the 500 px short edge, which is the floor a readable mark
        // needs — the upload is fine, the stamp is not.
        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph(320)],
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::None, $document->stamp);

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'stamp' => true,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(DocumentStamp::None, $document->fresh()->stamp);
    }

    /**
     * A PDF is not a refusal: the server opens the window and the browser
     * draws it, so the edit reports the ordinary success.
     */
    public function test_a_pdf_edit_is_not_reported_as_a_failed_stamp(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('gatepass.pdf', 16, 'application/pdf')],
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $touched = $document->file->updated_at;

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'stamp' => true,
        ])->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');

        // The window the browser's half needs was opened.
        $this->assertTrue($document->file->fresh()->updated_at->gte($touched));
    }

    /**
     * Stamping redraws the picture on the card.
     *
     * A stamp rewrites the file in place and keeps its version — it is a
     * mark on the bytes, not a new version of them — and the thumbnail is
     * drawn per version, so nothing regenerated it. A stamped photograph
     * therefore showed its unstamped self in every card, row and preview
     * pane, and the only way to see the mark was to download the file.
     * From the person's side that is a stamp that did not happen.
     */
    public function test_stamping_an_image_redraws_its_thumbnail(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$this->photograph()],
            'stamp' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $file = $document->file;

        $this->assertTrue($file->has_thumbnail, 'The upload should have a thumbnail to begin with.');

        $disk = Storage::disk($file->disk);
        $before = (string) $disk->get($file->thumbnailPath());

        $this->actingAs($user)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'stamp' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStamp::Image, $document->fresh()->stamp);
        $this->assertNotSame(
            $before,
            (string) $disk->get($file->refresh()->thumbnailPath()),
            'The thumbnail must show the bytes as they are now, mark and all.',
        );
    }

    /** The smallest thing Word will open, built in the test rather than fixtured. */
    private function word(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>Original content</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** One part out of a stored OOXML file. */
    private function part(string $bytes, string $name): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'read').'.docx';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $zip->open($path);
        $read = $zip->getFromName($name);
        $zip->close();
        unlink($path);

        return $read;
    }
}
