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
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

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
     *
     * 模板内容以字符串形式保存在 pb_virtual_theme_component 表中，需要以 PHP 脚本方式执行：
     * 这里将字符串落地为进程私有的临时 .phtml 文件，再走框架 Template::ob_file，保证 $this / $block /
     * 常用 helper 在模板内依旧可用，同时避免 eval 带来的静态分析与安全问题。
     */
    public function render(string $componentCode, int $virtualThemeId, string $area, array $config = []): ?string
    {
        $definition = $this->resolveDefinition($componentCode, $virtualThemeId, $area);
        if ($definition === null) {
            return null;
        }

        $templateContent = (string)$definition['template_content'];
        if ($templateContent === '') {
            return null;
        }

        $defaultConfig = \is_array($definition['default_config'] ?? null) ? $definition['default_config'] : [];
        $mergedConfig = \array_replace($defaultConfig, $config);
        $mergedConfig['component_config'] = \is_array($mergedConfig['component_config'] ?? null)
            ? $mergedConfig['component_config']
            : $config;

        $tmpDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline_vt_bridge';
        if (!\is_dir($tmpDir) && !@\mkdir($tmpDir, 0775, true) && !\is_dir($tmpDir)) {
            return null;
        }
        $tmpFile = $tmpDir . \DIRECTORY_SEPARATOR . 'vt_' . $virtualThemeId . '_' . \md5($componentCode) . '_' . \bin2hex(\random_bytes(4)) . '.phtml';

        try {
            if (\file_put_contents($tmpFile, $templateContent) === false) {
                return null;
            }
            /** @var \Weline\Framework\View\Template $renderer */
            $renderer = ObjectManager::getInstance(\Weline\Framework\View\Template::class);
            $html = $renderer->ob_file($tmpFile, $mergedConfig);
            return \is_string($html) ? $html : '';
        } catch (\Throwable) {
            return null;
        } finally {
            if (\is_file($tmpFile)) {
                @\unlink($tmpFile);
            }
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
