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
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;
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
        LocalDescription $localDescriptionModel
    ) {
        $this->layoutAssembler = $layoutAssembler;
        $this->layoutOwnerResolver = $layoutOwnerResolver;
        $this->pageModel = $pageModel;
        $this->styleModel = $styleModel;
        $this->localDescriptionModel = $localDescriptionModel;
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
        ?string $tempStyleCode = null
    ): string {
        // 重置模板变量
        $this->templateVars = [];
        
        // 获取样式代码
        $styleCode = $tempStyleCode ?: ($page->getData('style') ?: 'default');
        
        // 获取当前语言
        $currentLocale = $locale ?: \Weline\Framework\Http\Cookie::getLang();
        
        // 构建样式配置
        $finalSettings = $this->buildStyleSettings($page, $styleCode, $currentLocale, $tempStyleCode);
        
        // 检查是否为虚拟页面（id=0，用于模板预览等场景）
        $isVirtualPage = !$page->getId();
        
        // 获取布局配置（通过 LayoutOwnerResolver 统一处理 layout_page_id 和 header/footer 继承）
        $layoutConfig = $this->layoutOwnerResolver->getFullLayoutConfig($page);
        
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
        $pageType = $page->getData(Page::fields_TYPE);
        
        // 获取页面类型对应的布局信息
        $layoutInfo = $this->getLayoutInfoForPageType($styleCode, $pageType);
        $this->assign('page_type', $pageType);
        $this->assign('layout_info', $layoutInfo);
        
        // 如果页面没有自定义布局配置，加载该页面类型的默认布局配置
        $hasCustomLayout = !empty($layoutConfig) && (
            !empty($layoutConfig['header']) || 
            !empty($layoutConfig['content']) || 
            !empty($layoutConfig['footer'])
        );
        
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
        return $this->finalizeOutput($headerHtml, $contentHtml, $footerHtml, $debugInfo, $page, $styleCode, $mode);
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
        $layoutsJsonPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/layouts.json";
        
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
        $fullPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/{$layoutFile}";
        
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
        $layoutsJsonPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/layouts.json";
        
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
        $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/{$pageType}.json";
        
        if (!file_exists($configFilePath)) {
            // fallback 到 custom_page
            $configFilePath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/layouts/default/custom_page.json";
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
            ->where(Style::fields_CODE, $styleCode)
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
            ->where(LocalDescription::fields_ID, $page->getId())
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
        $pageType = $page->getData(Page::fields_TYPE);
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
    
    /**
     * 根据 slug 获取博客文章
     */
    private function getBlogPostBySlug(string $slug): ?array
    {
        try {
            $postClass = '\\GuoLaiRen\\Blog\\Model\\Post';
            if (!class_exists($postClass)) {
                return null;
            }
            
            $websiteId = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            $postModel = \Weline\Framework\Manager\ObjectManager::getInstance($postClass);
            $query = $postModel->clear()
                ->where('slug', $slug)
                ->where('status', 1);
            
            // 根据当前网站过滤
            if ($websiteId) {
                $query->where('site_id', $websiteId);
            }
            
            $query->find()->fetch();
            
            if (!$postModel->getId()) {
                return null;
            }
            
            return [
                'post_id' => $postModel->getId(),
                'title' => $postModel->getData('title'),
                'slug' => $postModel->getData('slug'),
                'url' => '/blog/' . $postModel->getData('slug'),
                'summary' => $postModel->getData('summary'),
                'content' => $postModel->getData('content'),
                'cover_image' => $postModel->getData('cover_image'),
                'author' => $postModel->getData('author'),
                'published_at' => $postModel->getData('published_at'),
                'view_count' => $postModel->getData('view_count'),
                'category_id' => $postModel->getData('category_id'),
                'tags' => $postModel->getData('tags'),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 根据 slug 获取博客分类
     */
    private function getBlogCategoryBySlug(string $slug): ?array
    {
        try {
            $categoryClass = '\\GuoLaiRen\\Blog\\Model\\Category';
            if (!class_exists($categoryClass)) {
                return null;
            }
            
            $websiteId = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            $categoryModel = \Weline\Framework\Manager\ObjectManager::getInstance($categoryClass);
            $query = $categoryModel->clear()
                ->where('slug', $slug)
                ->where('status', 1);
            
            // 根据当前网站过滤
            if ($websiteId) {
                $query->where('site_id', $websiteId);
            }
            
            $query->find()->fetch();
            
            if (!$categoryModel->getId()) {
                return null;
            }
            
            return [
                'category_id' => $categoryModel->getId(),
                'name' => $categoryModel->getData('name'),
                'slug' => $categoryModel->getData('slug'),
                'url' => '/blog/category/' . $categoryModel->getData('slug'),
                'description' => $categoryModel->getData('description'),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 获取分类下的博客文章
     */
    private function getBlogPostsByCategory(int $categoryId, int $limit = 20): array
    {
        try {
            $postClass = '\\GuoLaiRen\\Blog\\Model\\Post';
            if (!class_exists($postClass)) {
                return [];
            }
            
            $websiteId = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            $postModel = \Weline\Framework\Manager\ObjectManager::getInstance($postClass);
            $query = $postModel->clear()
                ->where('category_id', $categoryId)
                ->where('status', 1);
            
            // 根据当前网站过滤
            if ($websiteId) {
                $query->where('site_id', $websiteId);
            }
            
            $posts = $query->order('published_at', 'DESC')
                ->limit($limit)
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($posts as $post) {
                $result[] = [
                    'post_id' => $post->getId(),
                    'title' => $post->getData('title'),
                    'slug' => $post->getData('slug'),
                    'url' => '/blog/' . $post->getData('slug'),
                    'summary' => $post->getData('summary'),
                    'cover_image' => $post->getData('cover_image'),
                    'author' => $post->getData('author'),
                    'published_at' => $post->getData('published_at'),
                ];
            }
            
            return $result;
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
            $postClass = '\\GuoLaiRen\\Blog\\Model\\Post';
            if (!class_exists($postClass)) {
                return [];
            }
            
            $websiteId = \Weline\Websites\Data\WebsiteData::getWebsiteId();
            $postModel = \Weline\Framework\Manager\ObjectManager::getInstance($postClass);
            $query = $postModel->clear()
                ->where('status', 1)
                ->where('post_id', $currentPost['post_id'], '!=');
            
            // 根据当前网站过滤
            if ($websiteId) {
                $query->where('site_id', $websiteId);
            }
            
            // 优先同分类文章
            if (!empty($currentPost['category_id'])) {
                $query->where('category_id', $currentPost['category_id']);
            }
            
            $posts = $query->order('published_at', 'DESC')
                ->limit($limit)
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($posts as $post) {
                $result[] = [
                    'post_id' => $post->getId(),
                    'title' => $post->getData('title'),
                    'slug' => $post->getData('slug'),
                    'url' => '/blog/' . $post->getData('slug'),
                    'summary' => $post->getData('summary'),
                    'cover_image' => $post->getData('cover_image'),
                    'published_at' => $post->getData('published_at'),
                ];
            }
            
            return $result;
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
            $customContent = $page->getData(Page::fields_CONTENT);
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
            
            // 尝试去掉模板前缀
            if (!$componentFile && strpos($code, $styleCode . '-') === 0) {
                $codeWithoutPrefix = substr($code, strlen($styleCode) + 1);
                $componentFile = $componentFiles[$codeWithoutPrefix] ?? null;
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
                $html .= "<!-- Component not found: {$code} (tried file-based and Component model) -->\n";
                $componentIndex++;
                continue;
            }
            
            // 构建组件模板路径（如果未通过 Component 模型解析）
            if (!$componentPath) {
                $componentPath = "GuoLaiRen_PageBuilder::templates/style/{$useTemplateCode}/components/{$componentFile}";
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
                        $componentHtml = "<div class=\"tpmst-component-wrapper\" data-component=\"{$escapedCode}\" data-region=\"{$escapedRegion}\" data-index=\"{$componentIndex}\">{$componentHtml}</div>";
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
     */
    private function getComponentFilesMap(string $styleCode): array
    {
        if (isset(self::$componentFilesCache[$styleCode])) {
            return self::$componentFilesCache[$styleCode];
        }
        
        $componentJsonPath = BP . "app/code/GuoLaiRen/PageBuilder/view/templates/style/{$styleCode}/components/component.json";
        
        if (!file_exists($componentJsonPath)) {
            self::$componentFilesCache[$styleCode] = [];
            return [];
        }
        
        $jsonContent = file_get_contents($componentJsonPath);
        $jsonConfig = json_decode($jsonContent, true);
        
        if (!$jsonConfig || !isset($jsonConfig['components'])) {
            self::$componentFilesCache[$styleCode] = [];
            return [];
        }
        
        $map = [];
        foreach ($jsonConfig['components'] as $code => $config) {
            $map[$code] = $config['file'] ?? ($code . '.phtml');
        }
        
        self::$componentFilesCache[$styleCode] = $map;
        return $map;
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
                ->where($componentModelClass::fields_CODE, $componentCode)
                ->where($componentModelClass::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component->getId()) {
                $path = $component->getData($componentModelClass::fields_PATH);
                $styleCode = $component->getData($componentModelClass::fields_STYLE_CODE);
                
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
                ->where($componentModelClass::fields_CODE, $prefixedCode)
                ->where($componentModelClass::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($component2->getId()) {
                $path = $component2->getData($componentModelClass::fields_PATH);
                $styleCode = $component2->getData($componentModelClass::fields_STYLE_CODE);
                
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
        $headerCustomCode = $page->getData(Page::fields_HEADER_CUSTOM_CODE) ?? '';
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
        $footerCustomCode = $page->getData(Page::fields_FOOTER_CUSTOM_CODE) ?? '';
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
        string $mode
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
        
        if ($mode === self::MODE_VISUAL) {
            // 可视化编辑器模式：添加插槽容器和拖拽支持
            return $this->renderVisualMode($headerHtml, $contentHtml, $footerHtml, $debugInfo, $previewBoot, $page, $styleCode);
        }
        
        // preview 和 live 模式：纯净输出
        return $previewBoot . $headerHtml . $contentHtml . $footerHtml;
    }
    
    /**
     * 渲染可视化编辑器模式
     */
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
        
        // 检查 header 是否包含完整的 HTML 结构
        $hasFullHtml = (strpos($headerHtml, '<head>') !== false || strpos($headerHtml, '<html>') !== false);
        
        if ($hasFullHtml) {
            // 传统模式：header 包含完整的 HTML 结构
            $headerHtml = preg_replace('/(<body[^>]*>)/i', "$1\n" . $debugInfo, $headerHtml, 1);
            $wrappedHeader = '<div class="pb-slot pb-slot-header" data-region="header" data-multiple="false" data-slot-name="Header">' . $headerHtml . '</div>';
            $wrappedContent = '<div class="pb-slot pb-slot-content" data-region="content" data-multiple="true" data-slot-name="Content">' . $contentHtml . '</div>';
            $wrappedFooter = '<div class="pb-slot pb-slot-footer" data-region="footer" data-multiple="false" data-slot-name="Footer">' . $footerHtml . '</div>';
            
            // 注入样式到 head
            $wrappedHeader = preg_replace(
                '/(<\/head>)/i',
                $dropZoneStyles . "\n    $1",
                $wrappedHeader,
                1
            );
            
            // 注入脚本到 body 末尾
            $wrappedFooter = preg_replace(
                '/(<\/body>)/i',
                "\n    " . $dropZoneScripts . "\n$1",
                $wrappedFooter,
                1
            );
            
            return $previewBoot . $wrappedHeader . $wrappedContent . $wrappedFooter;
        }
        
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
            }
            .tpmst-component-wrapper:hover,
            .pb-component-wrapper:hover {
                border: 2px dashed rgba(52, 152, 219, 0.6) !important;
                box-shadow: inset 0 0 0 2px rgba(52, 152, 219, 0.3) !important;
                background: transparent !important;
                background-color: transparent !important;
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
                display: none !important;
                flex-direction: row !important;
                align-items: center !important;
                gap: 6px;
                z-index: 99999 !important;
                background: rgba(255,255,255,0.95) !important;
                padding: 6px 8px !important;
                border-radius: 6px !important;
                box-shadow: 0 2px 12px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05) !important;
                pointer-events: auto !important;
            }
            .tpmst-component-wrapper:hover .component-actions,
            .pb-component-wrapper:hover .component-actions,
            .tpmst-component-wrapper .component-actions:hover,
            .pb-component-wrapper .component-actions:hover {
                display: flex !important;
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
