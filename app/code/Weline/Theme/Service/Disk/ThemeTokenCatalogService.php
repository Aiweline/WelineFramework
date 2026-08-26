<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Disk;

use Weline\Theme\Helper\CssVariableParser;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceCatalog;

/**
 * Lists native theme disks (colors/variables) via Catalog + file @meta.
 */
class ThemeTokenCatalogService
{
    public function __construct(
        private readonly ThemeResourceCatalog $resourceCatalog,
    ) {
    }

    /**
     * @return array{panels: array<string, array>, disks: list<array>}
     */
    public function getCatalog(string $area, ?WelineTheme $theme = null, bool $includeDiskTokens = true): array
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        if ($theme) {
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
        }

        $disks = [];
        foreach ($this->resourceCatalog->getResources('colors', $area, $theme) as $resource) {
            $disk = $this->mapCssResource($resource, 'colors', $includeDiskTokens);
            if ($disk !== null) {
                $disks[] = $disk;
            }
        }
        foreach ($this->resourceCatalog->getResources('variables', $area, $theme) as $resource) {
            $disk = $this->mapCssResource($resource, 'variables', $includeDiskTokens);
            if ($disk !== null) {
                $disks[] = $disk;
            }
        }

        $panels = [];
        foreach ($disks as $disk) {
            if (!empty($disk['editor_hidden'])) {
                continue;
            }
            $panel = (string)$disk['panel'];
            $panels[$panel] ??= [
                'code' => $panel,
                'disks' => [],
            ];
            $panels[$panel]['disks'][] = $disk;
        }

        ksort($panels);

        return [
            'panels' => $panels,
            'disks' => $disks,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getDiskTokensForRef(
        string $area,
        ?WelineTheme $theme,
        string $panel,
        string $ref,
    ): array {
        $area = ThemeDiskKeys::normalizeArea($area);
        $panel = ThemeDiskKeys::normalizePanel($panel);
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException((string)__('无效的盘引用'));
        }

        foreach ($this->getCatalog($area, $theme, false)['panels'][$panel]['disks'] ?? [] as $disk) {
            if (!\is_array($disk) || (string)($disk['ref'] ?? '') !== $ref) {
                continue;
            }
            $path = (string)($disk['path'] ?? '');
            if ($path === '') {
                throw new \InvalidArgumentException((string)__('主题盘不存在'));
            }

            return CssVariableParser::parseFile($path);
        }

        throw new \InvalidArgumentException((string)__('主题盘不存在'));
    }

    /**
     * @return list<array{panel: string, active: string, custom: list<array>}>
     */
    public function getActiveAndCustom(string $area, string $scope = 'default'): array
    {
        $area = ThemeDiskKeys::normalizeArea($area);
        $scope = ThemeDiskKeys::normalizeScope($scope);
        $catalog = $this->getCatalog($area);
        $result = [];

        foreach (array_keys($catalog['panels']) as $panel) {
            $panel = ThemeDiskKeys::normalizePanel((string)$panel);
            $activeMap = ThemeData::getConfigList($area, 'disk_active', $scope);
            $active = (string)($activeMap[$panel] ?? '');
            $customs = [];
            $customMap = ThemeData::getConfigList($area, 'disk_custom', $scope);
            foreach ($customMap as $key => $payload) {
                $key = (string)$key;
                if (!str_starts_with($key, $panel . '.')) {
                    continue;
                }
                $diskKey = substr($key, strlen($panel) + 1);
                if (!is_array($payload)) {
                    if (is_string($payload) && $payload !== '') {
                        $decoded = json_decode($payload, true);
                        $payload = is_array($decoded) ? $decoded : [];
                    } else {
                        $payload = [];
                    }
                }
                $customs[] = [
                    'disk_key' => $diskKey,
                    'ref' => ThemeDiskKeys::customRef($diskKey),
                    'name' => (string)($payload['name'] ?? $diskKey),
                    'base_file' => (string)($payload['base_file'] ?? ''),
                    'disk_kind' => (string)($payload['disk_kind'] ?? 'variables'),
                    'tokens' => is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
                ];
            }
            $result[] = [
                'panel' => $panel,
                'active' => $active,
                'custom' => $customs,
            ];
        }

        $bundleMap = ThemeData::getConfigList($area, 'disk_bundle', $scope);
        $bundle = (string)($bundleMap[$scope] ?? $bundleMap['default'] ?? '');

        return [
            'panels' => $result,
            'bundle_hash' => $bundle,
        ];
    }

    private function mapCssResource(array $resource, string $diskKind, bool $includeDiskTokens = true): ?array
    {
        $path = (string)($resource['file_path'] ?? $resource['path'] ?? '');
        $value = (string)($resource['value'] ?? '');
        if ($path === '' || $value === '') {
            return null;
        }

        $fileMeta = CssVariableParser::parseFileMeta($path);
        $paletteRole = (string)($fileMeta['palette_role'] ?? '');
        if ($paletteRole === '' && $diskKind === 'colors') {
            $paletteRole = in_array($value, ['light', 'dark'], true) ? 'mode' : 'brand';
        }

        $panel = (string)($fileMeta['panel'] ?? '');
        if ($panel === '') {
            $panel = $diskKind === 'colors' ? ThemeDiskKeys::PANEL_COLOR : $value;
        }

        $editorHidden = ((string)($fileMeta['editor_hidden'] ?? '')) === '1'
            || ((string)($fileMeta['editor_hidden'] ?? '')) === 'true';

        // Dual-source: variables/_colors is reference-only; brand UI uses colors/*.
        if ($diskKind === 'variables' && $value === 'colors') {
            $editorHidden = true;
            $panel = ThemeDiskKeys::PANEL_COLOR;
        }

        $parsedTokens = CssVariableParser::parseFile($path);

        return [
            'key' => $value,
            'ref' => ThemeDiskKeys::fileRef($value),
            'name' => (string)($fileMeta['name'] ?? $resource['meta']['name'] ?? $value),
            'description' => (string)($fileMeta['description'] ?? $resource['meta']['description'] ?? ''),
            'panel' => ThemeDiskKeys::normalizePanel($panel),
            'disk_kind' => (string)($fileMeta['disk_kind'] ?? $diskKind),
            'palette_role' => $paletteRole,
            'editor_hidden' => $editorHidden,
            'path' => $path,
            'logical_key' => (string)($resource['logical_key'] ?? ''),
            'layer_type' => (string)($resource['layer_type'] ?? ''),
            'module_name' => (string)($resource['module_name'] ?? ''),
            'token_count' => \count($parsedTokens),
            'tokens' => $includeDiskTokens ? $parsedTokens : [],
        ];
    }
}
