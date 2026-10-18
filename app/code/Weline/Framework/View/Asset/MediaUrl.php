<?php

declare(strict_types=1);

namespace Weline\Framework\View\Asset;

/** Allocation-light media URL formatter for framework/module UI code. */
final class MediaUrl
{
    public static function fromPath(string $path, ?int $width = null, ?int $height = null): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if (\str_starts_with($path, 'http') || \str_starts_with($path, '//') || \str_starts_with($path, '@')) {
            return $path;
        }

        $relative = self::normalizeMediaPath($path);
        if ($relative === '') {
            return $path;
        }

        $mediaRoot = \defined('MEDIA_DIR') ? (string)\constant('MEDIA_DIR') : (PUB . 'media' . DIRECTORY_SEPARATOR);
        $extension = \strtolower(\pathinfo(\rtrim($mediaRoot, '/\\') . DIRECTORY_SEPARATOR . $relative, PATHINFO_EXTENSION));
        if ($extension === 'svg') {
            return '/pub/media/' . \ltrim($relative, '/');
        }

        $url = '/media/image/' . \ltrim($relative, '/');
        if ($width !== null && $height !== null) {
            $url .= '?w=' . $width . '&h=' . $height;
        }
        return $url;
    }

    private static function normalizeMediaPath(string $path): string
    {
        foreach (['/pub/media/', 'pub/media/', '/media/'] as $prefix) {
            if (\str_starts_with($path, $prefix)) {
                return \ltrim(\substr($path, \strlen($prefix)), '/');
            }
        }
        return \ltrim($path, '/');
    }
}
