<?php

namespace Tests\Feature;

use App\Enums\DocumentStamp;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\PowerPointCoverSlide;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * A cover slide at the front of a deck.
 *
 * The hardest of the three Office formats, because a slide is not
 * free-standing: it must name a layout, the layout names a master, and the
 * master carries the theme. Add a slide without a layout and PowerPoint
 * "repairs" the file by discarding it — which is the failure these tests
 * exist to prevent.
 */
class PowerPointCoverSlideTest extends TestCase
{
    use RefreshDatabase;

    /** A genuine minimal `.pptx` with one slide on one layout. */
    private function deck(bool $withSlide = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src').'.pptx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'</Relationships>');

        $list = $withSlide ? '<p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>' : '<p:sldIdLst/>';

        $zip->addFromString('ppt/presentation.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$list.'</p:presentation>');

        $slideRel = $withSlide
            ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>'
            : '';

        $zip->addFromString('ppt/_rels/presentation.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$slideRel.'</Relationships>');

        if ($withSlide) {
            $zip->addFromString('ppt/slides/slide1.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
                .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree/></p:cSld></p:sld>');

            $zip->addFromString('ppt/slides/_rels/slide1.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
                .'</Relationships>');

            $zip->addFromString('ppt/slideLayouts/slideLayout1.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree/></p:cSld></p:sldLayout>');
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function part(string $bytes, string $name): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'read').'.pptx';
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
            'code' => 'PRES-20261124-0001',
            'title' => 'Q4 Briefing & Review <draft>',
            'type' => 'Presentation',
            'visibility' => 'Private',
            'uploader' => 'Carl Llemos',
            'filed_on' => '24 November 2026',
        ];
    }

    private function stamped(): string
    {
        $out = app(PowerPointCoverSlide::class)->add(
            $this->deck(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        );

        $this->assertNotNull($out, 'A deck with a slide should take a cover slide.');

        return $out;
    }

    public function test_every_part_written_is_well_formed(): void
    {
        $stamped = $this->stamped();

        foreach ([
            'ppt/presentation.xml',
            'ppt/_rels/presentation.xml.rels',
            '[Content_Types].xml',
            'ppt/slides/doctrack-cover.xml',
            'ppt/slides/_rels/doctrack-cover.xml.rels',
        ] as $name) {
            $xml = $this->part($stamped, $name);

            $this->assertIsString($xml, "[{$name}] is missing from the rebuilt deck.");
            $this->assertNotFalse(simplexml_load_string($xml), "[{$name}] is not well-formed.");
        }

        $this->assertNotFalse($this->part($stamped, 'ppt/media/doctrack-code.png'));
    }

    /**
     * The cover borrows the deck's own layout.
     *
     * A slide with no layout is discarded by PowerPoint's repair, and a
     * layout invented here would carry no theme — so the cover points at
     * whatever the deck's first slide already uses and inherits its fonts
     * and colours.
     */
    public function test_the_cover_borrows_the_decks_own_layout(): void
    {
        $rels = (string) $this->part($this->stamped(), 'ppt/slides/_rels/doctrack-cover.xml.rels');

        $this->assertStringContainsString('slideLayouts/slideLayout1.xml', $rels);
        $this->assertStringContainsString('relationships/slideLayout', $rels);
    }

    /** It is the first slide, with an id that does not collide. */
    public function test_the_cover_is_the_first_slide(): void
    {
        $presentation = (string) $this->part($this->stamped(), 'ppt/presentation.xml');

        $this->assertLessThan(
            strpos($presentation, 'r:id="rId2"'),
            strpos($presentation, 'rIdDocTrackSlide'),
        );

        preg_match_all('/<p:sldId id="(\d+)"/', $presentation, $found);
        $this->assertSame($found[1], array_unique($found[1]));
        // The spec reserves everything below 256.
        foreach ($found[1] as $id) {
            $this->assertGreaterThanOrEqual(256, (int) $id);
        }
    }

    public function test_the_record_is_written_onto_the_slide(): void
    {
        $slide = (string) $this->part($this->stamped(), 'ppt/slides/doctrack-cover.xml');

        $this->assertStringContainsString('PRES-20261124-0001', $slide);
        $this->assertStringContainsString('Presentation', $slide);
        $this->assertStringContainsString('Q4 Briefing &amp; Review &lt;draft&gt;', $slide);
        $this->assertNotFalse(simplexml_load_string($slide));
    }

    /**
     * A deck with no slides is refused.
     *
     * There is no layout to borrow, and inventing one means inventing a
     * theme — a repaired file is worse than an unstamped one.
     */
    public function test_a_deck_with_no_slides_is_refused(): void
    {
        $this->assertNull(app(PowerPointCoverSlide::class)->add(
            $this->deck(withSlide: false),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        ));
    }

    public function test_a_deck_already_stamped_is_left_alone(): void
    {
        $this->assertNull(app(PowerPointCoverSlide::class)->add(
            $this->stamped(),
            $this->cover(),
            (string) file_get_contents(public_path('icon-192.png')),
        ));
    }

    public function test_an_uploaded_deck_is_stamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->createWithContent('briefing.pptx', $this->deck())],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::query()->sole();

        $this->assertSame(DocumentStamp::Pptx, $document->stamp);

        $stored = (string) Storage::disk($document->file->disk)->get($document->file->storage_path);

        $this->assertStringContainsString(
            $document->code,
            (string) $this->part($stored, 'ppt/slides/doctrack-cover.xml'),
        );
    }
}
