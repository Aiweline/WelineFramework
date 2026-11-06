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

    /**
     * Refresh every auto-materialized design-theme copy to the current baseline.
     *
     * Generated bodies are a pure function of the baseline, so this needs no theme
     * model and no DB access — safe to re-run from `theme:compile layoutCritical`.
     * Fork themes (which removed the marker to take the file over) are reported but
     * never written: once a theme owns layout-critical, the framework must not
     * overwrite it.
     *
     * @return array{synced:list<string>,fork:list<string>,current:list<string>}
     */
    public function syncGeneratedCopies(): array
    {
        $result = ['synced' => [], 'fork' => [], 'current' => []];

        foreach ($this->designCriticalPaths() as $target) {
            $area = $this->extractArea($target);
            if ($area === null) {
                continue;
            }

            $baselinePath = BP . sprintf(self::BASELINE_RELATIVE, $area);
            if (!is_readable($baselinePath)) {
                continue;
            }

            $baseline = (string)file_get_contents($baselinePath);
            if (trim($baseline) === '') {
                continue;
            }
            $baselineSha = hash('sha256', $baseline);

            $existing = is_readable($target) ? (string)file_get_contents($target) : '';
            if (!$this->isGeneratedPayload($existing)) {
                $result['fork'][] = $target;
                continue;
            }
            if ($this->extractBaselineSha($existing) === $baselineSha) {
                $result['current'][] = $target;
                continue;
            }

            $body = $this->buildGeneratedBody($baseline, $baselineSha, $area);
            if (@file_put_contents($target, $body) === false) {
                throw new \RuntimeException('layout_critical_materialize_write_failed:' . $target);
            }
            $result['synced'][] = $target;
        }

        return $result;
    }

    /** @return list<string> */
    private function designCriticalPaths(): array
    {
        $paths = [];
        foreach ([BP . 'app/design', BP . 'design'] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (['frontend', 'backend'] as $area) {
                foreach (glob($root . '/*/*/' . $area . '/assets/css/layout-critical.css') ?: [] as $path) {
                    $paths[$path] = $path;
                }
            }
        }

        return array_values($paths);
    }

    private function extractArea(string $target): ?string
    {
        if (preg_match('!/(frontend|backend)/assets/css/layout-critical\.css$!', str_replace('\\', '/', $target), $m) === 1) {
            return $m[1];
        }

        return null;
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
