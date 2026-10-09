<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeStaticAssetPublisher;

/**
 * Ensure a theme-namespaced /static/{theme}/.../view/theme/... URL has bytes on disk.
 *
 * WLS fast-404s missing /static/*.png without Framework lazy publish. Site brand assets
 * (favicon / logo kit under design themes) must be published into pub/static before the
 * browser follows the link — Host/hash must not change website brand identity.
 */
final class EnsurePublishedThemeStaticUrl
{
    /**
     * @return string Original URL when published (or already on disk); empty when ensure failed.
     */
    public static function ensure(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (!str_contains($path, '/view/theme/')) {
            return $url;
        }

        $diskFile = self::diskPathForStaticUrl($path);
        if ($diskFile !== '' && is_file($diskFile)) {
            return $url;
        }

        try {
            /** @var ThemeStaticAssetPublisher $publisher */
            $publisher = ObjectManager::getInstance(ThemeStaticAssetPublisher::class);
            $published = $publisher->publishForRequestPath($path);
            if (!is_string($published) || $published === '') {
                return '';
            }
            $publishedDisk = rtrim((string)BP, '\\/')
                . str_replace('/', DIRECTORY_SEPARATOR, $published);

            return is_file($publishedDisk) ? $url : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function diskPathForStaticUrl(string $path): string
    {
        if (str_starts_with($path, '/static/')) {
            $relative = '/pub' . $path;
        } elseif (str_starts_with($path, '/pub/static/')) {
            $relative = $path;
        } else {
            return '';
        }

        return rtrim((string)BP, '\\/') . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
