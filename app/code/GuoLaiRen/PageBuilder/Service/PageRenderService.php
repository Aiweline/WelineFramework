<?php

declare(strict_types=1);

/**
 * 页面渲染服务
 * 
 * 统一渲染逻辑，确保可视化编辑器预览和正式上线页面效果一致
 * 
 * 渲染模式：
 * - visual: 可视化编辑模式（带拖拽插槽容器、组件包装器）
 * - preview: 预览模式（纯净渲染，可查看未发布页面，带预览标记脚本）
 * - live: 正式上线模式（纯净渲染，仅已发布页面）
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;
use GuoLaiRen\PageBuilder\Model\Style;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use GuoLaiRen\PageBuilder\Service\Component\ComponentResolver;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\View\Template;

class PageRenderService
{
    /** 渲染模式常量 */
    public const MODE_VISUAL = 'visual';   // 可视化编辑模式
    public const MODE_PREVIEW = 'preview'; // 预览模式
    public const MODE_LIVE = 'live';       // 正式上线模式
    
    private LayoutAssembler $layoutAssembler;
    private LayoutOwnerResolver $layoutOwnerResolver;
    private Page $pageModel;
    private Style $styleModel;
    private LocalDescription $localDescriptionModel;
    private TemplatePathResolver $pathResolver;
    private ComponentResolver $componentResolver;
    private LayoutConfigNormalizer $configNormalizer;
    private ?Request $request = null;
    private ?Template $template = null;
    
    /** @var array 模板变量 */
    private array $templateVars = [];
    
    /** @var array 组件文件映射缓存 */
    private static array $componentFilesCache = [];
    
    public function __construct(
        LayoutAssembler $layoutAssembler,
        LayoutOwnerResolver $layoutOwnerResolver,
        Page $pageModel,
        Style $styleModel,
        LocalDescription $localDescriptionModel,
        ?TemplatePathResolver $pathResolver = null,
        ?ComponentResolver $componentResolver = null,
        ?LayoutConfigNormalizer $configNormalizer = null
    ) {
        $this->layoutAssembler = $layoutAssembler;
        $this->layoutOwnerResolver = $layoutOwnerResolver;
        $this->pageModel = $pageModel;
        $this->styleModel = $styleModel;
        $this->localDescriptionModel = $localDescriptionModel;
        $this->pathResolver = $pathResolver ?? TemplatePathResolver::getInstance();
        $this->componentResolver = $componentResolver ?? ComponentResolver::getInstance();
        $this->configNormalizer = $configNormalizer ?? LayoutConfigNormalizer::getInstance();
    }
    
    /**
     * 设置请求对象（用于获取参数）
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }
    
    /**
     * 获取 Template 实例
     */
    private function getTemplate(): Template
    {
        if ($this->template === null) {
            $this->template = Template::getInstance();
        }
        return $this->template;
    }
    
    /**
     * 设置模板变量
     */
    private function assign(string $key, $value): void
    {
        $this->templateVars[$key] = $value;
        $this->getTemplate()->assign($key, $value);
    }
    
    /**
     * 渲染模板
     */
    private function fetch(string $templatePath): string
    {
        $result = $this->getTemplate()->fetch($templatePath, $this->templateVars);
        return is_string($result) ? $result : '';
    }
    
    /**
     * 渲染页面
     * 
     * @param Page $page 页面对象
     * @param string $mode 渲染模式 (visual/preview/live)
     * @param string|null $locale 语言代码
     * @param string|null $tempStyleCode 临时样式代码（用于预览）
     * @return string 渲染后的 HTML
     */
    public function render(
        Page $page,
        string $mode = self::MODE_LIVE,
        ?string $locale = null,
        ?string $tempStyleCode = null,
        ?int $virtualThemeId = null
    ): string {
        // 重置模板变量
        $this->templateVars = [];
        if ($virtualThemeId !== null && $virtualThemeId > 0) {
            $page->setData('virtual_theme_id', $virtualThemeId);
        }
        
        // 获取样式代码
        $styleCode = $tempStyleCode ?: ($page->getData('style') ?: 'default');
        
        // 获取当前语言
        $currentLocale = $locale ?: \Weline\Framework\Http\Cookie::getLang();
        
        // 构建样式配置
        $finalSettings = $this->buildStyleSettings($page, $styleCode, $currentLocale, $tempStyleCode);
        
        // 检查是否为虚拟页面（id=0，用于模板预览等场景）
        $isVirtualPage = !$page->getId();
        
        // 获取布局配置（通过 LayoutOwnerResolver 统一处理 layout_page_id 和 header/footer 继承）
        // 可视化编辑模式下允许访问草稿状态首页的 header/footer
        // 预览时传入 tempStyleCode，使“无自定义布局”时按当前预览样式加载默认 header/footer，避免页面 DB 为 default 时仍显示 default 头部
        $forBackend = ($mode === self::MODE_VISUAL);
        $layoutConfig = $this->layoutOwnerResolver->getFullLayoutConfig($page, $forBackend, $tempStyleCode);
        
        // 获取布局拥有者页面ID（用于可视化编辑时传递给脚本）
        $layoutOwnerPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageId($page);
        $this->assign('layout_owner_page_id', $layoutOwnerPageId);
        
        // 获取布局页面信息（如果使用外部布局页面）
        $layoutPageInfo = $isVirtualPage ? null : $this->layoutOwnerResolver->getLayoutPageInfo($page);
        $this->assign('layout_page_info', $layoutPageInfo);
        
        // 获取本地化内容（虚拟页面跳过数据库查询）
        $localizedContent = $isVirtualPage ? null : $this->getLocalizedContent($page, $currentLocale);
        
        // 设置模板变量
        $this->assign('page', $page);
        $this->assign('style_settings', $finalSettings);
        $this->assign('style', $finalSettings);
        $this->assign('is_preview', $mode !== self::MODE_LIVE);
        $this->assign('is_virtual_page', $isVirtualPage); // 标记为虚拟页面
        $this->assign('lang', $currentLocale);
        $this->assign('lang_local', $currentLocale);
        $this->assign('current_locale', $currentLocale);
        $this->assign('layout_config', $layoutConfig);
        $this->assign('render_mode', $mode);
        
        // 获取导航页面（用于 header 组件）
        // 虚拟页面返回空数组，避免数据库查询
        $navigationPages = $isVirtualPage ? [] : $page->getNavigationPages([], 10);
        $this->assign('navigation_pages', $navigationPages);
        
        // 如果是博客类型页面或布局中包含博客组件，加载博客数据
        // 虚拟页面跳过博客数据加载
        $hasBlogComponent = $this->hasBlogComponent($layoutConfig);
        if (!$isVirtualPage && ($page->isBlogType() || $hasBlogComponent)) {
            $this->loadBlogData($page);
        }
        
        // 渲染 header/content/footer
        $stylePath = "GuoLaiRen_PageBuilder::templates/style/{$styleCode}";
        
        // 获取页面类型
        $pageType = $page->getData(Page::schema_fields_TYPE);
        
        // 获取页面类型对应的布局信息
        $layoutInfo = $this->getLayoutInfoForPageType($styleCode, $pageType);
        $this->assign('page_type', $pageType);
        $this->assign('layout_info', $layoutInfo);
        
        // 如果页面没有自定义布局配置，加载该页面类型的默认布局配置
        // 注意：需要检查区域是否真的有有效组件，而不仅仅是非空数组
        $hasCustomHeader = $this->regionHasValidComponents($layoutConfig['header'] ?? null);
        $hasCustomContent = $this->regionHasValidComponents($layoutConfig['content'] ?? null);
        $hasCustomFooter = $this->regionHasValidComponents($layoutConfig['footer'] ?? null);
        $hasCustomLayout = $hasCustomHeader || $hasCustomContent || $hasCustomFooter;
        
        // 如果 header、content 或 footer 没有有效组件，尝试从默认配置加载
        if (!$hasCustomHeader || !$hasCustomContent || !$hasCustomFooter) {
            $defaultLayoutConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
            
            // 如果 header 为空，使用默认 header
            if (!$hasCustomHeader && !empty($defaultLayoutConfig['header'])) {
                $layoutConfig['header'] = $defaultLayoutConfig['header'];
                $this->assign('using_default_header', true);
            }
            
            // 如果 content 为空，使用默认 content
            if (!$hasCustomContent && !empty($defaultLayoutConfig['content'])) {
                $layoutConfig['content'] = $defaultLayoutConfig['content'];
                $this->assign('using_default_content', true);
            }
            
            // 如果 footer 为空，使用默认 footer
            if (!$hasCustomFooter && !empty($defaultLayoutConfig['footer'])) {
                $layoutConfig['footer'] = $defaultLayoutConfig['footer'];
                $this->assign('using_default_footer', true);
            }
            
            // 更新布局配置
            $this->assign('layout_config', $layoutConfig);
        }
        
        if (!$hasCustomLayout && $pageType) {
            // 加载页面类型的默认布局配置
            $defaultLayoutConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $pageType);
            
            if (!empty($defaultLayoutConfig['header']) || 
                !empty($defaultLayoutConfig['content']) || 
                !empty($defaultLayoutConfig['footer'])) {
                $layoutConfig = $defaultLayoutConfig;
                $this->assign('layout_config', $layoutConfig);
                $this->assign('using_default_layout', true);
            }
        }
        
        // 检查是否使用组件化渲染
        $useComponentRendering = !empty($layoutConfig) && (
            !empty($layoutConfig['header']) || 
            !empty($layoutConfig['content']) || 
            !empty($layoutConfig['footer'])
        );
        
        // 调试信息
        $debugInfo = $this->buildDebugInfo($useComponentRendering, $layoutConfig);
        
        if ($useComponentRendering) {
            // 使用组件化渲染
            $headerHtml = $this->renderRegion('header', $layoutConfig, $styleCode, $page, $finalSettings, $stylePath, $mode);
            $contentHtml = $this->renderRegion('content', $layoutConfig, $styleCode, $page, $finalSettings, $stylePath, $mode, $localizedContent);
            $footerHtml = $this->renderRegion('footer', $layoutConfig, $styleCode, $page, $finalSettings, $stylePath, $mode);
        } else {
            // 使用传统渲染方式
            $headerHtml = $this->fetch("{$stylePath}/header.phtml");
            $contentHtml = $this->renderTraditionalContent($page, $stylePath, $localizedContent);
            $footerHtml = $this->fetch("{$stylePath}/footer.phtml");
        }
        
        // 插入自定义代码
        $headerHtml = $this->injectHeaderCustomCode($headerHtml, $page);
        $footerHtml = $this->injectFooterCustomCode($footerHtml, $page);
        
        // 根据模式处理输出
        return $this->finalizeOutput($headerHtml, $contentHtml, $footerHtml, $debugInfo, $page, $styleCode, $mode, $virtualThemeId);
    }
    
    /**
     * 获取页面类型对应的布局模板路径
     * 
     * 根据样式代码和页面类型，查找对应的布局模板文件
     * 映射关系定义在 style/{styleCode}/layouts/layouts.json 中
     * 
     * @param string $styleCode 样式代码
     * @param string|null $pageType 页面类型
     * @return string|null 布局模板路径，不存在则返回 null
     */
    private function getLayoutTemplateForPageType(string $styleCode, ?string $pageType): ?string
    {
        if (empty($pageType)) {
            return null;
        }
        
        // 读取布局配置文件
        $layoutsJsonPath = $this->pathResolver->getLayoutsJsonPath($styleCode);
        
        if (!file_exists($layoutsJsonPath)) {
            return null;
        }
        
        $layoutsConfig = json_decode(file_get_contents($layoutsJsonPath), true);
        
        if (empty($layoutsConfig['layouts'][$pageType])) {
            // 如果没有对应的布局，检查是否有 fallback
            $fallback = $layoutsConfig['fallback_layout'] ?? null;
            if ($fallback && !empty($layoutsConfig['layouts'][$fallback])) {
                $layoutFile = $layoutsConfig['layouts'][$fallback]['file'] ?? null;
            } else {
                return null;
            }
        } else {
            $layoutFile = $layoutsConfig['layouts'][$pageType]['file'] ?? null;
        }
        
        if (empty($layoutFile)) {
            return null;
        }
        
        // 构建布局模板完整路径
        $templatePath = "GuoLaiRen_PageBuilder::style/{$styleCode}/layouts/{$layoutFile}";
        
        // 验证模板文件是否存在
        $layoutsPath = $this->pathResolver->getLayoutsPath($styleCode);
        $fullPath = $layoutsPath . '/' . $layoutFile;
        
        if (!file_exists($fullPath)) {
            return null;
        }
        
        return $templatePath;
    }
    
    /**
     * 获取页面类型对应的布局配置信息
     * 
     * @param string $styleCode 样式代码
     * @param string|null $pageType 页面类型
     * @return array|null 布局配置信息
     */
    public function getLayoutInfoForPageType(string $styleCode, ?string $pageType): ?array
    {
        if (empty($pageType)) {
            return null;
        }
        
        // 读取布局配置文件
        $layoutsJsonPath = $this->pathResolver->getLayoutsJsonPath($styleCode);
        
        if (!file_exists($layoutsJsonPath)) {
            return null;
        }
        
        $layoutsConfig = json_decode(file_get_contents($layoutsJsonPath), true);
        
        if (empty($layoutsConfig['layouts'][$pageType])) {
            // 如果没有对应的布局，使用 fallback
            $fallback = $layoutsConfig['fallback_layout'] ?? null;
            if ($fallback && !empty($layoutsConfig['layouts'][$fallback])) {
                return [
                    'page_type' => $fallback,
                    'layout_info' => $layoutsConfig['layouts'][$fallback],
                    'is_fallback' => true,
                ];
            }
            return null;
        }
        
        return [
            'page_type' => $pageType,
            'layout_info' => $layoutsConfig['layouts'][$pageType],
            'is_fallback' => false,
        ];
    }
    
    /**
     * 获取页面类型的默认布局配置
     * 
     * 简化逻辑：直接使用页面类型代码作为文件名
     * 例如：blog_post → layouts/default/blog_post.json
     * 
     * @param string $styleCode 样式代码
     * @param string|null $pageType 页面类型
     * @return array 默认布局配置 ['header' => [], 'content' => [], 'footer' => []]
     */
    public function getDefaultLayoutConfigForPageType(string $styleCode, ?string $pageType): array
    {
        $defaultConfig = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];
        
        if (empty($pageType)) {
            return $defaultConfig;
        }
        
        // 直接使用页面类型代码作为配置文件名
        $configFilePath = $this->pathResolver->getLayoutConfigPath($styleCode, $pageType);
        
        if (!file_exists($configFilePath)) {
            // fallback 到 custom_page
            $configFilePath = $this->pathResolver->getLayoutConfigPath($styleCode, 'custom_page');
            if (!file_exists($configFilePath)) {
                return $defaultConfig;
            }
        }
        
        $configData = json_decode(file_get_contents($configFilePath), true);
        
        if (empty($configData['layout_config'])) {
            return $defaultConfig;
        }
        
        $pageConfig = $configData['layout_config'];
        
        // 处理继承（header/footer 从首页继承）
        $inheritRegions = $configData['inherit_regions'] ?? [];
        
        foreach (['header', 'footer'] as $region) {
            // 如果该区域为空数组且需要继承
            if (empty($pageConfig[$region]) && isset($inheritRegions[$region])) {
                $inheritFrom = $inheritRegions[$region];
                $inheritConfig = $this->getDefaultLayoutConfigForPageType($styleCode, $inheritFrom);
                $pageConfig[$region] = $inheritConfig[$region] ?? [];
            }
        }
        
        return [
            'header' => $pageConfig['header'] ?? [],
            'content' => $pageConfig['content'] ?? [],
            'footer' => $pageConfig['footer'] ?? [],
        ];
    }
    
    /**
     * 检查区域配置是否包含有效的组件
     * 
     * 处理两种格式：
     * 1. 数组格式：[{code: ..., enabled: ...}, ...]
     * 2. PageLayout 导出格式：{component: ..., config: ...}
     * 
     * @param mixed $regionConfig 区域配置
     * @return bool 是否有有效组件
     */
    private function regionHasValidComponents($regionConfig): bool
    {
        if (empty($regionConfig)) {
            return false;
        }
        
        // 如果是数组格式 [{code: ...}, ...]
        if (is_array($regionConfig) && isset($regionConfig[0])) {
            foreach ($regionConfig as $component) {
                if (!empty($component['code']) || !empty($component['component'])) {
                    return true;
                }
            }
            return false;
        }
        
        // 如果是 PageLayout 导出格式 {component: ..., config: ...}
        if (is_array($regionConfig) && array_key_exists('component', $regionConfig)) {
            return !empty($regionConfig['component']);
        }
        
        // 如果是单组件格式 {code: ...}
        if (is_array($regionConfig) && isset($regionConfig['code'])) {
            return !empty($regionConfig['code']);
        }
        
        return false;
    }
    
    /**
     * 检查布局配置中是否包含博客组件
     */
    private function hasBlogComponent(array $layoutConfig): bool
    {
        $blogComponents = ['blog-list', 'blog-detail', 'blog-content', 'blog-sidebar'];
        
        foreach (['header', 'content', 'footer'] as $region) {
            if (!empty($layoutConfig[$region])) {
                foreach ($layoutConfig[$region] as $component) {
                    $code = $component['code'] ?? '';
                    if (in_array($code, $blogComponents) || strpos($code, 'blog') !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * 构建样式配置
     */
    private function buildStyleSettings(Page $page, string $styleCode, string $currentLocale, ?string $tempStyleCode): array
    {
        $finalSettings = [];
        
        // 检查是否为虚拟页面
        $isVirtualPage = !$page->getId();
        
        // 加载样式模型获取默认配置
        $style = clone $this->styleModel;
        $style->clear()
            ->where(Style::schema_fields_CODE, $styleCode)
            ->find()
            ->fetch();
        
        // 第一步：使用模板默认配置值（最低优先级）
        if ($style->getId()) {
            $parsed = $style->parseStyleConfig();
            $styleConfigs = $parsed['configs'] ?? [];
            
            foreach ($styleConfigs as $key => $config) {
                if (isset($config['default'])) {
                    $finalSettings[$key] = $config['default'];
                }
            }
        }
        
        // 第二步：用页面保存的配置覆盖（中等优先级）
        // 如果使用临时样式，跳过页面配置（只使用模板默认值）
        // 虚拟页面也跳过此步骤
        if (!$isVirtualPage && (!$tempStyleCode || $tempStyleCode === $page->getData('style'))) {
            $allStyleSettings = $page->getStyleSetting();
            if ($styleCode && isset($allStyleSettings[$styleCode])) {
                $rawSettings = $allStyleSettings[$styleCode];
                // 清理可能存在的三层结构，只保留标量值
                foreach ($rawSettings as $key => $value) {
                    if (!is_array($value)) {
                        $finalSettings[$key] = $value;
                    }
                }
            }
        }
        
        // 第三步：用翻译的配置覆盖（最高优先级）
        // 虚拟页面跳过数据库查询
        if (!$isVirtualPage) {
            $localizedContent = $this->getLocalizedContent($page, $currentLocale);
            if ($localizedContent && !empty($localizedContent['config'])) {
                $translatedConfig = is_string($localizedContent['config']) 
                    ? json_decode($localizedContent['config'] ?? '', true) 
                    : $localizedContent['config'];
                
                if (isset($translatedConfig['style_config']) && is_array($translatedConfig['style_config'])) {
                    foreach ($translatedConfig['style_config'] as $key => $value) {
                        $finalSettings[$key] = $value;
                    }
                }
            }
        }
        
        return $finalSettings;
    }
    
    /**
     * 获取本地化内容
     */
    private function getLocalizedContent(Page $page, string $locale): ?array
    {
        if (!$locale) {
            return null;
        }
        
        $localDesc = clone $this->localDescriptionModel;
        $localDesc->clear()
            ->where(LocalDescription::schema_fields_ID, $page->getId())
            ->where('local_code', $locale)
            ->find()
            ->fetch();
        
        if ($localDesc->getId()) {
            return [
                'content' => $localDesc->getData('content'),
                'config' => $localDesc->getData('config')
            ];
        }
        
        return null;
    }
    
    /**
     * 为博客类型页面加载博客数据
     * 
     * 优先使用已通过 Template::getInstance()->assign() 预设的数据
     * 如果没有预设数据，则自动加载
     */
    private function loadBlogData(Page $page): void
    {
        $pageType = $page->getData(Page::schema_fields_TYPE);
        $template = $this->getTemplate();
        
        // 检查是否已有预设的博客数据（由控制器预先设置）
        $existingBlogPosts = $template->getData('blog_posts');
        $existingCategories = $template->getData('blog_categories');
        $existingCurrentPost = $template->getData('current_post');
        $existingRelatedPosts = $template->getData('related_posts');
        $existingRecentPosts = $template->getData('recent_posts');
        
        // 博客文章列表：优先使用预设数据
        if (!empty($existingBlogPosts)) {
            $this->assign('blog_posts', $existingBlogPosts);
        } else {
            $blogPosts = $page->getBlogPosts(20, 'published_at', 'DESC');
            $this->assign('blog_posts', $blogPosts);
        }
        
        // 博客分类：优先使用预设数据
        if (!empty($existingCategories)) {
            $this->assign('blog_categories', $existingCategories);
        } else {
            $blogCategories = $page->getBlogCategories();
            $this->assign('blog_categories', $blogCategories);
        }
        
        // 如果是博客文章详情页，获取当前文章
        if ($pageType === Page::TYPE_BLOG || !empty($existingCurrentPost)) {
            if (!empty($existingCurrentPost)) {
                $this->assign('current_post', $existingCurrentPost);
                
                // 相关文章：优先使用预设数据
                if (!empty($existingRelatedPosts)) {
                    $this->assign('related_posts', $existingRelatedPosts);
                } elseif ($existingCurrentPost) {
                    $relatedPosts = $this->getRelatedBlogPosts($existingCurrentPost, 6);
                    $this->assign('related_posts', $relatedPosts);
                }
            } else {
                // 从 URL 参数获取文章 slug
                $slug = $this->request ? $this->request->getGet('slug') : null;
                if ($slug) {
                    $currentPost = $this->getBlogPostBySlug($slug);
                    $this->assign('current_post', $currentPost);
                    
                    // 获取相关文章
                    if ($currentPost) {
                        $relatedPosts = $this->getRelatedBlogPosts($currentPost, 6);
                        $this->assign('related_posts', $relatedPosts);
                    }
                }
            }
        }
        
        // 如果是博客分类页，获取当前分类和分类下的文章
        if ($pageType === Page::TYPE_BLOG_CATEGORY) {
            $categorySlug = $this->request ? $this->request->getGet('category') : null;
            if ($categorySlug) {
                $currentCategory = $this->getBlogCategoryBySlug($categorySlug);
                $this->assign('current_category', $currentCategory);
                
                if ($currentCategory) {
                    $categoryPosts = $this->getBlogPostsByCategory($currentCategory['category_id'], 20);
                    $this->assign('category_posts', $categoryPosts);
                }
            }
        }
        
        // 最近文章（用于侧边栏）：优先使用预设数据
        if (!empty($existingRecentPosts)) {
            $this->assign('recent_posts', $existingRecentPosts);
        } else {
            $recentPosts = $page->getBlogPosts(10, 'published_at', 'DESC');
            $this->assign('recent_posts', $recentPosts);
        }
    }
    
    private function getBlogPostBySlug(string $slug): ?array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getPostBySlug', ['slug' => $slug, 'site_id' => $websiteId]);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getBlogCategoryBySlug(string $slug): ?array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getCategoryBySlug', ['slug' => $slug, 'site_id' => $websiteId]);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getBlogPostsByCategory(int $categoryId, int $limit = 20): array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            $result = w_query('blog', 'getPostList', [
                'site_id' => $websiteId,
                'category_id' => $categoryId,
                'page' => 1,
                'page_size' => $limit,
            ]);
            return $result['items'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取相关博客文章
     */
    private function getRelatedBlogPosts(array $currentPost, int $limit = 6): array
    {
        try {
            $websiteId = (int)\Weline\Websites\Data\WebsiteData::getWebsiteId();
            return w_query('blog', 'getRelatedPosts', [
                'category_id' => (int)($currentPost['category_id'] ?? 0),
                'exclude_post_id' => (int)($currentPost['post_id'] ?? 0),
                'site_id' => $websiteId,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 渲染区域
     */
    private function renderRegion(
        string $region,
        array $layoutConfig,
        string $styleCode,
        Page $page,
        array $styleSettings,
        string $stylePath,
        string $mode,
        ?array $localizedContent = null
    ): string {
        $regionConfig = $layoutConfig[$region] ?? [];
        
        // 规范化布局配置结构
        // PageLayout.exportConfig() 返回 header/footer 为 {component: ..., config: ...}
        // 但 renderRegionComponents() 期望 [{code: ..., enabled: ..., config: ...}]
        if (!empty($regionConfig)) {
            $components = $this->normalizeRegionConfig($region, $regionConfig);
            if (!empty($components)) {
                return $this->renderRegionComponents(
                    $region, 
                    $components, 
                    $styleCode, 
                    $page, 
                    $styleSettings, 
                    $mode
                );
            }
        }
        
        // 回退到默认模板或自定义内容
        if ($region === 'content') {
            return $this->renderTraditionalContent($page, $stylePath, $localizedContent);
        }
        
        return $this->fetch("{$stylePath}/{$region}.phtml");
    }
    
    /**
     * 规范化区域配置结构
     * 
     * 将不同格式的配置转换为统一的组件数组格式
     * 
     * @param string $region 区域名称 (header/content/footer)
     * @param mixed $config 区域配置
     * @return array 统一格式的组件数组
     */
    private function normalizeRegionConfig(string $region, $config): array
    {
        if (empty($config)) {
            return [];
        }
        
        // 如果已经是正确的组件数组格式 [{code: ..., ...}, ...]
        if (is_array($config) && isset($config[0]) && isset($config[0]['code'])) {
            return $config;
        }
        
        // 如果是 PageLayout.exportConfig() 格式的 header/footer: {component: ..., config: ...}
        if (is_array($config) && isset($config['component'])) {
            $component = $config['component'];
            if (empty($component)) {
                return [];
            }
            return [
                [
                    'code' => $component,
                    'enabled' => true,
                    'config' => $config['config'] ?? [],
                ]
            ];
        }
        
        // 如果是带有 code 的单组件配置 {code: ..., config: ...}
        if (is_array($config) && isset($config['code'])) {
            return [$config];
        }
        
        // content 区域可能直接是组件数组（不需要转换）
        if ($region === 'content' && is_array($config)) {
            // 检查第一个元素是否有 code 或 component 键
            $firstItem = reset($config);
            if (is_array($firstItem)) {
                if (isset($firstItem['code'])) {
                    return $config;
                }
                if (isset($firstItem['component'])) {
                    // 转换格式
                    return array_map(function($item) {
                        return [
                            'code' => $item['component'] ?? '',
                            'enabled' => $item['enabled'] ?? true,
                            'config' => $item['config'] ?? [],
                        ];
                    }, $config);
                }
            }
        }
        
        return [];
    }
    
    /**
     * 渲染传统内容（非组件化）
     */
    private function renderTraditionalContent(Page $page, string $stylePath, ?array $localizedContent): string
    {
        $customContent = '';
        if ($localizedContent && !empty($localizedContent['content'])) {
            $customContent = $localizedContent['content'];
        } else {
            $customContent = $page->getData(Page::schema_fields_CONTENT);
        }
        
        if (!empty($customContent)) {
            return $customContent;
        }
        
        return $this->fetch("{$stylePath}/content.phtml");
    }
    
    /**
     * 渲染区域组件
     */
    private function renderRegionComponents(
        string $region,
        array $components,
        string $styleCode,
        Page $page,
        array $styleSettings,
        string $mode
    ): string {
        if (empty($components)) {
            return '';
        }
        
        $html = '';
        $isVisualEditor = ($mode === self::MODE_VISUAL);
        
        // 组件代码到文件的映射
        $componentFiles = $this->getComponentFilesMap($styleCode);
        
        $html .= "<!-- Rendering region: {$region}, styleCode: {$styleCode}, components: " . count($components) . ", mode: {$mode} -->\n";
        
        $componentIndex = 0;
        foreach ($components as $componentConfig) {
            $code = $componentConfig['code'] ?? '';
            $enabled = $componentConfig['enabled'] ?? true;
            $config = $componentConfig['config'] ?? [];
            $componentTemplateCode = $componentConfig['template_code'] ?? '';
            
            if (!$enabled || empty($code)) {
                $componentIndex++;
                continue;
            }
            
            // 确定使用哪个模板的组件文件
            $useTemplateCode = $styleCode;
            
            // 查找组件文件
            $componentFile = $componentFiles[$code] ?? null;
            
            // 🔧 处理 {styleCode}-header/footer 格式
            if (!$componentFile) {
                if ($code === $styleCode . '-header' || $code === 'header') {
                    $componentFile = $componentFiles['header-nav'] ?? null;
                } elseif ($code === $styleCode . '-footer' || $code === 'footer') {
                    $componentFile = $componentFiles['footer-links'] ?? null;
                }
            }
            
            // 🔧 处理 Component 模型生成的特殊格式：{styleCode}_header_header, {styleCode}_footer_footer
            if (!$componentFile) {
                if (preg_match('/^' . preg_quote($styleCode, '/') . '_header_header$/i', $code)) {
                    $componentFile = $componentFiles['header-nav'] ?? null;
                } elseif (preg_match('/^' . preg_quote($styleCode, '/') . '_footer_(footer|links)$/i', $code)) {
                    $componentFile = $componentFiles['footer-links'] ?? null;
                }
            }
            
            // 🔧 处理下划线格式的组件代码（Component 模型生成的格式）
            // 例如：tpmst_header_nav -> header-nav
            if (!$componentFile && strpos($code, $styleCode . '_') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $codeWithDash = str_replace('_', '-', $codeWithoutPrefix);
                $componentFile = $componentFiles[$codeWithDash] ?? null;
            }
            
            // 尝试去掉模板前缀（破折号格式）
            if (!$componentFile && strpos($code, $styleCode . '-') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
            }
            
            // 🔧 尝试转换下划线为破折号后查找
            if (!$componentFile && str_contains($code, '_')) {
                $codeWithDash = str_replace('_', '-', $code);
                $componentFile = $componentFiles[$codeWithDash] ?? null;
            }
            
            // 尝试从指定的其他模板查找
            if (!$componentFile && !empty($componentTemplateCode) && $componentTemplateCode !== $styleCode) {
                $otherComponentFiles = $this->getComponentFilesMap($componentTemplateCode);
                $componentFile = $otherComponentFiles[$code] ?? null;
                
                if (!$componentFile && strpos($code, $componentTemplateCode . '-') === 0) {
                    $codeWithoutPrefix = substr($code, strlen($componentTemplateCode) + 1);
                    $componentFile = $otherComponentFiles[$codeWithoutPrefix] ?? null;
                }
                
                if ($componentFile) {
                    $useTemplateCode = $componentTemplateCode;
                }
            }
            
            // 如果仍未找到，尝试通过 Component 模型解析（支持数据库注册的组件）
            $componentPath = null;
            if (!$componentFile) {
                $modelResolution = $this->resolveComponentViaModel($code, $styleCode);
                if ($modelResolution) {
                    $componentPath = $modelResolution['path'];
                    $useTemplateCode = $modelResolution['style_code'];
                    $html .= "<!-- Component {$code} resolved via Component model -->\n";
                }
            }

            if (!$componentFile && !$componentPath) {
                $virtualThemeHtml = $this->renderVirtualThemeComponent(
                    $code,
                    $page,
                    \is_array($config) ? $config : [],
                    $styleSettings,
                    $region,
                    $componentIndex,
                    $isVisualEditor,
                    $mode
                );
                if ($virtualThemeHtml !== null) {
                    $html .= $virtualThemeHtml;
                    $componentIndex++;
                    continue;
                }
            }
            
            if (!$componentFile && !$componentPath) {
                $html .= "<!-- Component not found: {$code} (tried file-based and Component model) -->\n";
                $componentIndex++;
                continue;
            }
            
            // 构建组件模板路径（如果未通过 Component 模型解析）
            if (!$componentPath) {
                $componentPath = $this->pathResolver->getComponentTemplateReference($useTemplateCode, $componentFile);
            }
            
            // 传递数据到组件
            $this->assign('page', $page);
            $this->assign('style', $styleSettings);
            $this->assign('style_settings', $styleSettings);
            $this->assign('component_config', $config);
            
            try {
                $componentHtml = $this->fetch($componentPath);
                
                if (empty($componentHtml)) {
                    $html .= "<!-- Component {$code} rendered but output is empty -->\n";
                } else {
                    // 在可视化编辑器模式下，添加组件包装器
                    if ($isVisualEditor) {
                        $escapedCode = htmlspecialchars($code);
                        $escapedRegion = htmlspecialchars($region);
                        // 存储组件实际所属的模板代码（用于跨模板组件编辑）
                        $escapedStyleCode = htmlspecialchars($useTemplateCode);
                        $componentHtml = "<div class=\"tpmst-component-wrapper\" data-component=\"{$escapedCode}\" data-region=\"{$escapedRegion}\" data-index=\"{$componentIndex}\" data-style-code=\"{$escapedStyleCode}\">{$componentHtml}</div>";
                    }
                    $html .= $componentHtml;
                    $html .= "<!-- Component {$code} rendered successfully -->\n";
                }
            } catch (\Throwable $e) {
                $html .= "<!-- Error rendering component {$code}: " . htmlspecialchars($e->getMessage()) . " -->\n";
            }
            
            $componentIndex++;
        }
        
        return $html;
    }
    
    /**
     * 获取组件文件映射
     * 
     * 使用 ComponentResolver 获取组件映射
     */
    private function renderVirtualThemeComponent(
        string $code,
        Page $page,
        array $config,
        array $styleSettings,
        string $region,
        int $componentIndex,
        bool $isVisualEditor,
        string $mode
    ): ?string {
        $virtualThemeId = (int)$page->getData('virtual_theme_id');
        if ($virtualThemeId <= 0) {
            $virtualThemeId = $this->resolveActiveAiVirtualThemeId((int)$page->getData(Page::schema_fields_WEBSITE_ID));
        }
        if ($virtualThemeId <= 0) {
            return null;
        }

        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery()
            ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $code)
            ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
            ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        if ((int)$component->getId() <= 0) {
            return null;
        }

        $templateContent = $component->getTemplateContent();
        if (\trim($templateContent) === '') {
            return "<!-- Component {$code} resolved via Weline_Theme virtual theme but template is empty -->\n";
        }

        $componentConfig = \array_replace($component->getDefaultConfig(), $config);
        $vars = \array_replace($this->templateVars, $componentConfig, [
            'page' => $page,
            'style' => $styleSettings,
            'style_settings' => $styleSettings,
            'component_config' => $componentConfig,
            'block' => $this->getTemplate(),
            'render_mode' => $mode,
            'virtual_theme_id' => $virtualThemeId,
        ]);

        try {
            $componentHtml = $this->renderPhtmlString($templateContent, $vars);
        } catch (\Throwable $throwable) {
            return '<!-- Error rendering virtual theme component ' . \htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . ': ' . \htmlspecialchars($throwable->getMessage(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . " -->\n";
        }
        $componentHtml = $this->sanitizeVirtualThemeComponentHtml($componentHtml);

        $marker = "<!-- Component {$code} resolved via Weline_Theme virtual theme -->\n";
        if ($isVisualEditor) {
            $escapedCode = \htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $escapedRegion = \htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $componentHtml = "<div class=\"tpmst-component-wrapper\" data-component=\"{$escapedCode}\" data-region=\"{$escapedRegion}\" data-index=\"{$componentIndex}\" data-style-code=\"virtual-theme\">{$componentHtml}</div>";
        }

        return $marker . $componentHtml . "\n<!-- Component {$code} rendered successfully -->\n";
    }

    private function sanitizeVirtualThemeComponentHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $html = \preg_replace_callback(
            '/<strong>\s*(?:Game Card|Category|Badge)\s+\d+\s*<\/strong>\s*<p>(.*?)<\/p>/isu',
            static function (array $matches): string {
                $body = \trim((string)\strip_tags((string)($matches[1] ?? '')));
                if ($body === '') {
                    return '';
                }
                $title = $body;
                $description = 'A focused highlight from this section.';
                if (\str_contains($body, '–')) {
                    [$title, $description] = \array_map('trim', \explode('–', $body, 2));
                } elseif (\str_contains($body, '-')) {
                    [$title, $description] = \array_map('trim', \explode('-', $body, 2));
                }

                return '<strong>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
                    . '<p>' . \htmlspecialchars($description !== '' ? $description : $body, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            },
            $html
        ) ?? $html;

        $html = $this->sanitizeGeneratedBrandCopy($html);
        $html = $this->sanitizeGeneratedLogoImages($html);
        $html = $this->sanitizePersistedHeroImageLayout($html);

        return $html;
    }

    private function sanitizeGeneratedBrandCopy(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        return \preg_replace('/\b([A-Z][A-Za-z0-9-]{2,40})\s+(?:websiteProfile|WebsiteProfile|Website Profile)\b/u', '$1', $html) ?? $html;
    }

    private function sanitizeGeneratedLogoImages(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $html = \preg_replace('/<img\b[^>]*\bsrc=(["\'])[^"\']*\/ai-generated\/\1[^>]*>\s*/iu', '', $html) ?? $html;

        return \preg_replace_callback(
            '/<a\b([^>]*\bclass=(["\'])(?=[^"\']*\blogo\b)[^"\']*\2[^>]*)>(.*?)<\/a>/isu',
            static function (array $matches): string {
                $attrs = (string)($matches[1] ?? '');
                $inner = (string)($matches[3] ?? '');
                $inner = \preg_replace_callback(
                    '/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>\s*/iu',
                    static function (array $imgMatches): string {
                        $src = \strtolower((string)($imgMatches[2] ?? ''));
                        if (\str_contains($src, '/ai-generated/') && !\str_contains($src, 'logo') && !\str_contains($src, 'identity')) {
                            return '';
                        }

                        return (string)($imgMatches[0] ?? '');
                    },
                    $inner
                ) ?? $inner;

                return '<a ' . $attrs . '>' . $inner . '</a>';
            },
            $html
        ) ?? $html;
    }

    private function sanitizePersistedHeroImageLayout(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $html = \str_replace('class="ai-site-hero-image ai-site-visual-image"', 'class="ai-site-visual-image ai-site-hero-image"', $html);
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-image)\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { position:absolute !important; inset:0 !important; top:0 !important; right:0 !important; bottom:0 !important; left:0 !important; width:100% !important; height:100% !important; max-width:none !important; aspect-ratio:auto !important; transform:none !important; object-fit:cover !important; object-position:center !important; z-index:0 !important; border-radius:0 !important; border:0 !important; box-shadow:none !important; filter:saturate(1.08) contrast(1.05) brightness(.84) !important; }',
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero)\s*\{[^}]*\}/iu',
            static function (array $matches): string {
                $selector = (string)($matches[1] ?? '.ai-site-hero');
                $rule = (string)($matches[0] ?? '');
                $rule = \preg_replace('/min-height\s*:\s*[^;]+;?/iu', '', $rule) ?? $rule;
                $body = \trim((string)\preg_replace('/^[^{]+\{/u', '', \substr($rule, 0, -1)));
                return $selector . ' { ' . $body . ' width:100vw !important; margin-left:calc(50% - 50vw) !important; margin-right:calc(50% - 50vw) !important; min-height:clamp(620px,78vh,820px) !important; }';
            },
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-track)\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { position:relative !important; z-index:2 !important; display:grid !important; grid-template-areas:"slide" !important; min-height:inherit !important; width:min(1200px,calc(100% - 48px)) !important; max-width:none !important; margin:0 auto !important; padding:clamp(72px,9vw,128px) 0 !important; }',
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-slide)\s*\{[^}]*\}/iu',
            static function (array $matches): string {
                $selector = (string)($matches[1] ?? '.ai-site-hero-slide');
                $rule = (string)($matches[0] ?? '');
                $rule = \preg_replace('/max-width\s*:\s*[^;]+;?/iu', '', $rule) ?? $rule;
                $body = \trim((string)\preg_replace('/^[^{]+\{/u', '', \substr($rule, 0, -1)));
                return $selector . ' { ' . $body . ' max-width:min(720px,58vw) !important; }';
            },
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero--has-photo:after)\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { content:"" !important; position:absolute !important; inset:0 !important; background:linear-gradient(100deg,rgba(5,10,20,.88) 0%,rgba(5,10,20,.66) 42%,rgba(5,10,20,.20) 72%,rgba(5,10,20,.42) 100%) !important; z-index:1 !important; pointer-events:none !important; }',
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-slide\[aria-hidden="false"\])\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { opacity:1 !important; transform:translateX(0) !important; pointer-events:auto !important; padding:clamp(30px,4vw,52px) !important; border-radius:34px !important; background:linear-gradient(135deg,rgba(4,10,22,.78),rgba(4,10,22,.44)) !important; border:1px solid rgba(255,255,255,.22) !important; box-shadow:0 34px 110px rgba(0,0,0,.36) !important; backdrop-filter:blur(14px) !important; color:#fff !important; text-align:left !important; }',
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-title)\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { margin:0 0 18px !important; font-size:clamp(48px,6.2vw,88px) !important; line-height:.98 !important; letter-spacing:-.055em !important; font-weight:900 !important; color:#fff !important; text-shadow:0 4px 0 rgba(0,0,0,.18),0 20px 54px rgba(0,0,0,.70) !important; }',
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-body)\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { margin:0 0 30px !important; max-width:58ch !important; font-size:clamp(18px,1.45vw,22px) !important; line-height:1.62 !important; color:rgba(255,255,255,.94) !important; text-shadow:0 8px 26px rgba(0,0,0,.56) !important; }',
            $html
        ) ?? $html;
        $html = \preg_replace_callback(
            '/((?:#[A-Za-z0-9_-]+\s+)?\.ai-site-hero-eyebrow)\s*\{[^}]*\}/iu',
            static fn(array $matches): string => (string)$matches[1] . ' { display:inline-flex !important; padding:7px 15px !important; margin-bottom:20px !important; border-radius:999px !important; background:rgba(255,255,255,.18) !important; color:#fff !important; font-size:12px !important; font-weight:800 !important; letter-spacing:.16em !important; text-transform:uppercase !important; }',
            $html
        ) ?? $html;
        $html = \str_replace(
            '.ai-site-hero-slide[aria-hidden="false"] { opacity:1; transform:translateX(0); pointer-events:auto; }',
            '.ai-site-hero-slide[aria-hidden="false"] { opacity:1; transform:translateX(0); pointer-events:auto; padding:34px 36px; border-radius:32px; background:linear-gradient(135deg,rgba(7,13,25,.68),rgba(7,13,25,.30)); border:1px solid rgba(255,255,255,.14); box-shadow:0 30px 90px rgba(0,0,0,.28); backdrop-filter:blur(12px); }',
            $html
        );
        $html = \str_replace(
            'font-size:clamp(36px,5vw,64px); line-height:1.05; letter-spacing:-.02em; font-weight:800; color:#fff; text-shadow:0 12px 34px rgba(0,0,0,.42);',
            'font-size:clamp(40px,5.6vw,76px); line-height:1.02; letter-spacing:-.045em; font-weight:900; color:#fff; text-shadow:0 3px 0 rgba(0,0,0,.16),0 18px 42px rgba(0,0,0,.58);',
            $html
        );
        $html = \str_replace(
            'max-width:62ch; font-size:clamp(16px,1.4vw,20px); line-height:1.6; color:rgba(255,255,255,.96); text-shadow:0 8px 24px rgba(0,0,0,.36);',
            'max-width:58ch; font-size:clamp(17px,1.45vw,21px); line-height:1.62; color:rgba(255,255,255,.98); text-shadow:0 8px 24px rgba(0,0,0,.42);',
            $html
        );

        return $html;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderPhtmlString(string $templateContent, array $vars): string
    {
        $initialObLevel = \ob_get_level();
        FiberOutputBuffer::beginCapture();
        try {
            $template = $this->getTemplate();
            $renderer = \Closure::bind(
                function () use ($templateContent, $vars): void {
                    if ($vars !== []) {
                        $this->addData($vars);
                    }
                    $block = $this;
                    $this->setData('block', $this);
                    if ($this->getData()) {
                        \extract($this->getData(), \EXTR_SKIP);
                    }
                    eval('?>' . $templateContent);
                },
                $template,
                $template::class
            );
            $renderer();
        } catch (\Throwable $throwable) {
            if (\Weline\Framework\Runtime\Runtime::isPersistent()) {
                FiberOutputBuffer::discardCapture();
            } elseif (\ob_get_level() > $initialObLevel) {
                \ob_end_clean();
            }
            throw $throwable;
        }

        return FiberOutputBuffer::endCapture();
    }

    private function getComponentFilesMap(string $styleCode): array
    {
        return $this->componentResolver->getComponentFilesMap($styleCode);
    }
    
    /**
     * 通过 Component 模型解析组件模板路径
     * 
     * 这是一个备用方法，当 component.json 中找不到组件时使用
     * 可以支持跨模板组件解析
     * 
     * @param string $componentCode 组件代码
     * @param string $preferredStyleCode 首选样式代码
     * @return array|null ['path' => '模板路径', 'style_code' => '实际使用的样式代码']
     */
    private function resolveComponentViaModel(string $componentCode, string $preferredStyleCode): ?array
    {
        try {
            $componentModelClass = '\\GuoLaiRen\\PageBuilder\\Model\\Component';
            if (!class_exists($componentModelClass)) {
                return null;
            }
            
            $componentModel = ObjectManager::getInstance($componentModelClass);
            
            // 首先尝试在首选样式中查找
            $component = clone $componentModel;
            $component->clear()
                ->where($componentModelClass::schema_fields_CODE, $componentCode)
                ->where($componentModelClass::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                $path = $component->getData($componentModelClass::schema_fields_PATH);
                $styleCode = $component->getData($componentModelClass::schema_fields_STYLE_CODE);
                
                if ($path) {
                    // 如果是相对路径，转换为模板引用
                    if (strpos($path, 'style/') === 0) {
                        return [
                            'path' => "GuoLaiRen_PageBuilder::templates/{$path}",
                            'style_code' => $styleCode,
                        ];
                    }
                    return [
                        'path' => $path,
                        'style_code' => $styleCode,
                    ];
                }
            }
            
            // 尝试带样式前缀的组件代码
            $prefixedCode = $preferredStyleCode . '-' . $componentCode;
            $component2 = clone $componentModel;
            $component2->clear()
                ->where($componentModelClass::schema_fields_CODE, $prefixedCode)
                ->where($componentModelClass::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component2->getId()) {
                $path = $component2->getData($componentModelClass::schema_fields_PATH);
                $styleCode = $component2->getData($componentModelClass::schema_fields_STYLE_CODE);
                
                if ($path) {
                    if (strpos($path, 'style/') === 0) {
                        return [
                            'path' => "GuoLaiRen_PageBuilder::templates/{$path}",
                            'style_code' => $styleCode,
                        ];
                    }
                    return [
                        'path' => $path,
                        'style_code' => $styleCode,
                    ];
                }
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 构建调试信息
     */
    private function buildDebugInfo(bool $useComponentRendering, array $layoutConfig): string
    {
        $debugInfo = "<!-- [DEBUG] useComponentRendering: " . ($useComponentRendering ? 'true' : 'false') . " -->\n";
        $debugInfo .= "<!-- [DEBUG] layoutConfig header count: " . count($layoutConfig['header'] ?? []) . " -->\n";
        $debugInfo .= "<!-- [DEBUG] layoutConfig content count: " . count($layoutConfig['content'] ?? []) . " -->\n";
        $debugInfo .= "<!-- [DEBUG] layoutConfig footer count: " . count($layoutConfig['footer'] ?? []) . " -->\n";
        return $debugInfo;
    }
    
    /**
     * 注入 Header 自定义代码
     */
    private function injectHeaderCustomCode(string $headerHtml, Page $page): string
    {
        $headerCustomCode = $page->getData(Page::schema_fields_HEADER_CUSTOM_CODE) ?? '';
        if (!empty($headerCustomCode)) {
            $headerHtml = preg_replace(
                '/(<\/head>)/i',
                $headerCustomCode . "\n    $1",
                $headerHtml,
                1
            );
        }
        return $headerHtml;
    }
    
    /**
     * 注入 Footer 自定义代码
     */
    private function injectFooterCustomCode(string $footerHtml, Page $page): string
    {
        $footerCustomCode = $page->getData(Page::schema_fields_FOOTER_CUSTOM_CODE) ?? '';
        if (!empty($footerCustomCode)) {
            $footerHtml = preg_replace(
                '/(<\/body>)/i',
                "\n    " . $footerCustomCode . "\n$1",
                $footerHtml,
                1
            );
        }
        return $footerHtml;
    }
    
    /**
     * 最终处理输出
     */
    private function finalizeOutput(
        string $headerHtml,
        string $contentHtml,
        string $footerHtml,
        string $debugInfo,
        Page $page,
        string $styleCode,
        string $mode,
        ?int $virtualThemeId = null
    ): string {
        // 预览标记脚本（preview 和 visual 模式都需要）
        $previewBoot = '';
        if ($mode !== self::MODE_LIVE) {
            $previewBoot = '<script>(function(){
                try {
                    window.__PAGEBUILDER_PREVIEW__ = true;
                    var url = new URL(window.location.href);
                    if (!url.searchParams.get("preview")) {
                        url.searchParams.set("preview", "1");
                        window.history.replaceState({}, document.title, url.toString());
                    }
                } catch(e) {}
            })();</script>';
        }
        
        if ($page->isAiHtmlRenderMode()) {
            $aiHtml = $this->renderAiHtmlBlocks($page, $mode !== self::MODE_LIVE, $mode === self::MODE_VISUAL);
            if ($aiHtml !== '') {
                if ($mode === self::MODE_VISUAL) {
                    return $this->renderVisualMode($headerHtml, $aiHtml, $footerHtml, $debugInfo, $previewBoot, $page, $styleCode);
                }

                return $this->renderAiHtmlDocument($headerHtml, $aiHtml, $footerHtml, $previewBoot, $page, $virtualThemeId);
            }
        }

        if ($mode === self::MODE_VISUAL) {
            // 可视化编辑器模式：添加插槽容器和拖拽支持
            return $this->renderVisualMode($headerHtml, $contentHtml, $footerHtml, $debugInfo, $previewBoot, $page, $styleCode);
        }
        
        // preview 和 live 模式：纯净输出
        return $this->renderStandardDocument($headerHtml, $contentHtml, $footerHtml, $previewBoot, $page);
    }

    private function renderAiHtmlDocument(
        string $headerHtml,
        string $aiHtml,
        string $footerHtml,
        string $previewBoot,
        Page $page,
        ?int $virtualThemeId = null
    ): string
    {
        $effectiveVirtualThemeId = (int)($virtualThemeId ?? 0);
        if ($effectiveVirtualThemeId <= 0) {
            $effectiveVirtualThemeId = (int)$page->getData('virtual_theme_id');
        }
        if ($effectiveVirtualThemeId <= 0) {
            $effectiveVirtualThemeId = $this->resolveActiveAiVirtualThemeId((int)$page->getData(Page::schema_fields_WEBSITE_ID));
        }
        $themeMarker = $effectiveVirtualThemeId > 0
            ? '<!-- theme_id=' . $effectiveVirtualThemeId . ' -->'
            : '';

        return $this->renderStandardDocument($themeMarker . $headerHtml, $aiHtml, $footerHtml, $previewBoot, $page);
    }

    private function renderStandardDocument(
        string $headerHtml,
        string $contentHtml,
        string $footerHtml,
        string $previewBoot,
        Page $page
    ): string {
        $headerHtml = $this->cleanHtmlDocumentTags($headerHtml);
        $contentHtml = $this->cleanHtmlDocumentTags($contentHtml);
        $footerHtml = $this->cleanHtmlDocumentTags($footerHtml);

        $headerCustomCode = (string)($page->getData(Page::schema_fields_HEADER_CUSTOM_CODE) ?? '');
        $footerCustomCode = (string)($page->getData(Page::schema_fields_FOOTER_CUSTOM_CODE) ?? '');
        $headHtml = $this->buildSeoHeadHtml($page);
        $htmlLang = $this->resolveDocumentLanguage();

        return '<!DOCTYPE html>
<html lang="' . \htmlspecialchars($htmlLang, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    ' . $headHtml . '
    ' . $headerCustomCode . '
</head>
<body>
    ' . $previewBoot . '
    ' . $headerHtml . '
    ' . $contentHtml . '
    ' . $footerHtml . '
    ' . $footerCustomCode . '
</body>
</html>';
    }

    private function buildSeoHeadHtml(Page $page): string
    {
        $template = $this->getTemplate();
        $currentLocale = $this->resolveCurrentLocale();
        $canonicalUrl = $this->resolveCanonicalUrl($page);

        $seo = $template->getData('seo');
        $seo = \is_array($seo) ? $seo : [];
        if (($seo['canonical_url'] ?? '') === '' && $canonicalUrl !== '') {
            $seo['canonical_url'] = $canonicalUrl;
        }
        if (($seo['title'] ?? '') === '') {
            $seo['title'] = $this->readPageString($page, ['meta_title', 'title', 'name']);
        }
        if (($seo['description'] ?? '') === '') {
            $seo['description'] = $this->readPageString($page, ['meta_description', 'ai_description', 'description', 'excerpt']);
        }
        if (($seo['image'] ?? '') === '') {
            $seo['image'] = $this->readPageString($page, ['image', 'cover_image', 'featured_image']);
        }

        $template->assign('page', $page);
        $template->assign('seo', $seo);
        $template->assign('title', $seo['title'] ?? '');
        $template->assign('lang', $currentLocale);
        $template->assign('lang_local', $currentLocale);
        $template->assign('current_locale', $currentLocale);
        if ($canonicalUrl !== '') {
            $template->assign('canonical_url', $canonicalUrl);
        }

        try {
            $providerRegistry = ObjectManager::getInstance(\Weline\Seo\Service\Head\HeadProviderRegistry::class);
            $resolver = new \Weline\Seo\Service\Head\PageSeoContextResolver($providerRegistry);
            $renderer = new \Weline\Seo\Service\Head\HeadRenderer($resolver, $providerRegistry);
            $html = $renderer->render($template, ['slot' => 'head']);
            return \trim(\is_string($html) ? $html : '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveCurrentLocale(): string
    {
        $template = $this->getTemplate();
        foreach ([
            $template->getData('lang_local'),
            $template->getData('current_locale'),
            $template->getData('lang'),
            \w_env('user.lang', ''),
            $_SERVER['WELINE_USER_LANG'] ?? '',
            'zh_Hans_CN',
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'zh_Hans_CN';
    }

    private function resolveDocumentLanguage(): string
    {
        return \str_replace('_', '-', $this->resolveCurrentLocale());
    }

    private function resolveCanonicalUrl(Page $page): string
    {
        $url = '';
        try {
            if ($this->request) {
                $url = (string)$this->request->getUrlBuilder()->getCurrentUrl([], true);
            }
        } catch (\Throwable) {
        }

        if ($url === '') {
            $fullUrl = \trim((string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? ''));
            if ($fullUrl !== '' && \preg_match('/^https?:\/\//i', $fullUrl)) {
                $url = $fullUrl;
            }
        }

        if ($url === '') {
            $scheme = (string)($_SERVER['REQUEST_SCHEME'] ?? '');
            if ($scheme === '') {
                $https = \strtolower((string)($_SERVER['HTTPS'] ?? ''));
                $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
            }
            $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            if ($host === '') {
                return '';
            }
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            if ($uri === '') {
                $uri = '/';
            }
            $url = $scheme . '://' . $host . $uri;
        }

        return $this->normalizeLocaleCanonicalUrl(
            $url,
            $this->resolveCurrentLocale(),
            'zh_Hans_CN',
            $page
        );
    }

    private function normalizeLocaleCanonicalUrl(string $url, string $currentLocale, string $defaultLocale, Page $page): string
    {
        if ($url === '' || !\preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }

        $path = (string)($parts['path'] ?? '/');
        if ($this->isInternalPageBuilderPath($path)) {
            $path = $this->publicPagePath($page, $query);
        }
        $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
        while ($segments !== [] && $this->isLocalePathSegment((string)$segments[0])) {
            \array_shift($segments);
        }

        $currentLocale = $this->normalizeLocaleCode($currentLocale);
        $defaultLocale = $this->normalizeLocaleCode($defaultLocale) ?: 'zh_Hans_CN';
        if ($currentLocale !== '' && $currentLocale !== $defaultLocale && $currentLocale !== 'zh_Hans_CN') {
            \array_unshift($segments, $currentLocale);
        }

        if ($query !== []) {
            unset(
                $query['lang'],
                $query['locale'],
                $query['page_id'],
                $query['handle'],
                $query['website_id'],
                $query['preview'],
                $query['style_code']
            );
        }

        $scheme = (string)($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . (string)$parts['port'] : '';
        $normalizedPath = $segments === [] ? '/' : '/' . \implode('/', $segments);
        $queryString = $query === [] ? '' : '?' . \http_build_query($query);

        return $scheme . '://' . (string)$parts['host'] . $port . $normalizedPath . $queryString;
    }

    private function isInternalPageBuilderPath(string $path): bool
    {
        return \strtolower('/' . \trim($path, '/')) === '/pagebuilder/frontend/page/view';
    }

    /**
     * @param array<string,mixed> $query
     */
    private function publicPagePath(Page $page, array $query): string
    {
        if ((string)$page->getData(Page::schema_fields_TYPE) === Page::TYPE_HOME) {
            return '/';
        }

        $handle = \trim((string)($page->getData(Page::schema_fields_HANDLE) ?: ($query['handle'] ?? '')));
        if ($handle === '') {
            return '/';
        }

        return '/' . \ltrim($handle, '/');
    }

    private function isLocalePathSegment(string $segment): bool
    {
        $segment = $this->normalizeLocaleCode($segment);
        return $segment !== '' && (bool)\preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(?:_[A-Z]{2})?$/', $segment);
    }

    private function normalizeLocaleCode(string $locale): string
    {
        return \str_replace('-', '_', \trim($locale));
    }

    /**
     * @param string[] $keys
     */
    private function readPageString(Page $page, array $keys): string
    {
        foreach ($keys as $key) {
            $value = \trim((string)$page->getData($key));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveActiveAiVirtualThemeId(int $websiteId): int
    {
        if ($websiteId <= 0) {
            return 0;
        }
        try {
            /** @var \GuoLaiRen\PageBuilder\Model\VirtualTheme $theme */
            $theme = ObjectManager::make(\GuoLaiRen\PageBuilder\Model\VirtualTheme::class);
            $theme->clearData()->clearQuery()
                ->where(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_WEBSITE_ID, $websiteId)
                ->where(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_SOURCE, \GuoLaiRen\PageBuilder\Model\VirtualTheme::SOURCE_PAGEBUILDER_AI)
                ->where(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_IS_ACTIVE, 1)
                ->order(\GuoLaiRen\PageBuilder\Model\VirtualTheme::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            return (int)$theme->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function renderAiHtmlBlocks(Page $page, bool $useDraftInsteadOfSnapshot = false, bool $visualEditor = false): string
    {
        $layout = $page->resolveAiLayoutForFrontend($useDraftInsteadOfSnapshot);
        $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        if ($blocks === []) {
            return '';
        }

        $html = '';
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (AiSiteHtmlBlocksBuildService::isSharedLayoutBlock($block)) {
                continue;
            }
            $blockHtml = \trim((string)($block['html'] ?? $block['config']['html_content'] ?? ''));
            if ($blockHtml === '') {
                continue;
            }
            $blockHtml = $this->sanitizeAiHtmlBlockFragment($blockHtml);
            if ($blockHtml === '') {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $blockType = \trim((string)($block['type'] ?? 'ai_html_block'));
            $componentCode = $this->resolveAiHtmlBlockComponentCode($block);
            $html .= '<section class="pb-ai-html-block"';
            if ($blockId !== '') {
                $html .= ' data-block-id="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            if ($componentCode !== '') {
                $html .= ' data-component-code="' . \htmlspecialchars($componentCode, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            if ($blockType !== '') {
                $html .= ' data-block-type="' . \htmlspecialchars($blockType, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            $html .= '>';
            if ($visualEditor) {
                $region = \trim((string)($block['_pb_server_region'] ?? $block['region'] ?? 'content'));
                $region = $region !== '' ? $region : 'content';
                $componentKey = $componentCode !== '' ? $componentCode : ($blockId !== '' ? $blockId : $blockType);
                $html .= '<div class="pb-component-wrapper tpmst-component-wrapper"'
                    . ' data-component="' . \htmlspecialchars($componentKey, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-component-code="' . \htmlspecialchars($componentCode !== '' ? $componentCode : $componentKey, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-block-id="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-region="' . \htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                    . ' data-index="0"'
                    . ' data-ai-block-id="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . '<div class="component-actions" aria-label="Component actions">'
                    . '<button type="button" data-pb-action="refine">Refine</button>'
                    . '<button type="button" data-pb-action="move-up">Move up</button>'
                    . '<button type="button" data-pb-action="move-down">Move down</button>'
                    . '</div>'
                    . $blockHtml
                    . '</div>';
            } else {
                $html .= $blockHtml;
            }
            $html .= '</section>';
        }

        return $html;
    }
    
    /**
     * 渲染可视化编辑器模式
     * 
     * 统一使用组件化模式：始终构建完整 HTML 结构
     * header/content/footer 组件只是 HTML 片段，不包含完整的 HTML 文档结构
     */
    /**
     * @param array<string, mixed> $block
     */
    private function resolveAiHtmlBlockComponentCode(array $block): string
    {
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        foreach ([
            $block['_pb_server_component_code'] ?? '',
            $config['_pb_server_component_code'] ?? '',
            $block['component_code'] ?? '',
            $config['component_code'] ?? '',
            $block['section_code'] ?? '',
            $config['section_code'] ?? '',
            $block['code'] ?? '',
            $block['component'] ?? '',
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function sanitizeAiHtmlBlockFragment(string $html): string
    {
        if (!$this->containsAiInstructionLeak($html)) {
            return $html;
        }

        $html = \preg_replace_callback(
            '/<div\b[^>]*class=(["\'])(?=[^"\']*header)[^"\']*\1[^>]*>.*?<\/div>\s*/is',
            function (array $matches): string {
                return $this->containsAiInstructionLeak((string)($matches[0] ?? '')) ? '' : (string)($matches[0] ?? '');
            },
            $html
        ) ?? $html;

        $html = \preg_replace_callback(
            '/<span\b[^>]*class=(["\'])(?=[^"\']*ai-site-fallback-kicker)[^"\']*\1[^>]*>.*?<\/span>\s*/is',
            function (array $matches): string {
                return $this->containsAiInstructionLeak((string)($matches[0] ?? '')) ? '' : (string)($matches[0] ?? '');
            },
            $html
        ) ?? $html;

        $html = \preg_replace_callback(
            '/<(h[1-6]|p)\b[^>]*>.*?<\/\1>/is',
            function (array $matches): string {
                return $this->containsAiInstructionLeak((string)($matches[0] ?? '')) ? '' : (string)($matches[0] ?? '');
            },
            $html
        ) ?? $html;

        return \trim($html);
    }

    private function containsAiInstructionLeak(string $html): bool
    {
        $html = \preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = \html_entity_decode(\strip_tags($html), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $text = \trim(\preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return false;
        }
        $normalized = \mb_strtolower($text);

        foreach ([
            '优先沿用第一阶段',
            '输出必须是访客可见内容',
            '不能写方向型提示语',
            '直接产出可上屏',
            '字段样例',
            'content_fill_rule',
            'field_content_requirements',
            'Generated website section',
            'visitor-visible copy',
            'prompt text',
            'stage-2 planned text',
            'Visitors see',
            'Visitors can review',
            'Built from plan',
            'generated from plan',
            'content_fill_rule',
            'field_content_requirements',
            'task_script',
            'stage3_directive',
        ] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, \mb_strtolower($marker)) !== false) {
                return true;
            }
        }

        return \preg_match('/^(?:present|provide|showcase|explain|build|create|highlight|include|display|structure|design|render|add)\b.{0,180}\b(?:cta|card|cards|accordion|accordions|section|block|layout|grid|module|page|signals?|content|policy|terms?|download)\b/iu', $text) === 1;
    }

    private function renderVisualMode(
        string $headerHtml,
        string $contentHtml,
        string $footerHtml,
        string $debugInfo,
        string $previewBoot,
        Page $page,
        string $styleCode
    ): string {
        $dropZoneStyles = $this->getDropZoneStyles();
        
        // 获取布局拥有者页面ID（用于可视化编辑API调用）
        $layoutOwnerPageId = $this->layoutOwnerResolver->resolveLayoutOwnerPageId($page);
        $dropZoneScripts = $this->getDropZoneScripts((int)$page->getId(), $layoutOwnerPageId);
        
        // 清理 header/footer 中可能存在的 HTML 文档结构标签（兼容旧模板）
        $headerHtml = $this->cleanHtmlDocumentTags($headerHtml);
        $footerHtml = $this->cleanHtmlDocumentTags($footerHtml);
        
        // 组件化模式：构建完整 HTML
        $pageTitle = $page ? ($page->getData('title') ?: 'Preview') : 'Preview';
        $templateHelper = Template::getInstance();
        $baseCssUrl = $templateHelper->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/' . $styleCode . '/asset/css/home.css');
        
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($pageTitle) . '</title>
    <link rel="stylesheet" href="' . $baseCssUrl . '">
    ' . $dropZoneStyles . '
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body>
    ' . $debugInfo . '
    ' . $previewBoot . '
    <div class="pb-slot pb-slot-header" data-region="header" data-multiple="false" data-slot-name="Header">' . $headerHtml . '</div>
    <div class="pb-slot pb-slot-content" data-region="content" data-multiple="true" data-slot-name="Content">' . $contentHtml . '</div>
    <div class="pb-slot pb-slot-footer" data-region="footer" data-multiple="false" data-slot-name="Footer">' . $footerHtml . '</div>
    ' . $dropZoneScripts . '
</body>
</html>';
    }
    
    /**
     * 清理 HTML 文档结构标签
     * 
     * 移除组件 HTML 中可能存在的完整文档结构标签；
     * 提取组件内所有 <style> 到片段前部，并从原位置移除，避免 <style> 出现在 nav 等标签内。
     * 确保组件只是纯粹的 HTML 片段，且样式集中在片段前部。
     */
    private function cleanHtmlDocumentTags(string $html): string
    {
        // 移除 DOCTYPE
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        
        // 移除 <html> 标签（保留内容）
        $html = preg_replace('/<html[^>]*>/i', '', $html);
        $html = preg_replace('/<\/html>/i', '', $html);
        
        // 提取所有 style 标签（包括组件内部的，如 <nav><style>...</style>）
        $styles = '';
        if (preg_match_all('/<style[^>]*>.*?<\/style>/is', $html, $matches)) {
            $styles = implode("\n", $matches[0]);
            // 从片段中移除这些 style 标签，避免 header 等组件里残留 <style> 导致结构混乱
            $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        }
        $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
        
        // 移除 <body> 标签（保留内容）
        $html = preg_replace('/<body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/body>/i', '', $html);
        
        // 将提取的样式放在片段前部，最终插入到插槽内时样式在组件结构之前
        if (!empty($styles)) {
            $html = $styles . "\n" . trim($html);
        }
        
        return trim($html);
    }
    
    /**
     * 获取拖拽区域样式
     * 
     * 统一使用 inset box-shadow 作为视觉效果，避免 outline 导致的布局问题
     */
    private function getDropZoneStyles(): string
    {
        return '<style>
            /* 拖拽插槽区域 */
            .pb-slot {
                position: relative;
                min-height: 50px;
                transition: box-shadow 0.2s ease;
            }
            /* 移除 pb-slot 和 pb-slot-content 的 hover 效果 */
            .pb-slot:hover,
            .pb-slot-content:hover {
                box-shadow: none !important;
            }
            .pb-slot.drag-over {
                box-shadow: inset 0 0 0 3px #4a90d9;
                background: rgba(74, 144, 217, 0.05);
            }
            
            /* 插槽名称标签 */
            .pb-slot::before {
                content: attr(data-slot-name);
                position: absolute;
                top: 0;
                left: 0;
                background: rgba(74, 144, 217, 0.9);
                color: white;
                padding: 2px 8px;
                font-size: 11px;
                font-weight: 500;
                border-radius: 0 0 4px 0;
                opacity: 0;
                transition: opacity 0.2s ease;
                z-index: 1000;
                pointer-events: none;
            }
            /* 移除 hover 时显示标签的效果 */
            .pb-slot:hover::before,
            .pb-slot-content:hover::before {
                opacity: 0 !important;
            }
            
            /* 组件包装器（统一样式，适用于所有模板） */
            .tpmst-component-wrapper,
            .pb-component-wrapper {
                position: relative !important;
                transition: box-shadow 0.2s ease;
                overflow: visible !important;
                z-index: 1 !important;
            }
            .tpmst-component-wrapper[data-region="header"],
            .tpmst-component-wrapper[data-region="footer"],
            .pb-component-wrapper[data-region="header"],
            .pb-component-wrapper[data-region="footer"] {
                z-index: 10000 !important;
            }
            .tpmst-component-wrapper:hover,
            .pb-component-wrapper:hover {
                border: 2px dashed rgba(52, 152, 219, 0.6) !important;
                box-shadow: inset 0 0 0 2px rgba(52, 152, 219, 0.3) !important;
                background: transparent !important;
                background-color: transparent !important;
                z-index: 10001 !important;
            }
            .tpmst-component-wrapper.selected,
            .pb-component-wrapper.selected {
                box-shadow: inset 0 0 0 3px #4a90d9;
            }
            
            /* 组件拖拽状态 */
            .tpmst-component-wrapper.dragging,
            .pb-component-wrapper.dragging {
                opacity: 0.6;
                box-shadow: inset 0 0 0 2px rgba(74, 144, 217, 0.8);
            }
            
            /* 组件操作按钮容器 */
            .tpmst-component-wrapper .component-actions,
            .pb-component-wrapper .component-actions {
                position: absolute !important;
                top: 8px !important;
                right: 8px !important;
                left: auto !important;
                bottom: auto !important;
                display: none !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: flex-end !important;
                flex-wrap: wrap !important;
                gap: 6px;
                z-index: 100000 !important;
                background: rgba(15, 23, 42, 0.88) !important;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                padding: 6px !important;
                border-radius: 999px !important;
                box-shadow: 0 10px 30px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(148, 163, 184, 0.22) !important;
                pointer-events: auto !important;
                margin: 0 !important;
                min-width: auto !important;
                width: auto !important;
                height: auto !important;
            }
            .tpmst-component-wrapper:hover .component-actions,
            .pb-component-wrapper:hover .component-actions,
            .tpmst-component-wrapper .component-actions.pb-actions-visible,
            .pb-component-wrapper .component-actions.pb-actions-visible,
            .tpmst-component-wrapper .component-actions:hover,
            .pb-component-wrapper .component-actions:hover {
                display: flex !important;
            }
            .tpmst-component-wrapper[data-region="header"] .component-actions,
            .tpmst-component-wrapper[data-region="footer"] .component-actions,
            .pb-component-wrapper[data-region="header"] .component-actions,
            .pb-component-wrapper[data-region="footer"] .component-actions {
                z-index: 100001 !important;
            }
            .tpmst-component-wrapper .component-actions [data-pb-action="move-up"],
            .tpmst-component-wrapper .component-actions [data-pb-action="move-down"],
            .pb-component-wrapper .component-actions [data-pb-action="move-up"],
            .pb-component-wrapper .component-actions [data-pb-action="move-down"] {
                display: none !important;
            }
            .tpmst-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-up"],
            .tpmst-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-down"],
            .pb-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-up"],
            .pb-component-wrapper[data-region="content"] .component-actions [data-pb-action="move-down"] {
                display: inline-flex !important;
            }
            .tpmst-component-wrapper .component-actions button,
            .pb-component-wrapper .component-actions button {
                height: 30px;
                padding: 0 12px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 0;
                border-radius: 999px;
                cursor: pointer;
                white-space: nowrap;
                font-size: 12px;
                line-height: 1;
                font-weight: 600;
                letter-spacing: 0.01em;
                color: #e2e8f0;
                background: rgba(255, 255, 255, 0.12);
                transition: transform 0.16s ease, background-color 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
            }
            .tpmst-component-wrapper .component-actions button:hover,
            .pb-component-wrapper .component-actions button:hover {
                transform: translateY(-1px);
                color: #ffffff;
                background: rgba(255, 255, 255, 0.22);
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="refine"],
            .pb-component-wrapper .component-actions button[data-pb-action="refine"] {
                background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(37, 99, 235, 0.92));
                color: #eff6ff;
                box-shadow: 0 8px 20px rgba(37, 99, 235, 0.28);
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="refine"]:hover,
            .pb-component-wrapper .component-actions button[data-pb-action="refine"]:hover {
                background: linear-gradient(135deg, rgba(2, 132, 199, 0.98), rgba(29, 78, 216, 0.94));
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="regenerate-block"],
            .pb-component-wrapper .component-actions button[data-pb-action="regenerate-block"] {
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.98), rgba(234, 88, 12, 0.94));
                color: #fff7ed;
                box-shadow: 0 8px 20px rgba(245, 158, 11, 0.24);
            }
            .tpmst-component-wrapper .component-actions button[data-pb-action="regenerate-block"]:hover,
            .pb-component-wrapper .component-actions button[data-pb-action="regenerate-block"]:hover {
                background: linear-gradient(135deg, rgba(217, 119, 6, 0.98), rgba(194, 65, 12, 0.94));
            }
            .tpmst-component-wrapper .component-actions button:disabled,
            .pb-component-wrapper .component-actions button:disabled {
                opacity: 0.45;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
        </style>';
    }
    
    /**
     * 获取拖拽区域脚本
     * 
     * @param int $pageId 当前页面ID
     * @param int $layoutOwnerPageId 布局拥有者页面ID（用于API调用）
     */
    private function getDropZoneScripts(int $pageId, int $layoutOwnerPageId): string
    {
        return '<script>
            (function() {
                // 可视化编辑器脚本
                window.__PAGEBUILDER_PAGE_ID__ = ' . $pageId . ';
                // 布局拥有者页面ID（API调用时使用此ID）
                window.__PAGEBUILDER_LAYOUT_OWNER_PAGE_ID__ = ' . $layoutOwnerPageId . ';
                
                // 初始化拖拽区域
                document.querySelectorAll(".pb-slot").forEach(function(slot) {
                    slot.addEventListener("dragover", function(e) {
                        e.preventDefault();
                        this.classList.add("drag-over");
                    });
                    slot.addEventListener("dragleave", function(e) {
                        this.classList.remove("drag-over");
                    });
                    slot.addEventListener("drop", function(e) {
                        e.preventDefault();
                        this.classList.remove("drag-over");
                        // 通知父窗口
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: "pb-component-drop",
                                region: this.dataset.region,
                                data: e.dataTransfer.getData("text/plain")
                            }, "*");
                        }
                    });
                });
                
                // 组件选择
                document.querySelectorAll(".tpmst-component-wrapper").forEach(function(wrapper) {
                    wrapper.addEventListener("click", function(e) {
                        e.stopPropagation();
                        document.querySelectorAll(".tpmst-component-wrapper.selected").forEach(function(el) {
                            el.classList.remove("selected");
                        });
                        this.classList.add("selected");
                        // 通知父窗口
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: "pb-component-select",
                                component: this.dataset.component,
                                region: this.dataset.region,
                                index: this.dataset.index
                            }, "*");
                        }
                    });
                });
            })();
        </script>';
    }
    
    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$componentFilesCache = [];
    }
}
