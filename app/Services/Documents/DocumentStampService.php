<?php

namespace App\Services\Documents;

use App\Enums\DocumentStamp;
use App\Support\FileTypes;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Draws a document's tracking code into the file's own bytes.
 *
 * A code on a screen travels with the record. A code *in the file* travels
 * with every printed copy, which is the thing an office actually hands
 * around — and that is the whole point of this: somebody holding a page off a
 * printer can scan it and reach the record.
 *
 * **Only two formats can be given one, and the rest is not a gap to close
 * later.** A raster image is drawn here with GD. A PDF is drawn in the
 * browser, because there is no PDF library on these servers and the
 * documented constraint is that there will not be one — see CLAUDE.md on GD
 * versus Imagick. Everything else is `Unsupported` and means it: writing into
 * OOXML or OLE2 needs a document-format library this project does not have,
 * and appending a line to a `.txt` is a destructive edit of the content
 * itself rather than a stamp on it.
 *
 * **What the stamp encodes is the tracking URL, never a share link.** A
 * stamp is permanent in a way a link is not: `regenerateLink()` exists
 * precisely because a URL has gone further than its owner meant it to, and a
 * token burned into every copy already distributed cannot be re-addressed or
 * revoked. Baking one in would also make every file's bytes carry a bearer
 * credential, which is the inverse of the rule that a printed label is not
 * one unless its uploader deliberately made it one.
 *
 * **A failed stamp never fails an upload.** The bytes are the person's; the
 * stamp is ours. Every failure here is logged and swallowed, and the document
 * records `None` rather than claiming a stamp it does not carry.
 */
class DocumentStampService
{
    /**
     * Rasters GD can open and write back here.
     *
     * SVG is absent for the reason it is absent everywhere else in this
     * application — it is a document that can carry script, and rendering one
     * executes it.
     *
     * **GIF is here, but an animated one is refused**, which is a different
     * statement: GD writes a single frame, so stamping an animation would
     * silently flatten it to its first frame. The refusal is by counting
     * frames in the bytes rather than by excluding the format, because a
     * scanned page saved as a GIF is an ordinary thing in an office and
     * there is no reason it should not carry its code.
     *
     * @var list<string>
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];

    /**
     * The smallest picture worth stamping, in pixels on the short edge.
     *
     * Below this a symbol large enough to scan would cover the picture.
     * `LABEL_MIN` is 84 px measured, and a mark taking more than about a
     * sixth of the short edge stops being a mark on a picture and becomes a
     * picture of a QR code — so 500 px is where those two meet.
     *
     * It was 800, set when the mark needed 200 px because it was carrying a
     * URL. Encoding the bare code halved the symbol, so the floor came down
     * with it and several hundred more pictures an office actually holds —
     * scans, screenshots, phone photographs cropped small — can carry their
     * code. Under it the honest answer is still that these bytes cannot, and
     * the row says `none` rather than claiming one.
     */
    public const MIN_SHORT_EDGE = 500;

    /**
     * The mark's side as a fraction of the picture's short edge.
     *
     * Small, because a tracking mark is a mark and not a feature of the
     * photograph. The bounds below are what stop the ratio alone deciding:
     * on a phone photograph 8% would be enormous and on a scan it would be
     * unreadable, so in practice the mark is almost always `MIN`..`MAX` and
     * the ratio only chooses between them.
     */
    private const LABEL_RATIO = 0.05;

    /**
     * The smallest symbol that reads, and the largest worth drawing.
     *
     * **80 is measured, and what moved it there was the payload rather than
     * the drawing.** A symbol's module count follows the length of what it
     * encodes, and module count is what sets the pixels a decoder needs: the
     * tracking URL is 55 characters and bottomed out at 164 px, while the
     * bare tracking code is 18 and reads at 84. Nothing about the rendering
     * changed — the mark halved because it is carrying less.
     *
     * Asked for at 80 the encoder returns 84; it rounds a module to whole
     * pixels. That rounding is also why `QrLabelService::png()` takes a size
     * at all: resampling a 512 px label down to the size wanted lands module
     * boundaries on fractional pixels and smears them unevenly, which
     * measured as 120 px and 180 px reading while 150 px, 200 px and 240 px
     * did not. Rendering *at* the size gives whole-pixel modules and a
     * monotonic floor.
     */
    private const LABEL_MIN = 80;

    private const LABEL_MAX = 120;

    /** Margin from both edges, as a fraction of the short edge. */
    private const MARGIN_RATIO = 0.025;

    /**
     * Pictures too large to redraw in a request.
     *
     * GD holds an uncompressed bitmap, so a 50MP photograph is ~200MB of
     * memory before anything is drawn. Refusing is better than exhausting the
     * worker on somebody's upload.
     */
    private const MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly QrLabelService $labels,
        private readonly WordCoverPage $word,
        private readonly ExcelCoverSheet $excel,
        private readonly PowerPointCoverSlide $slides,
    ) {}

    /**
     * Which of the three things can happen to these bytes.
     *
     * One place answers it, so the upload dialog, the registrar and the
     * backfill cannot disagree about what a format can carry — and the
     * dialog can tell somebody *before* they commit.
     */
    public function plan(string $extension, string $mimeType): DocumentStamp
    {
        $extension = FileTypes::normalize($extension);

        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            return DocumentStamp::Pdf;
        }

        if (in_array($mimeType, self::IMAGE_MIMES, true)) {
            return DocumentStamp::Image;
        }

        /*
         * Word, by extension rather than by sniffed type.
         *
         * Every OOXML file is a zip underneath, so a correctly sniffed
         * `.docx` is `application/zip` — the same reason `FilePreview`
         * dispatches the Office viewer on the extension. `.doc` is absent:
         * it is an OLE2 container, not a zip, and nothing here can write
         * into one.
         */
        if (in_array($extension, ['docx', 'docm'], true)) {
            return DocumentStamp::Docx;
        }

        // Excel, by extension for the same reason. `.xlsb` is absent: it is
        // a zip, but its workbook part is binary rather than XML, so none of
        // the edits a cover sheet needs can be made to it.
        if (in_array($extension, ['xlsx', 'xlsm'], true)) {
            return DocumentStamp::Xlsx;
        }

        // PowerPoint. `.pps`/`.ppt` are absent for the same reason `.doc`
        // and `.xls` are: OLE2 containers, not zips.
        if (in_array($extension, ['pptx', 'pptm', 'ppsx'], true)) {
            return DocumentStamp::Pptx;
        }

        return DocumentStamp::Unsupported;
    }

    /**
     * Put a cover page at the front of a Word document.
     *
     * Null means the bytes are unchanged, for every reason: not a Word
     * document after all, a part named unusually, already stamped, or a
     * rebuild that could not be verified. See `WordCoverPage`, which
     * refuses rather than risks returning a document Word will not open.
     *
     * @param  array<string, string|null>  $cover
     */
    public function stampWord(string $bytes, string $code, array $cover): ?string
    {
        return $this->word->add(
            $bytes,
            [...$cover, 'code' => $code],
            $this->labels->png($this->labels->trackingUrl($code), 320),
        );
    }

    /**
     * Put a cover sheet at the front of a workbook.
     *
     * Null means the bytes are unchanged, for every reason: not a workbook
     * after all, a part named unusually, already stamped, or a rebuild that
     * could not be verified. See `ExcelCoverSheet`.
     *
     * @param  array<string, string|null>  $cover
     */
    public function stampExcel(string $bytes, string $code, array $cover): ?string
    {
        return $this->excel->add(
            $bytes,
            [...$cover, 'code' => $code],
            $this->labels->png($this->labels->trackingUrl($code), 320),
        );
    }

    /**
     * Put a cover slide at the front of a deck.
     *
     * Null means the bytes are unchanged — including for a deck with no
     * slides, which has no layout to borrow. See `PowerPointCoverSlide`.
     *
     * @param  array<string, string|null>  $cover
     */
    public function stampPowerPoint(string $bytes, string $code, array $cover): ?string
    {
        return $this->slides->add(
            $bytes,
            [...$cover, 'code' => $code],
            $this->labels->png($this->labels->trackingUrl($code), 320),
        );
    }

    /**
     * Draw the code into raster bytes, returning the new bytes or null.
     *
     * Null means "these bytes are unchanged", for every reason: the format,
     * the size, a corrupt image, GD missing, or a failure part way through.
     * The caller stores what it already had and records `None`.
     */
    public function stampImage(string $bytes, string $code, string $mimeType): ?string
    {
        if (! in_array($mimeType, self::IMAGE_MIMES, true) || ! extension_loaded('gd')) {
            return null;
        }

        // An animation would be flattened to its first frame by GD's writer,
        // which destroys the file in order to stamp it.
        if ($mimeType === 'image/gif' && $this->isAnimated($bytes)) {
            return null;
        }

        $image = null;
        $label = null;

        try {
            $image = @imagecreatefromstring($bytes);

            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $short = min($width, $height);

            if ($short < self::MIN_SHORT_EDGE || ($width * $height) > self::MAX_PIXELS) {
                return null;
            }

            $side = (int) min(self::LABEL_MAX, max(self::LABEL_MIN, round($short * self::LABEL_RATIO)));
            $margin = (int) round($short * self::MARGIN_RATIO);

            /*
             * Rendered **at** this size rather than resampled down to it —
             * see `LABEL_MIN` for the measurements that forced it. The
             * encoder rounds a module to whole pixels, so the symbol that
             * comes back may be a few pixels larger than asked for; that
             * number is what everything below is placed against.
             */
            /*
             * **The bare code, not the tracking URL.**
             *
             * A symbol's module count follows what it encodes, and that is
             * what sets how small it can be drawn and still read: 55
             * characters of URL needs 164 px, 18 characters of code needs
             * 84. The mark halves by carrying less.
             *
             * What it costs is real and worth stating: a generic phone
             * camera pointed at this shows the text `ADOR-20261124-0001`
             * rather than opening a page. This application's own scanner
             * reads it — `documents/scan.js` normalises a bare code and a
             * full URL to the same lookup — and the code is also in the
             * file's download name and on its row. The *printable* label is
             * untouched and still encodes a URL, because that one is handed
             * to couriers who have no account and no app.
             */
            $label = @imagecreatefromstring($this->labels->png($code, $side));

            if ($label === false) {
                return null;
            }

            $side = imagesx($label);

            /*
             * The bottom-right corner, and **not** the top centre the cover
             * page uses, which was tried and measured.
             *
             * A mark drawn near the top edge stops decoding. Swept over
             * picture sizes 520–2400 px, mark sizes 84–204 px and light,
             * mid and dark backgrounds, a top-centre mark failed to read in
             * roughly half the combinations — 84 px failed on an 800 px
             * picture and read on a 1200 px one, 128 px read where 134 px
             * did not — while the same mark in this corner read in every
             * one of them. The pattern is not monotonic in either size or
             * margin, so there is no ratio to tune it to: it is the
             * decoder's own row sampling, and the only placement the
             * measurements support is this one.
             *
             * A cover page has no such constraint, because the symbol there
             * is 160 pt on an otherwise empty page.
             */
            $x = $width - $side - $margin;
            $y = $height - $side - $margin;

            /*
             * Drawn with its own white, and the white is the quiet zone
             * rather than a plate around one.
             *
             * The mark was briefly composited transparently where the
             * picture was light enough, sampling the luminance underneath to
             * decide. That worked — measured, it read over backgrounds down
             * to a luminance of 150 and failed at 130 — but it is a rule
             * whose answer depends on the photograph, and the office would
             * rather have one mark that always looks the same and always
             * scans. What is left is the label exactly as rendered: a
             * two-module quiet zone, which is the smallest a scanner can
             * reliably find the symbol in, and nothing beyond it.
             */
            imagealphablending($image, true);
            imagecopy($image, $label, $x, $y, 0, 0, $side, $side);

            return $this->encode($image, $mimeType);
        } catch (Throwable $e) {
            Log::warning('Stamping an image failed; its bytes are unchanged.', [
                'code' => $code,
                'mime' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            // Freed here rather than at each return: GD holds an uncompressed
            // bitmap and an early return used to leak one per refusal.
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }

            if ($label instanceof \GdImage) {
                imagedestroy($label);
            }
        }
    }

    /**
     * Does this GIF hold more than one frame?
     *
     * Counted off the bytes, because GD cannot tell us: `imagecreatefromstring`
     * hands back the first frame and says nothing about the rest, so a check
     * through GD would call every animation a still image.
     *
     * A frame begins with an Image Descriptor — `0x2C` — following either the
     * Graphic Control Extension that times it (`0x21 0xF9`) or the end of the
     * previous block. Two descriptors means an animation. This is a
     * deliberately coarse read: it only has to answer "more than one", and
     * erring towards "animated" costs a stamp rather than a file.
     */
    private function isAnimated(string $bytes): bool
    {
        return preg_match_all('/\x00\x21\xF9\x04/', $bytes) > 1;
    }

    /**
     * Re-encode in the format it arrived in.
     *
     * **This is lossy and it is irreversible**, and the original is not kept
     * as a version: `file_versions` is the history of *replacements* somebody
     * made, and putting our own edit in it would make "version 1" mean
     * something it does not. An office that needs the untouched original
     * keeps it where it came from.
     */
    private function encode(\GdImage $image, string $mimeType): ?string
    {
        ob_start();

        $ok = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, null, 92),
            'image/png' => imagepng($image),
            'image/webp' => imagewebp($image, null, 92),
            // A single-frame GIF only; an animated one was refused above.
            'image/gif' => imagegif($image),
            'image/bmp' => imagebmp($image),
            default => false,
        };

        $encoded = (string) ob_get_clean();

        return $ok && $encoded !== '' ? $encoded : null;
    }
}
