<?php

declare(strict_types=1);

namespace Weline\Server\Service;

final class StaticRequestBypassDecider
{
    private const FAST_MISSING_STATIC_EXTENSIONS = [
        'avif' => true,
        'bmp' => true,
        'css' => true,
        'eot' => true,
        'gif' => true,
        'ico' => true,
        'jpeg' => true,
        'jpg' => true,
        'js' => true,
        'map' => true,
        'otf' => true,
        'png' => true,
        'svg' => true,
        'ttf' => true,
        'webp' => true,
        'woff' => true,
        'woff2' => true,
    ];

    public static function shouldDeferToFramework(string $candidateUri, string $requestUri = ''): bool
    {
        $candidateUri = \trim(\str_replace('\\', '/', $candidateUri), '/');
        if ($candidateUri === '') {
            return false;
        }

        $isThemeViewAsset = \str_contains($candidateUri, '/view/theme/frontend/')
            || \str_contains($candidateUri, '/view/theme/backend/');
        if (!$isThemeViewAsset) {
            return false;
        }

        return self::isExplicitPreviewRequest($candidateUri)
            || self::isExplicitPreviewRequest($requestUri)
            || self::hasExplicitPreviewQuery($requestUri);
    }

    public static function shouldReturnFastMissingStatic(string $candidateUri): bool
    {
        $candidateUri = \trim(\str_replace('\\', '/', $candidateUri), '/');
        if ($candidateUri === '') {
            return false;
        }

        $extension = \strtolower(\pathinfo($candidateUri, PATHINFO_EXTENSION));
        if (!(self::FAST_MISSING_STATIC_EXTENSIONS[$extension] ?? false)) {
            return false;
        }

        if (\str_starts_with($candidateUri, 'static/')
            || \str_starts_with($candidateUri, 'pub/static/')
            || \str_starts_with($candidateUri, 'statics/')
            || \str_starts_with($candidateUri, 'theme_previews/')
            || \str_starts_with($candidateUri, 'media/')
            || \str_starts_with($candidateUri, '.well-known/')
            || \str_starts_with($candidateUri, 'errors/')
        ) {
            return true;
        }

        return \preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/view/(?:templates/.+/asset|statics)/#', $candidateUri) === 1;
    }

    private static function isExplicitPreviewRequest(string $uri): bool
    {
        $uri = \trim(\str_replace('\\', '/', $uri), '/');
        if ($uri === '') {
            return false;
        }

        $path = (string)(\parse_url($uri, PHP_URL_PATH) ?: $uri);
        $path = \trim(\str_replace('\\', '/', $path), '/');

        return \str_starts_with($path, 'static/__preview/')
            || \str_starts_with($path, 'pub/static/__preview/')
            || \str_starts_with($path, '__preview/')
            || \str_starts_with($path, 'theme_previews/')
            || \str_contains($path, '/__preview/')
            || \str_contains($path, '/theme_previews/');
    }

    private static function hasExplicitPreviewQuery(string $uri): bool
    {
        $query = (string)(\parse_url($uri, PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return false;
        }

        \parse_str($query, $params);
        foreach ([
            'preview',
            'preview_theme',
            'preview_theme_id',
            'theme_preview',
            'weline_preview_token',
            'virtual_theme_id',
            'frontend_theme_id',
            'backend_theme_id',
            'visual_editor',
        ] as $key) {
            if (\array_key_exists($key, $params) && \trim((string)$params[$key]) !== '') {
                return true;
            }
        }

        return false;
    }
}
