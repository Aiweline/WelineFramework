<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统前端页面展示控制器
 */

namespace Weline\Cms\Controller\Frontend;

use Weline\Cms\Helper\PageHelper;
use Weline\Cms\Model\Page as PageModel;
use Weline\Cms\Model\Style;
use Weline\Framework\App\Controller\FrontendController;

class Page extends FrontendController
{
    private PageModel $pageModel;
    private PageHelper $pageHelper;
    private Style $styleModel;

    public function __construct(
        PageModel $pageModel,
        PageHelper $pageHelper,
        Style $styleModel
    ) {
        $this->pageModel = $pageModel;
        $this->pageHelper = $pageHelper;
        $this->styleModel = $styleModel;
    }

    /**
     * 根据句柄显示页面
     */
    public function view()
    {
        $handle = $this->request->getGet('handle');
        if (!$handle) {
            $this->redirect(404);
            return;
        }

        // 检查是否为预览模式
        $isPreview = $this->request->getGet('preview') == '1';
        
        // 获取URL中的语言参数
        $requestedLocale = $this->request->getGet('lang', $this->request->getGet('locale'));
        
        // 如果URL中指定了语言，更新Cookie
        if ($requestedLocale) {
            \Weline\Framework\Http\Cookie::set('WELINE_USER_LANG', $requestedLocale, 3600 * 24 * 30);
        }
        
        // 获取当前使用的语言（从Cookie或URL参数）
        $currentLocale = $requestedLocale ?: \Weline\Framework\Http\Cookie::getLang();

        // 获取当前网站ID
        $websiteId = \Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
        
        // 加载页面（按 website_id + handle 查询）
        $page = clone $this->pageModel;
        $page->clear()
            ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId)
            ->where(PageModel::schema_fields_HANDLE, $handle);
        
        // 如果不是预览模式，只显示已发布的页面
        if (!$isPreview) {
            $page->where(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
        }
        
        $page->find()->fetch();


        if (!$page->getId()) {
            $this->redirect(404);
            return;
        }

        // 检查页面是否选择了当前语言
        $selectedLocales = $page->getSelectedLocales();
        $isLocaleSupported = empty($selectedLocales) || in_array($currentLocale, $selectedLocales);
        
        // 获取指定语言的内容
        $localizedContent = $this->pageHelper->getLocalizedContent($page, $currentLocale);
        
        // 检查是否有该语言的翻译
        $hasTranslation = $this->pageHelper->hasTranslation($page, $currentLocale);
        
        // 获取所有可用的语言
        $availableLocales = $this->pageHelper->getAvailableLocales($page);
        
        // 获取SEO数据
        $seoData = $this->pageHelper->getSeoData($page);

        // 传递数据到视图
        $this->assign('page', $page);
        $this->assign('content', $localizedContent);
        $this->assign('seo', $seoData);
        $this->assign('title', $seoData['title']);
        $this->assign('current_locale', $currentLocale);
        $this->assign('available_locales', $availableLocales);
        $this->assign('has_translation', $hasTranslation);
        $this->assign('is_locale_supported', $isLocaleSupported);
        $this->assign('is_preview', $isPreview);

        // 获取页面的样式和样式配置
        $styleCode = $page->getData(PageModel::schema_fields_STYLE);
        $allStyleSettings = $page->getStyleSetting(); // 获取页面的所有样式配置
        
        // 获取当前样式的配置值（从所有样式配置中提取）
        $pageStyleSettings = [];
        if ($styleCode && isset($allStyleSettings[$styleCode])) {
            $rawSettings = $allStyleSettings[$styleCode];
            
            // 清理可能存在的三层结构（旧数据可能包含语言代码作为key）
            // 只保留标量值（配置项），过滤掉数组值（语言代码节点）
            foreach ($rawSettings as $key => $value) {
                if (!is_array($value)) {
                    $pageStyleSettings[$key] = $value;
                }
            }
        }
        
		if ($styleCode) {
            // 加载样式信息
            $style = clone $this->styleModel;
			$style->clear()
				->where(Style::schema_fields_CODE, $styleCode);
			// 预览模式下允许未激活样式；非预览需限制仅激活样式
			if (!$isPreview) {
				$style->where(Style::schema_fields_IS_ACTIVE, 1);
			}
			$style->find()->fetch();
            
            if ($style->getId()) {
                // 获取样式的默认配置（最低优先级）
                $parsed = $style->parseStyleConfig();
                $styleConfigs = $parsed['configs'] ?? [];  // ← 修复：提取 configs 部分
                $finalSettings = [];
                
                // 第一步：使用默认配置值
                foreach ($styleConfigs as $key => $config) {
                    if (isset($config['default'])) {
                        $finalSettings[$key] = $config['default'];
                    }
                }
                
                // 第二步：用页面保存的配置覆盖（中等优先级）
                foreach ($pageStyleSettings as $key => $value) {
                    if (isset($styleConfigs[$key])) {
                        $finalSettings[$key] = $value;
                    }
                }
                
                // 第三步：用翻译的配置覆盖（最高优先级）
                // 从本地化描述中获取翻译的样式配置
                if ($localizedContent && !empty($localizedContent['config'])) {
                    $translatedConfig = is_string($localizedContent['config']) 
                        ? json_decode($localizedContent['config'] ?? '', true) 
                        : $localizedContent['config'];
                    
                    // 检查是否有 style_config 节点
                    if (isset($translatedConfig['style_config']) && is_array($translatedConfig['style_config'])) {
                        foreach ($translatedConfig['style_config'] as $key => $value) {
                            // 只覆盖样式定义中存在的配置项
                            if (isset($styleConfigs[$key])) {
                                $finalSettings[$key] = $value;
                            }
                        }
                    }
                }
                
                // 将最终配置传递给模板（传递两个变量名以兼容不同模板）
                $this->assign('style_settings', $finalSettings);
                $this->assign('style', $finalSettings);
                
                // 使用模块路径格式渲染样式模板
                $stylePath = "Weline_Cms::templates/style/{$styleCode}";
                
                // 渲染 header
                $headerHtml = $this->fetch("{$stylePath}/header.phtml");
                
                // 渲染 content
                // 如果页面有自定义内容，使用自定义内容替代 content.phtml
                // 优先使用翻译后的内容，如果没有则使用默认内容
                $customContent = '';
                if ($localizedContent && !empty($localizedContent['content'])) {
                    // 使用翻译后的自定义内容
                    $customContent = $localizedContent['content'];
                } else {
                    // 使用默认语言的自定义内容
                    $customContent = $page->getData(PageModel::schema_fields_CONTENT);
                }
                
                if (!empty($customContent)) {
                    // 使用自定义内容（已翻译或默认）
                    $contentHtml = $customContent;
                } else {
                    // 使用样式模板的 content.phtml
                    $contentHtml = $this->fetch("{$stylePath}/content.phtml");
                }
                
                // 渲染 footer
                $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
                
                // 输出组合后的完整页面
                echo $headerHtml . $contentHtml . $footerHtml;
                
                // 如果是预览模式，添加语言切换悬浮按钮
                if ($isPreview && !empty($availableLocales) && count($availableLocales) > 1) {
                    echo $this->renderLanguageSwitcher($page, $availableLocales, $currentLocale);
                }
                
                return;
            }
        }
        
        // 如果没有指定样式或样式不存在，设置空的样式配置
        $this->assign('style', []);
        
        // 使用默认模板
        return $this->fetch();
    }
    
    /**
     * 渲染内容部分
     */
    private function renderContent(array $content, bool $hasTranslation, bool $isLocaleSupported): string
    {
        $html = '';
        
        // 标题
        $html .= '<h1 class="page-title">' . htmlspecialchars($content['title'] ?? '') . '</h1>';
        
        // 发布时间
        $page = $this->getData('page');
        if ($page && $page->getData('create_time')) {
            $html .= '<div class="page-meta">' . __('发布时间：') . $page->getData('create_time') . '</div>';
        }
        
        // 翻译提示
        if (!$hasTranslation) {
            $html .= '<div class="translation-notice">';
            $html .= '<strong>' . __('提示：') . '</strong> ';
            if (!$isLocaleSupported) {
                $html .= __('此页面不支持当前语言，以下内容显示为默认语言。');
            } else {
                $html .= __('此页面尚未翻译为当前语言，以下内容显示为默认语言。');
            }
            $html .= '</div>';
        }
        
        // 解析页面内容中的变量
        $parsedContent = $this->parseContentVariables($content['content']);
        
        // 页面内容
        $html .= '<div class="page-content">' . $parsedContent . '</div>';
        
        return $html;
    }
    
    /**
     * 解析内容中的变量
     * 支持 {{style.xxx}}, {{page.xxx}}, {{content.xxx}} 等变量
     */
    private function parseContentVariables(string $content): string
    {
        // 获取所有可用的数据
        $data = $this->getData();
        
        // 解析 {{variable.key}} 格式的变量
        $content = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\}\}/', function($matches) use ($data) {
            $varName = $matches[1];  // 如 style, page, content
            $key = $matches[2];       // 如 background_color
            
            // 检查数据是否存在
            if (isset($data[$varName])) {
                $varData = $data[$varName];
                
                // 如果是数组，返回对应的值
                if (is_array($varData) && isset($varData[$key])) {
                    return htmlspecialchars($varData[$key] ?? '');
                }
                
                // 如果是对象，尝试调用 getData 方法
                if (is_object($varData) && method_exists($varData, 'getData')) {
                    $value = $varData->getData($key);
                    return $value !== null ? htmlspecialchars($value ?? '') : '';
                }
            }
            
            // 如果变量不存在，保留原样
            return $matches[0];
        }, $content);
        
        return $content;
    }
    
    /**
     * 渲染模板
     */
    private function render(string $templatePath): string
    {
        ob_start();
        
        // 创建一个模板实例
        $template = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\View\Template::class);
        
        // 传递所有数据到模板
        foreach ($this->getData() as $key => $value) {
            $template->assign($key, $value);
        }
        
        // 渲染模板
        echo $template->fetchFile($templatePath);
        
        return ob_get_clean();
    }
    
    /**
     * 渲染语言切换悬浮按钮（仅预览模式）
     */
    private function renderLanguageSwitcher(PageModel $page, array $availableLocales, string $currentLocale): string
    {
        $handle = $page->getData(PageModel::schema_fields_HANDLE);
        // 使用友好的重写 URL 格式
        $baseUrl = '/' . $handle;
        
        $html = '<style>
            .preview-language-switcher {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 99999;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            }
            
            .preview-lang-button {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 50%;
                width: 56px;
                height: 56px;
                font-size: 24px;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .preview-lang-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
            }
            
            .preview-lang-menu {
                display: none;
                position: absolute;
                bottom: 70px;
                right: 0;
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                min-width: 200px;
                overflow: hidden;
                animation: slideUp 0.3s ease;
            }
            
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .preview-lang-menu.active {
                display: block;
            }
            
            .preview-lang-menu-header {
                padding: 12px 16px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                font-weight: 600;
                font-size: 14px;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            
            .preview-lang-menu-header .preview-mode-badge {
                background: rgba(255, 255, 255, 0.2);
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 500;
            }
            
            .preview-lang-item {
                display: block;
                padding: 12px 16px;
                color: #333;
                text-decoration: none;
                transition: background 0.2s;
                border-bottom: 1px solid #f0f0f0;
                font-size: 14px;
            }
            
            .preview-lang-item:last-child {
                border-bottom: none;
            }
            
            .preview-lang-item:hover {
                background: #f5f5f5;
            }
            
            .preview-lang-item.active {
                background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
                color: #667eea;
                font-weight: 600;
                position: relative;
            }
            
            .preview-lang-item.active::before {
                content: "✓";
                position: absolute;
                right: 16px;
                color: #667eea;
                font-weight: bold;
            }
            
            @media (max-width: 768px) {
                .preview-language-switcher {
                    bottom: 16px;
                    right: 16px;
                }
                
                .preview-lang-button {
                    width: 48px;
                    height: 48px;
                    font-size: 20px;
                }
                
                .preview-lang-menu {
                    bottom: 60px;
                    min-width: 180px;
                }
            }
        </style>
        
        <div class="preview-language-switcher">
            <button class="preview-lang-button" onclick="togglePreviewLangMenu()" title="切换语言">
                🌐
            </button>
            <div class="preview-lang-menu" id="previewLangMenu">
                <div class="preview-lang-menu-header">
                    <span>选择语言</span>
                    <span class="preview-mode-badge">预览模式</span>
                </div>';
        
        foreach ($availableLocales as $localeData) {
            // 支持两种格式：数组格式和字符串格式
            if (is_array($localeData)) {
                $localeCode = $localeData['code'] ?? '';
                $localeName = $localeData['name'] ?? $localeCode;
            } else {
                $localeCode = $localeData;
                $localeName = $localeData;
            }
            
            $isActive = ($localeCode === $currentLocale) ? ' active' : '';
            
            // 构建新的URL，替换或添加 locale 参数
            $newUrl = $this->buildUrlWithLocale($baseUrl, $localeCode);
            
            $html .= sprintf(
                '<a href="%s" class="preview-lang-item%s">%s</a>',
                htmlspecialchars($newUrl ?? ''),
                $isActive,
                htmlspecialchars($localeName ?? '')
            );
        }
        
        $html .= '    </div>
        </div>
        
        <script>
            function togglePreviewLangMenu() {
                const menu = document.getElementById("previewLangMenu");
                menu.classList.toggle("active");
            }
            
            // 点击外部关闭菜单
            document.addEventListener("click", function(event) {
                const switcher = document.querySelector(".preview-language-switcher");
                const menu = document.getElementById("previewLangMenu");
                
                if (switcher && !switcher.contains(event.target)) {
                    menu.classList.remove("active");
                }
            });
        </script>';
        
        return $html;
    }
    
    /**
     * 构建带有语言参数的URL
     */
    private function buildUrlWithLocale(string $url, string $locale): string
    {
        // 解析URL
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        
        // 解析查询字符串
        parse_str($query, $params);
        
        // 更新或添加 locale 参数
        $params['locale'] = $locale;
        
        // 如果当前请求是预览模式，保留 preview 参数
        if (isset($_GET['preview']) && $_GET['preview'] == '1') {
            $params['preview'] = '1';
        }
        
        // 重新构建URL
        $newQuery = http_build_query($params);
        
        return $path . ($newQuery ? '?' . $newQuery : '');
    }
}
