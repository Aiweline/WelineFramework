<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Theme\Service\TextileHeritageCatalog as ThemeTextileHeritageCatalog;

/**
 * @deprecated The reusable widget and its catalog are owned by Weline_Theme.
 *             This proxy only preserves already-persisted Product references.
 */
final class TextileHeritageCatalog
{
    public const TITLE = ThemeTextileHeritageCatalog::TITLE;

    public const IMAGE_BASE = ThemeTextileHeritageCatalog::IMAGE_BASE;

    /**
     * @return list<array<string, string>>
     */
    public static function items(): array
    {
        return ThemeTextileHeritageCatalog::items();
    }

    /**
     * @return list<array<string, string>>
     */
    public static function brandsConfig(): array
    {
        return ThemeTextileHeritageCatalog::brandsConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public static function widgetConfig(): array
    {
        return ThemeTextileHeritageCatalog::widgetConfig();
    }
}
