<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use JSMin\JSMin;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Helper\CodeMinifier;
use Weline\Theme\Helper\AssetsExtractor;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Helper\LayoutAssetsManager;
use Weline\Theme\Helper\LayoutDependencyTracker;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局资源提取Observer
 * 
 * 监听模板编译事件（Weline_Framework_Template::after_compile），
 * 在编译阶段提取内联CSS/JS，直接生成布局特定的CSS/JS文件到pub/static目录
 * 
 * 注意：文件是在编译阶段直接生成的，不是搬迁生成的
 */
class LayoutAssetsExtractor implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $content = $event->getData('content');
        $tplFile = $event->getData('tplFile');
        
        if (empty($content) || empty($tplFile)) {
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: content或tplFile为空');
            }
            return;
        }
        
        // 检测是否为布局文件或partials文件
        if (!$this->isLayoutOrPartialFile($tplFile)) {
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: 不是布局或partials文件: ' . $tplFile);
            }
            return;
        }
        
        try {
            // 解析文件路径，提取布局信息
            $layoutInfo = $this->parseLayoutInfo($tplFile);
            if (!$layoutInfo) {
                if (defined('DEV') && DEV) {
                    error_log('LayoutAssetsExtractor: 无法解析布局信息: ' . $tplFile);
                }
                return;
            }
            
            $area = $layoutInfo['area'];
            $layoutType = $layoutInfo['layoutType'] ?? null;
            $layoutOption = $layoutInfo['layoutOption'] ?? 'default';
            $isPartial = $layoutInfo['isPartial'] ?? false;
            
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: 解析布局信息 - area: ' . $area . ', layoutType: ' . ($layoutType ?? 'null') . ', layoutOption: ' . $layoutOption . ', isPartial: ' . ($isPartial ? 'true' : 'false'));
            }
            
            // 如果是partials文件，只移除内联标签，不生成文件
            if ($isPartial) {
                // Partials文件的CSS/JS会在布局文件编译时处理
                // 这里只移除内联标签
                $this->removeInlineTags($content, $event);
                return;
            }
            
            // 只处理布局文件
            if (!$layoutType) {
                if (defined('DEV') && DEV) {
                    error_log('LayoutAssetsExtractor: layoutType为空，跳过');
                }
                return;
            }
            
            // 获取主题
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme = $theme->getActiveTheme();
            
            if (!$theme || !$theme->getId()) {
                if (defined('DEV') && DEV) {
                    error_log('LayoutAssetsExtractor: 无法获取激活主题');
                }
                return;
            }
            
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: 主题ID: ' . $theme->getId() . ', 主题路径: ' . $theme->getPath());
            }
            
            // 检查是否需要重新生成
            /** @var LayoutAssetsManager $assetsManager */
            $assetsManager = ObjectManager::getInstance(LayoutAssetsManager::class);
            
            $cssPath = $assetsManager->getGeneratedCssPath($area, $layoutType, $layoutOption, $theme);
            $jsPath = $assetsManager->getGeneratedJsPath($area, $layoutType, $layoutOption, $theme);
            
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: CSS路径: ' . $cssPath);
                error_log('LayoutAssetsExtractor: JS路径: ' . $jsPath);
            }
            
            /** @var LayoutDependencyTracker $tracker */
            $tracker = ObjectManager::getInstance(LayoutDependencyTracker::class);
            
            // 根据计划要求：在编译阶段总是生成当前布局的CSS和JS
            // 不依赖needsRegeneration判断，确保每次编译都生成最新的文件
            // 提取CSS/JS
            $this->extractAndGenerateAssets(
                $content,
                $tplFile,
                $area,
                $layoutType,
                $layoutOption,
                $theme,
                $cssPath,
                $jsPath,
                $tracker
            );
            
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: 文件生成完成 - CSS: ' . (is_file($cssPath) ? '存在' : '不存在') . ', JS: ' . (is_file($jsPath) ? '存在' : '不存在'));
            }
            
            // 注意：根据计划，开发环境生成到var/generated，生产环境直接生成到pub/static
            // 所以不需要额外的复制操作
            
            // 移除内联标签并替换为外部引用
            $this->replaceWithExternalLinks($content, $event, $area, $layoutType, $layoutOption, $theme, $assetsManager);
            
        } catch (\Exception $e) {
            // 记录错误但不阻止编译
            error_log('布局资源提取失败: ' . $e->getMessage() . ' 文件: ' . $tplFile . ' 堆栈: ' . $e->getTraceAsString());
        }
    }
    
    /**
     * 检测是否为布局文件或partials文件
     * 
     * @param string $filePath 文件路径
     * @return bool
     */
    private function isLayoutOrPartialFile(string $filePath): bool
    {
        return strpos($filePath, DS . 'layouts' . DS) !== false ||
               strpos($filePath, DS . 'partials' . DS) !== false;
    }
    
    /**
     * 解析布局信息
     * 
     * @param string $filePath 文件路径
     * @return array|null 布局信息
     */
    private function parseLayoutInfo(string $filePath): ?array
    {
        // 匹配路径: .../theme/{area}/layouts/{layoutType}/{layoutOption}.phtml
        // 支持Windows和Linux路径分隔符
        // 使用更宽松的匹配，允许路径中有其他部分
        if (preg_match('/theme[\/\\\\]([^\/\\\\]+)[\/\\\\](layouts|partials)[\/\\\\]([^\/\\\\]+)[\/\\\\]([^\/\\\\]+)\.phtml$/i', $filePath, $matches)) {
            $area = $matches[1];
            $type = $matches[2]; // layouts 或 partials
            $layoutType = $matches[3];
            $layoutOption = basename($matches[4], '.phtml');
            
            if (defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: 路径解析成功 - area: ' . $area . ', type: ' . $type . ', layoutType: ' . $layoutType . ', layoutOption: ' . $layoutOption);
            }
            
            return [
                'area' => $area,
                'layoutType' => $type === 'layouts' ? $layoutType : null,
                'layoutOption' => $layoutOption,
                'isPartial' => $type === 'partials',
                'partialType' => $type === 'partials' ? $layoutType : null
            ];
        }
        
        if (defined('DEV') && DEV) {
            error_log('LayoutAssetsExtractor: 路径解析失败 - 文件路径: ' . $filePath);
        }
        
        return null;
    }
    
    /**
     * 提取并生成资源文件
     * 
     * @param string $content 模板内容
     * @param string $tplFile 模板文件路径
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme $theme 主题对象
     * @param string $cssPath CSS文件路径
     * @param string $jsPath JS文件路径
     * @param LayoutDependencyTracker $tracker 依赖追踪器
     * @return void
     */
    private function extractAndGenerateAssets(
        string $content,
        string $tplFile,
        string $area,
        string $layoutType,
        string $layoutOption,
        WelineTheme $theme,
        string $cssPath,
        string $jsPath,
        LayoutDependencyTracker $tracker
    ): void {
        /** @var AssetsExtractor $extractor */
        $extractor = ObjectManager::getInstance(AssetsExtractor::class);
        
        // 提取当前文件的CSS/JS
        $extraction = $extractor->extract($content, $tplFile);
        
        // 提取依赖的partials的CSS/JS
        $dependencies = $tracker->extractDependencies($tplFile);
        $partialExtractions = [];
        
        foreach ($dependencies as $depFile) {
            if (is_file($depFile)) {
                $depContent = file_get_contents($depFile);
                $partialExtraction = $extractor->extract($depContent, $depFile);
                $partialExtractions[] = $partialExtraction;
            }
        }
        
        // 合并所有提取结果
        $allExtractions = array_merge([$extraction], $partialExtractions);
        $merged = $extractor->mergeExtractions($allExtractions);
        
        if (defined('DEV') && DEV) {
            error_log('LayoutAssetsExtractor: 提取的CSS长度: ' . strlen($merged['css']) . ', JS长度: ' . strlen($merged['js']));
        }
        
        // 生成CSS（包含变量注入）
        // 即使没有提取到CSS，也要生成包含CSS变量的文件
        $cssContent = $this->generateCss($merged['css'], $area, $theme);
        if (!empty($cssContent)) {
            $this->writeFile($cssPath, $cssContent);
        } elseif (defined('DEV') && DEV) {
            error_log('LayoutAssetsExtractor: CSS内容为空，跳过写入');
        }
        
        // 生成JS（只有提取到JS内容时才生成）
        $jsContent = $merged['js'];
        if (!empty($jsContent)) {
            // 生产环境压缩JS
            if (!defined('DEV') || !DEV) {
                $jsContent = $this->minifyJs($jsContent);
            }
            $this->writeFile($jsPath, $jsContent);
        } elseif (defined('DEV') && DEV) {
            error_log('LayoutAssetsExtractor: JS内容为空，跳过写入');
        }
        
        // 文件在模板编译阶段直接生成到pub/static目录（不是搬迁，而是编译时生成）
    }
    
    /**
     * 生成CSS内容（包含变量注入）
     * 
     * @param string $extractedCss 提取的CSS
     * @param string $area 区域
     * @param WelineTheme $theme 主题对象
     * @return string 完整的CSS内容
     */
    private function generateCss(string $extractedCss, string $area, WelineTheme $theme): string
    {
        /** @var CssVariableInjector $injector */
        $injector = ObjectManager::getInstance(CssVariableInjector::class);
        
        // 获取scope（优先从预览模式获取，否则使用default）
        $scope = 'default';
        try {
            /** @var \Weline\Theme\Helper\PreviewManager $previewManager */
            $previewManager = ObjectManager::getInstance(\Weline\Theme\Helper\PreviewManager::class);
            if ($previewManager::isPreviewMode()) {
                $previewScope = $previewManager::getPreviewScope($area);
                if ($previewScope) {
                    $scope = $previewScope;
                }
            }
        } catch (\Exception $e) {
            // 获取预览scope失败，使用默认值
        }
        
        // 设置当前主题和区域（确保CssVariableInjector能正确读取配置）
        \Weline\Theme\Helper\ThemeData::setCurrentTheme($theme);
        \Weline\Theme\Helper\ThemeData::setCurrentArea($area);
        
        // 生成CSS变量（传递scope）
        $cssVariables = $injector->generateCssVariables($area, $theme, $scope);
        
        // 合并变量和提取的CSS
        $css = $cssVariables;
        if (!empty($extractedCss)) {
            $css .= "\n" . $extractedCss;
        }
        
        // 生产环境压缩
        if (!defined('DEV') || !DEV) {
            $css = $this->minifyCss($css);
        }
        
        return $css;
    }
    
    /**
     * 压缩CSS
     * 
     * @param string $css CSS内容
     * @return string 压缩后的CSS
     */
    private function minifyCss(string $css): string
    {
        try {
            /** @var CodeMinifier $minifier */
            $minifier = ObjectManager::getInstance(CodeMinifier::class);
            return $minifier->minify($css);
        } catch (\Exception $e) {
            // 压缩失败，返回原内容
            return $css;
        }
    }
    
    /**
     * 压缩JS
     * 
     * @param string $js JS内容
     * @return string 压缩后的JS
     */
    private function minifyJs(string $js): string
    {
        try {
            return JSMin::minify($js);
        } catch (\Exception $e) {
            // 压缩失败，返回原内容
            return $js;
        }
    }
    
    /**
     * 写入文件
     * 
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @return void
     */
    private function writeFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            $result = mkdir($dir, 0755, true);
            if (!$result && defined('DEV') && DEV) {
                error_log('LayoutAssetsExtractor: 创建目录失败 - ' . $dir);
            }
        }
        
        $result = file_put_contents($filePath, $content, LOCK_EX);
        if ($result === false && defined('DEV') && DEV) {
            error_log('LayoutAssetsExtractor: 写入文件失败 - ' . $filePath);
        } elseif (defined('DEV') && DEV) {
            error_log('LayoutAssetsExtractor: 文件写入成功 - ' . $filePath . ' (大小: ' . $result . ' 字节)');
        }
    }
    
    /**
     * 移除内联标签
     * 
     * @param string $content 模板内容
     * @param Event $event 事件对象
     * @return void
     */
    private function removeInlineTags(string &$content, Event $event): void
    {
        /** @var AssetsExtractor $extractor */
        $extractor = ObjectManager::getInstance(AssetsExtractor::class);
        
        $result = $extractor->extract($content, '');
        $content = $result['content'];
        
        $event->setData('content', $content);
    }
    
    /**
     * 替换为外部链接
     * 
     * @param string $content 模板内容
     * @param Event $event 事件对象
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme $theme 主题对象
     * @param LayoutAssetsManager $assetsManager 资源管理器
     * @return void
     */
    private function replaceWithExternalLinks(
        string &$content,
        Event $event,
        string $area,
        string $layoutType,
        string $layoutOption,
        WelineTheme $theme,
        LayoutAssetsManager $assetsManager
    ): void {
        // 先移除内联标签
        /** @var AssetsExtractor $extractor */
        $extractor = ObjectManager::getInstance(AssetsExtractor::class);
        $result = $extractor->extract($content, '');
        $content = $result['content'];
        
        // 获取文件URL
        $cssUrl = $assetsManager->getCssUrl($area, $layoutType, $layoutOption, $theme);
        $jsUrl = $assetsManager->getJsUrl($area, $layoutType, $layoutOption, $theme);
        
        // 检查是否已经存在外部链接（避免重复添加）
        $hasCssLink = strpos($content, $cssUrl) !== false;
        $hasJsLink = strpos($content, $jsUrl) !== false;
        
        // 在</head>前插入CSS链接
        if (!$hasCssLink && preg_match('/(<\/head>)/i', $content)) {
            $cssLink = "\n<link href=\"{$cssUrl}\" rel=\"stylesheet\" type=\"text/css\"/>";
            $content = preg_replace('/(<\/head>)/i', $cssLink . "\n$1", $content, 1);
        }
        
        // 在</body>前插入JS脚本
        if (!$hasJsLink && preg_match('/(<\/body>)/i', $content)) {
            $jsScript = "\n<script src=\"{$jsUrl}\"></script>";
            $content = preg_replace('/(<\/body>)/i', $jsScript . "\n$1", $content, 1);
        }
        
        $event->setData('content', $content);
    }
}

