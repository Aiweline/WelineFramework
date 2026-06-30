<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Interface\ThemeComponentSourceInterface;
use Weline\Theme\Model\WelineTheme;

class ThemeFileComponentSource implements ThemeComponentSourceInterface
{
    public function __construct(
        private readonly ThemeResourceCatalog $resourceCatalog,
    ) {
    }

    public function getName(): string
    {
        return 'theme_file';
    }

    public function collect(string $area, ?WelineTheme $theme = null, array $options = []): array
    {
        $resources = array_merge(
            $this->resourceCatalog->getRawResources('components', $area, $theme),
            $this->resourceCatalog->getRawResources('widgets', $area, $theme)
        );

        $definitions = [];
        foreach ($resources as $resource) {
            if (!$this->matchesLayer($resource, $options)) {
                continue;
            }

            $widgetMeta = $resource['widget_meta'] ?? [];
            $isLegacyWidget = ($resource['type'] ?? '') === 'widgets';
            $category = (string)($resource['category'] ?? 'basic');
            $componentCode = (string)($resource['code'] ?? '');
            if ($componentCode === '') {
                continue;
            }

            $definitionType = $isLegacyWidget
                ? (string)($widgetMeta['type'] ?? $category ?: 'content')
                : 'theme_component';
            $definitionCode = $isLegacyWidget
                ? (string)($widgetMeta['code'] ?? $componentCode)
                : "{$category}/{$componentCode}";

            $definitions[] = new ThemeComponentDefinition(
                module: 'Weline_Theme',
                type: $definitionType,
                code: $definitionCode,
                name: (string)($widgetMeta['name'] ?? $resource['meta']['name'] ?? $componentCode),
                description: (string)($widgetMeta['description'] ?? $resource['meta']['description'] ?? ''),
                area: $area,
                sourceType: 'file',
                category: $category,
                renderMode: ThemeRenderable::MODE_TEMPLATE_PATH,
                configSchema: $this->buildConfigSchema($resource['params'] ?? []),
                defaultConfig: $this->buildDefaultConfig($resource['params'] ?? []),
                meta: array_merge($resource['meta'] ?? [], [
                    'resource_type' => $resource['type'] ?? 'components',
                    'relative_path' => $resource['relative_path'] ?? '',
                    'layer_type' => $resource['layer_type'] ?? '',
                    'theme_name' => $resource['theme_name'] ?? '',
                    'widget_meta' => $widgetMeta,
                ]),
                params: $resource['params'] ?? [],
                position: $this->normalizeArray($widgetMeta['position'] ?? [], $isLegacyWidget ? [] : ['content']),
                pageLayouts: $this->normalizeArray($widgetMeta['page_layouts'] ?? ['*'], ['*']),
                slots: $resource['slots'] ?? [],
                slot: !empty($widgetMeta['slot']) ? (string)$widgetMeta['slot'] : null,
                supports: $this->normalizeArray($widgetMeta['supports'] ?? [], []),
                exclusive: (bool)($widgetMeta['exclusive'] ?? false),
                compatible: (bool)($widgetMeta['compatible'] ?? true),
                isContainer: (bool)($widgetMeta['is_container'] ?? false),
                isAiGenerated: false,
                icon: !empty($widgetMeta['icon']) ? (string)$widgetMeta['icon'] : ($resource['meta']['icon'] ?? null),
                templatePath: $this->resolveTemplatePath($resource, $area, $isLegacyWidget),
                themeId: (int)($resource['theme_id'] ?? 0),
                themePath: $resource['theme_path'] ?? null,
                logicalKey: $resource['logical_key'] ?? null,
                layerKey: $resource['layer_key'] ?? null,
                sortOrder: 0,
            );
        }

        return $definitions;
    }

    private function matchesLayer(array $resource, array $options): bool
    {
        $themeId = array_key_exists('theme_id', $options) ? (int)$options['theme_id'] : null;
        $defaultOnly = (bool)($options['default_only'] ?? false);
        $includeDefault = $options['include_default'] ?? true;

        if ($defaultOnly) {
            return ($resource['layer_type'] ?? '') === 'default';
        }
        if ($themeId !== null) {
            return (int)($resource['theme_id'] ?? 0) === $themeId;
        }
        if (!$includeDefault && ($resource['layer_type'] ?? '') === 'default') {
            return false;
        }

        return true;
    }

    private function buildConfigSchema(array $params): array
    {
        $schema = [];
        foreach ($params as $key => $param) {
            if (!is_array($param)) {
                continue;
            }
            $schema[$key] = [
                'type' => $param['type'] ?? 'text',
                'label' => $param['label'] ?? $param['name'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? null,
                'required' => (bool)($param['required'] ?? false),
                'options' => $param['options'] ?? [],
            ];
        }

        return $schema;
    }

    private function buildDefaultConfig(array $params): array
    {
        $config = [];
        foreach ($params as $key => $param) {
            if (is_array($param) && array_key_exists('default', $param)) {
                $config[$key] = $param['default'];
            }
        }
        return $config;
    }

    private function normalizeArray(array|string $value, array $fallback): array
    {
        if (is_array($value)) {
            $value = array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
            return $value ?: $fallback;
        }

        $values = array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', $value)
        ), static fn(string $item): bool => $item !== ''));

        return $values ?: $fallback;
    }

    private function resolveTemplatePath(array $resource, string $area, bool $isLegacyWidget): ?string
    {
        $filePath = $resource['file_path'] ?? null;
        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        if (!$isLegacyWidget) {
            return $filePath;
        }

        $relativePath = str_replace('\\', '/', (string)($resource['relative_path'] ?? ''));
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '') {
            return $filePath;
        }

        return sprintf('Weline_Theme::theme/%s/widgets/%s', $area, $relativePath);
    }
}
