<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

/**
 * Builds srcset strings for media-library images.
 *
 * Only URLs that nginx can serve as static files under /pub/media are emitted:
 * the original asset, plus any pre-generated `thumbnail/…_w_{w}_h_{h}_c_o.{ext}`
 * variant that already exists on disk. The /media/image on-demand endpoint is
 * deliberately not referenced: it needs GD inside the WLS worker, which is not
 * guaranteed, and a srcset candidate must never 404.
 *
 * Pure string assembly: no files are generated here and nothing is cached.
 */
final class ResponsiveImageService
{
    public const DEFAULT_WIDTHS = [320, 640, 960, 1280, 1600];

    private const MAX_CANDIDATES = 5;

    /** Raster extensions the media router can fall back from to a sibling .webp. */
    private const RASTER_FALLBACK_FROM = ['jpg', 'jpeg', 'png', 'gif'];

    /**
     * @param array<int, int|string> $widths
     */
    public function srcset(string $path, array $widths = self::DEFAULT_WIDTHS): string
    {
        $relative = self::toMediaRelativePath($path);
        if ($relative === '') {
            return '';
        }
        [$sourceRelative, $sourceFile] = self::resolveSource($relative);
        if ($sourceFile === '') {
            return '';
        }
        $size = @getimagesize($sourceFile);
        if ($size === false) {
            return '';
        }
        $sourceWidth = (int)($size[0] ?? 0);
        $sourceHeight = (int)($size[1] ?? 0);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return '';
        }

        $candidates = [];
        foreach ($widths as $width) {
            $width = (int)$width;
            if ($width > 0 && $width < $sourceWidth) {
                $candidates[$width] = $width;
            }
        }
        // Never upscale: the natural width is always the last candidate.
        $candidates[$sourceWidth] = $sourceWidth;
        ksort($candidates);
        $candidates = array_values($candidates);
        if (count($candidates) > self::MAX_CANDIDATES) {
            $candidates = array_slice($candidates, -self::MAX_CANDIDATES);
        }

        $parts = [];
        foreach ($candidates as $width) {
            $height = max(1, (int)round($width * $sourceHeight / $sourceWidth));
            $url = $width === $sourceWidth
                ? '/pub/media/' . $sourceRelative
                : self::existingVariantUrl($sourceRelative, $width, $height);
            if ($url === '') {
                continue;
            }
            $parts[] = $url . ' ' . $width . 'w';
        }

        // A single-candidate srcset adds bytes without letting the browser choose.
        return count($parts) < 2 ? '' : implode(', ', $parts);
    }

    public function sizesFor(string $slot): string
    {
        return match (strtolower(trim($slot))) {
            'hero' => '100vw',
            'card' => '(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw',
            'detail' => '(min-width: 1024px) 720px, 100vw',
            default => '',
        };
    }

    /**
     * Normalizes the four accepted spellings to a media relative path.
     * Rejects remote/data URLs, theme static assets (@), SVG and anything else.
     */
    private static function toMediaRelativePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'https://')
            || str_starts_with($path, '//')
            || str_starts_with($lower, 'data:')
        ) {
            return '';
        }
        if (str_contains($path, '@') || str_contains($path, '\\')) {
            return '';
        }

        $path = substr($path, 0, strcspn($path, '?#'));
        $relative = null;
        foreach (['/media/image/', '/pub/media/', '/media/'] as $prefix) {
            if (strncasecmp($path, $prefix, strlen($prefix)) === 0) {
                $relative = substr($path, strlen($prefix));
                break;
            }
        }
        if ($relative === null) {
            // Any other absolute path (e.g. /static/…) is not served by this endpoint.
            if (str_starts_with($path, '/')) {
                return '';
            }
            $relative = $path;
        }

        $relative = ltrim(rawurldecode($relative), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\')) {
            return '';
        }
        if (strtolower((string)pathinfo($relative, PATHINFO_EXTENSION)) === 'svg') {
            return '';
        }

        return $relative;
    }

    /**
     * Locates the real file, mirroring Router::resolveRasterFallback (jpg/png/gif → sibling .webp).
     *
     * @return array{0: string, 1: string} [web-relative path as served, absolute path]
     */
    private static function resolveSource(string $relative): array
    {
        $root = self::mediaRoot();
        if ($root === '') {
            return ['', ''];
        }
        $absolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            return [$relative, $absolute];
        }

        $info = pathinfo($relative);
        $extension = strtolower((string)($info['extension'] ?? ''));
        $stem = (string)($info['filename'] ?? '');
        if ($stem === '' || !in_array($extension, self::RASTER_FALLBACK_FROM, true)) {
            return ['', ''];
        }
        $dir = (string)($info['dirname'] ?? '.');
        $webpRelative = ($dir === '.' || $dir === '') ? $stem . '.webp' : $dir . '/' . $stem . '.webp';
        $webpAbsolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $webpRelative);

        return is_file($webpAbsolute) ? [$webpRelative, $webpAbsolute] : ['', ''];
    }

    /**
     * Existing pre-generated variant, using the Image controller's thumbnail naming.
     */
    private static function existingVariantUrl(string $sourceRelative, int $width, int $height): string
    {
        $info = pathinfo($sourceRelative);
        $dir = (string)($info['dirname'] ?? '.');
        $stem = (string)($info['filename'] ?? '');
        $extension = (string)($info['extension'] ?? '');
        if ($stem === '' || $extension === '') {
            return '';
        }
        $thumbRelative = 'thumbnail/' . ($dir === '.' || $dir === '' ? '' : $dir . '/')
            . $stem . '_w_' . $width . '_h_' . $height . '_c_o.' . $extension;
        $root = self::mediaRoot();
        if ($root === '' || !is_file($root . str_replace('/', DIRECTORY_SEPARATOR, $thumbRelative))) {
            return '';
        }

        return '/pub/media/' . $thumbRelative;
    }

    private static function mediaRoot(): string
    {
        if (\defined('MEDIA_DIR')) {
            return rtrim((string)\constant('MEDIA_DIR'), '/\\') . DIRECTORY_SEPARATOR;
        }
        if (\defined('BP')) {
            return rtrim((string)\constant('BP'), '/\\') . DIRECTORY_SEPARATOR
                . 'pub' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR;
        }

        return '';
    }
}
