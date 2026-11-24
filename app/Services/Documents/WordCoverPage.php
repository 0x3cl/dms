<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * A cover page at the front of a Word document.
 *
 * **This is the format family it was assumed could not be written into.** A
 * `.docx` is a zip of XML parts and `ext-zip` is present, so the assumption
 * was about a *library* — PhpWord, which this project does not carry — and
 * not about the format. What a cover page needs is four edits to that zip,
 * all of them additive:
 *
 *   1. the symbol into `word/media/`,
 *   2. a relationship pointing at it,
 *   3. a `png` default in `[Content_Types].xml`, where one is missing,
 *   4. a block of paragraphs at the top of `word/document.xml`'s body.
 *
 * Nothing existing is rewritten, which is what makes this safe enough to do
 * at all: the document's own styles, numbering, headers and content are
 * untouched, and the inserted block carries every namespace it uses on its
 * own elements rather than relying on the root declaring them.
 *
 * **It verifies before it returns.** Word refuses a malformed part outright
 * — the file opens as "unreadable content" — so a stamp that corrupted
 * somebody's document would be far worse than no stamp. The rebuilt archive
 * is reopened, every part this touched is re-read, and the XML is parsed; a
 * failure anywhere discards the result and the original bytes are kept.
 */
class WordCoverPage
{
    /** The symbol's side in EMUs — 914,400 to the inch, so this is 1.6in. */
    private const SYMBOL_EMU = 1463040;

    /** Where the symbol is stored inside the archive. */
    private const MEDIA_PATH = 'word/media/doctrack-code.png';

    /** A relationship id unlikely to collide with the document's own. */
    private const REL_ID = 'rIdDocTrackCode';

    /**
     * Return the document with a cover page, or null to keep the original.
     *
     * @param  array<string, string|null>  $cover
     */
    public function add(string $bytes, array $cover, string $symbolPng): ?string
    {
        $source = tempnam(sys_get_temp_dir(), 'docx');
        $working = tempnam(sys_get_temp_dir(), 'docx');

        try {
            file_put_contents($source, $bytes);
            copy($source, $working);

            $zip = new ZipArchive;

            if ($zip->open($working) !== true) {
                return null;
            }

            $document = $zip->getFromName('word/document.xml');
            $rels = $zip->getFromName('word/_rels/document.xml.rels');
            $types = $zip->getFromName('[Content_Types].xml');

            // Not a Word document, or one whose parts are named unusually.
            // Either way this is not something to guess at.
            if ($document === false || $rels === false || $types === false) {
                $zip->close();

                return null;
            }

            // Already stamped — a second cover page is not a correction of
            // the first, and re-running must not stack them.
            if (str_contains($rels, self::REL_ID)) {
                $zip->close();

                return null;
            }

            $zip->addFromString(self::MEDIA_PATH, $symbolPng);
            $zip->addFromString('word/_rels/document.xml.rels', $this->withRelationship($rels));
            $zip->addFromString('[Content_Types].xml', $this->withPngType($types));
            $zip->addFromString('word/document.xml', $this->withCover($document, $cover));

            $zip->close();

            $stamped = (string) file_get_contents($working);

            return $this->verify($stamped) ? $stamped : null;
        } catch (Throwable $e) {
            Log::warning('Adding a cover page to a Word document failed; its bytes are unchanged.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($source);
            @unlink($working);
        }
    }

    /**
     * Point a relationship at the symbol.
     *
     * Appended before the closing tag rather than parsed and re-serialised,
     * because re-serialising rewrites the whole part — attribute order,
     * self-closing forms, the XML declaration — and a diff that large over
     * somebody's document is a diff nobody can review.
     */
    private function withRelationship(string $rels): string
    {
        $relationship = '<Relationship Id="'.self::REL_ID.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
            .' Target="media/doctrack-code.png"/>';

        return str_replace('</Relationships>', $relationship.'</Relationships>', $rels);
    }

    /**
     * Declare PNG, where the document does not already.
     *
     * A document with no images has no `png` default and Word refuses the
     * part that references one. A document that already has images has it
     * already, and declaring it twice is itself malformed.
     */
    private function withPngType(string $types): string
    {
        if (str_contains($types, 'Extension="png"')) {
            return $types;
        }

        return str_replace(
            '</Types>',
            '<Default Extension="png" ContentType="image/png"/></Types>',
            $types,
        );
    }

    /**
     * Insert the cover block at the very top of the body.
     *
     * After `<w:body>` rather than before `</w:body>`, because a body ends
     * with its `<w:sectPr>` — the section's page size, margins and headers —
     * and anything placed after that is outside the section it belongs to.
     *
     * @param  array<string, string|null>  $cover
     */
    private function withCover(string $document, array $cover): string
    {
        $body = strpos($document, '<w:body>');

        if ($body === false) {
            // A body with attributes, or none at all. Not something to guess
            // at: `verify()` would catch a bad insert, but refusing early is
            // a clearer answer than a mangled one.
            return $document;
        }

        $at = $body + strlen('<w:body>');

        return substr($document, 0, $at).$this->coverXml($cover).substr($document, $at);
    }

    /**
     * The cover page itself: the symbol, the code, and what the document is.
     *
     * Every namespace it uses is declared on the element that uses it. A
     * document's root may or may not declare `a` and `pic` — most do not
     * until they hold a picture — and a part referencing an undeclared
     * prefix is malformed, which Word reports as unreadable content rather
     * than as a missing image.
     *
     * @param  array<string, string|null>  $cover
     */
    private function coverXml(array $cover): string
    {
        $lines = array_values(array_filter([
            [$cover['code'] ?? null, 28, true],
            [$cover['title'] ?? null, 32, true],
            [$this->facts($cover), 22, false],
            [$cover['visibility_note'] ?? null, 18, false],
            [$this->provenance($cover), 18, false],
        ], fn (array $line) => ($line[0] ?? '') !== ''));

        $paragraphs = '';

        foreach ($lines as [$text, $halfPoints, $bold]) {
            $paragraphs .= $this->paragraph((string) $text, $halfPoints, $bold);
        }

        // The page break lives in its own paragraph at the end, so the
        // document's own first paragraph starts a fresh page rather than
        // sharing one with the cover.
        return $this->symbolParagraph().$paragraphs.$this->pageBreak();
    }

    private function facts(array $cover): string
    {
        return implode('  ·  ', array_filter([$cover['type'] ?? null, $cover['visibility'] ?? null]));
    }

    private function provenance(array $cover): string
    {
        return implode('  ·  ', array_filter([$cover['uploader'] ?? null, $cover['filed_on'] ?? null]));
    }

    private function symbolParagraph(): string
    {
        $side = self::SYMBOL_EMU;

        // `w:before="0"`, so the symbol sits at the top margin of the page
        // and not wherever the document's default paragraph style happens to
        // start — a template carrying 18pt of space above every paragraph
        // would otherwise push it down the page on some documents and not on
        // others. The PDF cover is placed from the top edge for the same
        // reason; this is the same page from the other door.
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="0" w:after="160"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$side.'" cy="'.$side.'"/>'
            .'<wp:docPr id="4701" name="Tracking code"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:nvPicPr><pic:cNvPr id="0" name="doctrack-code.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill>'
            .'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' r:embed="'.self::REL_ID.'"/>'
            .'<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$side.'" cy="'.$side.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** One centred line. `w:sz` is in half-points, so 28 is 14pt. */
    private function paragraph(string $text, int $halfPoints, bool $bold): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:rPr>'
            .($bold ? '<w:b/>' : '')
            .'<w:sz w:val="'.$halfPoints.'"/></w:rPr></w:pPr>'
            .'<w:r><w:rPr>'.($bold ? '<w:b/>' : '').'<w:sz w:val="'.$halfPoints.'"/></w:rPr>'
            // `xml:space` because a centred line of prose may end in a space
            // and Word collapses it otherwise.
            .'<w:t xml:space="preserve">'.$this->escape($text).'</w:t></w:r></w:p>';
    }

    private function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Is the result still a Word document?
     *
     * Word reports a malformed part as unreadable content and refuses to
     * open the file at all, so this is the difference between a stamp and a
     * destroyed document. Every part written is read back and parsed, and
     * the symbol is confirmed present — a relationship pointing at a missing
     * target is exactly the kind of damage that survives a naive check.
     */
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
                'word/document.xml',
                'word/_rels/document.xml.rels',
                '[Content_Types].xml',
            ];

            foreach ($parts as $part) {
                $xml = $zip->getFromName($part);

                if ($xml === false || simplexml_load_string($xml) === false) {
                    $zip->close();

                    return false;
                }
            }

            $media = $zip->getFromName(self::MEDIA_PATH);
            $zip->close();

            return is_string($media) && $media !== '';
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($path);
        }
    }
}
