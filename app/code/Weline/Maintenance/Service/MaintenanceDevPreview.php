<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\MaintenanceStaticPage;

/**
 * DEV-only maintenance page preview entry.
 *
 * Production must never expose this route; the controller gates on DEV.
 */
final class MaintenanceDevPreview
{
    /** Public storefront path segment used for routing and whitelist checks. */
    public const URI_MARKER = '/maintenance/frontend/dev-preview';

    public static function isAvailable(): bool
    {
        return \defined('DEV') && DEV;
    }

    public static function matchesRequestUri(string $uri): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: $uri);

        return \str_contains($path, self::URI_MARKER);
    }

    public static function languageUrl(string $lang): string
    {
        $lang = MaintenanceStaticPage::normalizeLangCode($lang);

        return self::URI_MARKER . '?lang=' . \rawurlencode($lang !== '' ? $lang : MaintenanceStaticPage::DEFAULT_LANG);
    }

    public static function apiUrl(string $lang = ''): string
    {
        $url = self::URI_MARKER;
        $params = ['api' => '1'];
        $lang = MaintenanceStaticPage::normalizeLangCode($lang);
        if ($lang !== '') {
            $params['lang'] = $lang;
        }

        return $url . '?' . \http_build_query($params);
    }

    public static function retryAfter(): int
    {
        return \max(1, (int)Env::getInstance()->getConfig('maintenance_retry_after', 60));
    }
}
