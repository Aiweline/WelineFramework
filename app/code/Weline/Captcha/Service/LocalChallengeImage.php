<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

/**
 * Renders a local challenge without embedding the plaintext answer in markup.
 *
 * Prefer GD + TrueType when available (PNG data URI consumers). The inline SVG
 * path uses anonymous stroke glyphs only — never a <text> node with the code —
 * so the answer cannot be scraped from the DOM under strict CSP.
 */
final class LocalChallengeImage
{
    private const WIDTH = 168;
    private const HEIGHT = 40;
    private const FONT_SIZE = 18;
    private const MAX_ANGLE = 5;

    /** @var array<string, list<string>> Stroke paths in a 24x36 glyph box. */
    private const STROKE_GLYPHS = [
        '2' => ['M3 9 C3 3 21 3 21 10 C21 16 5 22 5 30 H21'],
        '3' => ['M4 7 C4 3 20 3 20 9 C20 14 10 16 10 18 C10 20 21 21 21 28 C21 34 4 34 4 29'],
        '4' => ['M16 4 V32', 'M16 4 L4 22 H21'],
        '5' => ['M19 5 H6 V17 H15 C21 17 21 31 12 31 C6 31 4 27 4 27'],
        '6' => ['M18 8 C16 3 5 5 5 18 V24 C5 33 19 34 19 24 C19 16 5 16 5 22'],
        '7' => ['M4 6 H20 L10 32'],
        '8' => [
            'M12 4 C5 4 5 17 12 17 C19 17 19 4 12 4 Z',
            'M12 17 C4 17 4 34 12 34 C20 34 20 17 12 17 Z',
        ],
        '9' => ['M6 28 C8 33 19 31 19 18 V12 C19 3 5 2 5 12 C5 20 19 20 19 14'],
        'A' => ['M3 34 L12 4 L21 34', 'M7 22 H17'],
        'B' => ['M5 4 V32', 'M5 4 H14 C20 4 20 17 14 17 H5', 'M5 17 H15 C21 17 21 32 15 32 H5'],
        'C' => ['M19 9 C17 3 5 3 5 18 C5 33 17 33 19 27'],
        'D' => ['M5 4 V32', 'M5 4 H13 C20 4 21 18 13 32 H5'],
        'E' => ['M19 5 H6 V31 H19', 'M6 18 H16'],
        'F' => ['M19 5 H6 V31', 'M6 18 H15'],
        'G' => ['M19 10 C17 3 5 3 5 18 C5 33 17 33 19 26 V20 H12'],
        'H' => ['M5 4 V32', 'M19 4 V32', 'M5 18 H19'],
        'J' => ['M18 5 V24 C18 32 6 33 6 25'],
        'K' => ['M5 4 V32', 'M19 5 L5 18 L19 32'],
        'L' => ['M6 4 V31 H19'],
        'M' => ['M4 32 V5 L12 20 L20 5 V32'],
        'N' => ['M5 32 V5 L19 32 V5'],
        'P' => ['M5 32 V5 H14 C20 5 20 18 14 18 H5'],
        'Q' => ['M12 4 C5 4 4 18 12 32 C20 32 21 18 12 4 Z', 'M13 24 L20 33'],
        'R' => ['M5 32 V5 H14 C20 5 20 18 14 18 H5', 'M12 18 L19 32'],
        'S' => ['M19 9 C17 3 5 4 5 11 C5 17 19 17 19 25 C19 33 5 33 5 27'],
        'T' => ['M4 6 H20', 'M12 6 V32'],
        'U' => ['M5 5 V22 C5 32 19 32 19 22 V5'],
        'V' => ['M4 5 L12 32 L20 5'],
        'W' => ['M3 5 L7 32 L12 14 L17 32 L21 5'],
        'X' => ['M5 5 L19 32', 'M19 5 L5 32'],
        'Y' => ['M5 5 L12 18 L19 5', 'M12 18 V32'],
        'Z' => ['M4 6 H20 L4 32 H20'],
    ];

    public static function inlineSvg(string $answer, string $label): string
    {
        $svg = self::svg($answer);
        $attributes = ' class="weline-captcha-image" role="img" aria-label="'
            . \htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" focusable="false"';
        return \preg_replace('/\A<svg\b/', '<svg' . $attributes, $svg, 1) ?? $svg;
    }

    /**
     * Preferred markup for form injection: TrueType PNG when GD+font exist,
     * otherwise the CSP-safe stroke SVG.
     */
    public static function markup(string $answer, string $label): string
    {
        $labelEsc = \htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (\function_exists('imagecreatetruecolor') && \function_exists('imagepng')) {
            $png = self::png($answer);
            if ($png !== '') {
                return '<img class="weline-captcha-image" width="' . self::WIDTH . '" height="' . self::HEIGHT . '" alt="" role="img" aria-label="'
                    . $labelEsc
                    . '" src="data:image/png;base64,'
                    . \base64_encode($png)
                    . '">';
            }
        }

        return self::inlineSvg($answer, $label);
    }

    public static function dataUri(string $answer): string
    {
        if (\function_exists('imagecreatetruecolor') && \function_exists('imagepng')) {
            $png = self::png($answer);
            if ($png !== '') {
                return 'data:image/png;base64,' . \base64_encode($png);
            }
        }

        return 'data:image/svg+xml;base64,' . \base64_encode(self::svg($answer));
    }

    private static function png(string $answer): string
    {
        $width = self::WIDTH;
        $height = self::HEIGHT;
        $image = \imagecreatetruecolor($width, $height);
        if ($image === false) {
            return '';
        }

        \imagealphablending($image, true);
        \imagesavealpha($image, true);
        $background = \imagecolorallocate($image, 247, 250, 252);
        \imagefill($image, 0, 0, $background);

        for ($point = 0; $point < 48; $point++) {
            $noise = \imagecolorallocate(
                $image,
                \random_int(210, 226),
                \random_int(216, 230),
                \random_int(224, 236),
            );
            \imagesetpixel($image, \random_int(1, $width - 2), \random_int(1, $height - 2), $noise);
        }

        $line = \imagecolorallocate($image, 198, 210, 222);
        \imagesetthickness($image, 1);
        $lastX = 0;
        $lastY = (int)\round($height / 2);
        for ($step = 1; $step <= 8; $step++) {
            $x = (int)\round(($width - 1) * $step / 8);
            $y = \max(6, \min($height - 6, $lastY + \random_int(-3, 3)));
            \imageline($image, $lastX, $lastY, $x, $y, $line);
            $lastX = $x;
            $lastY = $y;
        }

        $font = self::fontPath();
        $chars = \str_split($answer);
        $count = \max(1, \count($chars));
        $paddingX = 10;
        $slot = ($width - ($paddingX * 2)) / $count;
        $ink = \imagecolorallocate($image, 36, 48, 72);
        foreach ($chars as $index => $digit) {
            $slotCenter = $paddingX + ($index * $slot) + ($slot / 2);
            $angle = \random_int(-self::MAX_ANGLE, self::MAX_ANGLE);
            if ($font !== null && \function_exists('imagettftext') && \function_exists('imagettfbbox')) {
                $box = \imagettfbbox(self::FONT_SIZE, $angle, $font, $digit);
                if ($box === false) {
                    self::drawStrokeGlyphRaster($image, $digit, (int)\round($slotCenter - 10), 6, $ink);
                    continue;
                }
                $glyphWidth = \max($box[2], $box[4]) - \min($box[0], $box[6]);
                $glyphHeight = \max($box[1], $box[3]) - \min($box[5], $box[7]);
                $x = (int)\round($slotCenter - ($glyphWidth / 2) - \min($box[0], $box[6]));
                $y = (int)\round(($height + $glyphHeight) / 2 - \max($box[1], $box[3]));
                \imagettftext($image, self::FONT_SIZE, $angle, $x, $y, $ink, $font, $digit);
            } else {
                self::drawStrokeGlyphRaster(
                    $image,
                    $digit,
                    (int)\round($slotCenter - 11),
                    (int)\round(($height - 28) / 2),
                    $ink,
                );
            }
        }

        $arc = \imagecolorallocate($image, 180, 196, 214);
        \imagearc(
            $image,
            (int)\round($width / 2 + \random_int(-12, 12)),
            (int)\round($height / 2 + \random_int(-4, 4)),
            \random_int(90, 140),
            \random_int(18, 28),
            \random_int(10, 60),
            \random_int(200, 340),
            $arc,
        );

        \ob_start();
        \imagepng($image);
        $png = \ob_get_clean();
        \imagedestroy($image);
        return \is_string($png) ? $png : '';
    }

    /** @param \GdImage|resource $image */
    private static function drawStrokeGlyphRaster(mixed $image, string $digit, int $originX, int $originY, int $color): void
    {
        $paths = self::STROKE_GLYPHS[$digit] ?? [];
        if ($paths === []) {
            return;
        }
        // Lightweight fallback when FreeType is missing: sample polyline points.
        foreach ($paths as $path) {
            if (!\preg_match_all('/[MLHVCSQTAZ][^MLHVCSQTAZ]*/i', $path, $commands)) {
                continue;
            }
            $cursorX = 0.0;
            $cursorY = 0.0;
            $startX = 0.0;
            $startY = 0.0;
            $scale = 1.0;
            foreach ($commands[0] as $command) {
                $type = \strtoupper($command[0]);
                $nums = [];
                if (\preg_match_all('/-?\d+(?:\.\d+)?/', \substr($command, 1), $matches)) {
                    $nums = \array_map('floatval', $matches[0]);
                }
                if ($type === 'M' && \count($nums) >= 2) {
                    $cursorX = $nums[0];
                    $cursorY = $nums[1];
                    $startX = $cursorX;
                    $startY = $cursorY;
                    continue;
                }
                if ($type === 'L' && \count($nums) >= 2) {
                    $nx = $nums[0];
                    $ny = $nums[1];
                    \imageline(
                        $image,
                        (int)\round($originX + $cursorX * $scale),
                        (int)\round($originY + $cursorY * $scale),
                        (int)\round($originX + $nx * $scale),
                        (int)\round($originY + $ny * $scale),
                        $color,
                    );
                    $cursorX = $nx;
                    $cursorY = $ny;
                    continue;
                }
                if ($type === 'H' && \count($nums) >= 1) {
                    $nx = $nums[0];
                    \imageline(
                        $image,
                        (int)\round($originX + $cursorX * $scale),
                        (int)\round($originY + $cursorY * $scale),
                        (int)\round($originX + $nx * $scale),
                        (int)\round($originY + $cursorY * $scale),
                        $color,
                    );
                    $cursorX = $nx;
                    continue;
                }
                if ($type === 'V' && \count($nums) >= 1) {
                    $ny = $nums[0];
                    \imageline(
                        $image,
                        (int)\round($originX + $cursorX * $scale),
                        (int)\round($originY + $cursorY * $scale),
                        (int)\round($originX + $cursorX * $scale),
                        (int)\round($originY + $ny * $scale),
                        $color,
                    );
                    $cursorY = $ny;
                    continue;
                }
                if ($type === 'Z') {
                    \imageline(
                        $image,
                        (int)\round($originX + $cursorX * $scale),
                        (int)\round($originY + $cursorY * $scale),
                        (int)\round($originX + $startX * $scale),
                        (int)\round($originY + $startY * $scale),
                        $color,
                    );
                    $cursorX = $startX;
                    $cursorY = $startY;
                }
            }
        }
    }

    private static function svg(string $answer): string
    {
        $width = self::WIDTH;
        $height = self::HEIGHT;
        $parts = [
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height
            . '" viewBox="0 0 ' . $width . ' ' . $height . '">',
            '<rect width="' . $width . '" height="' . $height . '" rx="8" fill="#f7fafc"/>',
        ];

        $lastY = (int)\round($height / 2);
        $points = [];
        for ($step = 0; $step <= 8; $step++) {
            $x = (int)\round($width * $step / 8);
            $y = \max(6, \min($height - 6, $lastY + \random_int(-3, 3)));
            $points[] = $x . ',' . $y;
            $lastY = $y;
        }
        $parts[] = '<polyline points="' . \implode(' ', $points)
            . '" fill="none" stroke="#94a3b8" stroke-width="1" opacity=".35"/>';
        for ($index = 0; $index < 16; $index++) {
            $parts[] = '<circle cx="' . \random_int(4, $width - 4) . '" cy="' . \random_int(4, $height - 4)
                . '" r="0.8" fill="#94a3b8" opacity=".22"/>';
        }

        $chars = \str_split($answer);
        $count = \max(1, \count($chars));
        $paddingX = 8;
        $slot = ($width - ($paddingX * 2)) / $count;
        $originY = (int)\round(($height - 28) / 2);
        foreach ($chars as $index => $digit) {
            $paths = self::STROKE_GLYPHS[$digit] ?? [];
            if ($paths === []) {
                continue;
            }
            $originX = $paddingX + ($index * $slot) + (($slot - 22) / 2);
            $angle = \random_int(-self::MAX_ANGLE, self::MAX_ANGLE);
            $pivotX = $originX + 11;
            $pivotY = $originY + 14;
            $parts[] = '<g transform="rotate(' . $angle . ' ' . $pivotX . ' ' . $pivotY
                . ') translate(' . $originX . ' ' . $originY . ') scale(0.78)"'
                . ' fill="none" stroke="#243048" stroke-width="2.2"'
                . ' stroke-linecap="round" stroke-linejoin="round">';
            foreach ($paths as $path) {
                $parts[] = '<path d="' . $path . '"/>';
            }
            $parts[] = '</g>';
        }

        $parts[] = '<path d="M' . \random_int(-4, 20) . ' ' . \random_int(10, $height - 10)
            . ' Q' . \random_int(50, 110) . ' ' . \random_int(4, $height - 4) . ' '
            . ($width + 6) . ' ' . \random_int(10, $height - 10)
            . '" fill="none" stroke="#64748b" stroke-width="1" opacity=".28"/>';

        $parts[] = '</svg>';
        return \implode('', $parts);
    }

    private static function fontPath(): ?string
    {
        $candidates = [
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Helvetica.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];
        foreach ($candidates as $path) {
            if (\is_readable($path)) {
                return $path;
            }
        }
        return null;
    }
}
