<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Theme;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Minify\StaticAssetMinifier;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceConfig;

/**
 * Inline layout-critical CSS for first-paint shell stability (FOUC gate companion).
 *
 * Prefers design-theme owned {area}/assets/css/layout-critical.css, then baseline.
 * When resource_files/css_minify is on, minify before inline.
 */
final class LayoutCriticalCssService
{
    private const BASELINE_RELATIVE = 'app/code/Weline/Theme/view/ui/css/layout-critical-%s.css';

    /**
     * First-paint primitives (`.w-container`, `.w-grid`, `.w-stack` …).
     * Composed in front of the theme shell so critical CSS has one source of truth
     * (no duplicated copies drifting between inline and bundled CSS).
     */
    private const BASELINE_PRIMITIVES_RELATIVE = 'app/code/Weline/Theme/view/ui/css/critical-utilities.css';

    public function renderInlineStyle(string $area): string
    {
        $area = $this->normalizeArea($area);
        $css = $this->readCriticalCss($area);
        if ($css === '') {
            return '';
        }

        if ($this->shouldMinify($area)) {
            try {
                /** @var StaticAssetMinifier $minifier */
                $minifier = ObjectManager::getInstance(StaticAssetMinifier::class);
                $css = $minifier->minifyFileContent($css, 'css');
            } catch (\Throwable) {
                // Keep original critical bytes when minify cannot prove safety.
            }
        }

        $css = str_replace('</style', '<\\/style', $css);

        return '<style data-weline-layout-critical>' . $css . '</style>';
    }

    private function shouldMinify(string $area): bool
    {
        try {
            /** @var ThemeResourceConfig $config */
            $config = ObjectManager::getInstance(ThemeResourceConfig::class);
            $theme = null;
            try {
                $theme = ThemeData::getCurrentTheme();
            } catch (\Throwable) {
                $theme = null;
            }

            return !empty($config->resolve(
                $theme instanceof WelineTheme ? $theme : null,
                $area
            )['css_minify']);
        } catch (\Throwable) {
            return defined('PROD') && PROD;
        }
    }

    private function readCriticalCss(string $area): string
    {
        $parts = [];

        $primitives = BP . self::BASELINE_PRIMITIVES_RELATIVE;
        if (is_readable($primitives)) {
            $contents = file_get_contents($primitives);
            if (is_string($contents) && trim($contents) !== '') {
                $parts[] = $contents;
            }
        }

        $shell = $this->readShellCriticalCss($area);
        if ($shell !== '') {
            $parts[] = $shell;
        }

        return implode("\n", $parts);
    }

    private function readShellCriticalCss(string $area): string
    {
        $overridePath = $this->resolveActiveThemeOverridePath($area);
        if ($overridePath !== null && is_readable($overridePath)) {
            $contents = file_get_contents($overridePath);
            if (is_string($contents) && trim($contents) !== '') {
                return $contents;
            }
        }

        $baseline = BP . sprintf(self::BASELINE_RELATIVE, $area);
        if (!is_readable($baseline)) {
            return '';
        }

        $contents = file_get_contents($baseline);

        return is_string($contents) ? $contents : '';
    }

    private function resolveActiveThemeOverridePath(string $area): ?string
    {
        try {
            $theme = ThemeData::getCurrentTheme();
        } catch (\Throwable) {
            $theme = null;
        }
        if (!$theme instanceof WelineTheme) {
            return null;
        }

        $basePath = rtrim((string)$theme->getPath(), '/\\');
        if ($basePath === '') {
            return null;
        }

        $candidates = [
            $basePath . DIRECTORY_SEPARATOR . $area . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'layout-critical.css',
            $basePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'layout-critical-' . $area . '.css',
        ];

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));

        return $area === 'backend' ? 'backend' : 'frontend';
    }
}
