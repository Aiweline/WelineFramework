<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 将 PageBuilder 布局中的组件 code 解析为 PageBuilder 自有虚拟主题部件定义
 * 仅从 PageBuilder 自有的 pb_virtual_theme_component 表查询，不回退到 Weline_Theme
 */

namespace GuoLaiRen\PageBuilder\Service\Theme;

use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;

class PageBuilderThemeComponentBridge
{
    /**
     * 在指定主题层上解析部件定义（仅 PageBuilder 虚拟主题数据）
     */
    public function resolveDefinition(string $componentCode, int $themeId, string $area = 'frontend'): ?ThemeComponentDefinition
    {
        if ($themeId <= 0 || \trim($componentCode) === '') {
            return null;
        }
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';

        // 优先尝试 PageBuilder 自有的虚拟主题表
        return $this->resolveFromPageBuilderVirtualTheme($componentCode, $themeId, $area);
    }

    /**
     * 从 PageBuilder 自有的 pb_virtual_theme_component 表解析
     * @return ThemeComponentDefinition|null
     */
    private function resolveFromPageBuilderVirtualTheme(string $componentCode, int $themeId, string $area): ?ThemeComponentDefinition
    {
        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery();
        $component->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->where(VirtualThemeComponent::schema_fields_AREA, $area)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        if (!$component->getId()) {
            return null;
        }

        $defaultConfig = $component->getDefaultConfig();
        $meta = $component->getMeta();
        $templateContent = $component->getTemplateContent();

        return new ThemeComponentDefinition(
            module: 'GuoLaiRen_PageBuilder',
            type: 'virtual_theme_component',
            code: $component->getComponentCode(),
            name: $component->getName(),
            description: '',
            area: $component->getArea(),
            sourceType: 'virtual',
            category: $component->getCategory(),
            renderMode: \Weline\Theme\Dto\ThemeRenderable::MODE_TEMPLATE_CONTENT,
            configSchema: [],
            defaultConfig: $defaultConfig,
            meta: $meta,
            params: [],
            position: \is_array($meta['position'] ?? null) ? $meta['position'] : ['content'],
            pageLayouts: \is_array($meta['page_layouts'] ?? null) ? $meta['page_layouts'] : ['*'],
            slots: [],
            slot: null,
            exclusive: false,
            compatible: true,
            isContainer: false,
            isAiGenerated: $component->isAiGenerated(),
            icon: null,
            templatePath: null,
            templateContent: $templateContent,
            blockClass: null,
            themeId: $themeId,
            themePath: null,
            logicalKey: null,
            layerKey: null,
            componentId: $component->getId(),
            versionId: $component->getPublishedVersionId() ?: null,
            sortOrder: (int)($meta['sort_order'] ?? 0),
        );
    }

}
