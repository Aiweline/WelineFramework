<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

/**
 * Renders a local challenge without embedding the plaintext answer in markup.
 *
 * GD produces a raster PNG when available. The portable SVG fallback consists
 * only of anonymous segment rectangles, never a text node containing the code.
 */
final class LocalChallengeImage
{
    /** @var array<string, list<string>> */
    private const BITMAP_GLYPHS = [
        '2' => ['11110', '00001', '00001', '01110', '10000', '10000', '11111'],
        '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['10010', '10010', '10010', '11111', '00010', '00010', '00010'],
        '5' => ['11111', '10000', '10000', '11110', '00001', '00001', '11110'],
        '6' => ['01110', '10000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00001', '01110'],
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
        'C' => ['01111', '10000', '10000', '10000', '10000', '10000', '01111'],
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
        'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
        'G' => ['01111', '10000', '10000', '10111', '10001', '10001', '01111'],
        'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
        'J' => ['00111', '00010', '00010', '00010', '10010', '10010', '01100'],
        'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
        'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
        'N' => ['10001', '11001', '11001', '10101', '10011', '10011', '10001'],
        'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
        'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'],
        'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
        'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
        'W' => ['10001', '10001', '10001', '10101', '10101', '11011', '10001'],
        'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
        'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
        'Z' => ['11111', '00001', '00010', '00100', '01000', '10000', '11111'],
    ];
    /** @var array<string, list<string>> */
    private const SEGMENTS = [
        '2' => ['a', 'b', 'g', 'e', 'd'],
        '3' => ['a', 'b', 'g', 'c', 'd'],
        '4' => ['f', 'g', 'b', 'c'],
        '5' => ['a', 'f', 'g', 'c', 'd'],
        '6' => ['a', 'f', 'g', 'e', 'c', 'd'],
        '7' => ['a', 'b', 'c'],
        '8' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
        '9' => ['a', 'b', 'c', 'd', 'f', 'g'],
    ];

    /** @var array<string, array{int,int,int,int}> */
    private const RECTS = [
        'a' => [4, 2, 15, 5],
        'b' => [17, 5, 20, 22],
        'c' => [17, 26, 20, 43],
        'd' => [4, 41, 15, 44],
        'e' => [1, 26, 4, 43],
        'f' => [1, 5, 4, 22],
        'g' => [4, 21, 15, 25],
    ];

    public static function inlineSvg(string $answer, string $label): string
    {
        $svg = self::svg($answer);
        $attributes = ' class="weline-captcha-image" role="img" aria-label="'
            . \htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" focusable="false"';
        return \preg_replace('/\A<svg\b/', '<svg' . $attributes, $svg, 1) ?? $svg;
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
        $image = \imagecreatetruecolor(168, 52);
        if ($image === false) {
            return '';
        }
        $background = \imagecolorallocate($image, 248, 250, 252);
        \imagefill($image, 0, 0, $background);

        // Low-contrast texture reduces pixel/template matching while keeping
        // the six-character answer readable for a person.
        for ($point = 0; $point < 420; $point++) {
            $noise = \imagecolorallocate(
                $image,
                \random_int(205, 235),
                \random_int(210, 240),
                \random_int(220, 245),
            );
            \imagesetpixel($image, \random_int(1, 166), \random_int(1, 50), $noise);
        }

        $line = \imagecolorallocate($image, 194, 207, 221);
        for ($index = 0; $index < 2; $index++) {
            $lastX = 0;
            $lastY = \random_int(8, 44);
            for ($step = 1; $step <= 12; $step++) {
                $x = (int)\round(167 * $step / 12);
                $y = \max(2, \min(50, $lastY + \random_int(-5, 5)));
                \imageline($image, $lastX, $lastY, $x, $y, $line);
                $lastX = $x;
                $lastY = $y;
            }
        }

        foreach (\str_split($answer) as $index => $digit) {
            self::drawRotatedGlyph($image, $digit, $index);
        }

        $arc = \imagecolorallocate($image, 202, 214, 227);
        for ($index = 0; $index < 2; $index++) {
            \imagearc(
                $image,
                \random_int(0, 168),
                \random_int(0, 52),
                \random_int(60, 140),
                \random_int(26, 70),
                \random_int(0, 160),
                \random_int(200, 360),
                $arc,
            );
        }

        \ob_start();
        \imagepng($image);
        $png = \ob_get_clean();
        \imagedestroy($image);
        return \is_string($png) ? $png : '';
    }

    private static function drawRotatedGlyph(object $image, string $digit, int $index): void
    {
        $glyph = \imagecreatetruecolor(25, 48);
        if ($glyph === false) {
            return;
        }

        \imagealphablending($glyph, false);
        \imagesavealpha($glyph, true);
        $transparent = \imagecolorallocatealpha($glyph, 0, 0, 0, 127);
        \imagefill($glyph, 0, 0, $transparent);
        $foreground = \imagecolorallocate(
            $glyph,
            \random_int(24, 47),
            \random_int(35, 61),
            \random_int(55, 86),
        );

        foreach (self::SEGMENTS[$digit] ?? [] as $segment) {
            [$x1, $y1, $x2, $y2] = self::RECTS[$segment];
            \imagefilledrectangle(
                $glyph,
                $x1 + \random_int(-1, 1),
                $y1 + \random_int(-1, 1),
                $x2 + \random_int(-1, 1),
                $y2 + \random_int(-1, 1),
                $foreground,
            );
        }

        \imagealphablending($glyph, true);
        $rotated = \imagerotate($glyph, \random_int(-6, 6), $transparent);
        \imagedestroy($glyph);
        if ($rotated === false) {
            return;
        }

        \imagecopy(
            $image,
            $rotated,
            2 + ($index * 26) + \random_int(-1, 1),
            \random_int(-1, 2),
            0,
            0,
            \imagesx($rotated),
            \imagesy($rotated),
        );
        \imagedestroy($rotated);
    }

    private static function svg(string $answer): string
    {
        $parts = [
            '<svg xmlns="http://www.w3.org/2000/svg" width="168" height="52" viewBox="0 0 168 52">',
            '<rect width="168" height="52" rx="8" fill="#f8fafc"/>',
        ];
        for ($index = 0; $index < 3; $index++) {
            $points = [];
            $lastY = \random_int(8, 44);
            for ($step = 0; $step <= 12; $step++) {
                $x = (int)\round(168 * $step / 12);
                $y = \max(2, \min(50, $lastY + \random_int(-8, 8)));
                $points[] = $x . ',' . $y;
                $lastY = $y;
            }
            $parts[] = '<polyline points="' . \implode(' ', $points) . '" fill="none" stroke="#718096" stroke-width="' . (\random_int(8, 14) / 10) . '" opacity=".72"/>';
        }
        for ($index = 0; $index < 110; $index++) {
            $parts[] = '<circle cx="' . \random_int(1, 166) . '" cy="' . \random_int(1, 50)
                . '" r="' . (\random_int(1, 6) / 4) . '" fill="#64748b" opacity=".48"/>';
        }
        foreach (\str_split($answer) as $index => $digit) {
            $offsetX = 2 + ($index * 27) + \random_int(-3, 3);
            $offsetY = \random_int(-3, 4);
            $scale = \random_int(90, 112) / 100;
            $angle = \random_int(-16, 16);
            $glyph = [];
            foreach (self::BITMAP_GLYPHS[$digit] ?? [] as $row => $pattern) {
                foreach (\str_split($pattern) as $column => $pixel) {
                    if ($pixel !== '1') {
                        continue;
                    }
                    $x = $offsetX + (int)\round($column * 4.1 * $scale);
                    $y = $offsetY + (int)\round($row * 5.3 * $scale);
                    $glyph[] = '<rect x="' . $x . '" y="' . $y . '" width="' . \random_int(4, 6)
                        . '" height="' . \random_int(5, 7) . '" rx="' . (\random_int(0, 2) / 2)
                        . '" fill="#172033" opacity=".94"/>';
                }
            }
            $parts[] = '<g transform="rotate(' . $angle . ' ' . ($offsetX + 10) . ' 26)">' . \implode('', $glyph) . '</g>';
        }
        for ($index = 0; $index < 4; $index++) {
            $parts[] = '<path d="M' . \random_int(-10, 120) . ' ' . \random_int(5, 46)
                . ' Q' . \random_int(45, 125) . ' ' . \random_int(0, 52) . ' 178 ' . \random_int(5, 48)
                . '" fill="none" stroke="#475569" stroke-width="' . (\random_int(7, 14) / 10)
                . '" opacity=".62"/>';
        }
        $parts[] = '</svg>';
        return \implode('', $parts);
    }
}
