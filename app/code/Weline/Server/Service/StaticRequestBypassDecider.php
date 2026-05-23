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

    public static function shouldDeferToFramework(string $candidateUri): bool
    {
        $candidateUri = \trim(\str_replace('\\', '/', $candidateUri), '/');
        if ($candidateUri === '') {
            return false;
        }

        $isThemeViewAsset = \str_contains($candidateUri, '/view/theme/frontend/')
            || \str_contains($candidateUri, '/view/theme/backend/');
        if ($isThemeViewAsset) {
            return true;
        }

        if (\str_starts_with($candidateUri, 'static/') || \str_starts_with($candidateUri, 'pub/static/')) {
            return false;
        }

        return false;
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
}
