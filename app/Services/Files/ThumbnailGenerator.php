<?php

namespace App\Services\Files;

use App\Models\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Thumbnails, for images only.
 *
 * **Images only, and that is a deployment constraint rather than a choice.**
 * These servers have GD but not Imagick — the same reason QR labels are SVG
 * rather than PNG — so a PDF's first page cannot be rasterised. A document in
 * the grid view therefore shows a MIME-keyed icon tile, which is handled in the
 * frontend by FileIcon. Do not add PDF thumbnailing without first checking what
 * the target server actually has installed.
 *
 * A thumbnail is written to the same private disk as its file and served
 * through the same gated route (`?variant=thumb`). A thumbnail of a
 * confidential document is still a disclosure of that document, so it is not
 * public any more than the file is.
 */
class ThumbnailGenerator
{
    /** The long edge, in pixels. Enough for a card at 2× density. */
    public const SIZE = 480;

    /**
     * What GD can actually open here. An allow-list: `getimagesize` will claim
     * all sorts of things, and `imagecreatefromstring` on an untrusted file is
     * the one part of this that touches a decoder.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private readonly FilePathFactory $paths) {}

    public function supports(File $file): bool
    {
        return in_array($file->mime_type, self::SUPPORTED, true);
    }

    /**
     * Generate a thumbnail, and say whether one now exists.
     *
     * Failure is swallowed and logged on purpose. A corrupt or hostile image is
     * an expected thing to be handed, and a thumbnail is a convenience — losing
     * one must never fail the upload that carried it, which is the user's
     * actual work.
     */
    public function generate(File $file): bool
    {
        if (! $this->supports($file) || ! extension_loaded('gd')) {
            return false;
        }

        try {
            $disk = Storage::disk(FileStorage::DISK);
            $source = $disk->get($file->storage_path);

            if ($source === null) {
                return false;
            }

            $image = @imagecreatefromstring($source);

            if ($image === false) {
                return false;
            }

            $thumb = imagescale($image, ...$this->dimensions($image));
            imagedestroy($image);

            if ($thumb === false) {
                return false;
            }

            ob_start();
            imagewebp($thumb, null, 82);
            $encoded = (string) ob_get_clean();
            imagedestroy($thumb);

            $disk->put($this->paths->thumbnailFor($file), $encoded);
            $file->update(['has_thumbnail' => true]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Thumbnail generation failed.', [
                'file' => $file->public_id,
                'mime' => $file->mime_type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Scaled to fit the long edge, never enlarged — upscaling a small image
     * produces a blurrier card than showing it at its own size.
     *
     * @param  \GdImage  $image
     * @return array{int, int}
     */
    private function dimensions($image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::SIZE) {
            return [$width, $height];
        }

        $scale = self::SIZE / $longest;

        return [(int) round($width * $scale), (int) round($height * $scale)];
    }
}
