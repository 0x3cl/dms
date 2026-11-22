<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Derive the tab-icon set from the one source logo.
 *
 * `public/ptri.png` is the DOST-PTRI seal at full resolution. It is the only
 * brand image anyone edits; everything a browser asks for in an icon slot is
 * generated from it by this command, so replacing the seal is one file plus one
 * command rather than seven files kept in step by hand.
 *
 * Two things it does that are easy to get wrong.
 *
 * **It letterboxes rather than stretches.** The seal is 810x706 — not square —
 * and an icon slot is. Scaling it to fit a square canvas and centring it keeps
 * the proportions; `imagecopyresampled` onto a square target without that
 * would squash it, which reads as a mistake at every size.
 *
 * **It writes a real `favicon.ico`.** A browser requests `/favicon.ico`
 * unprompted whatever the markup says, and the file that used to sit there was
 * zero bytes — worse than absent, because an empty body is a broken image and
 * not a fallback. The ICO carries 16, 32 and 48 as PNG-compressed entries,
 * which every browser this app targets understands and which avoids writing a
 * BMP encoder for a 16-pixel image.
 *
 * GD, not Imagick: these servers have GD and do not have Imagick, which is the
 * same constraint that decided the QR label format.
 */
class GenerateBrandIcons extends Command
{
    protected $signature = 'brand:icons
                            {--source=ptri.png : The logo in public/ to derive from}';

    protected $description = 'Regenerate the favicon and app icons from the DOST-PTRI logo';

    /** The square sizes written as PNG, and the subset bundled into the ICO. */
    private const SIZES = [16, 32, 48, 180, 192, 512];

    private const ICO_SIZES = [16, 32, 48];

    public function handle(): int
    {
        $source = public_path((string) $this->option('source'));

        if (! is_file($source)) {
            $this->components->error("No such logo: {$source}");

            return self::FAILURE;
        }

        $logo = @imagecreatefrompng($source);

        if ($logo === false) {
            $this->components->error("{$source} is not a PNG this build of GD can read.");

            return self::FAILURE;
        }

        imagealphablending($logo, false);
        imagesavealpha($logo, true);

        $written = [];

        foreach (self::SIZES as $size) {
            $path = public_path("icon-{$size}.png");
            $this->square($logo, $size, $path);
            $written[$size] = $path;

            $this->components->twoColumnDetail("icon-{$size}.png", $this->bytes($path));
        }

        $ico = public_path('favicon.ico');
        file_put_contents($ico, $this->ico($written));
        $this->components->twoColumnDetail('favicon.ico', $this->bytes($ico));

        imagedestroy($logo);

        $this->newLine();
        $this->components->info('Icons regenerated. resources/views/app.blade.php already points at them.');

        return self::SUCCESS;
    }

    /**
     * One size: scaled to fit, centred on a transparent square canvas.
     *
     * @param  \GdImage  $logo
     */
    private function square($logo, int $size, string $path): void
    {
        $sourceWidth = imagesx($logo);
        $sourceHeight = imagesy($logo);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $scale = min($size / $sourceWidth, $size / $sourceHeight);
        $width = (int) round($sourceWidth * $scale);
        $height = (int) round($sourceHeight * $scale);

        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas, $logo,
            (int) floor(($size - $width) / 2), (int) floor(($size - $height) / 2),
            0, 0,
            $width, $height,
            $sourceWidth, $sourceHeight,
        );
        imagesavealpha($canvas, true);

        imagepng($canvas, $path, 9);
        imagedestroy($canvas);
    }

    /**
     * An ICONDIR, one ICONDIRENTRY per size, then the PNG bytes.
     *
     * @param  array<int, string>  $written  size => path of the PNG to embed
     */
    private function ico(array $written): string
    {
        $images = [];

        foreach (self::ICO_SIZES as $size) {
            $images[$size] = (string) file_get_contents($written[$size]);
        }

        // reserved, type 1 (icon), image count
        $ico = pack('vvv', 0, 1, count($images));
        $offset = 6 + (16 * count($images));

        foreach ($images as $size => $data) {
            $ico .= pack(
                'CCCCvvVV',
                $size >= 256 ? 0 : $size,   // width; 0 means 256
                $size >= 256 ? 0 : $size,   // height
                0,                          // palette entries
                0,                          // reserved
                1,                          // colour planes
                32,                         // bits per pixel
                strlen($data),
                $offset,
            );
            $offset += strlen($data);
        }

        return $ico.implode('', $images);
    }

    private function bytes(string $path): string
    {
        return number_format((int) filesize($path)).' bytes';
    }
}
