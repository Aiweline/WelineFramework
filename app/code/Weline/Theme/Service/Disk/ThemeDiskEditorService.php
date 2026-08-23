<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Disk;

use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * Theme-disk CRUD against ThemeData (disk_* only) + compile.
 */
class ThemeDiskEditorService
{
    public function __construct(
        private readonly ThemeTokenCatalogService $catalogService,
        private readonly ThemeDiskCompileService $compileService,
    ) {
    }

    public function getTokensPayload(WelineTheme $theme, string $area, string $scope = 'default'): array
    {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $catalog = $this->catalogService->getCatalog($area, $theme);
        $state = $this->catalogService->getActiveAndCustom($area, $scope);

        return [
            'theme_id' => (int)$theme->getId(),
            'area' => ThemeDiskKeys::normalizeArea($area),
            'scope' => ThemeDiskKeys::normalizeScope($scope),
            'catalog' => $catalog,
            'state' => $state,
        ];
    }

    /**
     * @param array<string, string> $tokens
     */
    public function saveCustom(
        WelineTheme $theme,
        string $area,
        string $panel,
        string $diskKey,
        string $name,
        string $baseFile,
        array $tokens,
        string $scope = 'default',
        bool $select = true
    ): array {
        $prepared = $this->prepareCustom(
            $theme,
            $area,
            $panel,
            $diskKey,
            $name,
            $baseFile,
            $tokens,
        );
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $area = ThemeDiskKeys::normalizeArea($area);
        $panel = (string)$prepared['panel'];
        $diskKey = (string)$prepared['disk_key'];
        $scope = ThemeDiskKeys::normalizeScope($scope);
        $payload = (array)$prepared['payload'];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $this->assertMetaValueWritable($encoded);

        $ok = ThemeData::set(
            ThemeDiskKeys::customIdentify($area, $panel, $diskKey),
            $encoded,
            $scope
        );
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        if (!$ok) {
            throw new \RuntimeException((string)__('保存主题盘失败：Meta 写入被拒绝（请检查 config_value 长度与主题上下文）'));
        }

        if ($select) {
            $selectOk = ThemeData::set(
                ThemeDiskKeys::activeIdentify($area, $panel),
                ThemeDiskKeys::customRef($diskKey),
                $scope
            );
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            if (!$selectOk) {
                throw new \RuntimeException((string)__('选用主题盘失败'));
            }
        }

        $compiled = $this->compileService->compileBundle($theme, $area, $scope);

        return [
            'disk_key' => $diskKey,
            'ref' => ThemeDiskKeys::customRef($diskKey),
            'compiled' => $compiled,
        ];
    }

    public function select(
        WelineTheme $theme,
        string $area,
        string $panel,
        string $ref,
        string $scope = 'default'
    ): array {
        $validated = $this->validateSelection($theme, $area, $panel, $ref);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $area = ThemeDiskKeys::normalizeArea($area);
        $panel = (string)$validated['panel'];
        $scope = ThemeDiskKeys::normalizeScope($scope);
        $normalized = (string)$validated['active'];

        $ok = ThemeData::set(ThemeDiskKeys::activeIdentify($area, $panel), $normalized, $scope);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        if (!$ok) {
            throw new \RuntimeException((string)__('选用主题盘失败'));
        }

        $compiled = $this->compileService->compileBundle($theme, $area, $scope);

        return [
            'active' => $normalized,
            'compiled' => $compiled,
        ];
    }

    public function deleteCustom(
        WelineTheme $theme,
        string $area,
        string $panel,
        string $diskKey,
        string $scope = 'default'
    ): array {
        $validated = $this->validateDelete($area, $panel, $diskKey);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        $area = ThemeDiskKeys::normalizeArea($area);
        $panel = (string)$validated['panel'];
        $diskKey = (string)$validated['disk_key'];
        $scope = ThemeDiskKeys::normalizeScope($scope);

        ThemeData::set(ThemeDiskKeys::customIdentify($area, $panel, $diskKey), '', $scope);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $activeMap = ThemeData::getConfigList($area, 'disk_active', $scope);
        $active = (string)($activeMap[$panel] ?? '');
        if ($active === ThemeDiskKeys::customRef($diskKey)) {
            ThemeData::set(ThemeDiskKeys::activeIdentify($area, $panel), '', $scope);
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
        }

        $compiled = $this->compileService->compileBundle($theme, $area, $scope);

        return ['compiled' => $compiled];
    }

    /**
     * Normalize a custom disk without mutating Meta or generated assets.
     * Scoped drafts use this method; saveCustom() is publication compatibility.
     *
     * @param array<string,mixed> $tokens
     * @return array{panel:string,disk_key:string,ref:string,payload:array<string,mixed>}
     */
    public function prepareCustom(
        WelineTheme $theme,
        string $area,
        string $panel,
        string $diskKey,
        string $name,
        string $baseFile,
        array $tokens,
    ): array {
        $area = ThemeDiskKeys::normalizeArea($area);
        $panel = ThemeDiskKeys::normalizePanel($panel);
        $diskKey = ThemeDiskKeys::normalizeDiskKey($diskKey);
        $payload = [
            'name' => $name !== '' ? $name : $diskKey,
            'base_file' => $baseFile,
            'disk_kind' => $panel === ThemeDiskKeys::PANEL_COLOR ? 'colors' : 'variables',
            'tokens' => $this->toDeltaTokens(
                $theme,
                $area,
                $panel,
                $baseFile,
                $this->normalizeTokens($tokens),
            ),
        ];
        $this->assertMetaValueWritable(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return [
            'panel' => $panel,
            'disk_key' => $diskKey,
            'ref' => ThemeDiskKeys::customRef($diskKey),
            'payload' => $payload,
        ];
    }

    /** @return array{panel:string,active:string} */
    public function validateSelection(
        WelineTheme $theme,
        string $area,
        string $panel,
        string $ref,
    ): array {
        $area = ThemeDiskKeys::normalizeArea($area);
        $panel = ThemeDiskKeys::normalizePanel($panel);
        $parsed = ThemeDiskKeys::parseActiveRef($ref);
        if (($parsed['kind'] ?? '') === '') {
            throw new \InvalidArgumentException((string)__('无效的盘引用'));
        }
        $normalized = $parsed['kind'] === 'custom'
            ? ThemeDiskKeys::customRef((string)$parsed['key'])
            : ThemeDiskKeys::fileRef((string)$parsed['key']);
        if ($parsed['kind'] === 'file') {
            $exists = false;
            foreach ($this->catalogService->getCatalog($area, $theme)['panels'][$panel]['disks'] ?? [] as $disk) {
                if (\is_array($disk) && (string)($disk['ref'] ?? '') === $normalized) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                throw new \InvalidArgumentException((string)__('主题盘不存在'));
            }
        }

        return ['panel' => $panel, 'active' => $normalized];
    }

    /** @return array{panel:string,disk_key:string} */
    public function validateDelete(string $area, string $panel, string $diskKey): array
    {
        ThemeDiskKeys::normalizeArea($area);

        return [
            'panel' => ThemeDiskKeys::normalizePanel($panel),
            'disk_key' => ThemeDiskKeys::normalizeDiskKey($diskKey),
        ];
    }

    /**
     * @param array<string, mixed> $tokens
     * @return array<string, string>
     */
    private function normalizeTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $cssName = trim((string)$name);
            if ($cssName === '') {
                continue;
            }
            if (!str_starts_with($cssName, '--')) {
                $cssName = '--' . ltrim($cssName, '-');
            }
            if (!preg_match('/^--[A-Za-z0-9_-]+$/', $cssName)) {
                continue;
            }
            $out[$cssName] = (string)$value;
        }

        return $out;
    }

    /**
     * Persist only tokens that differ from the native base disk.
     *
     * @param array<string, string> $tokens
     * @return array<string, string>
     */
    private function toDeltaTokens(
        WelineTheme $theme,
        string $area,
        string $panel,
        string $baseFile,
        array $tokens
    ): array {
        if ($tokens === [] || $baseFile === '') {
            return $tokens;
        }

        $base = $this->nativeTokenMap($theme, $area, $panel, $baseFile);
        if ($base === []) {
            return $tokens;
        }

        $delta = [];
        foreach ($tokens as $name => $value) {
            if (!array_key_exists($name, $base) || (string)$base[$name] !== (string)$value) {
                $delta[$name] = $value;
            }
        }

        return $delta;
    }

    /**
     * @return array<string, string>
     */
    private function nativeTokenMap(WelineTheme $theme, string $area, string $panel, string $baseFile): array
    {
        $catalog = $this->catalogService->getCatalog($area, $theme);
        $disks = $catalog['panels'][$panel]['disks'] ?? [];
        if (!is_array($disks)) {
            return [];
        }
        $needle = ltrim($baseFile, '_');
        foreach ($disks as $disk) {
            if (!is_array($disk)) {
                continue;
            }
            $key = ltrim((string)($disk['key'] ?? ''), '_');
            if ($key !== $needle) {
                continue;
            }
            $map = [];
            foreach ((array)($disk['tokens'] ?? []) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                $name = trim((string)($token['variable_name'] ?? $token['name'] ?? ''));
                if ($name === '' || !str_starts_with($name, '--')) {
                    continue;
                }
                $map[$name] = (string)($token['default_value'] ?? $token['value'] ?? '');
            }

            return $map;
        }

        return [];
    }

    private function assertMetaValueWritable(string $value): void
    {
        $max = \Weline\Meta\Api\Data\MetaConfigIdentity::CONFIG_VALUE_MAX_CHARS;
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max) {
            throw new \RuntimeException((string)__(
                '保存主题盘失败：配置值过长（%{len}/%{max}），请减少变更 Token 或缩短取值',
                ['len' => $length, 'max' => $max]
            ));
        }
    }
}
