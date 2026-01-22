<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 主题缓存生成器
 * 将数据库中的布局配置生成为缓存文件
 */
class ThemeCacheGenerator
{
    private const CACHE_DIR = BP . 'generated' . DS . 'themes' . DS;

    private ThemeLayoutService $layoutService;
    private WelineTheme $welineTheme;
    private WidgetRegistry $widgetRegistry;

    public function __construct(
        ThemeLayoutService $layoutService,
        WelineTheme $welineTheme,
        WidgetRegistry $widgetRegistry
    ) {
        $this->layoutService = $layoutService;
        $this->welineTheme = $welineTheme;
        $this->widgetRegistry = $widgetRegistry;
    }

    /**
     * 生成主题缓存
     *
     * @param int $themeId 主题ID
     * @return bool
     */
    public function generate(int $themeId): bool
    {
        $this->welineTheme->reset()->load($themeId);
        if (!$this->welineTheme->getId()) {
            return false;
        }

        $themeCode = $this->welineTheme->getModuleName();
        $cacheDir = self::CACHE_DIR . $themeCode . DS;

        // 创建缓存目录
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        if (!is_dir($cacheDir . 'areas' . DS)) {
            mkdir($cacheDir . 'areas' . DS, 0755, true);
        }
        if (!is_dir($cacheDir . 'pages' . DS)) {
            mkdir($cacheDir . 'pages' . DS, 0755, true);
        }

        // 生成布局配置缓存
        $this->generateLayoutCache($themeId, $cacheDir);

        // 生成各页面类型的缓存
        foreach (ThemeLayout::getPageTypes() as $pageType => $label) {
            $this->generatePageCache($themeId, $pageType, $cacheDir);
        }

        // 生成区域渲染缓存
        $this->generateAreaCaches($themeId, $cacheDir);

        return true;
    }

    /**
     * 生成布局配置缓存
     */
    private function generateLayoutCache(int $themeId, string $cacheDir): void
    {
        $layouts = [];
        foreach (ThemeLayout::getPageTypes() as $pageType => $label) {
            $layouts[$pageType] = $this->layoutService->getFullLayout($themeId, $pageType);
        }

        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * 主题布局配置缓存\n";
        $content .= " * 自动生成，请勿手动修改\n";
        $content .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";
        $content .= "return " . var_export($layouts, true) . ";\n";

        file_put_contents($cacheDir . 'layout.php', $content, LOCK_EX);
    }

    /**
     * 生成页面缓存
     */
    private function generatePageCache(int $themeId, string $pageType, string $cacheDir): void
    {
        $layout = $this->layoutService->getFullLayout($themeId, $pageType);

        // 如果当前页面类型没有布局，使用默认布局
        $hasWidgets = false;
        foreach ($layout as $area => $areaData) {
            if (!empty($areaData['widgets'])) {
                $hasWidgets = true;
                break;
            }
        }

        if (!$hasWidgets && $pageType !== ThemeLayout::PAGE_TYPE_DEFAULT) {
            $layout = $this->layoutService->getFullLayout($themeId, ThemeLayout::PAGE_TYPE_DEFAULT);
        }

        // 生成页面模板
        $content = $this->generatePageTemplate($layout, $pageType);
        file_put_contents($cacheDir . 'pages' . DS . $pageType . '.phtml', $content, LOCK_EX);
    }

    /**
     * 生成区域缓存
     */
    private function generateAreaCaches(int $themeId, string $cacheDir): void
    {
        // 使用默认布局生成区域缓存
        $layout = $this->layoutService->getFullLayout($themeId, ThemeLayout::PAGE_TYPE_DEFAULT);

        foreach ($layout as $area => $areaData) {
            $content = $this->generateAreaTemplate($area, $areaData);
            file_put_contents($cacheDir . 'areas' . DS . $area . '.phtml', $content, LOCK_EX);
        }
    }

    /**
     * 生成页面模板内容
     */
    private function generatePageTemplate(array $layout, string $pageType): string
    {
        $template = "<?php\n";
        $template .= "/**\n";
        $template .= " * 页面类型: {$pageType}\n";
        $template .= " * 自动生成，请勿手动修改\n";
        $template .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $template .= " * @var \\Weline\\Framework\\View\\Template \$this\n";
        $template .= " */\n";
        $template .= "?>\n\n";

        // 页面结构
        $template .= '<div class="theme-page page-' . $pageType . '">' . "\n";

        // Header 区域
        $template .= $this->generateAreaSection('header', $layout['header'] ?? ['widgets' => []]);

        // Banner 区域
        $template .= $this->generateAreaSection('banner', $layout['banner'] ?? ['widgets' => []]);

        // 主内容区
        $template .= '    <div class="theme-main-container">' . "\n";
        $template .= '        <div class="theme-main-wrapper">' . "\n";

        // 左侧栏
        $leftWidgets = $layout['left_sidebar']['widgets'] ?? [];
        if (!empty($leftWidgets)) {
            $template .= $this->generateAreaSection('left_sidebar', $layout['left_sidebar'], 'aside', 'theme-left-sidebar');
        }

        // 内容区
        $template .= $this->generateAreaSection('content', $layout['content'] ?? ['widgets' => []], 'main', 'theme-content');

        // 右侧栏
        $rightWidgets = $layout['right_sidebar']['widgets'] ?? [];
        if (!empty($rightWidgets)) {
            $template .= $this->generateAreaSection('right_sidebar', $layout['right_sidebar'], 'aside', 'theme-right-sidebar');
        }

        $template .= '        </div>' . "\n";
        $template .= '    </div>' . "\n";

        // Footer 区域
        $template .= $this->generateAreaSection('footer', $layout['footer'] ?? ['widgets' => []]);

        $template .= '</div>' . "\n";

        return $template;
    }

    /**
     * 生成区域模板内容
     */
    private function generateAreaTemplate(string $area, array $areaData): string
    {
        $template = "<?php\n";
        $template .= "/**\n";
        $template .= " * 区域: {$area}\n";
        $template .= " * 自动生成，请勿手动修改\n";
        $template .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $template .= " * @var \\Weline\\Framework\\View\\Template \$this\n";
        $template .= " */\n";
        $template .= "?>\n\n";

        $template .= $this->generateWidgetsRender($areaData['widgets'] ?? []);

        return $template;
    }

    /**
     * 生成区域段落
     */
    private function generateAreaSection(string $area, array $areaData, string $tag = 'div', string $extraClass = ''): string
    {
        $widgets = $areaData['widgets'] ?? [];

        if (empty($widgets) && !in_array($area, ['header', 'content', 'footer'])) {
            return '';
        }

        $className = 'theme-area theme-area-' . $area;
        if ($extraClass) {
            $className .= ' ' . $extraClass;
        }

        $section = "    <{$tag} class=\"{$className}\" data-area=\"{$area}\">\n";
        $section .= "        <div class=\"area-container\">\n";
        $section .= $this->generateWidgetsRender($widgets, '            ');
        $section .= "        </div>\n";
        $section .= "    </{$tag}>\n\n";

        return $section;
    }

    /**
     * 生成部件渲染代码
     */
    private function generateWidgetsRender(array $widgets, string $indent = ''): string
    {
        if (empty($widgets)) {
            return $indent . "<!-- 该区域暂无部件 -->\n";
        }

        $render = '';
        foreach ($widgets as $widget) {
            $widgetId = 'widget-' . ($widget['layout_id'] ?? uniqid());
            $template = $widget['meta']['template'] ?? '';
            $config = $widget['config'] ?? [];

            $render .= $indent . '<div class="theme-widget" data-widget-id="' . $widgetId . '" data-layout-id="' . ($widget['layout_id'] ?? '') . '">' . "\n";

            if ($template) {
                // 使用模板渲染
                $render .= $indent . '    <?php echo $this->fetchTagHtml(\'' . addslashes($template) . '\', ' . var_export($config, true) . '); ?>' . "\n";
            } else {
                // 无模板时显示占位符
                $render .= $indent . '    <div class="widget-placeholder">' . htmlspecialchars($widget['meta']['name'] ?? $widget['widget_code']) . '</div>' . "\n";
            }

            $render .= $indent . '</div>' . "\n";
        }

        return $render;
    }

    /**
     * 获取主题缓存路径
     */
    public function getCachePath(int $themeId): ?string
    {
        $this->welineTheme->reset()->load($themeId);
        if (!$this->welineTheme->getId()) {
            return null;
        }

        $themeCode = $this->welineTheme->getModuleName();
        return self::CACHE_DIR . $themeCode . DS;
    }

    /**
     * 获取页面缓存文件路径
     */
    public function getPageCachePath(int $themeId, string $pageType): ?string
    {
        $cachePath = $this->getCachePath($themeId);
        if (!$cachePath) {
            return null;
        }

        $pagePath = $cachePath . 'pages' . DS . $pageType . '.phtml';
        if (file_exists($pagePath)) {
            return $pagePath;
        }

        // 回退到默认页面
        $defaultPath = $cachePath . 'pages' . DS . ThemeLayout::PAGE_TYPE_DEFAULT . '.phtml';
        return file_exists($defaultPath) ? $defaultPath : null;
    }

    /**
     * 清除主题缓存
     */
    public function clearCache(int $themeId): bool
    {
        $cachePath = $this->getCachePath($themeId);
        if (!$cachePath || !is_dir($cachePath)) {
            return true;
        }

        $this->recursiveDelete($cachePath);
        return true;
    }

    /**
     * 递归删除目录
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . DS . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * 检查缓存是否存在且有效
     */
    public function isCacheValid(int $themeId): bool
    {
        $cachePath = $this->getCachePath($themeId);
        if (!$cachePath) {
            return false;
        }

        $layoutFile = $cachePath . 'layout.php';
        return file_exists($layoutFile);
    }
}
