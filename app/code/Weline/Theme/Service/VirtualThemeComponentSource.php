<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Interface\ThemeComponentSourceInterface;
use Weline\Theme\Model\ThemeComponent;
use Weline\Theme\Model\ThemeComponentVersion;
use Weline\Theme\Model\WelineTheme;

class VirtualThemeComponentSource implements ThemeComponentSourceInterface
{
    public function __construct(
        private readonly ThemeComponent $themeComponent,
        private readonly ThemeComponentVersion $themeComponentVersion,
    ) {
    }

    public function getName(): string
    {
        return 'theme_virtual';
    }

    public function collect(string $area, ?WelineTheme $theme = null, array $options = []): array
    {
        $themeId = array_key_exists('theme_id', $options) ? (int)$options['theme_id'] : (int)($theme?->getId() ?: 0);
        if ($themeId <= 0) {
            return [];
        }

        $components = clone $this->themeComponent;
        $items = $components->clearData()->clearQuery()
            ->where(ThemeComponent::schema_fields_THEME_ID, $themeId)
            ->where(ThemeComponent::schema_fields_AREA, $area)
            ->where(ThemeComponent::schema_fields_IS_ACTIVE, 1)
            ->where(ThemeComponent::schema_fields_SOURCE_TYPE, ThemeComponent::SOURCE_TYPE_VIRTUAL)
            ->select()
            ->fetchArray();

        if (!is_array($items)) {
            return [];
        }

        $definitions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $component = clone $this->themeComponent;
            $component->setData($item);
            $versionData = $this->resolveVersionData($component);
            if ($versionData === null) {
                continue;
            }

            $version = clone $this->themeComponentVersion;
            $version->setData($versionData);

            $category = $component->getCategory() ?: 'basic';
            $componentCode = $this->buildComponentCode($component->getComponentCode(), $category);
            $schema = $version->getConfigSchema() ?: $component->getConfigSchema();
            $defaultConfig = $version->getDefaultConfig() ?: $component->getDefaultConfig();
            $meta = array_merge($component->getMeta(), [
                'generation_meta' => $version->getGenerationMeta(),
                'prompt' => $version->getPrompt(),
                'agent_code' => $version->getAgentCode(),
                'model_code' => $version->getModelCode(),
                'validation' => $version->getValidation(),
            ]);

            $definitions[] = new ThemeComponentDefinition(
                module: 'Weline_Theme',
                type: 'theme_component',
                code: $componentCode,
                name: $component->getName(),
                description: $component->getDescription(),
                area: $component->getArea(),
                sourceType: 'virtual',
                category: $category,
                renderMode: $component->getRenderMode() ?: ThemeRenderable::MODE_TEMPLATE_CONTENT,
                configSchema: $schema,
                defaultConfig: $defaultConfig,
                meta: $meta,
                params: $this->convertSchemaToParams($schema),
                position: $this->normalizeArray($meta['position'] ?? ['content'], ['content']),
                pageLayouts: $this->normalizeArray($meta['page_layouts'] ?? ['*'], ['*']),
                slots: is_array($meta['slots'] ?? null) ? $meta['slots'] : [],
                slot: !empty($meta['slot']) ? (string)$meta['slot'] : null,
                supports: $this->normalizeArray($meta['supports'] ?? [], []),
                exclusive: (bool)($meta['exclusive'] ?? false),
                compatible: (bool)($meta['compatible'] ?? true),
                isContainer: (bool)($meta['is_container'] ?? false),
                isAiGenerated: $component->isAiGenerated(),
                icon: $component->getIcon() ?: null,
                templateContent: $version->getTemplateContent() ?: null,
                blockClass: !empty($meta['block_class']) ? (string)$meta['block_class'] : null,
                themeId: $component->getThemeId(),
                logicalKey: "components/{$category}/" . basename(str_replace('\\', '/', $componentCode)),
                layerKey: 'theme:' . $component->getThemeId(),
                componentId: $component->getId(),
                versionId: $version->getId(),
                sortOrder: (int)($meta['sort_order'] ?? 0),
            );
        }

        return $definitions;
    }

    private function resolveVersionData(ThemeComponent $component): ?array
    {
        $publishedVersionId = $component->getPublishedVersionId();
        if ($publishedVersionId > 0) {
            $version = clone $this->themeComponentVersion;
            $version->clearData()->clearQuery()->load($publishedVersionId);
            return $version->getId() ? $version->getData() : null;
        }

        $versions = clone $this->themeComponentVersion;
        $items = $versions->clearData()->clearQuery()
            ->where(ThemeComponentVersion::schema_fields_COMPONENT_ID, $component->getId())
            ->order(ThemeComponentVersion::schema_fields_VERSION_NO, 'DESC')
            ->select()
            ->fetchArray();

        return is_array($items) && !empty($items[0]) && is_array($items[0]) ? $items[0] : null;
    }

    private function buildComponentCode(string $componentCode, string $category): string
    {
        $componentCode = trim($componentCode);
        if ($componentCode === '') {
            return "{$category}/component";
        }

        return str_contains($componentCode, '/') ? $componentCode : "{$category}/{$componentCode}";
    }

    private function convertSchemaToParams(array $schema): array
    {
        $params = [];
        foreach ($schema as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $params[$key] = [
                'name' => $field['label'] ?? $field['name'] ?? $key,
                'label' => $field['label'] ?? $field['name'] ?? $key,
                'description' => $field['description'] ?? '',
                'default' => $field['default'] ?? null,
                'type' => $field['type'] ?? 'text',
                'required' => (bool)($field['required'] ?? false),
                'options' => $field['options'] ?? [],
            ];
        }
        return $params;
    }

    private function normalizeArray(array|string $value, array $fallback): array
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
            return $items ?: $fallback;
        }

        $items = array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', $value)
        ), static fn(string $item): bool => $item !== ''));

        return $items ?: $fallback;
    }
}
