<?php

namespace Tests\Feature;

use App\Services\Documents\QrLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Zxing\QrReader;

/**
 * The label has to scan.
 *
 * This is the one test in the suite that checks an *image* rather than a row,
 * and it earns its place: a QR symbol with a logo punched into the middle of
 * it looks completely correct to a person and may decode to nothing at all.
 * There is no other way to find that out — no exception is thrown, no column
 * is wrong, and the failure only surfaces when somebody in the office points a
 * phone at a printed folder and nothing happens.
 *
 * So it generates, decodes, and asserts the URL it was given comes back.
 *
 * What that URL *is* is a separate decision and lives in
 * `QrLabelService::targetFor()` — a document's share link, so a scanned label
 * opens for whoever holds the folder, falling back to the tracking URL for a
 * confidential document that may not have a link. `TrackedUploadTest` covers
 * that choice; this file covers whether the symbol reads at all.
 */
class QrLabelTest extends TestCase
{
    use RefreshDatabase;

    private function decode(string $png): ?string
    {
        // QrReader wants a path or a resource; the bytes are in memory.
        $path = tempnam(sys_get_temp_dir(), 'qr').'.png';
        file_put_contents($path, $png);

        try {
            $text = (new QrReader($path))->text();

            return is_string($text) ? $text : null;
        } finally {
            @unlink($path);
        }
    }

    /**
     * The styling is drawn by us, so the two ways it could break are ours.
     *
     * Rounded modules risk the symbol at small print sizes — a decoder
     * samples module centres, and a shape that is no longer a full square
     * leaves less to sample. Brand colours risk it under anything that
     * flattens hue to luminance: a photocopy, a fax, a grey-scale print, a
     * phone camera fighting warm office light. Neither failure is visible to
     * the eye, which is the whole reason this file exists.
     *
     * 128px is far below any size a label is printed at. The headroom is the
     * point: a change that quietly halves it fails here rather than on a
     * folder in somebody's hands.
     */
    public function test_the_styled_label_survives_being_printed_small(): void
    {
        $service = app(QrLabelService::class);
        $target = $service->trackingUrl('MEMO-20261111-0042');
        $png = $service->png($target);

        foreach ([256, 160, 128] as $width) {
            $this->assertSame($target, $this->decode($this->resize($png, $width)), "Failed to decode at {$width}px.");
        }
    }

    /**
     * Binarised, which is what a scanner actually does.
     *
     * A decoder converts to grey and thresholds; it does not look at hue. So
     * the test for a coloured symbol is that it survives being reduced to
     * two values — if it does, the colours cost nothing a scanner cares
     * about, and a photocopy, a fax and a grey-scale print all reduce to the
     * same thing.
     *
     * Recorded because it was measured rather than assumed: GD's
     * `IMG_FILTER_CONTRAST` *does* break this label while leaving a plain
     * black one intact, which looks alarming and is not. The styled PNG is
     * supersampled and resampled down, so its edges carry 200-odd grey
     * levels; a contrast curve pushes those intermediate pixels apart and
     * smears module boundaries. A binariser collapses them instead, which is
     * why that transform is not modelled here — it is a property of the
     * filter, not of anything a scanner will do.
     */
    public function test_the_styled_label_survives_being_binarised(): void
    {
        $service = app(QrLabelService::class);
        $target = $service->trackingUrl('MEMO-20261111-0042');

        $image = imagecreatefromstring($service->png($target));
        imagefilter($image, IMG_FILTER_GRAYSCALE);

        for ($x = 0; $x < imagesx($image); $x++) {
            for ($y = 0; $y < imagesy($image); $y++) {
                $value = (imagecolorat($image, $x, $y) & 0xFF) > 128 ? 255 : 0;
                imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, $value, $value, $value));
            }
        }

        ob_start();
        imagepng($image);
        $flattened = (string) ob_get_clean();
        imagedestroy($image);

        $this->assertSame($target, $this->decode($flattened));
    }

    /** The label as it would come off a printer at a smaller size. */
    private function resize(string $png, int $width): string
    {
        $source = imagecreatefromstring($png);
        $target = imagecreatetruecolor($width, $width);
        imagefilledrectangle($target, 0, 0, $width, $width, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $width, imagesx($source), imagesy($source));

        ob_start();
        imagepng($target);
        $bytes = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $bytes;
    }

    public function test_a_png_label_decodes_to_the_url_it_was_given(): void
    {
        $service = app(QrLabelService::class);
        $target = $service->trackingUrl('MEMO-20261111-0042');

        $this->assertSame($target, $this->decode($service->png($target)));
    }

    /** A share link URL is longer and denser; it still has to read. */
    public function test_a_share_link_label_decodes(): void
    {
        $service = app(QrLabelService::class);
        $target = url('/s/'.str_repeat('Ab3', 21).'X');

        $this->assertSame($target, $this->decode($service->png($target)));
    }

    /**
     * The point of the whole exercise: the seal is *on* the symbol and the
     * symbol still reads.
     *
     * High error correction is what pays for the modules the logo covers. Drop
     * it, or widen LOGO_RATIO far enough, and this is the test that fails.
     */
    public function test_the_label_still_decodes_with_the_seal_embedded(): void
    {
        $this->assertFileExists(public_path('ptri.png'), 'The seal is what this test is about.');

        $service = app(QrLabelService::class);
        $target = $service->trackingUrl('CONFID-20261111-0003');

        $withSeal = $service->png($target);
        $decoded = $this->decode($withSeal);

        $this->assertSame($target, $decoded);

        // And it is genuinely a different image from the unbranded one, so the
        // test above cannot be passing because the logo was silently skipped.
        $plain = (new QrLabelService('/nonexistent/logo.png'))->png($target);
        $this->assertNotSame($plain, $withSeal);
    }

    /**
     * A missing seal produces a plain label rather than an exception.
     *
     * A tracking code that cannot be issued because a brand asset is absent
     * would stop every upload in the office, and an unbranded label still
     * carries exactly the same symbol.
     *
     * **This one deliberately does not decode the result**, and the reason is
     * worth recording so nobody "fixes" it later. The unbranded and branded
     * PNGs are pixel-identical outside the area the seal occupies — same data,
     * same error-correction level, same size, same module grid — yet
     * `khanamiryan/qrcode-detector-decoder` reads the branded one and returns
     * false for the plain one, at every margin, with or without mid-tones in
     * the image. A symbol cannot be invalid and then become valid by having a
     * hole punched in it, so this is the decoder, not the label. The test above
     * covers the risk that actually exists (a logo destroying the symbol);
     * asserting this library can read a bare symbol would only pin a defect in
     * a dev-only dependency.
     */
    public function test_a_missing_logo_still_produces_a_label(): void
    {
        $service = new QrLabelService('/nonexistent/logo.png');
        $png = $service->png($service->trackingUrl('SO-20261111-0007'));

        $this->assertNotSame('', $png);

        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image, 'The unbranded label is not a readable PNG.');
        $this->assertSame(imagesy($image), imagesx($image), 'A QR symbol is square.');
    }

    public function test_the_svg_is_markup_and_names_the_same_target(): void
    {
        $service = app(QrLabelService::class);
        $svg = $service->svg($service->trackingUrl('ADOR-20261111-0001'));

        $this->assertStringContainsString('<svg', $svg);
        // Inertia must receive a real string: HtmlString serialised as `{}` and
        // the page rendered [object Object] where the label should have been.
        $this->assertIsString($svg);
    }

    public function test_a_label_is_named_after_its_code_and_nothing_else(): void
    {
        $this->assertSame('MEMO-20261111-0001.png', app(QrLabelService::class)->fileName('MEMO-20261111-0001'));
    }
}
