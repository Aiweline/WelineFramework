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

    /**
     * 设计主题只在基线上追加自己的增量（不整体替换基线）。
     * 通道与 layout-critical.css 平行，但语义是「baseline + addendum」：
     * 基线升级会自然送达每个主题，主题只需要维护差分部分。
     */
    private const ADDENDUM_SUFFIX = '-addendum.css';

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
        $shell = null;
        if ($overridePath !== null && !$this->isManagedSnapshot($overridePath)) {
            $contents = file_get_contents($overridePath);
            if (is_string($contents) && trim($contents) !== '') {
                $shell = $contents;
            }
        }

        if ($shell === null) {
            $baseline = BP . sprintf(self::BASELINE_RELATIVE, $area);
            $contents = is_readable($baseline) ? file_get_contents($baseline) : null;
            $shell = is_string($contents) ? $contents : '';
        }

        return $shell . $this->readAddendum($area);
    }

    /**
     * 主题增量（可选）：基线上追加，不替换。
     */
    private function readAddendum(string $area): string
    {
        $path = $this->resolveActiveThemeAddendumPath($area);
        if ($path === null) {
            return '';
        }
        $contents = file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? "\n" . $contents : '';
    }

    /**
     * 带 GENERATED_MARKER 的副本是物化器的托管快照（纯基线的函数），不是主题自持文件。
     * 读它等于读基线（且可能过期），所以显式走基线：去掉标记才算主题接管。
     */
    private function isManagedSnapshot(string $path): bool
    {
        $contents = @file_get_contents($path, false, null, 0, 2048);
        if (!is_string($contents) || $contents === '') {
            return false;
        }

        return str_contains($contents, LayoutCriticalCssMaterializer::GENERATED_MARKER);
    }

    private function resolveActiveThemeAddendumPath(string $area): ?string
    {
        $basePath = $this->activeThemeBasePath();
        if ($basePath === null) {
            return null;
        }

        $candidates = [
            $basePath . DIRECTORY_SEPARATOR . $area . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'layout-critical' . self::ADDENDUM_SUFFIX,
            $basePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'layout-critical-' . $area . self::ADDENDUM_SUFFIX,
        ];

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveActiveThemeOverridePath(string $area): ?string
    {
        $basePath = $this->activeThemeBasePath();
        if ($basePath === null) {
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

    private function activeThemeBasePath(): ?string
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

        return $basePath === '' ? null : $basePath;
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));

        return $area === 'backend' ? 'backend' : 'frontend';
    }
}
