<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Disk;

use Weline\Framework\App\Env;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * Compiles theme-level disk Meta into one generated override CSS file per theme/area/scope.
 */
class ThemeDiskCompileService
{
    private const MARKER = 'Weline Theme disk override v1';

    public function __construct(
        private readonly ThemeTokenCatalogService $catalogService,
        private readonly CssVariableInjector $variableInjector,
    ) {
    }

    public function diskRoot(): string
    {
        return rtrim(Env::GENERATED_DIR, '\\/') . DS . 'theme' . DS . 'disks';
    }

    public function diskDirectory(int $themeId, string $area, string $scope = 'default'): string
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);

        return $this->diskRoot() . DS . $themeId . DS . $area . DS . $scope;
    }

    /**
     * @return array{hash: string, path: string, url_query: array, empty: bool}
     */
    public function compileBundle(WelineTheme $theme, string $area, string $scope = 'default'): array
    {
        $themeId = (int)$theme->getId();
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $tokens = $this->collectOverrideTokens($area, $scope);
        $css = $this->renderCss($tokens);
        $hash = substr(hash('sha256', $css), 0, 16);

        $empty = $tokens === [];
        if ($empty) {
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            $saved = ThemeData::set(ThemeDiskKeys::bundleIdentify($area, $scope), '', $scope);
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            if (!$saved) {
                throw new \RuntimeException('Unable to persist empty theme disk bundle hash');
            }
            return [
                'hash' => '',
                'path' => '',
                'url_query' => [],
                'empty' => true,
            ];
        }

        $dir = $this->diskDirectory($themeId, $area, $scope);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create theme disk directory: ' . $dir);
        }
        $path = $dir . DS . 'override.' . $hash . '.css';
        if (!is_file($path)) {
            $temporaryPath = tempnam($dir, '.override-');
            if (!is_string($temporaryPath) || $temporaryPath === '') {
                throw new \RuntimeException('Unable to allocate temporary theme disk override');
            }
            try {
                if (file_put_contents($temporaryPath, $css, LOCK_EX) === false) {
                    throw new \RuntimeException('Unable to write theme disk override: ' . $temporaryPath);
                }
                if (!rename($temporaryPath, $path)) {
                    throw new \RuntimeException('Unable to publish theme disk override: ' . $path);
                }
            } finally {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $saved = ThemeData::set(ThemeDiskKeys::bundleIdentify($area, $scope), $hash, $scope);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        if (!$saved) {
            throw new \RuntimeException('Unable to persist theme disk bundle hash');
        }

        // Content-addressed predecessors are intentionally retained. compileBundle()
        // can run inside a wider database transaction; deleting the previous file
        // here would break the last committed release if that transaction rolls back.

        return [
            'hash' => $hash,
            'path' => $path,
            'url_query' => [
                'theme_id' => $themeId,
                'area' => $area,
                'scope' => $scope,
                'h' => $hash,
            ],
            'empty' => false,
        ];
    }

    public function resolveBundlePath(int $themeId, string $area, string $scope, string $hash): string
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);
        $hash = preg_replace('/[^a-f0-9]/', '', strtolower($hash)) ?? '';
        if ($themeId < 1 || $hash === '') {
            return '';
        }
        $path = $this->diskDirectory($themeId, $area, $scope) . DS . 'override.' . $hash . '.css';

        return is_file($path) ? $path : '';
    }

    public function hasCustomActive(string $area, string $scope = 'default'): bool
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);
        $activeList = ThemeData::getConfigList($area, 'disk_active', $scope);
        foreach ($activeList as $ref) {
            $ref = is_string($ref) ? $ref : '';
            if (str_starts_with($ref, 'custom:')) {
                return true;
            }
        }

        // Also accept legacy element-level overrides as reason to keep a bundle.
        return $this->collectLegacyElementTokens($area, $scope) !== [];
    }

    /**
     * @return array<string, string> CSS var name => value
     */
    public function collectOverrideTokens(string $area, string $scope = 'default'): array
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);

        $merged = $this->collectLegacyElementTokens($area, $scope);

        $activeList = ThemeData::getConfigList($area, 'disk_active', $scope);
        $customList = ThemeData::getConfigList($area, 'disk_custom', $scope);

        foreach ($activeList as $panel => $ref) {
            $panel = ThemeDiskKeys::normalizePanel((string)$panel);
            $parsed = ThemeDiskKeys::parseActiveRef(is_string($ref) ? $ref : '');
            if (($parsed['kind'] ?? '') !== 'custom') {
                continue;
            }
            $diskKey = (string)($parsed['key'] ?? '');
            $payload = $customList[$panel . '.' . $diskKey] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            $tokens = $payload['tokens'] ?? [];
            if (!is_array($tokens)) {
                continue;
            }
            foreach ($tokens as $name => $value) {
                $cssName = $this->normalizeTokenName((string)$name);
                if ($cssName === '' || !is_scalar($value)) {
                    continue;
                }
                $merged[$cssName] = (string)$value;
            }
        }

        $safe = [];
        foreach ($merged as $name => $value) {
            if (!$this->variableInjector->isLateSafeToken($name)) {
                continue;
            }
            $safe[$name] = $value;
        }

        ksort($safe);

        return $safe;
    }

    /**
     * @return array<string, string>
     */
    private function collectLegacyElementTokens(string $area, string $scope): array
    {
        $variables = [];
        $configList = ThemeData::getConfigList($area, 'variables', $scope);
        foreach ($configList as $configKey => $configValue) {
            if (!preg_match('/^([^.]+)\.([^.]+)\.value$/', (string)$configKey, $matches)
                && !preg_match('/^([^.]+)\.([^.]+)$/', (string)$configKey, $matches)) {
                continue;
            }
            $variableName = $matches[2];
            $cssVarName = str_starts_with($variableName, '--') ? $variableName : '--' . $variableName;
            $value = is_array($configValue) ? json_encode($configValue) : (string)$configValue;
            if ($value === '') {
                continue;
            }
            $variables[$cssVarName] = $value;
        }

        return $variables;
    }

    /**
     * @param array<string, string> $tokens
     */
    private function renderCss(array $tokens): string
    {
        if ($tokens === []) {
            return '/* ' . self::MARKER . ': empty */' . "\n";
        }

        $lines = ['/* ' . self::MARKER . ' */', ':root {'];
        foreach ($tokens as $name => $value) {
            $lines[] = '    ' . $name . ': ' . $value . ';';
        }
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function normalizeTokenName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (!str_starts_with($name, '--')) {
            $name = '--' . ltrim($name, '-');
        }

        return preg_match('/^--[A-Za-z0-9_-]+$/', $name) ? $name : '';
    }
}
