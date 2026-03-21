<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeBuilderSchema;
use Weline\Theme\Dto\ThemeSlotDefinition;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

class ThemeBuilderSchemaService
{
    private array $cache = [];

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeDirectoryResolver $directoryResolver,
        private readonly ThemeResourceCatalog $resourceCatalog,
        private readonly ThemeComponentCatalog $componentCatalog,
        private readonly ThemeLayoutService $layoutService,
    ) {
    }

    public function getSchema(int $themeId, string $area = 'frontend', ?string $pageType = null, bool $forceReload = false): ThemeBuilderSchema
    {
        $area = $this->normalizeArea($area);
        $pageType = $pageType ?: ThemeLayout::PAGE_TYPE_DEFAULT;
        $cacheKey = "{$themeId}:{$area}:{$pageType}";

        if (!$forceReload && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $theme = $this->loadTheme($themeId);
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $layouts = $this->resourceCatalog->getLayouts($area, $theme);
        $partials = $this->resourceCatalog->getPartials($area, $theme);
        $variables = $this->resourceCatalog->getVariables($area, $theme);
        $colors = $this->resourceCatalog->getColors($area, $theme);
        $slots = $this->normalizeSlots($this->resourceCatalog->getSlots($area, $theme));
        $components = array_map(
            static fn($definition) => method_exists($definition, 'toArray') ? $definition->toArray() : (array)$definition,
            $this->componentCatalog->getDefinitions($area, $theme, $forceReload)
        );

        $meta = [
            'page_types' => ThemeLayout::getPageTypes(),
            'areas' => ThemeLayout::getAreas(),
            'page_type' => $pageType,
            'theme_chain' => array_map(
                static fn(WelineTheme $chainTheme): array => [
                    'id' => (int)$chainTheme->getId(),
                    'name' => (string)$chainTheme->getName(),
                    'path' => (string)$chainTheme->getOriginPath(),
                ],
                $this->directoryResolver->getThemeChain($theme)
            ),
            'defaults' => [
                'layouts' => ThemeData::getLayoutsConfig($area),
                'partials' => ThemeData::getPartialsConfig($area),
                'variables' => ThemeData::getVariablesConfig($area),
                'colors' => ThemeData::getColorConfig($area),
            ],
            'placements' => [
                'draft' => $this->layoutService->getFullDraftLayout($themeId, $pageType),
                'published' => $this->layoutService->getFullLayout($themeId, $pageType, ThemeLayout::STATUS_PUBLISHED),
            ],
        ];

        $fingerprint = sha1((string)json_encode([
            'theme_id' => $themeId,
            'area' => $area,
            'page_type' => $pageType,
            'layouts' => $layouts,
            'partials' => $partials,
            'variables' => $variables,
            'colors' => $colors,
            'slots' => $slots,
            'components' => array_map(
                static fn(array $component): array => [
                    'identity' => ($component['module'] ?? '') . '::' . ($component['type'] ?? '') . '::' . ($component['code'] ?? ''),
                    'logical_key' => $component['logical_key'] ?? '',
                    'layer_key' => $component['layer_key'] ?? '',
                    'version_id' => $component['version_id'] ?? 0,
                    'source_type' => $component['source_type'] ?? '',
                ],
                $components
            ),
            'defaults' => $meta['defaults'],
        ], JSON_UNESCAPED_UNICODE));

        $schema = new ThemeBuilderSchema(
            themeId: $themeId,
            themeName: (string)$theme->getName(),
            area: $area,
            fingerprint: $fingerprint,
            layouts: $layouts,
            partials: $partials,
            components: $components,
            variables: $variables,
            colors: $colors,
            slots: $slots,
            meta: $meta,
        );

        $this->cache[$cacheKey] = $schema;
        return $schema;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    private function loadTheme(int $themeId): WelineTheme
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            throw new \InvalidArgumentException((string)__('主题不存在：%{1}', [$themeId]));
        }

        return $theme;
    }

    private function normalizeSlots(array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slotId => $slot) {
            if ($slot instanceof ThemeSlotDefinition) {
                $normalized[$slotId] = $slot->toArray();
                continue;
            }
            if (!is_array($slot)) {
                continue;
            }

            $definition = new ThemeSlotDefinition(
                id: (string)($slot['id'] ?? $slotId),
                name: (string)($slot['name'] ?? $slot['id'] ?? $slotId),
                area: (string)($slot['area'] ?? 'content'),
                accept: is_array($slot['accept'] ?? null) ? $slot['accept'] : [],
                exclusive: (bool)($slot['exclusive'] ?? false),
                multiple: !isset($slot['multiple']) || (bool)$slot['multiple'],
                append: (bool)($slot['append'] ?? false),
                prepend: (bool)($slot['prepend'] ?? false),
                meta: is_array($slot['meta'] ?? null) ? $slot['meta'] : [],
                sourcePath: isset($slot['source_path']) ? (string)$slot['source_path'] : null,
            );
            $normalized[$definition->id] = $definition->toArray();
        }

        ksort($normalized);
        return $normalized;
    }

    private function normalizeArea(string $area): string
    {
        return strtolower($area) === 'backend' ? 'backend' : 'frontend';
    }
}
