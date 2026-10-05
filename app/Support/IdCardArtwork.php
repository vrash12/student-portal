<?php

namespace App\Support;

/**
 * The army look of the candidate ID card (owner request, 2026-10-05):
 * woodland camouflage bands and a topographic-map background.
 *
 * The page gets the artwork as SVG (sharp at any zoom). The PDF gets the
 * same shapes drawn as PNG with GD: the PDF renderer redraws an SVG on every
 * page (seconds for a class), but embeds a PNG once for the whole file.
 * Without GD the PDF uses the SVG too.
 *
 * The patterns are generated, not copied from any official design, and are
 * the same on every card (fixed seeds). No insignia is drawn: the only
 * emblem on the card is the institution's configured logo.
 */
final class IdCardArtwork
{
    /** Olive base, light olive, brown and black of the camouflage. */
    public const CAMOUFLAGE = ['#3f4628', '#5c6136', '#4a3d27', '#1e2115'];

    public const TERRAIN_BACKGROUND = '#efe9d6';

    public const TERRAIN_LINES = '#dcd2b2';

    /** Drawing units per point: 4 (the PNG is 288 dpi). */
    private const UNITS = 4;

    /** Contour line width in points. */
    private const LINE_WIDTH = 0.6;

    /**
     * Each piece of the card: [kind, width, height, seed], sizes in points,
     * matching the layout of the page and the PDF view.
     */
    private const PIECES = [
        'frontHeader' => ['camouflage', 153, 64, 11],
        'frontFooter' => ['camouflage', 153, 15.5, 23],
        'backHeader' => ['camouflage', 153, 30, 37],
        'backFooter' => ['camouflage', 153, 15.5, 41],
        'frontTerrain' => ['terrain', 153, 159, 53],
        'backTerrain' => ['terrain', 153, 194, 67],
    ];

    private int $state;

    private function __construct(int $seed)
    {
        $this->state = $seed & 0x7FFFFFFF;
    }

    /**
     * Every piece of artwork of the card as data addresses: SVG for the
     * page, PNG for the PDF when GD is available.
     *
     * @return array{frontHeader: string, frontFooter: string, backHeader: string, backFooter: string, frontTerrain: string, backTerrain: string}
     */
    public static function dataUris(bool $forPdf = false): array
    {
        static $cache = [];
        $raster = $forPdf && function_exists('imagecreatetruecolor');

        return $cache[$raster ? 'png' : 'svg'] ??= array_map(function (array $piece) use ($raster): string {
            [$kind, $width, $height, $seed] = $piece;
            $png = $raster ? self::png($kind, (float) $width, (float) $height, $seed) : null;

            return $png !== null
                ? 'data:image/png;base64,'.base64_encode($png)
                : 'data:image/svg+xml;base64,'.base64_encode($kind === 'camouflage' ? self::camouflage($width, $height, $seed) : self::terrain($width, $height, $seed));
        }, self::PIECES);
    }

    /**
     * Woodland camouflage as SVG: an olive base with light olive, brown and
     * black blobs, stretched sideways.
     */
    public static function camouflage(float $width, float $height, int $seed): string
    {
        $paths = '';
        foreach ((new self($seed))->camouflageBlobs($width, $height) as [$colour, $points]) {
            $paths .= '<path fill="'.$colour.'" d="'.self::svgPath($points).'"/>';
        }

        return self::svg($width, $height, self::CAMOUFLAGE[0], $paths);
    }

    /** A faint topographic map as SVG: rings of contour lines around a few hills. */
    public static function terrain(float $width, float $height, int $seed): string
    {
        $paths = '';
        foreach ((new self($seed))->contourRings($width, $height) as $points) {
            $paths .= '<path fill="none" stroke="'.self::TERRAIN_LINES.'" stroke-width="'.(self::LINE_WIDTH * self::UNITS).'" d="'.self::svgPath($points).'"/>';
        }

        return self::svg($width, $height, self::TERRAIN_BACKGROUND, $paths);
    }

    /**
     * Blobs of each colour; blob sizes are in points, so every band shows
     * the pattern at the same scale whatever its height.
     *
     * @return list<array{0: string, 1: list<array{0: float, 1: float}>}>
     */
    private function camouflageBlobs(float $width, float $height): array
    {
        [, $light, $brown, $black] = self::CAMOUFLAGE;
        $blobs = [];
        foreach ([[$light, 11.0, 2.0], [$brown, 9.0, 2.4], [$black, 5.5, 3.2]] as [$colour, $radius, $spread]) {
            $count = (int) ceil(($width + 2 * $radius) * ($height + 2 * $radius) / ($radius * $radius * $spread * 1.7));
            for ($index = 0; $index < $count; $index++) {
                $centreX = $this->between(-$radius, $width + $radius);
                $centreY = $this->between(-$radius, $height + $radius);
                $blobs[] = [$colour, $this->blob($centreX, $centreY, $radius, 1.7)];
            }
        }

        return $blobs;
    }

    /**
     * Contour lines: each hill's outline repeated larger for each ring.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function contourRings(float $width, float $height): array
    {
        $rings = [];
        foreach ([[0.12, 0.2], [0.9, 0.55], [0.3, 0.95]] as [$x, $y]) {
            $centreX = $width * $x + $this->between(-8, 8);
            $centreY = $height * $y + $this->between(-8, 8);
            $shape = array_map(fn (): float => $this->between(0.7, 1.25), range(1, 10));
            for ($ring = 1; $ring <= 8; $ring++) {
                $points = [];
                foreach ($shape as $index => $scale) {
                    $angle = 2 * M_PI * $index / count($shape);
                    $radius = $ring * 7.5 * $scale * $this->between(0.94, 1.06);
                    $points[] = [$centreX + cos($angle) * $radius * 1.3, $centreY + sin($angle) * $radius];
                }
                $rings[] = $points;
            }
        }

        return $rings;
    }

    /**
     * An irregular blob's corner points around a centre (points).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function blob(float $centreX, float $centreY, float $radius, float $stretch): array
    {
        $points = [];
        $corners = 9;
        for ($index = 0; $index < $corners; $index++) {
            $angle = 2 * M_PI * $index / $corners + $this->between(-0.25, 0.25);
            $distance = $radius * $this->between(0.5, 1.15);
            $points[] = [$centreX + cos($angle) * $distance * $stretch, $centreY + sin($angle) * $distance];
        }

        return $points;
    }

    /**
     * The smooth closed outline through the points (Catmull-Rom curves) as
     * cubic Bézier segments: [start, control 1, control 2, end].
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<list<array{0: float, 1: float}>>
     */
    private static function curves(array $points): array
    {
        $count = count($points);
        $segments = [];
        for ($index = 0; $index < $count; $index++) {
            [$x0, $y0] = $points[($index - 1 + $count) % $count];
            [$x1, $y1] = $points[$index];
            [$x2, $y2] = $points[($index + 1) % $count];
            [$x3, $y3] = $points[($index + 2) % $count];
            $segments[] = [[$x1, $y1], [$x1 + ($x2 - $x0) / 6, $y1 + ($y2 - $y0) / 6], [$x2 - ($x3 - $x1) / 6, $y2 - ($y3 - $y1) / 6], [$x2, $y2]];
        }

        return $segments;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    private static function svgPath(array $points): string
    {
        $path = 'M'.self::units($points[0][0]).' '.self::units($points[0][1]);
        foreach (self::curves($points) as [, $control1, $control2, $end]) {
            $path .= 'C'.self::units($control1[0]).' '.self::units($control1[1])
                .' '.self::units($control2[0]).' '.self::units($control2[1])
                .' '.self::units($end[0]).' '.self::units($end[1]);
        }

        return $path.'Z';
    }

    private static function svg(float $width, float $height, string $background, string $content): string
    {
        $w = self::units($width);
        $h = self::units($height);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none">'
            .'<rect width="100%" height="100%" fill="'.$background.'"/>'.$content.'</svg>';
    }

    /** The same shapes as the SVG drawn with GD, as PNG bytes; null if drawing fails. */
    private static function png(string $kind, float $width, float $height, int $seed): ?string
    {
        $image = imagecreatetruecolor(max(1, (int) round($width * self::UNITS)), max(1, (int) round($height * self::UNITS)));
        $colour = fn (string $hex): int => (int) imagecolorallocate($image, ...array_map('hexdec', str_split(ltrim($hex, '#'), 2)));
        $art = new self($seed);

        if ($kind === 'camouflage') {
            imagefill($image, 0, 0, $colour(self::CAMOUFLAGE[0]));
            foreach ($art->camouflageBlobs($width, $height) as [$fill, $points]) {
                imagefilledpolygon($image, self::flattened($points), $colour($fill));
            }
        } else {
            imagefill($image, 0, 0, $colour(self::TERRAIN_BACKGROUND));
            imagesetthickness($image, (int) round(self::LINE_WIDTH * self::UNITS));
            foreach ($art->contourRings($width, $height) as $points) {
                imagepolygon($image, self::flattened($points), $colour(self::TERRAIN_LINES));
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();

        return $png === '' ? null : $png;
    }

    /**
     * The smooth outline as many straight pieces, in pixels, for GD.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<int>
     */
    private static function flattened(array $points): array
    {
        $pixels = [];
        foreach (self::curves($points) as [$start, $control1, $control2, $end]) {
            for ($step = 0; $step < 8; $step++) {
                $t = $step / 8;
                $u = 1 - $t;
                foreach ([0, 1] as $axis) {
                    $pixels[] = (int) round(self::UNITS * ($u ** 3 * $start[$axis] + 3 * $u ** 2 * $t * $control1[$axis] + 3 * $u * $t ** 2 * $control2[$axis] + $t ** 3 * $end[$axis]));
                }
            }
        }

        return $pixels;
    }

    private static function units(float $points): string
    {
        return (string) round($points * self::UNITS, 1);
    }

    /** The next number of this pattern's own sequence, between the bounds. */
    private function between(float $minimum, float $maximum): float
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $minimum + ($maximum - $minimum) * $this->state / 0x7FFFFFFF;
    }
}
