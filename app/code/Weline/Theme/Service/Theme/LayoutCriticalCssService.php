<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Theme;

use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * Inline layout-critical CSS for first-paint shell stability (FOUC gate companion).
 */
final class LayoutCriticalCssService
{
    private const BASELINE_RELATIVE = 'app/code/Weline/Theme/view/ui/css/layout-critical-%s.css';

    public function renderInlineStyle(string $area): string
    {
        $area = $this->normalizeArea($area);
        $css = $this->readCriticalCss($area);
        if ($css === '') {
            return '';
        }

        $css = str_replace('</style', '<\\/style', $css);

        return '<style data-weline-layout-critical>' . $css . '</style>';
    }

    private function readCriticalCss(string $area): string
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
