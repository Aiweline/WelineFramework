<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 将 PageBuilder 布局中的组件 code 解析为 Weline 主题目录中的部件定义（含 DB 虚拟部件）
 * 优先从 PageBuilder 自有的 pb_virtual_theme_component 表查询虚拟部件
 */

namespace GuoLaiRen\PageBuilder\Service\Theme;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeComponentCatalog;

class PageBuilderThemeComponentBridge
{
    public function __construct(
        private readonly ThemeComponentCatalog $themeComponentCatalog,
        private readonly LayoutConfigNormalizer $layoutConfigNormalizer,
        private readonly WelineTheme $welineTheme,
    ) {
    }

    /**
     * 在指定主题层上解析部件定义
     * 优先从 PageBuilder 自有的 pb_virtual_theme_component 表查询（virtual_theme_id）
     * 若无则回退到 WelineTheme 的 theme_component 表
     */
    public function resolveDefinition(string $componentCode, int $themeId, string $area = 'frontend'): ?ThemeComponentDefinition
    {
        if ($themeId <= 0 || \trim($componentCode) === '') {
            return null;
        }
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';

        // 优先尝试 PageBuilder 自有的虚拟主题表
        $pbDef = $this->resolveFromPageBuilderVirtualTheme($componentCode, $themeId, $area);
        if ($pbDef !== null) {
            return $pbDef;
        }

        // 回退到 WelineTheme
        return $this->resolveFromWelineTheme($componentCode, $themeId, $area);
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
            ->find();

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

    /**
     * 从 WelineTheme 的 theme_component 表解析
     * @return ThemeComponentDefinition|null
     */
    private function resolveFromWelineTheme(string $componentCode, int $themeId, string $area): ?ThemeComponentDefinition
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return null;
        }

        $norm = $this->layoutConfigNormalizer->normalizeComponentCode($componentCode);
        if ($norm === '') {
            return null;
        }

        foreach ([$componentCode, $norm] as $tryCode) {
            $tryCode = \trim($tryCode);
            if ($tryCode === '') {
                continue;
            }
            $found = $this->themeComponentCatalog->find('Weline_Theme', 'theme_component', $tryCode, $area, $theme);
            if ($found !== null) {
                return $found;
            }
        }

        $definitions = $this->themeComponentCatalog->getDefinitions($area, $theme);
        foreach ($definitions as $def) {
            if (!$def instanceof ThemeComponentDefinition) {
                continue;
            }
            if ($def->module !== 'Weline_Theme' || $def->type !== 'theme_component') {
                continue;
            }
            if ($def->code === $norm || $def->code === $componentCode) {
                return $def;
            }
            if (\str_contains($def->code, '/')) {
                $base = \basename(\str_replace('\\', '/', $def->code));
                if ($base === $norm || $base === $componentCode) {
                    return $def;
                }
            }
        }

        return null;
    }
}
