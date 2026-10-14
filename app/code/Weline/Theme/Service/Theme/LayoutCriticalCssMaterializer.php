<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Theme;

use Weline\Theme\Model\WelineTheme;

/**
 * Materialize per-design-theme layout-critical.css from the shared baseline.
 *
 * Base Weline_Theme shell skips write (runtime reads view/ui baseline).
 * Design themes get {area}/assets/css/layout-critical.css under their own tree
 * so sites/themes do not share a mutable critical source.
 */
final class LayoutCriticalCssMaterializer
{
    public const GENERATED_MARKER = 'weline-layout-critical:generated';

    private const BASELINE_RELATIVE = 'app/code/Weline/Theme/view/ui/css/layout-critical-%s.css';

    /**
     * @return list<string> absolute paths written or refreshed
     */
    public function materializeForTheme(WelineTheme $theme, ?string $area = null): array
    {
        if (!$this->isDesignTheme($theme)) {
            return [];
        }

        $areas = $area === null
            ? ['frontend', 'backend']
            : [$this->normalizeArea($area)];

        $written = [];
        foreach ($areas as $normalizedArea) {
            $path = $this->materializeArea($theme, $normalizedArea);
            if ($path !== null) {
                $written[] = $path;
            }
        }

        return $written;
    }

    private function materializeArea(WelineTheme $theme, string $area): ?string
    {
        $baselinePath = BP . sprintf(self::BASELINE_RELATIVE, $area);
        if (!is_readable($baselinePath)) {
            return null;
        }

        $baseline = file_get_contents($baselinePath);
        if (!is_string($baseline) || trim($baseline) === '') {
            return null;
        }

        $baselineSha = hash('sha256', $baseline);
        $target = $this->resolveTargetPath($theme, $area);
        if ($target === null) {
            return null;
        }

        if (is_readable($target)) {
            $existing = (string)file_get_contents($target);
            if (!$this->isGeneratedPayload($existing)) {
                return null;
            }
            $existingSha = $this->extractBaselineSha($existing);
            if ($existingSha === $baselineSha) {
                return null;
            }
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('layout_critical_materialize_mkdir_failed:' . $dir);
        }

        $body = $this->buildGeneratedBody($baseline, $baselineSha, $area);
        if (@file_put_contents($target, $body) === false) {
            throw new \RuntimeException('layout_critical_materialize_write_failed:' . $target);
        }

        return $target;
    }

    public function isDesignTheme(WelineTheme $theme): bool
    {
        if ((int)$theme->getId() < 1) {
            return false;
        }

        try {
            $path = str_replace('\\', '/', (string)$theme->getPath());
        } catch (\Throwable) {
            $path = '';
        }

        if ($path === '') {
            return false;
        }

        if (str_contains($path, '/Weline/Theme/view/theme')) {
            return false;
        }

        if (str_contains($path, '/app/design/')) {
            return true;
        }

        try {
            $module = (string)$theme->getModuleName();
        } catch (\Throwable) {
            $module = '';
        }

        return $module !== '' && $module !== 'Weline_Theme';
    }

    private function resolveTargetPath(WelineTheme $theme, string $area): ?string
    {
        try {
            $basePath = rtrim((string)$theme->getPath(), '/\\');
        } catch (\Throwable) {
            $basePath = '';
        }
        if ($basePath === '') {
            return null;
        }

        return $basePath
            . DIRECTORY_SEPARATOR . $area
            . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . 'css'
            . DIRECTORY_SEPARATOR . 'layout-critical.css';
    }

    private function buildGeneratedBody(string $baseline, string $baselineSha, string $area): string
    {
        $header = "/* " . self::GENERATED_MARKER . " area={$area} baselineSha={$baselineSha} */\n"
            . "/* Source: Weline_Theme view/ui/css/layout-critical-{$area}.css — do not hand-edit unless removing this marker. */\n";

        return $header . rtrim($baseline) . "\n";
    }

    private function isGeneratedPayload(string $contents): bool
    {
        return str_contains($contents, self::GENERATED_MARKER);
    }

    private function extractBaselineSha(string $contents): ?string
    {
        if (preg_match('/baselineSha=([a-f0-9]{64})/', $contents, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));

        return $area === 'backend' ? 'backend' : 'frontend';
    }
}
