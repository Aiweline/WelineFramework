<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Framework\App\State;
use Weline\Framework\Http\Url;

/**
 * Storefront /CURRENCY/locale path prefix for social-login OAuth (not Google redirect_uri).
 */
final class SocialLoginStorefrontLocale
{
    public static function currentPrefix(): string
    {
        return self::normalize((string) Url::getPrefix());
    }

    public static function firstNonEmpty(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $fromUrl = self::prefixFromUrl($candidate);
            if ($fromUrl !== '') {
                return $fromUrl;
            }
        }

        return '';
    }

    public static function prefixFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $cut = strpbrk($url, '?#');
            $path = $cut === false ? $url : substr($url, 0, -strlen($cut) ?: strlen($url));
        }
        $segments = array_values(array_filter(
            explode('/', trim((string) $path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        if ($segments === []) {
            return '';
        }
        $loc = State::resolveLocalizationFromPathSegments(array_slice($segments, 0, 3));
        $consumed = (int) ($loc['consumed'] ?? 0);
        $offset = (int) ($loc['area_offset'] ?? 0);
        if ($consumed <= 0) {
            return '';
        }
        $prefixSegments = array_slice($segments, $offset, $consumed);

        return self::normalize('/' . implode('/', $prefixSegments));
    }

    public static function normalize(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }
        if ($prefix[0] !== '/') {
            $prefix = '/' . $prefix;
        }

        return rtrim($prefix, '/');
    }

    public static function languageFromPrefix(string $prefix): string
    {
        $prefix = self::normalize($prefix);
        if ($prefix === '') {
            return '';
        }
        $segments = array_values(array_filter(
            explode('/', trim($prefix, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        $loc = State::resolveLocalizationFromPathSegments($segments);

        return (string) ($loc['language'] ?? '');
    }
}
