<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * A cover sheet at the front of a workbook.
 *
 * The same idea as `WordCoverPage` and a materially bigger job, because a
 * picture in a worksheet is not a thing a worksheet holds — it is a thing a
 * *drawing* holds, and a drawing is a part of its own with its own
 * relationships. Word needed one new part and three edits; this needs five
 * new parts and three edits:
 *
 *   1. `xl/worksheets/doctrack-cover.xml` — the sheet, with its text,
 *   2. its rels, pointing at the drawing,
 *   3. `xl/drawings/doctrack-cover.xml` — where the symbol sits,
 *   4. its rels, pointing at the image,
 *   5. `xl/media/doctrack-code.png`,
 *
 * then `xl/workbook.xml` gains a `<sheet>` at the front, its rels gain a
 * relationship, and `[Content_Types].xml` gains overrides for the two new
 * XML parts and a default for `png`.
 *
 * Everything is additive — no existing sheet, formula, name or style is
 * touched — and the result is verified before it is returned, because Excel
 * refuses a malformed workbook outright and a stamp that destroyed
 * somebody's spreadsheet would be far worse than no stamp.
 *
 * Text is written as **inline strings** rather than through
 * `xl/sharedStrings.xml`, which would mean rewriting a part every other
 * sheet indexes into — the one edit here that could corrupt data rather than
 * structure.
 */
class ExcelCoverSheet
{
    private const SHEET_PART = 'xl/worksheets/doctrack-cover.xml';

    private const DRAWING_PART = 'xl/drawings/doctrack-cover.xml';

    private const MEDIA_PART = 'xl/media/doctrack-code.png';

    private const SHEET_REL = 'rIdDocTrackSheet';

    private const DRAWING_REL = 'rIdDocTrackDrawing';

    private const IMAGE_REL = 'rIdDocTrackImage';

    /** 1.6in square, in EMUs. */
    private const SYMBOL_EMU = 1463040;

    /**
     * Return the workbook with a cover sheet, or null to keep the original.
     *
     * @param  array<string, string|null>  $cover
     */
    public function add(string $bytes, array $cover, string $symbolPng): ?string
    {
        $working = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            file_put_contents($working, $bytes);

            $zip = new ZipArchive;

            if ($zip->open($working) !== true) {
                return null;
            }

            $workbook = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            $types = $zip->getFromName('[Content_Types].xml');

            if ($workbook === false || $rels === false || $types === false) {
                $zip->close();

                return null;
            }

            // Already stamped: a second cover sheet is not a correction of
            // the first, and re-running must not stack them.
            if (str_contains($rels, self::SHEET_REL)) {
                $zip->close();

                return null;
            }

            $zip->addFromString(self::MEDIA_PART, $symbolPng);
            $zip->addFromString(self::SHEET_PART, $this->sheetXml($cover));
            $zip->addFromString('xl/worksheets/_rels/doctrack-cover.xml.rels', $this->sheetRels());
            $zip->addFromString(self::DRAWING_PART, $this->drawingXml());
            $zip->addFromString('xl/drawings/_rels/doctrack-cover.xml.rels', $this->drawingRels());
            $zip->addFromString('xl/workbook.xml', $this->withSheet($workbook));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->withRelationship($rels));
            $zip->addFromString('[Content_Types].xml', $this->withTypes($types));

            $zip->close();

            $stamped = (string) file_get_contents($working);

            return $this->verify($stamped) ? $stamped : null;
        } catch (Throwable $e) {
            Log::warning('Adding a cover sheet to a workbook failed; its bytes are unchanged.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($working);
        }
    }

    /**
     * The sheet: the symbol's rows left clear, then the record beneath it.
     *
     * `<drawing>` comes after `<sheetData>` because the schema is a sequence
     * and Excel rejects the part if it does not.
     *
     * @param  array<string, string|null>  $cover
     */
    private function sheetXml(array $cover): string
    {
        $lines = array_values(array_filter([
            $cover['code'] ?? null,
            $cover['title'] ?? null,
            implode('  ·  ', array_filter([$cover['type'] ?? null, $cover['visibility'] ?? null])),
            $cover['visibility_note'] ?? null,
            implode('  ·  ', array_filter([$cover['uploader'] ?? null, $cover['filed_on'] ?? null])),
        ], fn (?string $line) => ($line ?? '') !== ''));

        // The symbol is anchored at row 1 and is about eight rows tall, so
        // the text starts below it rather than behind it.
        $row = 10;
        $rows = '';

        foreach ($lines as $line) {
            $rows .= '<row r="'.$row.'"><c r="B'.$row.'" t="inlineStr"><is><t xml:space="preserve">'
                .$this->escape($line).'</t></is></c></row>';
            $row++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetData>'.$rows.'</sheetData>'
            .'<drawing r:id="'.self::DRAWING_REL.'"/>'
            .'</worksheet>';
    }

    private function sheetRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="'.self::DRAWING_REL.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing"'
            .' Target="../drawings/doctrack-cover.xml"/>'
            .'</Relationships>';
    }

    /**
     * Where the symbol sits on the sheet.
     *
     * `oneCellAnchor`, so it keeps its size: a `twoCellAnchor` is stretched
     * between two cells and would be distorted by whatever column widths the
     * workbook happens to have — and a distorted QR code is an unreadable
     * one.
     */
    private function drawingXml(): string
    {
        $side = self::SYMBOL_EMU;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<xdr:oneCellAnchor>'
            .'<xdr:from><xdr:col>1</xdr:col><xdr:colOff>0</xdr:colOff>'
            .'<xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            .'<xdr:ext cx="'.$side.'" cy="'.$side.'"/>'
            .'<xdr:pic>'
            .'<xdr:nvPicPr><xdr:cNvPr id="1" name="Tracking code"/><xdr:cNvPicPr/></xdr:nvPicPr>'
            .'<xdr:blipFill>'
            .'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' r:embed="'.self::IMAGE_REL.'"/>'
            .'<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$side.'" cy="'.$side.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            .'</xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }

    private function drawingRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="'.self::IMAGE_REL.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
            .' Target="../media/doctrack-code.png"/>'
            .'</Relationships>';
    }

    /**
     * Put the cover first in the workbook's sheet list.
     *
     * Excel orders tabs by this list, so inserting at the front is what
     * makes it *the first sheet*. The id is chosen above every existing one
     * rather than taking 1: `sheetId` must be unique across the workbook and
     * low numbers are exactly the ones already used.
     */
    private function withSheet(string $workbook): string
    {
        preg_match_all('/sheetId="(\d+)"/', $workbook, $found);

        $id = $found[1] === [] ? 1 : max(array_map('intval', $found[1])) + 1;

        $sheet = '<sheet name="Tracking" sheetId="'.$id.'" r:id="'.self::SHEET_REL.'"/>';

        // `<sheets>` may carry attributes, so the opening tag is matched
        // rather than assumed to be bare.
        return (string) preg_replace('/(<sheets[^>]*>)/', '$1'.$sheet, $workbook, 1);
    }

    private function withRelationship(string $rels): string
    {
        $relationship = '<Relationship Id="'.self::SHEET_REL.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            .' Target="worksheets/doctrack-cover.xml"/>';

        return str_replace('</Relationships>', $relationship.'</Relationships>', $rels);
    }

    private function withTypes(string $types): string
    {
        $added = '';

        if (! str_contains($types, 'Extension="png"')) {
            $added .= '<Default Extension="png" ContentType="image/png"/>';
        }

        $added .= '<Override PartName="/'.self::SHEET_PART.'"'
            .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/'.self::DRAWING_PART.'"'
            .' ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';

        return str_replace('</Types>', $added.'</Types>', $types);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Is the result still a workbook?
     *
     * Every part written is read back and parsed, and the image is confirmed
     * present — a drawing pointing at a missing target is the kind of damage
     * that survives a check of the XML alone.
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
                'xl/workbook.xml',
                'xl/_rels/workbook.xml.rels',
                '[Content_Types].xml',
                self::SHEET_PART,
                self::DRAWING_PART,
                'xl/worksheets/_rels/doctrack-cover.xml.rels',
                'xl/drawings/_rels/doctrack-cover.xml.rels',
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
