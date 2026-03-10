<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 前端页面展示控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Frontend;

use GuoLaiRen\PageBuilder\Helper\PageHelper;
use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Style;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use Weline\Framework\App\Controller\FrontendController;

class Page extends FrontendController
{
    private PageModel $pageModel;
    private PageHelper $pageHelper;
    private Style $styleModel;
    private PageRenderService $pageRenderService;

    public function __construct(
        PageModel $pageModel,
        PageHelper $pageHelper,
        Style $styleModel,
        PageRenderService $pageRenderService
    ) {
        $this->pageModel = $pageModel;
        $this->pageHelper = $pageHelper;
        $this->styleModel = $styleModel;
        $this->pageRenderService = $pageRenderService;
    }

    /**
     * 首页访问（根路径）
     * 当访问站点根路径时，自动加载该站点的首页
     */
    public function index()
    {
        // 直接调用 view 方法，handle 为空时会自动加载首页
        return $this->view();
    }

    /**
     * 为博客类型页面加载博客数据
     */
    private function loadBlogData(PageModel $page): void
    {
        $pageType = $page->getData(PageModel::schema_fields_TYPE);
        
        // 检查是否是博客类型页面
        if (!in_array($pageType, [PageModel::TYPE_BLOG, PageModel::TYPE_BLOG_CATEGORY, PageModel::TYPE_BLOG_LIST])) {
            return;
        }
        
        $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
        $blogCategories = w_query('blog', 'getCategoryList', ['site_id' => $websiteId]);
        $this->assign('blog_categories', $blogCategories);
        
        // 获取请求参数
        $categorySlug = $this->request->getGet('category');
        $postSlug = $this->request->getGet('slug');
        $pageNum = (int)$this->request->getGet('page', 1);
        $pageNum = $pageNum > 0 ? $pageNum : 1;
        $pageSize = 12;
        
        $currentCategory = $categorySlug
            ? w_query('blog', 'getCategoryBySlug', ['slug' => $categorySlug, 'site_id' => $websiteId])
            : null;
        $this->assign('current_category', $currentCategory);
        
        if ($pageType === PageModel::TYPE_BLOG && $postSlug) {
            $currentPost = w_query('blog', 'getPostBySlug', ['slug' => $postSlug, 'site_id' => $websiteId]);
            if ($currentPost) {
                w_query('blog', 'incrementPostViewCount', ['post_id' => $currentPost['post_id']]);
                $currentPost['view_count'] = ($currentPost['view_count'] ?? 0) + 1;
                $this->assign('current_post', $currentPost);
                $relatedPosts = w_query('blog', 'getRelatedPosts', [
                    'category_id' => $currentPost['category_id'] ?? 0,
                    'exclude_post_id' => $currentPost['post_id'],
                    'site_id' => $websiteId,
                    'limit' => 6,
                ]);
                $this->assign('related_posts', $relatedPosts);
            }
        } else {
            $listResult = w_query('blog', 'getPostList', [
                'site_id' => $websiteId,
                'category_id' => $currentCategory ? ($currentCategory['category_id'] ?? null) : null,
                'page' => $pageNum,
                'page_size' => $pageSize,
            ]);
            $this->assign('blog_posts', $listResult['items'] ?? []);
            $this->assign('pagination', $listResult['pagination'] ?? []);
        }
        
        $recentPosts = w_query('blog', 'getRecentPosts', ['site_id' => $websiteId, 'limit' => 10]);
        $this->assign('recent_posts', $recentPosts);
        $postsWithTags = w_query('blog', 'getPostsWithTags', ['site_id' => $websiteId]);
        $allTags = [];
        foreach ($postsWithTags as $post) {
            foreach ($post['tags_array'] ?? [] as $tag) {
                if ($tag !== '' && !in_array($tag, $allTags)) {
                    $allTags[] = $tag;
                }
            }
        }
        $this->assign('all_tags', array_slice($allTags, 0, 20));
    }

    /**
     * 根据句柄显示页面
     */
    public function view()
    {
        $handle = $this->request->getGet('handle');
        
        // 检查是否为预览模式
        $isPreview = $this->request->getGet('preview') == '1';
        $previewPageId = $isPreview ? (int)$this->request->getGet('page_id') : 0;

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
        
        $page = null;

        // 真实预览：与可视化预览一致，按 page_id 加载页面并禁止缓存，避免 handle 歧义或缓存导致显示错误模板/数据
        if ($previewPageId > 0) {
            $response = $this->request->getResponse();
            $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
            $response->setHeader('Pragma', 'no-cache');
            $response->setHeader('Expires', '0');
            $response->setHeader('X-Accel-Expires', '0');
            $page = clone $this->pageModel;
            $page->clearData();
            $page->load($previewPageId);
        } elseif ($isPreview) {
            // 预览模式但未带 page_id 时也禁止缓存，避免看到旧模板
            $response = $this->request->getResponse();
            $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->setHeader('Pragma', 'no-cache');
            $response->setHeader('Expires', '0');
        }
        
        // 如果 handle 为空且未按 page_id 加载到页面，尝试加载该站点的首页
        if (($page === null || !$page->getId()) && empty($handle) && $websiteId > 0) {
            $page = clone $this->pageModel;
            $page->clear()
                ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PageModel::schema_fields_TYPE, PageModel::TYPE_HOME);
            
            // 如果不是预览模式，只显示已发布的页面
            if (!$isPreview) {
                $page->where(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
            }
            
            $page->find()->fetch();
            
            // 如果没找到，尝试 website_id = 0 的全局首页
            if (!$page->getId()) {
                $page = clone $this->pageModel;
                $page->clear()
                    ->where(PageModel::schema_fields_WEBSITE_ID, 0)
                    ->where(PageModel::schema_fields_TYPE, PageModel::TYPE_HOME);
                if (!$isPreview) {
                    $page->where(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
                }
                $page->find()->fetch();
            }
        }
        
        // 如果有 handle，按正常流程加载页面
        if (empty($page) || !$page->getId()) {
            if (empty($handle)) {
                $this->redirect(404);
                return;
            }
            
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

            // 如果没找到，尝试 website_id = 0（全局/默认站点，保持之前使用方式）
            if (!$page->getId() && $websiteId !== 0) {
                $page = clone $this->pageModel;
                $page->clear()
                    ->where(PageModel::schema_fields_WEBSITE_ID, 0)
                    ->where(PageModel::schema_fields_HANDLE, $handle);
                if (!$isPreview) {
                    $page->where(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
                }
                $page->find()->fetch();
            }
        }

        if (!$page || !$page->getId()) {
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
        
        // 如果是博客类型页面，加载博客数据
        $this->loadBlogData($page);

        // 获取页面的样式代码
        $styleCode = $page->getData(PageModel::schema_fields_STYLE);
        
        if ($styleCode) {
            // 验证样式是否存在
            $style = clone $this->styleModel;
            $style->clear()
                ->where(Style::schema_fields_CODE, $styleCode)
                ->find()
                ->fetch();
            
            if ($style->getId()) {
                // 确定渲染模式：preview 或 live
                $renderMode = $isPreview ? PageRenderService::MODE_PREVIEW : PageRenderService::MODE_LIVE;
                
                // 使用统一的 PageRenderService 渲染页面
                // 这确保了可视化编辑器预览和正式上线页面的渲染逻辑完全一致
                $html = $this->pageRenderService->render(
                    $page,
                    $renderMode,
                    $currentLocale,
                    null // 无临时样式
                );
                
                echo $html;
                
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
