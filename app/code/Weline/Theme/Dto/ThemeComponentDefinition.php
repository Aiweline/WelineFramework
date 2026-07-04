<?php

declare(strict_types=1);

namespace Weline\Theme\Dto;

class ThemeComponentDefinition
{
    public function __construct(
        public readonly string $module,
        public readonly string $type,
        public readonly string $code,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $area = 'frontend',
        public readonly string $sourceType = 'file',
        public readonly string $category = 'basic',
        public readonly string $renderMode = ThemeRenderable::MODE_TEMPLATE_PATH,
        public readonly array $configSchema = [],
        public readonly array $defaultConfig = [],
        public readonly array $meta = [],
        public readonly array $params = [],
        public readonly array $position = ['content'],
        public readonly array $pageLayouts = ['*'],
        public readonly array $slots = [],
        public readonly ?string $slot = null,
        public readonly array $defaultInjections = [],
        public readonly bool $exclusive = false,
        public readonly bool $compatible = true,
        public readonly bool $isContainer = false,
        public readonly bool $isAiGenerated = false,
        public readonly ?string $icon = null,
        public readonly ?string $templatePath = null,
        public readonly ?string $templateContent = null,
        public readonly ?string $blockClass = null,
        public readonly ?int $themeId = null,
        public readonly ?string $themePath = null,
        public readonly ?string $logicalKey = null,
        public readonly ?string $layerKey = null,
        public readonly ?int $componentId = null,
        public readonly ?int $versionId = null,
        public readonly int $sortOrder = 0,
        public readonly array $supports = [],
    ) {
    }

    public function getIdentity(): string
    {
        return $this->module . '::' . $this->type . '::' . $this->code;
    }

    public function getMetaIdentify(): string
    {
        $code = str_replace('/', '.', $this->code);
        return "theme.{$this->area}.components.{$code}";
    }

    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'type' => $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'area' => $this->area,
            'source_type' => $this->sourceType,
            'category' => $this->category,
            'render_mode' => $this->renderMode,
            'config_schema' => $this->configSchema,
            'default_config' => $this->defaultConfig,
            'meta' => $this->meta,
            'params' => $this->params,
            'position' => $this->position,
            'page_layouts' => $this->pageLayouts,
            'slots' => $this->slots,
            'slot' => $this->slot,
            'default_injections' => $this->defaultInjections,
            'supports' => $this->supports,
            'exclusive' => $this->exclusive,
            'compatible' => $this->compatible,
            'is_container' => $this->isContainer,
            'is_ai_generated' => $this->isAiGenerated,
            'icon' => $this->icon,
            'template_path' => $this->templatePath,
            'template_content' => $this->templateContent,
            'block_class' => $this->blockClass,
            'theme_id' => $this->themeId,
            'theme_path' => $this->themePath,
            'logical_key' => $this->logicalKey,
            'layer_key' => $this->layerKey,
            'component_id' => $this->componentId,
            'version_id' => $this->versionId,
            'sort_order' => $this->sortOrder,
        ];
    }

    public function toWidgetArray(): array
    {
        return [
            'module' => $this->module,
            'type' => $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'page_layouts' => $this->pageLayouts,
            'slot' => $this->slot,
            'slots' => $this->slots,
            'default_injections' => $this->defaultInjections,
            'supports' => $this->supports,
            'exclusive' => $this->exclusive,
            'compatible' => $this->compatible,
            'is_container' => $this->isContainer,
            'is_ai_generated' => $this->isAiGenerated,
            'params' => $this->params,
            'config_schema' => $this->configSchema,
            'default_config' => $this->defaultConfig,
            'icon' => $this->icon,
            'template' => $this->templatePath,
            'meta' => array_merge($this->meta, [
                'source_type' => $this->sourceType,
                'logical_key' => $this->logicalKey,
                'layer_key' => $this->layerKey,
                'component_id' => $this->componentId,
                'version_id' => $this->versionId,
            ]),
        ];
    }
}
