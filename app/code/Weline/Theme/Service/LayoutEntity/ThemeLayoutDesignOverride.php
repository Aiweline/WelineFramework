<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * Whether a theme's own design layer supplies a layout option (vs inheriting parent/module).
 * Shared by storefront resolution and upgrade solidify collect (skipped_inherit).
 */
final class ThemeLayoutDesignOverride
{
    public static function provides(
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $area,
    ): bool {
        if ($themeId < 1 || trim($layoutType) === '') {
            return false;
        }
        try {
            $theme = (clone ObjectManager::getInstance(WelineTheme::class))->clearData()->clearQuery()->load($themeId);
            if ((int)$theme->getId() !== $themeId) {
                return false;
            }
            $themePath = rtrim((string)$theme->getPath(), "/\\");
            $options = LayoutScanner::scanLayouts($theme, $area)[$layoutType] ?? [];
            foreach ($options as $option) {
                if (!\is_array($option) || (string)($option['value'] ?? '') !== $layoutOption) {
                    continue;
                }
                $layerKey = (string)($option['layer_key'] ?? '');
                if ($layerKey === 'theme:' . $themeId) {
                    return true;
                }
                $path = (string)($option['path'] ?? '');
                if ($themePath !== '' && $path !== '' && str_starts_with($path, $themePath . DIRECTORY_SEPARATOR)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
