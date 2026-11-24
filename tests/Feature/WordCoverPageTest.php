<?php

namespace Tests\Feature;

use App\Enums\DocumentStamp;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\WordCoverPage;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * A cover page at the front of a Word document.
 *
 * This is the format family it was assumed could not be written into. A
 * `.docx` is a zip of XML parts and `ext-zip` is present, so the assumption
 * was about a *library* — PhpWord, which this project does not carry — and
 * not about the format.
 *
 * **What these tests are really about is not corrupting somebody's
 * document.** Word reports a malformed part as unreadable content and
 * refuses to open the file at all, so a bad stamp is far worse than none.
 * Every one of them reads the rebuilt archive back and parses what was
 * written.
 */
class WordCoverPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A genuine minimal `.docx` — the parts Word actually requires, zipped.
     *
     * Built rather than fixtured so the test states what it is working
     * against: a document with no images, which is the case that needs the
     * `png` content type added.
     */
    private function document(string $body = '<w:p><w:r><w:t>Original content</w:t></w:r></w:p>'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');

        $zip->addFromString('word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');

        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body>'
            .'</w:document>');

        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** Read one part back out of a built archive. */
    private function part(string $bytes, string $name): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'read').'.docx';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $zip->open($path);
        $content = $zip->getFromName($name);
        $zip->close();
        unlink($path);

        return $content;
    }

    private function cover(): array
    {
        return [
            'code' => 'MEMO-20261124-0001',
            'title' => 'Office Hours & Attendance <2026>',
            'type' => 'Memorandum',
            'visibility' => 'Private',
            'visibility_note' => 'Only you and the people you tag.',
            'uploader' => 'Carl Llemos',
            'filed_on' => '24 November 2026',
        ];
    }

    public function test_a_cover_page_is_added_and_every_part_still_parses(): void
    {
        $stamped = app(WordCoverPage::class)->add(
            $this->document(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $this->assertNotNull($stamped, 'A plain Word document should take a cover page.');

        foreach (['word/document.xml', 'word/_rels/document.xml.rels', '[Content_Types].xml'] as $name) {
            $xml = $this->part($stamped, $name);

            $this->assertIsString($xml, "[{$name}] is missing from the rebuilt archive.");
            $this->assertNotFalse(
                simplexml_load_string($xml),
                "[{$name}] is not well-formed, which Word reports as unreadable content.",
            );
        }

        // The symbol is really there: a relationship pointing at a missing
        // target is exactly the damage a naive check would miss.
        $this->assertNotFalse($this->part($stamped, 'word/media/doctrack-code.png'));
    }

    public function test_the_original_content_survives_and_comes_after_the_cover(): void
    {
        $stamped = app(WordCoverPage::class)->add(
            $this->document(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $document = (string) $this->part($stamped, 'word/document.xml');

        $this->assertStringContainsString('Original content', $document);
        $this->assertStringContainsString('MEMO-20261124-0001', $document);
        $this->assertStringContainsString('Memorandum', $document);
        $this->assertStringContainsString('Private', $document);

        // The cover comes first, and a page break separates it from the
        // document's own first paragraph.
        $this->assertLessThan(
            strpos($document, 'Original content'),
            strpos($document, 'MEMO-20261124-0001'),
        );
        $this->assertLessThan(
            strpos($document, 'Original content'),
            strpos($document, 'w:type="page"'),
        );

        // And it is inside the section, not after `sectPr`, or the page
        // would not take the document's own size and margins.
        $this->assertLessThan(strpos($document, '<w:sectPr>'), strpos($document, 'MEMO-20261124-0001'));
    }

    /**
     * A title with XML in it must not break the part.
     *
     * A document title is arbitrary user input and lands in a `w:t`
     * element, so an ampersand or an angle bracket typed into the register
     * would be a malformed document rather than a stamped one.
     */
    public function test_a_title_containing_markup_is_escaped(): void
    {
        $stamped = app(WordCoverPage::class)->add(
            $this->document(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $document = (string) $this->part($stamped, 'word/document.xml');

        $this->assertNotFalse(simplexml_load_string($document));
        $this->assertStringContainsString('Office Hours &amp; Attendance &lt;2026&gt;', $document);
    }

    /** `png` is declared where the document had no images of its own. */
    public function test_the_png_content_type_is_declared_once(): void
    {
        $stamped = app(WordCoverPage::class)->add(
            $this->document(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $types = (string) $this->part($stamped, '[Content_Types].xml');

        $this->assertSame(1, substr_count($types, 'Extension="png"'));
    }

    /** Running twice must not stack two cover pages. */
    public function test_a_document_already_stamped_is_left_alone(): void
    {
        $service = app(WordCoverPage::class);
        $png = (string) file_get_contents(public_path('icon-192.png'));

        $once = $service->add($this->document(), $this->cover(), $png);

        $this->assertNotNull($once);
        $this->assertNull(
            $service->add($once, $this->cover(), $png),
            'A second cover page is not a correction of the first.',
        );
    }

    /** Anything that is not a Word document is refused rather than mangled. */
    public function test_bytes_that_are_not_a_word_document_are_refused(): void
    {
        $service = app(WordCoverPage::class);
        $png = (string) file_get_contents(public_path('icon-192.png'));

        $this->assertNull($service->add('not a zip at all', $this->cover(), $png));

        // A zip, but not a Word document — the parts it needs are absent.
        $path = tempnam(sys_get_temp_dir(), 'plain').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'hello');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        $this->assertNull($service->add($bytes, $this->cover(), $png));
    }

    /** And end to end: an uploaded .docx comes back with its cover. */
    public function test_an_uploaded_word_document_is_stamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->createWithContent('memo.docx', $this->document())],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->assertSame(DocumentStamp::Docx, $document->stamp);

        $stored = (string) Storage::disk($document->file->disk)->get($document->file->storage_path);

        $this->assertStringContainsString($document->code, (string) $this->part($stored, 'word/document.xml'));
        $this->assertNotFalse($this->part($stored, 'word/media/doctrack-code.png'));
    }
}
