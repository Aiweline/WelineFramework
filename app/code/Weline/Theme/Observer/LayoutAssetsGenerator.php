<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\AssetsExtractor;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Model\WelineTheme;
use JSMin\JSMin;

/**
 * 布局资源生成Observer
 * 
 * 监听控制器模板获取后事件（Weline_Framework_Controller::fetch_file_after），
 * 在最终tpl文件生成后，直接从HTML中提取CSS/JS并生成布局特定的CSS/JS文件到pub/static目录，
 * 然后更新tpl内容，移除内联标签并添加外部链接
 * 
 * 注意：
 * - 在最终tpl文件生成后直接从HTML提取CSS/JS（此时所有partials的CSS/JS都在HTML中）
 * - 生成CSS/JS文件
 * - 更新tpl内容，移除内联标签并添加外部链接
 */
class LayoutAssetsGenerator implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 开始执行'));
        
        /** @var \Weline\Framework\DataObject\DataObject $eventData */
        $eventData = $event->getData('data');
        if (!$eventData instanceof \Weline\Framework\DataObject\DataObject) {
            Env::log_debug('theme_assets', __('LayoutAssetsGenerator: eventData不是DataObject实例，跳过处理'));
            return;
        }
        
        $layoutType = $eventData->getData('layoutType');
        // 关键检查：只有当控制器设置了 layoutType 时才处理
        if (empty($layoutType)) {
            Env::log_debug('theme_assets', __('LayoutAssetsGenerator: layoutType为空，跳过处理'));
            return;
        }
        
        $content = $eventData->getData('content');
        if (empty($content)) {
            Env::log_debug('theme_assets', __('LayoutAssetsGenerator: content为空，跳过处理'));
            return;
        }
        
        Env::log_debug('theme_assets', __('LayoutAssetsGenerator: layoutType=%{1}, content长度=%{2}', [$layoutType, strlen($content)]));
        
        try {
            // 从模板实例获取布局信息
            $template = \Weline\Framework\View\Template::getInstance();
            $themeData = $template->getData('theme');
            if (empty($themeData)) {
                Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 模板实例中没有theme数据，跳过处理'));
                return;
            }
            
            $area = $themeData['area'] ?? 'frontend';
            $layoutOption = $themeData['layoutOption'] ?? 'default';
            $theme = $themeData['theme'] ?? null;
            
            if (!$theme || !($theme instanceof \Weline\Theme\Model\WelineTheme)) {
                Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 无法获取主题对象，跳过处理'));
                return;
            }
            
            // 获取资源管理器
            /** @var \Weline\Theme\Helper\LayoutAssetsManager $assetsManager */
            $assetsManager = ObjectManager::getInstance(\Weline\Theme\Helper\LayoutAssetsManager::class);
            
            $cssPath = $assetsManager->getGeneratedCssPath($area, $layoutType, $layoutOption, $theme);
            $jsPath = $assetsManager->getGeneratedJsPath($area, $layoutType, $layoutOption, $theme);
            $cssUrl = $assetsManager->getCssUrl($area, $layoutType, $layoutOption, $theme);
            $jsUrl = $assetsManager->getJsUrl($area, $layoutType, $layoutOption, $theme);
            
            Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 开始处理布局资源 - area: %{1}, layoutType: %{2}, layoutOption: %{3}, CSS URL: %{4}, JS URL: %{5}', [
                $area,
                $layoutType,
                $layoutOption,
                $cssUrl,
                $jsUrl
            ]));
            
            // 从最终HTML中提取CSS/JS
            /** @var AssetsExtractor $extractor */
            $extractor = ObjectManager::getInstance(AssetsExtractor::class);
            $extraction = $extractor->extract($content, '', $cssUrl, $jsUrl);
            
            $css = trim($extraction['css'] ?? '');
            $js = trim($extraction['js'] ?? '');
            
            Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 提取结果 - CSS长度: %{1}, JS长度: %{2}', [strlen($css), strlen($js)]));
            
            // 更新编译后的tpl文件：移除内联标签（开发模式下留下注释）
            $this->updateCompiledTplFiles($eventData, $extractor, $cssUrl, $jsUrl, $layoutType, $layoutOption);
            
            // 生成CSS/JS文件（即使没有提取到内容，也要生成CSS文件以包含CSS变量）
            $hasCssFile = $this->generateAssetsForLayout($css, $js, $area, $layoutType, $layoutOption, $theme, $cssPath, $jsPath);
            
            // 更新tpl内容：移除内联标签并添加外部链接
            // 只要有CSS文件生成（即使没有提取到CSS内容，也会生成包含CSS变量的文件），就添加链接
            $content = $this->updateTplContent($extraction['content'], $cssUrl, $jsUrl, $layoutType, $layoutOption, $hasCssFile, !empty($js));
            
            Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 处理完成，内容长度: %{1}', [strlen($content)]));
            
            // 更新事件数据中的内容
            $eventData->setData('content', $content);
            
        } catch (\Exception $e) {
            Env::log_error('theme_assets', __('布局资源生成失败: %{1}', [$e->getMessage()]));
            // 不抛出异常，避免影响页面输出
        }
    }
    
    /**
     * 为指定布局生成CSS/JS文件
     * 
     * @param string $css 提取的CSS内容
     * @param string $js 提取的JS内容
     * @param string $area 区域
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param WelineTheme $theme 主题对象
     * @param string $cssPath CSS文件路径
     * @param string $jsPath JS文件路径
     * @return bool 是否生成了CSS文件
     */
    private function generateAssetsForLayout(
        string $css,
        string $js,
        string $area,
        string $layoutType,
        string $layoutOption,
        WelineTheme $theme,
        string $cssPath,
        string $jsPath
    ): bool {
        // 最终验证：确保CSS/JS中没有PHP代码
        if (!empty($css)) {
            $this->validateMergedContent($css, 'CSS');
        }
        if (!empty($js)) {
            $this->validateMergedContent($js, 'JS');
        }
        
        $hasCssFile = false;
        
        // 生成CSS（包含变量注入）
        // 即使没有提取到CSS，也要生成包含CSS变量的文件
        $cssContent = $this->generateCss($css, $area, $theme);
        if (!empty($cssContent)) {
            // 再次验证生成的CSS内容（包含CSS变量后）
            $this->validateMergedContent($cssContent, 'CSS');
            $this->writeFile($cssPath, $cssContent);
            $hasCssFile = true;
        }
        
        // 生成JS（只有提取到JS内容时才生成）
        if (!empty($js)) {
            // 生产环境压缩JS
            if (!defined('DEV') || !DEV) {
                $js = $this->minifyJs($js);
                // 压缩后再次验证
                $this->validateMergedContent($js, 'JS');
            }
            $this->writeFile($jsPath, $js);
        }
        
        Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 文件生成完成 - CSS: %{1}, JS: %{2}', [
            is_file($cssPath) ? '存在' : '不存在',
            is_file($jsPath) ? '存在' : '不存在'
        ]));
        
        return $hasCssFile;
    }
    
    /**
     * 验证内容中是否包含PHP代码
     * 
     * @param string $content 内容
     * @param string $type 类型（CSS/JS）
     * @return void
     * @throws \Exception
     */
    private function validateMergedContent(string $content, string $type): void
    {
        if (preg_match('/<\?(?:php|=|\s)/i', $content)) {
            throw new \Exception(
                __("严重错误：%{1}内容中包含PHP代码，禁止提取\n\n错误说明：\n  布局%{1}文件必须是纯%{1}，不能包含任何PHP代码。\n\n解决方案：\n  1. 如果style/script标签包含PHP代码，请添加 data-no-extract=\"true\" 属性\n  2. 使用CSS变量：在模板中定义CSS变量，然后在CSS中使用 var()\n  3. 将动态值通过PHP变量注入到CSS变量中，而不是直接在CSS中写PHP代码", [
                    $type
                ])
            );
        }
    }
    
    /**
     * 生成CSS内容（包含变量注入）
     * 
     * @param string $extractedCss 提取的CSS
     * @param string $area 区域
     * @param WelineTheme $theme 主题
     * @return string
     */
    private function generateCss(string $extractedCss, string $area, WelineTheme $theme): string
    {
        /** @var CssVariableInjector $injector */
        $injector = ObjectManager::getInstance(CssVariableInjector::class);
        // 生成CSS变量定义并添加到CSS开头
        $cssVariables = $injector->generateCssVariables($area, $theme);
        return $cssVariables . "\n" . $extractedCss;
    }
    
    /**
     * 压缩JS
     * 
     * @param string $js JS内容
     * @return string
     */
    private function minifyJs(string $js): string
    {
        try {
            return JSMin::minify($js);
        } catch (\Exception $e) {
            Env::log_warning('theme_assets', __('JS压缩失败，使用原始内容: %{1}', [$e->getMessage()]));
            return $js;
        }
    }
    
    /**
     * 写入文件
     * 
     * @param string $filePath 文件路径
     * @param string $content 内容
     * @return void
     * @throws \Exception
     */
    private function writeFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \Exception(__('创建目录失败: %{1}', [$dir]));
            }
        }
        
        $result = file_put_contents($filePath, $content);
        if ($result === false) {
            throw new \Exception(__('写入文件失败: %{1}', [$filePath]));
        }
    }
    
    /**
     * 更新tpl内容：添加外部链接
     * 
     * @param string $content 已移除内联标签的tpl内容
     * @param string $cssUrl CSS URL
     * @param string $jsUrl JS URL
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @param bool $hasCssFile 是否生成了CSS文件
     * @param bool $hasJsFile 是否生成了JS文件
     * @return string 更新后的tpl内容
     */
    private function updateTplContent(string $content, string $cssUrl, string $jsUrl, string $layoutType, string $layoutOption, bool $hasCssFile = true, bool $hasJsFile = false): string
    {
        // 只有生成了CSS文件才添加CSS链接
        if ($hasCssFile && $cssUrl) {
            // 检查是否已经存在实际的CSS链接标签（不是注释）
            // 需要检查 <link> 标签，而不是注释
            $cssUrlEscaped = htmlspecialchars($cssUrl, ENT_QUOTES);
            $hasCssLink = preg_match('/<link[^>]*href\s*=\s*["\']?' . preg_quote($cssUrlEscaped, '/') . '["\']?[^>]*>/i', $content) ||
                          preg_match('/<link[^>]*href\s*=\s*["\']?' . preg_quote($cssUrl, '/') . '["\']?[^>]*>/i', $content);
            
            // 如果不存在CSS链接标签，在</head>前插入
            if (!$hasCssLink) {
                if (preg_match('/(<\/head>)/i', $content)) {
                    $cssLink = "\n<!-- 布局CSS（自动生成）：{$layoutType}/{$layoutOption} -->\n<link href=\"{$cssUrl}\" rel=\"stylesheet\" type=\"text/css\"/>";
                    $content = preg_replace('/(<\/head>)/i', $cssLink . "\n$1", $content, 1);
                    Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 已添加CSS链接 - %{1}', [$cssUrl]));
                } else {
                    Env::log_warning('theme_assets', __('LayoutAssetsGenerator: 未找到</head>标签，无法添加CSS链接 - %{1}', [$cssUrl]));
                }
            } else {
                Env::log_debug('theme_assets', __('LayoutAssetsGenerator: CSS链接已存在，跳过添加 - %{1}', [$cssUrl]));
            }
        }
        
        // 只有生成了JS文件才添加JS链接
        if ($hasJsFile && $jsUrl) {
            // 检查是否已经存在实际的JS链接标签（不是注释）
            $jsUrlEscaped = htmlspecialchars($jsUrl, ENT_QUOTES);
            $hasJsLink = preg_match('/<script[^>]*src\s*=\s*["\']?' . preg_quote($jsUrlEscaped, '/') . '["\']?[^>]*>/i', $content) ||
                         preg_match('/<script[^>]*src\s*=\s*["\']?' . preg_quote($jsUrl, '/') . '["\']?[^>]*>/i', $content);
            
            // 如果不存在JS链接标签，在</body>前插入
            if (!$hasJsLink && preg_match('/(<\/body>)/i', $content)) {
                $jsScript = "\n<!-- 布局JS（自动生成）：{$layoutType}/{$layoutOption} -->\n<script src=\"{$jsUrl}\"></script>";
                $content = preg_replace('/(<\/body>)/i', $jsScript . "\n$1", $content, 1);
                Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 已添加JS链接 - %{1}', [$jsUrl]));
            } else {
                if ($hasJsLink) {
                    Env::log_debug('theme_assets', __('LayoutAssetsGenerator: JS链接已存在，跳过添加 - %{1}', [$jsUrl]));
                } else {
                    Env::log_warning('theme_assets', __('LayoutAssetsGenerator: 未找到</body>标签，无法添加JS链接 - %{1}', [$jsUrl]));
                }
            }
        }
        
        return $content;
    }
    
    /**
     * 更新编译后的tpl文件：移除内联CSS/JS标签
     * 开发模式下，在移除的位置留下注释说明提取到哪里了
     * 
     * @param \Weline\Framework\DataObject\DataObject $eventData 事件数据
     * @param AssetsExtractor $extractor 提取器
     * @param string $cssUrl CSS URL
     * @param string $jsUrl JS URL
     * @param string $layoutType 布局类型
     * @param string $layoutOption 布局选项
     * @return void
     */
    private function updateCompiledTplFiles(
        \Weline\Framework\DataObject\DataObject $eventData,
        AssetsExtractor $extractor,
        string $cssUrl,
        string $jsUrl,
        string $layoutType,
        string $layoutOption
    ): void {
        $template = \Weline\Framework\View\Template::getInstance();
        
        // 获取布局文件路径
        $layoutFileName = $eventData->getData('fileName');
        if (empty($layoutFileName)) {
            return;
        }
        
        // 获取编译后的文件路径
        try {
            $comFileName = $template->getFetchFile($layoutFileName);
            if (!is_file($comFileName)) {
                return;
            }
            
            // 读取编译后的文件内容
            $tplContent = file_get_contents($comFileName);
            if (empty($tplContent)) {
                return;
            }
            
            // 提取并移除内联标签（开发模式下会留下注释）
            $tplExtraction = $extractor->extract($tplContent, $comFileName, $cssUrl, $jsUrl);
            
            // 如果内容有变化，写回文件
            if ($tplExtraction['content'] !== $tplContent) {
                file_put_contents($comFileName, $tplExtraction['content'], LOCK_EX);
                Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 已更新编译文件 - %{1}', [$comFileName]));
            }
            
            // 如果有内容模板，也处理内容模板的编译文件
            $contentTemplate = $eventData->getData('contentTemplate');
            if (!empty($contentTemplate)) {
                try {
                    $contentComFileName = $template->getFetchFile($contentTemplate);
                    if (is_file($contentComFileName)) {
                        $contentTplContent = file_get_contents($contentComFileName);
                        if (!empty($contentTplContent)) {
                            $contentExtraction = $extractor->extract($contentTplContent, $contentComFileName, $cssUrl, $jsUrl);
                            if ($contentExtraction['content'] !== $contentTplContent) {
                                file_put_contents($contentComFileName, $contentExtraction['content'], LOCK_EX);
                                Env::log_debug('theme_assets', __('LayoutAssetsGenerator: 已更新内容模板编译文件 - %{1}', [$contentComFileName]));
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // 内容模板处理失败，不影响主流程
                    Env::log_warning('theme_assets', __('LayoutAssetsGenerator: 更新内容模板编译文件失败 - %{1}', [$e->getMessage()]));
                }
            }
            
        } catch (\Exception $e) {
            // 更新编译文件失败，不影响主流程
            Env::log_warning('theme_assets', __('LayoutAssetsGenerator: 更新编译文件失败 - %{1}', [$e->getMessage()]));
        }
    }
}

