<?php

namespace App\Services\Documents;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Matrix\MatrixInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;

/**
 * Draws a DocTrack QR label: rounded modules, brand colours, the seal in the
 * middle.
 *
 * `endroid/qr-code` renders a symbol as flat black squares and offers one
 * foreground colour, so a styled label cannot be asked of it. What it *does*
 * offer is the thing worth keeping — the encoder — so this takes its matrix
 * and does the drawing. Nothing here touches encoding, error correction or
 * module placement; a styled label that moved a module would not be a styled
 * label, it would be a different code.
 *
 * **One geometry, two backends.** `shapes()` is the drawing, stated once;
 * `svg()` and `png()` only write it out. Two renderers would be two answers
 * to "what does our label look like", and the first drift is a printed label
 * that is not the one on screen.
 *
 * ## What is decoration and what is not
 *
 * Three of these choices look cosmetic and are not. The test that matters is
 * `QrLabelTest`, which decodes what this produces: a label that breaks
 * scanning is invisible to the eye, throws nothing, and surfaces only when
 * somebody points a phone at a printed folder and nothing happens.
 *
 * **Corners are rounded, modules are not shrunk.** The obvious way to get the
 * look is to draw each module as a small dot with space around it, and it is
 * the way that breaks decoding: a decoder samples the *centre* of each module
 * and thresholds on local contrast, so shrinking every module throws away the
 * dark area that threshold depends on. Here a module keeps its full square
 * and is rounded only at corners with no dark neighbour, so runs of modules
 * fuse into the smooth blobs the design asks for while every module keeps its
 * area and its centre.
 *
 * **The palette is the brand's dark end, deliberately.** DocTrack's blues are
 * `#07559e`, `#0a6bb8` and `#00aeef`, and the cyan is not in this gradient.
 * A decoder binarises on luminance, and `#00aeef` against white is roughly
 * 2.5:1 — bright enough to fail on a grey photocopy, under warm office light,
 * or through a phone camera's auto-exposure. So the modules run from
 * `#0a6bb8` to `#07559e` across the diagonal and the finder patterns take the
 * darkest, `#043d78`. The gradient is visible and every stop of it is dark.
 *
 * **The finder patterns are drawn as whole shapes, not module by module.**
 * They are what a decoder locates the symbol *with*, so they are the last
 * thing to improvise on: each is the exact 7×7 ring and 3×3 core the spec
 * requires, rounded as one object rather than as 33 squares, which keeps the
 * 1:1:3:1:1 run ratio a scanner measures across them intact.
 *
 * The PNG is drawn at `SUPERSAMPLE` times the final size and resampled down,
 * because GD does not antialias a filled arc — at final size the rounded
 * corners come out visibly stepped.
 */
class QrLabelRenderer
{
    /** The rendered symbol's edge, in pixels. Large enough to print sharply. */
    public const SIZE = 512;

    /** Quiet zone, in modules. Below about 2 a scanner struggles to find the symbol. */
    public const MARGIN = 2;

    /**
     * The seal's width as a fraction of the symbol.
     *
     * High correction tolerates roughly 30% damage and a logo's area grows
     * with the square of its width — at 0.22 the seal covers under 5% of the
     * symbol, leaving the correction budget for what it is actually for:
     * print bleed, creases and bad light.
     */
    private const LOGO_RATIO = 0.22;

    /** The gradient's ends, and the colour the finder patterns take. */
    private const GRADIENT_FROM = [0x0A, 0x6B, 0xB8];

    private const GRADIENT_TO = [0x07, 0x55, 0x9E];

    private const EYE_COLOR = [0x04, 0x3D, 0x78];

    private const BACKGROUND = [0xFF, 0xFF, 0xFF];

    /** How much larger the PNG is drawn before being resampled down. */
    private const SUPERSAMPLE = 3;

    /** A finder pattern is 7×7 modules, with a 3×3 core inset by 2. */
    private const FINDER = 7;

    public function svg(string $target, ?string $logoPath = null): string
    {
        $matrix = $this->matrix($target);
        $size = $matrix->getOuterSize();

        $parts = [
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img">',
                $size,
            ),
            sprintf('<rect width="%1$d" height="%1$d" fill="%2$s"/>', $size, $this->hex(self::BACKGROUND)),
        ];

        foreach ($this->shapes($matrix) as $shape) {
            $parts[] = sprintf(
                '<path d="%s" fill="%s"/>',
                $this->path($shape['x'], $shape['y'], $shape['w'], $shape['h'], $shape['r']),
                $this->hex($shape['color']),
            );
        }

        $logo = $this->logoBox($matrix);
        $bytes = $logoPath !== null && is_file($logoPath) ? file_get_contents($logoPath) : false;

        if ($bytes !== false) {
            // Punched out rather than blended: a seal half-merged with the
            // pattern confuses a decoder far more than a clean hole does.
            $parts[] = sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="%s"/>',
                $logo['pad']['x'], $logo['pad']['y'], $logo['pad']['size'], $logo['pad']['size'],
                round($logo['pad']['size'] * 0.2, 2), $this->hex(self::BACKGROUND),
            );
            $parts[] = sprintf(
                '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" xlink:href="data:image/png;base64,%s"/>',
                $logo['x'], $logo['y'], $logo['size'], $logo['size'], base64_encode($bytes),
            );
        }

        $parts[] = '</svg>';

        return implode('', $parts);
    }

    public function png(string $target, ?string $logoPath = null): string
    {
        $matrix = $this->matrix($target);
        $size = $matrix->getOuterSize();
        $scale = self::SUPERSAMPLE;

        $canvas = imagecreatetruecolor($size * $scale, $size * $scale);
        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $size * $scale, $size * $scale, $this->allocate($canvas, self::BACKGROUND));

        foreach ($this->shapes($matrix) as $shape) {
            $this->roundedRect(
                $canvas,
                $shape['x'] * $scale,
                $shape['y'] * $scale,
                $shape['w'] * $scale,
                $shape['h'] * $scale,
                array_map(fn (float $r): float => $r * $scale, $shape['r']),
                $this->allocate($canvas, $shape['color']),
            );
        }

        // Resampled down, because GD does not antialias a filled arc: at
        // final size every rounded corner comes out visibly stepped.
        $out = imagecreatetruecolor($size, $size);
        imagefilledrectangle($out, 0, 0, $size, $size, $this->allocate($out, self::BACKGROUND));
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);
        imagedestroy($canvas);

        $this->stampLogo($out, $matrix, $logoPath);

        ob_start();
        imagepng($out);
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        return $bytes;
    }

    /**
     * The encoder's matrix. Nothing here is ours.
     */
    private function matrix(string $target): MatrixInterface
    {
        return (new MatrixFactory)->create(new QrCode(
            data: $target,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: self::SIZE,
            margin: self::MARGIN,
            // Modules stay on a whole-pixel grid, which keeps edges crisp
            // rather than anti-aliased into their neighbours.
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ));
    }

    /**
     * Every shape in the label, in draw order.
     *
     * Stated once so the PNG and the SVG cannot disagree. A shape is a
     * rectangle with four corner radii, which is enough for both a rounded
     * module and a finder pattern's ring.
     *
     * @return list<array{x: float, y: float, w: float, h: float, r: array{float, float, float, float}, color: array{int, int, int}}>
     */
    private function shapes(MatrixInterface $matrix): array
    {
        $count = $matrix->getBlockCount();
        $block = $matrix->getBlockSize();
        $offset = $matrix->getMarginLeft();
        $shapes = [];

        $dark = function (int $row, int $column) use ($matrix, $count): bool {
            if ($row < 0 || $column < 0 || $row >= $count || $column >= $count) {
                return false;
            }

            return $matrix->getBlockValue($row, $column) === 1;
        };

        // The three finder patterns, by the row/column their 7×7 begins at.
        $finders = [[0, 0], [0, $count - self::FINDER], [$count - self::FINDER, 0]];

        $inFinder = function (int $row, int $column) use ($finders): bool {
            foreach ($finders as [$top, $left]) {
                if ($row >= $top && $row < $top + self::FINDER && $column >= $left && $column < $left + self::FINDER) {
                    return true;
                }
            }

            return false;
        };

        for ($row = 0; $row < $count; $row++) {
            for ($column = 0; $column < $count; $column++) {
                if (! $dark($row, $column) || $inFinder($row, $column)) {
                    continue;
                }

                /*
                 * A corner is rounded only where the module has no dark
                 * neighbour on either side of it, so a run of modules fuses
                 * into one blob and a lone module becomes a dot. The module
                 * keeps its full square either way — see the class docblock
                 * on why shrinking it is the thing that breaks decoding.
                 */
                $up = $dark($row - 1, $column);
                $down = $dark($row + 1, $column);
                $left = $dark($row, $column - 1);
                $right = $dark($row, $column + 1);
                $radius = $block * 0.5;

                $shapes[] = [
                    'x' => $offset + $column * $block,
                    'y' => $offset + $row * $block,
                    'w' => $block,
                    'h' => $block,
                    'r' => [
                        $up || $left ? 0.0 : $radius,
                        $up || $right ? 0.0 : $radius,
                        $down || $right ? 0.0 : $radius,
                        $down || $left ? 0.0 : $radius,
                    ],
                    'color' => $this->gradient($row, $column, $count),
                ];
            }
        }

        /*
         * The finder patterns, each drawn as three whole shapes rather than
         * as 33 squares: the 7×7 ring, the 5×5 hole punched back out of it,
         * and the 3×3 core. Exact to the spec, because these are what a
         * decoder measures the symbol's scale against.
         */
        foreach ($finders as [$top, $left]) {
            $x = $offset + $left * $block;
            $y = $offset + $top * $block;

            $shapes[] = $this->square($x, $y, $block * 7, $block * 2, self::EYE_COLOR);
            $shapes[] = $this->square($x + $block, $y + $block, $block * 5, $block * 1.4, self::BACKGROUND);
            $shapes[] = $this->square($x + $block * 2, $y + $block * 2, $block * 3, $block * 1.1, self::EYE_COLOR);
        }

        return $shapes;
    }

    /**
     * @param  array{int, int, int}  $color
     * @return array{x: float, y: float, w: float, h: float, r: array{float, float, float, float}, color: array{int, int, int}}
     */
    private function square(float $x, float $y, float $size, float $radius, array $color): array
    {
        return ['x' => $x, 'y' => $y, 'w' => $size, 'h' => $size, 'r' => [$radius, $radius, $radius, $radius], 'color' => $color];
    }

    /**
     * The module's colour, interpolated along the diagonal.
     *
     * Both ends are dark on purpose; see the class docblock.
     *
     * @return array{int, int, int}
     */
    private function gradient(int $row, int $column, int $count): array
    {
        $t = $count > 1 ? ($row + $column) / (2 * ($count - 1)) : 0.0;

        return [
            (int) round(self::GRADIENT_FROM[0] + ($t * (self::GRADIENT_TO[0] - self::GRADIENT_FROM[0]))),
            (int) round(self::GRADIENT_FROM[1] + ($t * (self::GRADIENT_TO[1] - self::GRADIENT_FROM[1]))),
            (int) round(self::GRADIENT_FROM[2] + ($t * (self::GRADIENT_TO[2] - self::GRADIENT_FROM[2]))),
        ];
    }

    /**
     * A rectangle with four independent corner radii, as an SVG path.
     *
     * @param  array{float, float, float, float}  $r  top-left, top-right, bottom-right, bottom-left
     */
    private function path(float $x, float $y, float $w, float $h, array $r): string
    {
        $cap = min($w, $h) / 2;
        [$tl, $tr, $br, $bl] = array_map(fn (float $radius): float => round(min($radius, $cap), 3), $r);

        $x = round($x, 3);
        $y = round($y, 3);
        $right = round($x + $w, 3);
        $bottom = round($y + $h, 3);

        return implode(' ', [
            sprintf('M%s,%s', $x + $tl, $y),
            sprintf('H%s', $right - $tr),
            $tr > 0 ? sprintf('A%s,%s 0 0 1 %s,%s', $tr, $tr, $right, $y + $tr) : '',
            sprintf('V%s', $bottom - $br),
            $br > 0 ? sprintf('A%s,%s 0 0 1 %s,%s', $br, $br, $right - $br, $bottom) : '',
            sprintf('H%s', $x + $bl),
            $bl > 0 ? sprintf('A%s,%s 0 0 1 %s,%s', $bl, $bl, $x, $bottom - $bl) : '',
            sprintf('V%s', $y + $tl),
            $tl > 0 ? sprintf('A%s,%s 0 0 1 %s,%s', $tl, $tl, $x + $tl, $y) : '',
            'Z',
        ]);
    }

    /**
     * The same rectangle, drawn with GD.
     *
     * Built from one cross of filled rectangles plus a filled ellipse at each
     * rounded corner, because GD has no rounded primitive. The caller draws
     * at SUPERSAMPLE scale, so the unantialiased arcs resample smooth.
     *
     * @param  array{float, float, float, float}  $r
     */
    private function roundedRect(\GdImage $image, float $x, float $y, float $w, float $h, array $r, int $color): void
    {
        $cap = min($w, $h) / 2;
        [$tl, $tr, $br, $bl] = array_map(fn (float $radius): float => min($radius, $cap), $r);

        $x1 = (int) round($x);
        $y1 = (int) round($y);
        $x2 = (int) round($x + $w) - 1;
        $y2 = (int) round($y + $h) - 1;

        $maxTop = (int) round(max($tl, $tr));
        $maxBottom = (int) round(max($br, $bl));

        // The body, as a cross: full width between the corner bands, full
        // height between the left and right ones.
        imagefilledrectangle($image, $x1, $y1 + $maxTop, $x2, $y2 - $maxBottom, $color);
        imagefilledrectangle($image, $x1 + (int) round(max($tl, $bl)), $y1, $x2 - (int) round(max($tr, $br)), $y2, $color);

        $corner = function (float $radius, int $cx, int $cy, int $sx, int $sy) use ($image, $color): void {
            $d = (int) round($radius * 2);

            if ($d <= 0) {
                return;
            }

            imagefilledellipse($image, $cx, $cy, $d, $d, $color);
            // The square the arc replaces, outside the body's cross.
            imagefilledrectangle($image, $cx, $cy, $cx + $sx, $cy + $sy, $color);
        };

        // Each corner: the disc, then the quarter that the cross already
        // covers is harmless to repaint.
        if ($tl > 0) {
            imagefilledellipse($image, $x1 + (int) round($tl), $y1 + (int) round($tl), (int) round($tl * 2), (int) round($tl * 2), $color);
        }
        if ($tr > 0) {
            imagefilledellipse($image, $x2 - (int) round($tr), $y1 + (int) round($tr), (int) round($tr * 2), (int) round($tr * 2), $color);
        }
        if ($br > 0) {
            imagefilledellipse($image, $x2 - (int) round($br), $y2 - (int) round($br), (int) round($br * 2), (int) round($br * 2), $color);
        }
        if ($bl > 0) {
            imagefilledellipse($image, $x1 + (int) round($bl), $y2 - (int) round($bl), (int) round($bl * 2), (int) round($bl * 2), $color);
        }

        unset($corner);

        // A square corner keeps its square: the cross above leaves it open
        // whenever the opposite corner on that edge is rounded.
        if ($tl <= 0) {
            imagefilledrectangle($image, $x1, $y1, $x1 + $maxTop, $y1 + $maxTop, $color);
        }
        if ($tr <= 0) {
            imagefilledrectangle($image, $x2 - $maxTop, $y1, $x2, $y1 + $maxTop, $color);
        }
        if ($br <= 0) {
            imagefilledrectangle($image, $x2 - $maxBottom, $y2 - $maxBottom, $x2, $y2, $color);
        }
        if ($bl <= 0) {
            imagefilledrectangle($image, $x1, $y2 - $maxBottom, $x1 + $maxBottom, $y2, $color);
        }
    }

    /**
     * Where the seal sits, and the cleared square behind it.
     *
     * @return array{x: float, y: float, size: float, pad: array{x: float, y: float, size: float}}
     */
    private function logoBox(MatrixInterface $matrix): array
    {
        $outer = $matrix->getOuterSize();
        $size = round($outer * self::LOGO_RATIO);
        $pad = round($size * 1.25);

        return [
            'x' => round(($outer - $size) / 2, 2),
            'y' => round(($outer - $size) / 2, 2),
            'size' => $size,
            'pad' => [
                'x' => round(($outer - $pad) / 2, 2),
                'y' => round(($outer - $pad) / 2, 2),
                'size' => $pad,
            ],
        ];
    }

    private function stampLogo(\GdImage $canvas, MatrixInterface $matrix, ?string $logoPath): void
    {
        if ($logoPath === null || ! is_file($logoPath)) {
            return;
        }

        $logo = @imagecreatefrompng($logoPath);

        if ($logo === false) {
            return;
        }

        $box = $this->logoBox($matrix);

        imagefilledrectangle(
            $canvas,
            (int) $box['pad']['x'],
            (int) $box['pad']['y'],
            (int) ($box['pad']['x'] + $box['pad']['size']),
            (int) ($box['pad']['y'] + $box['pad']['size']),
            $this->allocate($canvas, self::BACKGROUND),
        );

        // Letterboxed, never stretched: the seal is 810×706 and the box is
        // square, so resampling onto it without centring squashes it.
        $width = imagesx($logo);
        $height = imagesy($logo);
        $ratio = min($box['size'] / $width, $box['size'] / $height);
        $drawWidth = (int) round($width * $ratio);
        $drawHeight = (int) round($height * $ratio);

        imagecopyresampled(
            $canvas,
            $logo,
            (int) round($box['x'] + (($box['size'] - $drawWidth) / 2)),
            (int) round($box['y'] + (($box['size'] - $drawHeight) / 2)),
            0,
            0,
            $drawWidth,
            $drawHeight,
            $width,
            $height,
        );

        imagedestroy($logo);
    }

    /** @param array{int, int, int} $color */
    private function allocate(\GdImage $image, array $color): int
    {
        return (int) imagecolorallocate($image, $color[0], $color[1], $color[2]);
    }

    /** @param array{int, int, int} $color */
    private function hex(array $color): string
    {
        return sprintf('#%02x%02x%02x', $color[0], $color[1], $color[2]);
    }
}
