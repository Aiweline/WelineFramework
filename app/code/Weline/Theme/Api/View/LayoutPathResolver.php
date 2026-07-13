<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

use Weline\Theme\Helper\LayoutPathResolver as InternalLayoutPathResolver;
use Weline\Theme\Model\WelineTheme;

/**
 * Stable, allocation-free layout path API for other modules.
 *
 * Theme discovery remains internal; consumers exchange only strings and an
 * already-resolved theme object.
 */
final class LayoutPathResolver
{
    public static function buildLayoutPath(
        string $originalPath,
        string $area,
        string $layoutType,
        string $layoutOption,
    ): string {
        return InternalLayoutPathResolver::buildLayoutPath($originalPath, $area, $layoutType, $layoutOption);
    }

    public static function resolveLayoutTemplate(string $layoutPath, object $theme, string $area): ?string
    {
        return InternalLayoutPathResolver::resolveLayoutTemplate(
            $layoutPath,
            self::assertTheme($theme),
            $area,
        );
    }

    public static function getDefaultLayoutPath(string $layoutPath, string $area): ?string
    {
        return InternalLayoutPathResolver::getDefaultLayoutPath($layoutPath, $area);
    }

    public static function getLayoutFilePath(string $modulePath, object $theme, string $area): ?string
    {
        return InternalLayoutPathResolver::getLayoutFilePath(
            $modulePath,
            self::assertTheme($theme),
            $area,
        );
    }

    private static function assertTheme(object $theme): WelineTheme
    {
        if (!$theme instanceof WelineTheme) {
            throw new \TypeError('Theme layout path resolution requires a WelineTheme instance.');
        }

        return $theme;
    }
}
