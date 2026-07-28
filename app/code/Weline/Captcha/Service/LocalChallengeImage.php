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
        $foreground = \imagecolorallocate($image, 30, 41, 59);
        $noise = \imagecolorallocate($image, 203, 213, 225);
        \imagefill($image, 0, 0, $background);
        for ($line = 0; $line < 7; $line++) {
            \imageline(
                $image,
                \random_int(0, 167),
                \random_int(0, 51),
                \random_int(0, 167),
                \random_int(0, 51),
                $noise,
            );
        }
        foreach (\str_split($answer) as $index => $digit) {
            $offsetX = 7 + ($index * 26) + \random_int(-1, 1);
            $offsetY = \random_int(-1, 1);
            foreach (self::SEGMENTS[$digit] ?? [] as $segment) {
                [$x1, $y1, $x2, $y2] = self::RECTS[$segment];
                \imagefilledrectangle(
                    $image,
                    $offsetX + $x1,
                    $offsetY + $y1,
                    $offsetX + $x2,
                    $offsetY + $y2,
                    $foreground,
                );
            }
        }
        \ob_start();
        \imagepng($image);
        $png = \ob_get_clean();
        \imagedestroy($image);
        return \is_string($png) ? $png : '';
    }

    private static function svg(string $answer): string
    {
        $parts = [
            '<svg xmlns="http://www.w3.org/2000/svg" width="168" height="52" viewBox="0 0 168 52">',
            '<rect width="168" height="52" rx="8" fill="#f8fafc"/>',
            '<path d="M3 38L165 11M2 14L166 43M22 49L145 3" stroke="#cbd5e1" stroke-width="1.5"/>',
        ];
        foreach (\str_split($answer) as $index => $digit) {
            $offsetX = 7 + ($index * 26);
            foreach (self::SEGMENTS[$digit] ?? [] as $segment) {
                [$x1, $y1, $x2, $y2] = self::RECTS[$segment];
                $parts[] = '<rect x="' . ($offsetX + $x1) . '" y="' . $y1
                    . '" width="' . ($x2 - $x1 + 1) . '" height="' . ($y2 - $y1 + 1)
                    . '" rx="1" fill="#1e293b"/>';
            }
        }
        $parts[] = '</svg>';
        return \implode('', $parts);
    }
}
