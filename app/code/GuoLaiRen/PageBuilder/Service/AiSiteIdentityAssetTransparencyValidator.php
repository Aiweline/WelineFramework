<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteIdentityAssetTransparencyValidator
{
    public static function isAcceptableIdentityAsset(string $bytes, string $mimeType = '', string $role = ''): bool
    {
        if ($bytes === '') {
            return false;
        }

        $mimeType = \strtolower(\trim($mimeType));
        if (self::isPngImageBytes($bytes) || \str_contains($mimeType, 'png')) {
            return self::pngAppearsToHaveTransparentBackground($bytes);
        }

        if (self::looksLikeSvg($bytes) || \str_contains($mimeType, 'svg')) {
            return self::svgAppearsSafeAndTransparent($bytes, $role);
        }

        return false;
    }

    public static function isPngImageBytes(string $bytes): bool
    {
        return \strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0;
    }

    public static function pngAppearsToHaveTransparentBackground(string $bytes): bool
    {
        if (!self::isPngImageBytes($bytes)) {
            return false;
        }

        if (\function_exists('imagecreatefromstring')) {
            $image = @\imagecreatefromstring($bytes);
            if ($image !== false) {
                $width = \imagesx($image);
                $height = \imagesy($image);
                $points = [
                    [0, 0],
                    [\max(0, $width - 1), 0],
                    [0, \max(0, $height - 1)],
                    [\max(0, $width - 1), \max(0, $height - 1)],
                    [(int)\floor($width / 2), 0],
                    [(int)\floor($width / 2), \max(0, $height - 1)],
                ];
                $transparent = 0;
                foreach ($points as [$x, $y]) {
                    $alpha = (\imagecolorat($image, (int)$x, (int)$y) >> 24) & 0x7F;
                    if ($alpha >= 80) {
                        $transparent++;
                    }
                }
                \imagedestroy($image);

                return $transparent >= 4;
            }
        }

        $colorType = \ord($bytes[25] ?? "\0");
        if (\in_array($colorType, [4, 6], true)) {
            return true;
        }

        return \str_contains($bytes, 'tRNS');
    }

    public static function looksLikeSvg(string $bytes): bool
    {
        $text = \ltrim($bytes);
        return \str_contains(\strtolower(\substr($text, 0, 512)), '<svg');
    }

    public static function svgAppearsSafeAndTransparent(string $bytes, string $role = ''): bool
    {
        $svg = \trim($bytes);
        if ($svg === '' || !self::looksLikeSvg($svg) || !\str_contains(\strtolower($svg), '</svg>')) {
            return false;
        }

        $normalized = \strtolower($svg);
        foreach ([
            '<script',
            '<foreignobject',
            '<iframe',
            '<object',
            '<embed',
            '<!doctype',
            '<!entity',
            'javascript:',
            'onload=',
            'onclick=',
            '<image',
            'xlink:href=',
            'href="http',
            "href='http",
            '<animate',
            '<set ',
        ] as $pattern) {
            if (\str_contains($normalized, $pattern)) {
                return false;
            }
        }

        if (\strlen($svg) > 20000) {
            return false;
        }

        if (\class_exists(\DOMDocument::class)) {
            $previousUseInternalErrors = \libxml_use_internal_errors(true);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadXML($svg, \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR);
            $errors = \libxml_get_errors();
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousUseInternalErrors);
            if (!$loaded || $errors !== []) {
                return false;
            }
            $root = $document->documentElement;
            if (!$root instanceof \DOMElement || \strtolower($root->localName) !== 'svg') {
                return false;
            }
            $namespace = \trim((string)$root->namespaceURI);
            if ($namespace !== '' && $namespace !== 'http://www.w3.org/2000/svg') {
                return false;
            }
        }

        return !self::svgContainsBackgroundSurface($svg, $role);
    }

    private static function svgContainsBackgroundSurface(string $svg, string $role): bool
    {
        $width = 0;
        $height = 0;
        if (\preg_match('/viewBox\s*=\s*["\']\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*["\']/i', $svg, $matches) === 1) {
            $width = (int)\round((float)$matches[1]);
            $height = (int)\round((float)$matches[2]);
        }
        if (($width <= 0 || $height <= 0) && \preg_match('/<svg\b([^>]*)>/i', $svg, $rootMatches) === 1) {
            $rootAttrs = self::parseSvgAttributes((string)$rootMatches[1]);
            $width = (int)\round(self::resolveSvgLength($rootAttrs['width'] ?? '', 0));
            $height = (int)\round(self::resolveSvgLength($rootAttrs['height'] ?? '', 0));
        }
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        if (\preg_match_all('/<rect\b([^>]*)>/i', $svg, $rectMatches) === false) {
            return false;
        }

        $role = \strtolower(\trim($role));
        foreach ($rectMatches[1] as $rawAttrs) {
            $attrs = self::parseSvgAttributes((string)$rawAttrs);
            $rectWidth = self::resolveSvgLength($attrs['width'] ?? '', $width);
            $rectHeight = self::resolveSvgLength($attrs['height'] ?? '', $height);
            $x = self::resolveSvgLength($attrs['x'] ?? '0', $width);
            $y = self::resolveSvgLength($attrs['y'] ?? '0', $height);
            if ($rectWidth <= 0.0 || $rectHeight <= 0.0) {
                continue;
            }

            $coversCanvas = $x <= ($width * 0.05)
                && $y <= ($height * 0.05)
                && ($x + $rectWidth) >= ($width * 0.95)
                && ($y + $rectHeight) >= ($height * 0.95);
            if ($coversCanvas) {
                return true;
            }

            $isIconTile = \in_array($role, ['icon', 'favicon', 'site_title_icon'], true)
                && $width <= 80
                && $height <= 80
                && $x <= 8.0
                && $y <= 8.0
                && $rectWidth >= ($width * 0.72)
                && $rectHeight >= ($height * 0.72);
            if ($isIconTile) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,string>
     */
    private static function parseSvgAttributes(string $rawAttrs): array
    {
        $attrs = [];
        if (\preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $rawAttrs, $matches, \PREG_SET_ORDER) === false) {
            return $attrs;
        }
        foreach ($matches as $match) {
            $attrs[\strtolower((string)$match[1])] = (string)$match[3];
        }

        return $attrs;
    }

    private static function resolveSvgLength(string $value, int $basis): float
    {
        $value = \trim($value);
        if ($value === '') {
            return 0.0;
        }
        if (\str_ends_with($value, '%')) {
            return ((float)\rtrim($value, '%')) * $basis / 100;
        }
        if (\preg_match('/^-?[0-9]+(?:\.[0-9]+)?/', $value, $matches) !== 1) {
            return 0.0;
        }

        return (float)$matches[0];
    }
}
