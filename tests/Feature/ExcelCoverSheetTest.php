<?php

namespace Tests\Feature;

use App\Enums\DocumentStamp;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\ExcelCoverSheet;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * A cover sheet at the front of a workbook.
 *
 * The same idea as the Word cover page and a bigger job: a picture in a
 * worksheet is not something the worksheet holds, it is something a
 * *drawing* holds, and a drawing is a part of its own with its own
 * relationships. Five new parts and three edited, where Word needed one and
 * three.
 *
 * **What these tests are about is not destroying somebody's spreadsheet.**
 * Excel refuses a malformed workbook outright, and a workbook holds data
 * rather than prose — so every part written is read back and parsed here,
 * and the existing sheets are checked to be exactly as they were.
 */
class ExcelCoverSheetTest extends TestCase
{
    use RefreshDatabase;

    /** A genuine minimal `.xlsx` with one sheet of data. */
    private function workbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Budget" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Existing data</t></is></c></row></sheetData>'
            .'</worksheet>');

        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function part(string $bytes, string $name): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'read').'.xlsx';
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
            'code' => 'DATA-20261124-0001',
            'title' => 'Budget & Forecast <Q4>',
            'type' => 'Dataset or Spreadsheet',
            'visibility' => 'Private',
            'visibility_note' => 'Only you and the people you tag.',
            'uploader' => 'Carl Llemos',
            'filed_on' => '24 November 2026',
        ];
    }

    private function stamped(): string
    {
        $out = app(ExcelCoverSheet::class)->add(
            $this->workbook(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $this->assertNotNull($out, 'A plain workbook should take a cover sheet.');

        return $out;
    }

    public function test_every_part_written_is_well_formed(): void
    {
        $stamped = $this->stamped();

        $parts = [
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            '[Content_Types].xml',
            'xl/worksheets/doctrack-cover.xml',
            'xl/worksheets/_rels/doctrack-cover.xml.rels',
            'xl/drawings/doctrack-cover.xml',
            'xl/drawings/_rels/doctrack-cover.xml.rels',
        ];

        foreach ($parts as $name) {
            $xml = $this->part($stamped, $name);

            $this->assertIsString($xml, "[{$name}] is missing from the rebuilt workbook.");
            $this->assertNotFalse(
                simplexml_load_string($xml),
                "[{$name}] is not well-formed, which Excel refuses outright.",
            );
        }

        $this->assertNotFalse($this->part($stamped, 'xl/media/doctrack-code.png'));
    }

    /**
     * The existing sheet is untouched, byte for byte.
     *
     * A workbook holds data rather than prose: a stamp that reformatted a
     * number or dropped a formula would be a far worse outcome than no
     * stamp, so the only acceptable edit to an existing sheet is none.
     */
    public function test_the_existing_sheet_is_untouched(): void
    {
        $original = $this->workbook();

        $stamped = app(ExcelCoverSheet::class)->add(
            $original,
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $this->assertSame(
            $this->part($original, 'xl/worksheets/sheet1.xml'),
            $this->part($stamped, 'xl/worksheets/sheet1.xml'),
        );
    }

    /** The cover is the first tab, and the original keeps its place after it. */
    public function test_the_cover_is_the_first_tab(): void
    {
        $workbook = (string) $this->part($this->stamped(), 'xl/workbook.xml');

        $this->assertLessThan(
            strpos($workbook, 'name="Budget"'),
            strpos($workbook, 'name="Tracking"'),
        );

        // A unique sheetId, or Excel refuses the workbook.
        preg_match_all('/sheetId="(\d+)"/', $workbook, $found);
        $this->assertSame($found[1], array_unique($found[1]));
    }

    public function test_the_record_is_written_into_the_cover_sheet(): void
    {
        $sheet = (string) $this->part($this->stamped(), 'xl/worksheets/doctrack-cover.xml');

        $this->assertStringContainsString('DATA-20261124-0001', $sheet);
        $this->assertStringContainsString('Dataset or Spreadsheet', $sheet);
        // Arbitrary user input lands in a `t` element and must be escaped.
        $this->assertStringContainsString('Budget &amp; Forecast &lt;Q4&gt;', $sheet);
        $this->assertNotFalse(simplexml_load_string($sheet));
    }

    public function test_a_workbook_already_stamped_is_left_alone(): void
    {
        $service = app(ExcelCoverSheet::class);
        $png = (string) file_get_contents(public_path('icon-192.png'));

        $this->assertNull($service->add($this->stamped(), $this->cover(), $png));
    }

    public function test_bytes_that_are_not_a_workbook_are_refused(): void
    {
        $this->assertNull(app(ExcelCoverSheet::class)->add(
            'not a zip at all',
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        ));
    }

    /** And end to end: an uploaded workbook comes back with its cover. */
    public function test_an_uploaded_workbook_is_stamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->createWithContent('budget.xlsx', $this->workbook())],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->assertSame(DocumentStamp::Xlsx, $document->stamp);

        $stored = (string) Storage::disk($document->file->disk)->get($document->file->storage_path);

        $this->assertStringContainsString(
            $document->code,
            (string) $this->part($stored, 'xl/worksheets/doctrack-cover.xml'),
        );
    }
}
