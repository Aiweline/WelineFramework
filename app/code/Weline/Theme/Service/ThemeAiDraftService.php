<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Interface\ThemeAiDraftServiceInterface;
use Weline\Theme\Model\ThemeComponent;
use Weline\Theme\Model\ThemeComponentVersion;
use Weline\Theme\Model\WelineTheme;

class ThemeAiDraftService implements ThemeAiDraftServiceInterface
{
    public function __construct(
        private readonly ThemeComponent $themeComponent,
        private readonly ThemeComponentVersion $themeComponentVersion,
        private readonly WelineTheme $welineTheme,
        private readonly ThemeComponentRenderer $componentRenderer,
    ) {
    }

    public function saveDraft(array $componentData, array $versionData = []): ThemeComponentVersion
    {
        $themeId = (int)($componentData['theme_id'] ?? 0);
        $area = $this->normalizeArea((string)($componentData['area'] ?? 'frontend'));
        if ($themeId <= 0) {
            throw new \InvalidArgumentException((string)__('缺少主题ID'));
        }

        $category = $this->resolveCategory($componentData);
        $componentCode = $this->normalizeComponentCode(
            (string)($componentData['component_code'] ?? $componentData['code'] ?? ''),
            $category,
            (string)($componentData['name'] ?? 'virtual-component')
        );

        $component = $this->loadOrCreateComponent($themeId, $area, $componentCode);
        $meta = $this->normalizeArray($componentData['meta_json'] ?? $componentData['meta'] ?? []);

        $component->setThemeId($themeId)
            ->setArea($area)
            ->setComponentCode($componentCode)
            ->setCategory($category)
            ->setSourceType(ThemeComponent::SOURCE_TYPE_VIRTUAL)
            ->setRenderMode((string)($componentData['render_mode'] ?? $versionData['render_mode'] ?? ThemeComponent::RENDER_MODE_TEMPLATE_CONTENT))
            ->setName((string)($componentData['name'] ?? $this->humanizeCode($componentCode)))
            ->setDescription((string)($componentData['description'] ?? ''))
            ->setIcon((string)($componentData['icon'] ?? ''))
            ->setConfigSchema($this->normalizeArray($componentData['config_schema_json'] ?? $componentData['config_schema'] ?? []))
            ->setDefaultConfig($this->normalizeArray($componentData['default_config_json'] ?? $componentData['default_config'] ?? []))
            ->setMeta($meta)
            ->setIsAiGenerated((bool)($componentData['is_ai_generated'] ?? true))
            ->setIsActive(!array_key_exists('is_active', $componentData) || (bool)$componentData['is_active'])
            ->save();

        $version = clone $this->themeComponentVersion;
        $version->clearData()->clearQuery();
        $version->setComponentId($component->getId())
            ->setVersionNo($this->getNextVersionNo($component->getId()))
            ->setStatus(ThemeComponentVersion::STATUS_DRAFT)
            ->setTemplateContent((string)($versionData['template_content'] ?? $componentData['template_content'] ?? ''))
            ->setConfigSchema($this->normalizeArray($versionData['config_schema_json'] ?? $versionData['config_schema'] ?? $componentData['config_schema_json'] ?? $componentData['config_schema'] ?? []))
            ->setDefaultConfig($this->normalizeArray($versionData['default_config_json'] ?? $versionData['default_config'] ?? $componentData['default_config_json'] ?? $componentData['default_config'] ?? []))
            ->setGenerationMeta($this->normalizeArray($versionData['generation_meta_json'] ?? $versionData['generation_meta'] ?? []))
            ->setPrompt((string)($versionData['prompt'] ?? $componentData['prompt'] ?? ''))
            ->setAgentCode((string)($versionData['agent_code'] ?? $componentData['agent_code'] ?? ''))
            ->setModelCode((string)($versionData['model_code'] ?? $componentData['model_code'] ?? ''))
            ->setValidation($this->normalizeArray($versionData['validation_json'] ?? $versionData['validation'] ?? []))
            ->save();

        ThemeData::clearCache();
        return $version;
    }

    public function publishDraft(int $draftVersionId): ThemeComponent
    {
        $version = $this->loadVersionModel($draftVersionId);
        if ($version->getStatus() !== ThemeComponentVersion::STATUS_DRAFT && $version->getStatus() !== ThemeComponentVersion::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException((string)__('只能发布草稿或已发布版本'));
        }

        $component = $this->loadComponentModel($version->getComponentId());
        foreach ($this->getVersionsByComponent($component->getId()) as $versionData) {
            $versionId = (int)($versionData[ThemeComponentVersion::schema_fields_ID] ?? 0);
            if ($versionId <= 0 || $versionId === $version->getId()) {
                continue;
            }
            if (($versionData[ThemeComponentVersion::schema_fields_STATUS] ?? '') !== ThemeComponentVersion::STATUS_PUBLISHED) {
                continue;
            }

            $published = clone $this->themeComponentVersion;
            $published->clearData()->clearQuery()->load($versionId);
            if ($published->getId()) {
                $published->setStatus(ThemeComponentVersion::STATUS_ARCHIVED)->save();
            }
        }

        $version->setStatus(ThemeComponentVersion::STATUS_PUBLISHED)->save();
        $component->setPublishedVersionId($version->getId())
            ->setConfigSchema($version->getConfigSchema())
            ->setDefaultConfig($version->getDefaultConfig())
            ->save();

        ThemeData::clearCache();
        return $component;
    }

    public function revertVersion(int $versionId): ThemeComponentVersion
    {
        $version = $this->loadVersionModel($versionId);
        $component = $this->loadComponentModel($version->getComponentId());

        return $this->saveDraft([
            'theme_id' => $component->getThemeId(),
            'area' => $component->getArea(),
            'category' => $component->getCategory(),
            'component_code' => $component->getComponentCode(),
            'name' => $component->getName(),
            'description' => $component->getDescription(),
            'icon' => $component->getIcon(),
            'meta_json' => $component->getMeta(),
            'render_mode' => $component->getRenderMode(),
            'is_ai_generated' => $component->isAiGenerated(),
            'is_active' => $component->isActive(),
        ], [
            'template_content' => $version->getTemplateContent(),
            'config_schema' => $version->getConfigSchema(),
            'default_config' => $version->getDefaultConfig(),
            'generation_meta' => array_merge($version->getGenerationMeta(), [
                'reverted_from_version_id' => $version->getId(),
            ]),
            'prompt' => $version->getPrompt(),
            'agent_code' => $version->getAgentCode(),
            'model_code' => $version->getModelCode(),
            'validation' => $version->getValidation(),
        ]);
    }

    public function getPublishedVersion(int $componentId): ?ThemeComponentVersion
    {
        $component = $this->loadComponentModel($componentId, false);
        if (!$component || !$component->getPublishedVersionId()) {
            return null;
        }

        return $this->loadVersionModel($component->getPublishedVersionId(), false);
    }

    public function loadVersion(int $versionId): ?ThemeComponentVersion
    {
        return $this->loadVersionModel($versionId, false);
    }

    public function buildDefinitionForVersion(int $versionId): ?ThemeComponentDefinition
    {
        $version = $this->loadVersionModel($versionId, false);
        if (!$version) {
            return null;
        }

        $component = $this->loadComponentModel($version->getComponentId(), false);
        if (!$component) {
            return null;
        }

        $meta = array_merge($component->getMeta(), [
            'generation_meta' => $version->getGenerationMeta(),
            'prompt' => $version->getPrompt(),
            'agent_code' => $version->getAgentCode(),
            'model_code' => $version->getModelCode(),
            'validation' => $version->getValidation(),
        ]);

        $componentCode = $this->normalizeComponentCode($component->getComponentCode(), $component->getCategory(), $component->getName());

        return new ThemeComponentDefinition(
            module: 'Weline_Theme',
            type: 'theme_component',
            code: $componentCode,
            name: $component->getName(),
            description: $component->getDescription(),
            area: $component->getArea(),
            sourceType: 'virtual',
            category: $component->getCategory() ?: 'basic',
            renderMode: $component->getRenderMode() ?: ThemeRenderable::MODE_TEMPLATE_CONTENT,
            configSchema: $version->getConfigSchema() ?: $component->getConfigSchema(),
            defaultConfig: $version->getDefaultConfig() ?: $component->getDefaultConfig(),
            meta: $meta,
            params: $this->convertSchemaToParams($version->getConfigSchema() ?: $component->getConfigSchema()),
            position: $this->normalizeStringArray($meta['position'] ?? ['content'], ['content']),
            pageLayouts: $this->normalizeStringArray($meta['page_layouts'] ?? ['*'], ['*']),
            slots: is_array($meta['slots'] ?? null) ? $meta['slots'] : [],
            slot: !empty($meta['slot']) ? (string)$meta['slot'] : null,
            exclusive: (bool)($meta['exclusive'] ?? false),
            compatible: !array_key_exists('compatible', $meta) || (bool)$meta['compatible'],
            isContainer: (bool)($meta['is_container'] ?? false),
            isAiGenerated: $component->isAiGenerated(),
            icon: $component->getIcon() ?: null,
            templateContent: $version->getTemplateContent(),
            blockClass: !empty($meta['block_class']) ? (string)$meta['block_class'] : null,
            themeId: $component->getThemeId(),
            logicalKey: "components/{$component->getCategory()}/" . basename(str_replace('\\', '/', $componentCode)),
            layerKey: 'theme:' . $component->getThemeId(),
            componentId: $component->getId(),
            versionId: $version->getId(),
            sortOrder: (int)($meta['sort_order'] ?? 0),
        );
    }

    public function renderPreview(int $versionId, array $config = []): string
    {
        $definition = $this->buildDefinitionForVersion($versionId);
        if (!$definition) {
            return '';
        }

        $theme = $this->loadTheme($definition->themeId ?? 0);
        return $this->componentRenderer->render($definition, $config, $theme, [
            'area' => $definition->area,
            'preview_mode' => true,
        ]);
    }

    private function loadTheme(int $themeId): ?WelineTheme
    {
        if ($themeId <= 0) {
            return null;
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        return $theme->getId() ? $theme : null;
    }

    private function loadOrCreateComponent(int $themeId, string $area, string $componentCode): ThemeComponent
    {
        $component = clone $this->themeComponent;
        $item = $component->clearData()->clearQuery()
            ->where(ThemeComponent::schema_fields_THEME_ID, $themeId)
            ->where(ThemeComponent::schema_fields_AREA, $area)
            ->where(ThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->where(ThemeComponent::schema_fields_SOURCE_TYPE, ThemeComponent::SOURCE_TYPE_VIRTUAL)
            ->select()
            ->fetchArray();

        if (is_array($item) && !empty($item[0]) && is_array($item[0])) {
            $component->setData($item[0]);
            return $component;
        }

        $component->clearData()->clearQuery();
        return $component;
    }

    private function loadComponentModel(int $componentId, bool $strict = true): ?ThemeComponent
    {
        $component = clone $this->themeComponent;
        $component->clearData()->clearQuery()->load($componentId);
        if ($component->getId()) {
            return $component;
        }

        if ($strict) {
            throw new \InvalidArgumentException((string)__('部件不存在：%{1}', [$componentId]));
        }

        return null;
    }

    private function loadVersionModel(int $versionId, bool $strict = true): ?ThemeComponentVersion
    {
        $version = clone $this->themeComponentVersion;
        $version->clearData()->clearQuery()->load($versionId);
        if ($version->getId()) {
            return $version;
        }

        if ($strict) {
            throw new \InvalidArgumentException((string)__('版本不存在：%{1}', [$versionId]));
        }

        return null;
    }

    private function getVersionsByComponent(int $componentId): array
    {
        $version = clone $this->themeComponentVersion;
        $items = $version->clearData()->clearQuery()
            ->where(ThemeComponentVersion::schema_fields_COMPONENT_ID, $componentId)
            ->order(ThemeComponentVersion::schema_fields_VERSION_NO, 'DESC')
            ->select()
            ->fetchArray();

        return is_array($items) ? $items : [];
    }

    private function getNextVersionNo(int $componentId): int
    {
        $items = $this->getVersionsByComponent($componentId);
        $current = (int)($items[0][ThemeComponentVersion::schema_fields_VERSION_NO] ?? 0);
        return $current + 1;
    }

    private function resolveCategory(array $componentData): string
    {
        $category = (string)($componentData['category'] ?? '');
        if ($category !== '') {
            return $this->slugify($category);
        }

        $componentCode = (string)($componentData['component_code'] ?? $componentData['code'] ?? '');
        if (str_contains($componentCode, '/')) {
            return $this->slugify((string)explode('/', $componentCode, 2)[0]);
        }

        return 'basic';
    }

    private function normalizeComponentCode(string $componentCode, string $category, string $fallbackName): string
    {
        $componentCode = trim($componentCode);
        if ($componentCode === '') {
            $componentCode = $this->slugify($fallbackName);
        }

        if (!str_contains($componentCode, '/')) {
            $componentCode = $category . '/' . $componentCode;
        }

        [$resolvedCategory, $code] = explode('/', $componentCode, 2);
        return $this->slugify($resolvedCategory) . '/' . $this->slugify($code);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'component';
        return trim($value, '-') ?: 'component';
    }

    private function humanizeCode(string $componentCode): string
    {
        $label = basename(str_replace('\\', '/', $componentCode));
        return ucwords(str_replace(['-', '_'], ' ', $label));
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeStringArray(array|string $value, array $fallback): array
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

    private function normalizeArea(string $area): string
    {
        return strtolower($area) === 'backend' ? 'backend' : 'frontend';
    }
}
