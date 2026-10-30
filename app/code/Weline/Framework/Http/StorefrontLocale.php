<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Storefront locale resolver for the 404 special-FPC path.
 *
 * Extracted from the retired `StorefrontNotFoundStaticPage` so the 404 page can be
 * rendered per language by the theme and cached in the special FPC store without
 * the old pub/errors static generation pipeline.
 *
 * Resolution priority: path segment → query(lang|locale|locale_code) →
 * WelineEnv::getLang() (RequestContext route.language) → DEFAULT_LANG.
 */
final class StorefrontLocale
{
    public const DEFAULT_LANG = MaintenanceStaticPage::DEFAULT_LANG;

    public static function resolveLang(
        string $path,
        string $queryString = '',
        string $cookieHeader = '',
    ): string {
        $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
        foreach (\explode('/', \trim($pathOnly, '/')) as $segment) {
            $lang = self::normalizeLangCode($segment);
            if ($lang !== '') {
                return $lang;
            }
        }

        if ($queryString !== '') {
            \parse_str($queryString, $queryParams);
            foreach (['locale', 'locale_code', 'lang'] as $key) {
                $lang = self::normalizeLangCode((string)($queryParams[$key] ?? ''));
                if ($lang !== '') {
                    return $lang;
                }
            }
        }

        // Path locale may already be stripped from request.path; RequestContext /
        // WelineEnv::getLang() holds the visitor-facing locale written at peel time.
        try {
            if (\class_exists(\Weline\Framework\Context::class, false)
                && \Weline\Framework\Context::getCurrent() !== null
                && \class_exists(\Weline\Framework\App\State::class, false)
            ) {
                $fromState = self::normalizeLangCode(
                    (string)\Weline\Framework\Env\WelineEnv::getLang()
                );
                if ($fromState !== '') {
                    return $fromState;
                }
            }
        } catch (\Throwable) {
        }

        // $cookieHeader retained for call-site compatibility; language cookies are ignored.
        unset($cookieHeader);

        return self::DEFAULT_LANG;
    }

    public static function resolveLangFromRequestUri(string $requestUri, string $cookieHeader = ''): string
    {
        $queryString = (string)(\parse_url($requestUri, \PHP_URL_QUERY) ?: '');
        $path = (string)(\parse_url($requestUri, \PHP_URL_PATH) ?: '/');

        return self::resolveLang($path, $queryString, $cookieHeader);
    }

    /**
     * @return list<string>
     */
    public static function langFallbackChain(string $lang): array
    {
        $chain = [];
        foreach ([$lang, self::DEFAULT_LANG, 'en_US'] as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '' || \in_array($candidate, $chain, true)) {
                continue;
            }
            $chain[] = $candidate;
        }

        return $chain;
    }

    public static function normalizeLangCode(string $code): string
    {
        $code = \trim($code);
        if ($code === '') {
            return '';
        }

        $normalized = \str_replace('-', '_', $code);
        if (\preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $normalized) === 1) {
            $parts = \explode('_', $normalized);
            $langPart = \strtolower($parts[0]);
            $scriptOrRegion = $parts[1] ?? '';
            if (\strlen($scriptOrRegion) === 4) {
                $scriptOrRegion = \ucfirst(\strtolower($scriptOrRegion));
            } else {
                $scriptOrRegion = \strtoupper($scriptOrRegion);
            }
            $region = isset($parts[2]) ? '_' . \strtoupper($parts[2]) : '';

            return $langPart . '_' . $scriptOrRegion . $region;
        }

        return MaintenanceStaticPage::normalizeLangCode($code);
    }
}
