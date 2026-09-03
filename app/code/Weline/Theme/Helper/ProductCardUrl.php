<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Http\Url;

final class ProductCardUrl
{
    /**
     * Split a catalog URL into the direct URL / route-path pair expected by
     * Theme product-card templates. Relative routes shed the active prefix so
     * the @url tag can add it exactly once.
     *
     * @return array{url: string, url_path: string}
     */
    public static function splitForTaglib(string $route, ?string $prefix = null): array
    {
        $route = trim($route);
        if ($route === '') {
            return ['url' => '', 'url_path' => ''];
        }

        if (preg_match('#^(?:https?:)?//#i', $route) === 1) {
            return ['url' => $route, 'url_path' => ''];
        }

        $path = ltrim($route, '/');
        $normalizedPrefix = trim($prefix ?? Url::getPrefix(), '/');
        while (
            $normalizedPrefix !== ''
            && ($path === $normalizedPrefix || str_starts_with($path, $normalizedPrefix . '/'))
        ) {
            $path = ltrim(substr($path, strlen($normalizedPrefix)), '/');
        }

        return ['url' => '', 'url_path' => $path];
    }
}
