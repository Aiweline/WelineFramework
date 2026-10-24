<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Helper\LayoutScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * Storefront layout ownership: which page types the module ships vs. which a design theme declares.
 *
 * Module-owned page types live in Weline_Theme's own {@code view/theme/frontend/layouts/} and keep
 * their module allowlists. A theme-declared page type is a {@code layouts/{type}/{option}.phtml}
 * that only a design layer of the active frontend theme chain ships — the theme owns its path,
 * label ({@code @meta.name}) and body; modules never hardcode those types.
 */
class ThemeLayoutLocator
{
    private const TYPE_PATTERN = '#^[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*$#D';
    private const OPTION_PATTERN = '#^[a-z0-9][a-z0-9_-]*$#D';

    public function __construct(
        private readonly ThemeDirectoryResolver $themeDirectoryResolver,
    ) {
    }

    public function moduleDefaultFile(string $layoutType, string $layoutOption = 'default'): ?string
    {
        if (!$this->isValid($layoutType, $layoutOption)) {
            return null;
        }
        $file = $this->moduleLayoutsRoot() . $layoutType . '/' . $layoutOption . '.phtml';

        return is_file($file) ? $file : null;
    }

    /**
     * @return string|null Absolute file of the nearest design layer, or null when the module ships the type.
     */
    public function themeDeclaredFile(string $layoutType, string $layoutOption = 'default', ?WelineTheme $theme = null): ?string
    {
        if ($this->moduleDefaultFile($layoutType, $layoutOption) !== null || !$this->isValid($layoutType, $layoutOption)) {
            return null;
        }
        $relative = DS . 'layouts' . DS . str_replace('/', DS, $layoutType) . DS . $layoutOption . '.phtml';
        foreach ($this->themeDirectoryResolver->getAreaDirectories('frontend', $theme) as $directory) {
            if (($directory['layer_type'] ?? '') !== 'theme') {
                continue;
            }
            $file = rtrim((string)$directory['path'], '\\/') . $relative;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    public function themeDeclaredTitle(string $layoutType, string $layoutOption = 'default', ?WelineTheme $theme = null): string
    {
        $file = $this->themeDeclaredFile($layoutType, $layoutOption, $theme);

        return $file === null ? '' : (string)(LayoutScanner::extractLayoutMeta($file, 'frontend')['name'] ?? '');
    }

    private function isValid(string $layoutType, string $layoutOption): bool
    {
        return preg_match(self::TYPE_PATTERN, $layoutType) === 1
            && preg_match(self::OPTION_PATTERN, $layoutOption) === 1;
    }

    private function moduleLayoutsRoot(): string
    {
        return dirname(__DIR__) . '/view/theme/frontend/layouts/';
    }
}
