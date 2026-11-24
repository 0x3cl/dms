<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * A cover slide at the front of a deck.
 *
 * The third of the Office family, and the one with the most to go wrong: a
 * slide is not free-standing. It must name a **layout**, the layout must
 * name a master, and `presentation.xml` orders slides through a list of ids
 * that are themselves relationships. Add a slide without a layout and
 * PowerPoint repairs the file by discarding it.
 *
 * So the layout is borrowed rather than created: whatever layout the deck's
 * first slide already uses is the one this slide points at. A deck's own
 * layouts carry its theme, so the cover looks like the deck rather than like
 * a foreign page — and nothing new is introduced that a master has to know
 * about. A deck with no slides at all is refused: there is no layout to
 * borrow and inventing one means inventing a theme.
 *
 * Everything else is additive, and the result is verified before it is
 * returned.
 */
class PowerPointCoverSlide
{
    private const SLIDE_PART = 'ppt/slides/doctrack-cover.xml';

    private const MEDIA_PART = 'ppt/media/doctrack-code.png';

    private const SLIDE_REL = 'rIdDocTrackSlide';

    private const IMAGE_REL = 'rIdDocTrackImage';

    private const LAYOUT_REL = 'rIdDocTrackLayout';

    /** 1.6in square, in EMUs, and the slide's own middle to place it in. */
    private const SYMBOL_EMU = 1463040;

    /**
     * Return the deck with a cover slide, or null to keep the original.
     *
     * @param  array<string, string|null>  $cover
     */
    public function add(string $bytes, array $cover, string $symbolPng): ?string
    {
        $working = tempnam(sys_get_temp_dir(), 'pptx');

        try {
            file_put_contents($working, $bytes);

            $zip = new ZipArchive;

            if ($zip->open($working) !== true) {
                return null;
            }

            $presentation = $zip->getFromName('ppt/presentation.xml');
            $rels = $zip->getFromName('ppt/_rels/presentation.xml.rels');
            $types = $zip->getFromName('[Content_Types].xml');

            if ($presentation === false || $rels === false || $types === false) {
                $zip->close();

                return null;
            }

            if (str_contains($rels, self::SLIDE_REL)) {
                $zip->close();

                return null;
            }

            $layout = $this->layoutOfFirstSlide($zip, $rels);

            // No slide to borrow a layout from: inventing one means
            // inventing a theme, and a repaired file is worse than an
            // unstamped one.
            if ($layout === null) {
                $zip->close();

                return null;
            }

            $zip->addFromString(self::MEDIA_PART, $symbolPng);
            $zip->addFromString(self::SLIDE_PART, $this->slideXml($cover));
            $zip->addFromString('ppt/slides/_rels/doctrack-cover.xml.rels', $this->slideRels($layout));
            $zip->addFromString('ppt/presentation.xml', $this->withSlideFirst($presentation));
            $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->withRelationship($rels));
            $zip->addFromString('[Content_Types].xml', $this->withTypes($types));

            $zip->close();

            $stamped = (string) file_get_contents($working);

            return $this->verify($stamped) ? $stamped : null;
        } catch (Throwable $e) {
            Log::warning('Adding a cover slide to a deck failed; its bytes are unchanged.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($working);
        }
    }

    /**
     * The layout the deck's first slide uses, as a part path.
     *
     * Borrowed rather than chosen, because a layout belongs to a master and
     * a master carries the theme: pointing at the deck's own means the cover
     * inherits its fonts and colours instead of arriving as a foreign page.
     */
    private function layoutOfFirstSlide(ZipArchive $zip, string $rels): ?string
    {
        if (! preg_match('/Target="(slides\/[^"]+)"/', $rels, $slide)) {
            return null;
        }

        $name = 'ppt/'.$slide[1];
        $slideRels = $zip->getFromName(
            'ppt/slides/_rels/'.basename($name).'.rels'
        );

        if ($slideRels === false || ! preg_match('/Target="([^"]*slideLayouts\/[^"]+)"/', $slideRels, $layout)) {
            return null;
        }

        return $layout[1];
    }

    /**
     * The slide: the symbol, then the record under it.
     *
     * Absolute positions in EMUs rather than placeholders, because a
     * placeholder takes its geometry from the layout and the layout is the
     * deck's own — a title placeholder on a borrowed layout could be
     * anywhere, including off the slide.
     *
     * @param  array<string, string|null>  $cover
     */
    private function slideXml(array $cover): string
    {
        $side = self::SYMBOL_EMU;
        // Centred on a 10x7.5in slide, which is the 4:3 default; a 16:9 deck
        // is wider, so this sits left of centre rather than off the edge.
        $x = 3657600;
        $y = 914400;

        $lines = array_values(array_filter([
            $cover['code'] ?? null,
            $cover['title'] ?? null,
            implode('  ·  ', array_filter([$cover['type'] ?? null, $cover['visibility'] ?? null])),
            implode('  ·  ', array_filter([$cover['uploader'] ?? null, $cover['filed_on'] ?? null])),
        ], fn (?string $line) => ($line ?? '') !== ''));

        $text = '';
        $top = $y + $side + 228600;

        foreach ($lines as $index => $line) {
            $text .= $this->textBox($line, $top + ($index * 365760), $index === 0);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<p:cSld><p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .'<p:pic>'
            .'<p:nvPicPr><p:cNvPr id="2" name="Tracking code"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
            .'<p:blipFill><a:blip r:embed="'.self::IMAGE_REL.'"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$side.'" cy="'.$side.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
            .'</p:pic>'
            .$text
            .'</p:spTree></p:cSld><p:clrMapOvr><a:overrideClrMapping bg1="lt1" tx1="dk1" bg2="lt2"'
            .' tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4"'
            .' accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/></p:clrMapOvr></p:sld>';
    }

    private function textBox(string $text, int $y, bool $bold): string
    {
        static $id = 10;
        $id++;

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Line '.$id.'"/>'
            .'<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="914400" y="'.$y.'"/><a:ext cx="7315200" cy="365760"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
            .'<p:txBody><a:bodyPr wrap="square"/><a:lstStyle/>'
            .'<a:p><a:pPr algn="ctr"/><a:r><a:rPr lang="en-US" sz="'.($bold ? 2000 : 1400).'"'
            .' b="'.($bold ? 1 : 0).'"/><a:t>'.$this->escape($text).'</a:t></a:r></a:p>'
            .'</p:txBody></p:sp>';
    }

    private function slideRels(string $layout): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="'.self::LAYOUT_REL.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout"'
            .' Target="'.$this->escape($layout).'"/>'
            .'<Relationship Id="'.self::IMAGE_REL.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
            .' Target="../media/doctrack-code.png"/>'
            .'</Relationships>';
    }

    /**
     * Put the cover first in the slide order.
     *
     * `<p:sldIdLst>` is the order PowerPoint shows, and an `id` there must be
     * unique and at least 256 — the spec reserves everything below.
     */
    private function withSlideFirst(string $presentation): string
    {
        preg_match_all('/<p:sldId id="(\d+)"/', $presentation, $found);

        $id = $found[1] === [] ? 256 : max(array_map('intval', $found[1])) + 1;
        $entry = '<p:sldId id="'.$id.'" r:id="'.self::SLIDE_REL.'"/>';

        if (str_contains($presentation, '<p:sldIdLst>')) {
            return str_replace('<p:sldIdLst>', '<p:sldIdLst>'.$entry, $presentation);
        }

        // A deck whose list is self-closing, which is a deck with no slides
        // — refused earlier, so this is belt and braces.
        return (string) preg_replace('/<p:sldIdLst\s*\/>/', '<p:sldIdLst>'.$entry.'</p:sldIdLst>', $presentation, 1);
    }

    private function withRelationship(string $rels): string
    {
        $relationship = '<Relationship Id="'.self::SLIDE_REL.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide"'
            .' Target="slides/doctrack-cover.xml"/>';

        return str_replace('</Relationships>', $relationship.'</Relationships>', $rels);
    }

    private function withTypes(string $types): string
    {
        $added = '';

        if (! str_contains($types, 'Extension="png"')) {
            $added .= '<Default Extension="png" ContentType="image/png"/>';
        }

        $added .= '<Override PartName="/'.self::SLIDE_PART.'"'
            .' ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';

        return str_replace('</Types>', $added.'</Types>', $types);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function verify(string $bytes): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'check');

        try {
            file_put_contents($path, $bytes);

            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                return false;
            }

            $parts = [
                'ppt/presentation.xml',
                'ppt/_rels/presentation.xml.rels',
                '[Content_Types].xml',
                self::SLIDE_PART,
                'ppt/slides/_rels/doctrack-cover.xml.rels',
            ];

            foreach ($parts as $part) {
                $xml = $zip->getFromName($part);

                if ($xml === false || simplexml_load_string($xml) === false) {
                    $zip->close();

                    return false;
                }
            }

            $media = $zip->getFromName(self::MEDIA_PART);
            $zip->close();

            return is_string($media) && $media !== '';
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($path);
        }
    }
}
