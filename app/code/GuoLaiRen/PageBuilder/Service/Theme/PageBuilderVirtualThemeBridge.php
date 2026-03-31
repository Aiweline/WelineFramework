<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * PageBuilder AI 建站虚拟主题组件解析器
 * 直接从 PageBuilder 自有的 pb_virtual_theme_component 表查询，不依赖 Weline\Theme
 */

namespace GuoLaiRen\PageBuilder\Service\Theme;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponentVersion;
use Weline\Framework\Manager\ObjectManager;

class PageBuilderVirtualThemeBridge
{
    /**
     * 根据组件代码解析虚拟主题部件定义
     * @return array{component_code:string,area:string,template_content:string,default_config:array,meta:array}|null
     */
    public function resolveDefinition(string $componentCode, int $virtualThemeId, string $area = 'frontend'): ?array
    {
        if ($virtualThemeId <= 0 || \trim($componentCode) === '') {
            return null;
        }

        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery();
        $component->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->where(VirtualThemeComponent::schema_fields_AREA, $area)
            ->find();

        if (!$component->getId()) {
            return null;
        }

        return [
            'component_code' => $component->getComponentCode(),
            'area' => $component->getArea(),
            'template_content' => $component->getTemplateContent(),
            'default_config' => $component->getDefaultConfig(),
            'meta' => $component->getMeta(),
        ];
    }

    /**
     * 渲染虚拟主题部件
     */
    public function render(string $componentCode, int $virtualThemeId, string $area, array $config = []): ?string
    {
        $definition = $this->resolveDefinition($componentCode, $virtualThemeId, $area);
        if ($definition === null) {
            return null;
        }

        $templateContent = $definition['template_content'];
        if ($templateContent === '') {
            return null;
        }

        $defaultConfig = $definition['default_config'];
        $mergedConfig = \array_replace($defaultConfig, $config);

        try {
            $renderer = new \Weline\Framework\View\Template();
            $renderer->assign($mergedConfig ?: []);
            foreach ($config as $key => $value) {
                $renderer->assign($key, $value);
            }
            return $renderer->fetchString($templateContent);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 检查虚拟主题是否存在
     */
    public function themeExists(int $virtualThemeId): bool
    {
        if ($virtualThemeId <= 0) {
            return false;
        }
        /** @var VirtualTheme $theme */
        $theme = clone ObjectManager::getInstance(VirtualTheme::class);
        $theme->clearData()->clearQuery()->load($virtualThemeId);
        return $theme->getId() > 0;
    }
}
