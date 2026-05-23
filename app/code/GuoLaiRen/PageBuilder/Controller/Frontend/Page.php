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
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

class Page extends FrontendController
{
    private const VIEW_HTML_CACHE_TTL = 60;
    private const VIEW_HTML_CACHE_MAX_ENTRIES = 8;
    private const VIEW_HTML_CACHE_KEEP_ENTRIES = 4;
    private const VIEW_HTML_CACHE_MAX_BYTES = 8388608;
    private const VIEW_HTML_CACHE_MAX_ITEM_BYTES = 524288;

    /** @var array<string, array{expires_at: float, html: string}> */
    private static array $viewHtmlCache = [];

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

    private function disableFrontendBrowserCache(mixed $response): void
    {
        if (!\is_object($response) || !\method_exists($response, 'setHeader')) {
            return;
        }

        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
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
        if (is_string($handle)) {
            $handle = trim(rawurldecode($handle));
        } else {
            $handle = '';
        }
        
        // 检查是否为预览模式
        $isPreview = $this->request->getGet('preview') == '1';
        // page_id 不仅用于预览，也用于 URL rewrite 解码后精准命中页面
        $requestedPageId = (int)$this->request->getGet('page_id', 0);
        $previewStyleCode = $isPreview ? trim((string)$this->request->getGet('style_code', '')) : '';

        // 获取URL中的语言参数
        $requestedLocale = $this->normalizeRequestedLocale(
            $this->request->getGet('lang', $this->request->getGet('locale'))
        );
        
        // 如果URL中指定了语言，更新Cookie
        if ($requestedLocale) {
            $this->syncRequestedLocale($requestedLocale);
        }
        
        // 获取当前使用的语言（从Cookie或URL参数）
        $currentLocale = $requestedLocale ?: \Weline\Framework\Http\Cookie::getLang();

        // 获取当前网站ID；预览模式优先从 URL 的 website_id 配合 handle 解码，避免跨站预览时 getCurrentWebsiteId 不匹配
        $websiteId = \Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
        $response = $this->request->getResponse();
        $this->disableFrontendBrowserCache($response);
        if ($isPreview) {
            $websiteIdParam = $this->request->getGet('website_id');
            if ($websiteIdParam !== null && $websiteIdParam !== '') {
                $websiteId = (int)$websiteIdParam;
            }
        }
        if ($websiteId <= 0) {
            $websiteId = $this->resolveWebsiteIdFromCurrentHost() ?? 0;
        }
        if ($websiteId > 0) {
            $this->syncWebsiteContext($websiteId);
        }
        
        $page = null;

        // 预览和 rewrite 解码都优先按 page_id 精准加载页面，避免 handle 歧义导致命中旧模板
        if ($requestedPageId > 0) {
            if ($isPreview) {
                $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
                $response->setHeader('Pragma', 'no-cache');
                $response->setHeader('Expires', '0');
                $response->setHeader('X-Accel-Expires', '0');
            }
            $page = clone $this->pageModel;
            $page->clearData();
            $page->load($requestedPageId);
            if ($page->getId()) {
                $pageWebsiteId = (int)($page->getData(PageModel::schema_fields_WEBSITE_ID) ?? 0);
                if ($pageWebsiteId > 0) {
                    $websiteId = $pageWebsiteId;
                    $this->syncWebsiteContext($websiteId);
                }
                if (!$isPreview) {
                    $pageStatus = (int)($page->getData(PageModel::schema_fields_STATUS) ?? PageModel::STATUS_DRAFT);
                    if ($pageStatus !== PageModel::STATUS_PUBLISHED) {
                        $page = null;
                    }
                }
            }
        } elseif ($isPreview) {
            // 预览模式但未带 page_id 时也禁止缓存，避免看到旧模板
            $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->setHeader('Pragma', 'no-cache');
            $response->setHeader('Expires', '0');
        }

        $response->setHeader('Website-Id', (string)$websiteId);
        
        // 如果 handle 为空且未按 page_id 加载到页面，尝试加载该站点的首页
        if (($page === null || !$page->getId()) && empty($handle) && $websiteId > 0) {
            $page = clone $this->pageModel;
            $page->clearData();
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
                $page->clearData();
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
            $page->clearData();
            $page->clear()
                ->where(PageModel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PageModel::schema_fields_HANDLE, $handle)
                ->order(PageModel::schema_fields_ID, 'DESC');
            
            // 如果不是预览模式，只显示已发布的页面
            if (!$isPreview) {
                $page->where(PageModel::schema_fields_STATUS, PageModel::STATUS_PUBLISHED);
            }
            
            $page->find()->fetch();

            // 如果没找到，尝试 website_id = 0（全局/默认站点，保持之前使用方式）
            if (!$page->getId() && $websiteId !== 0) {
                $page = clone $this->pageModel;
                $page->clearData();
                $page->clear()
                    ->where(PageModel::schema_fields_WEBSITE_ID, 0)
                    ->where(PageModel::schema_fields_HANDLE, $handle)
                    ->order(PageModel::schema_fields_ID, 'DESC');
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

        $viewHtmlCacheKey = '';
        if (!$isPreview) {
            $viewHtmlCacheKey = $this->buildViewHtmlCacheKey($page, $currentLocale, $websiteId, (string)($page->getData(PageModel::schema_fields_STYLE) ?? ''));
            $cachedHtml = $this->getViewHtmlCache($viewHtmlCacheKey);
            if (is_string($cachedHtml)) {
                echo $cachedHtml;
                return;
            }
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
            $style->clearData();
            $style->clear()
                ->where(Style::schema_fields_CODE, $styleCode)
                ->find()
                ->fetch();
            
            if ($style->getId()) {
                // 确定渲染模式：preview 或 live
                $renderMode = $isPreview ? PageRenderService::MODE_PREVIEW : PageRenderService::MODE_LIVE;
                
                // 使用统一的 PageRenderService 渲染页面
                // 这确保了可视化编辑器预览和正式上线页面的渲染逻辑完全一致
                // 预览模式下允许通过 URL 的 style_code 临时覆盖模板，避免编辑器已切换模板但页面尚未保存时仍渲染旧模板
                $tempStyleCode = null;
                if ($isPreview && $previewStyleCode !== '') {
                    $previewStyle = clone $this->styleModel;
                    $previewStyle->clearData();
                    $previewStyle->clear()
                        ->where(Style::schema_fields_CODE, $previewStyleCode)
                        ->find()
                        ->fetch();
                    if ($previewStyle->getId()) {
                        $tempStyleCode = $previewStyleCode;
                    }
                }

                $html = $this->pageRenderService->setRequest($this->request)->render(
                    $page,
                    $renderMode,
                    $currentLocale,
                    $tempStyleCode
                );
                if (!$isPreview && $viewHtmlCacheKey !== '') {
                    $this->rememberViewHtmlCache($viewHtmlCacheKey, $html);
                }
                
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

    private function getViewHtmlCache(string $key): ?string
    {
        $now = \microtime(true);
        self::pruneViewHtmlCache($now);
        if (isset(self::$viewHtmlCache[$key])) {
            $cached = self::$viewHtmlCache[$key];
            if (($cached['expires_at'] ?? 0.0) >= $now) {
                return (string)($cached['html'] ?? '');
            }
            unset(self::$viewHtmlCache[$key]);
        }

        return null;
    }

    private function rememberViewHtmlCache(string $key, string $html): void
    {
        if ($html === '') {
            return;
        }
        if (\strlen($html) > self::VIEW_HTML_CACHE_MAX_ITEM_BYTES) {
            return;
        }

        self::rememberLocalViewHtmlCache($key, $html, \microtime(true));
    }

    public static function clearProcessCaches(bool $aggressive = false): void
    {
        self::$viewHtmlCache = [];
    }

    /**
     * @return array<string, int>
     */
    public static function debugViewHtmlCacheState(): array
    {
        self::pruneViewHtmlCache(\microtime(true));

        return [
            'entries' => \count(self::$viewHtmlCache),
            'bytes' => self::viewHtmlCacheBytes(),
            'max_entries' => self::VIEW_HTML_CACHE_MAX_ENTRIES,
            'max_bytes' => self::VIEW_HTML_CACHE_MAX_BYTES,
            'max_item_bytes' => self::VIEW_HTML_CACHE_MAX_ITEM_BYTES,
        ];
    }

    private static function rememberLocalViewHtmlCache(string $key, string $html, float $now): void
    {
        if ($html === '' || \strlen($html) > self::VIEW_HTML_CACHE_MAX_ITEM_BYTES) {
            return;
        }

        self::pruneViewHtmlCache($now);
        self::$viewHtmlCache[$key] = [
            'expires_at' => $now + self::VIEW_HTML_CACHE_TTL,
            'html' => $html,
        ];
        self::trimViewHtmlCache();
    }

    private static function pruneViewHtmlCache(float $now): void
    {
        foreach (self::$viewHtmlCache as $key => $cached) {
            if (!\is_array($cached)
                || (float)($cached['expires_at'] ?? 0.0) < $now
                || !\is_string($cached['html'] ?? null)) {
                unset(self::$viewHtmlCache[$key]);
            }
        }
    }

    private static function trimViewHtmlCache(): void
    {
        while (\count(self::$viewHtmlCache) > self::VIEW_HTML_CACHE_MAX_ENTRIES
            || self::viewHtmlCacheBytes() > self::VIEW_HTML_CACHE_MAX_BYTES) {
            if (\count(self::$viewHtmlCache) <= self::VIEW_HTML_CACHE_KEEP_ENTRIES) {
                self::$viewHtmlCache = [];
                return;
            }
            \array_shift(self::$viewHtmlCache);
        }
    }

    private static function viewHtmlCacheBytes(): int
    {
        $bytes = 0;
        foreach (self::$viewHtmlCache as $cached) {
            if (\is_array($cached) && \is_string($cached['html'] ?? null)) {
                $bytes += \strlen($cached['html']);
            }
        }

        return $bytes;
    }

    private function buildViewHtmlCacheKey(PageModel $page, string $locale, int $websiteId, string $styleCode): string
    {
        $uri = \function_exists('w_env_request_uri') ? (string)\w_env_request_uri() : '';
        $host = \function_exists('w_env_http_host') ? (string)\w_env_http_host() : '';

        return \sha1((string)\json_encode([
            'v' => 1,
            'page_id' => (int)$page->getId(),
            'website_id' => $websiteId,
            'style' => $styleCode,
            'locale' => $locale,
            'updated_at' => (string)($page->getData('updated_at') ?? ''),
            'render_mode' => (string)($page->getData(PageModel::schema_fields_RENDER_MODE) ?? ''),
            'host' => $host,
            'uri' => $uri,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    
    /**
     * Resolve the published-site domain context before loading the entity page.
     */
    private function resolveWebsiteIdFromCurrentHost(): ?int
    {
        foreach ($this->currentHostCandidates() as $host) {
            $websiteId = $this->findWebsiteIdByHost($host);
            if ($websiteId !== null && $websiteId > 0) {
                $this->syncWebsiteContext($websiteId);
                return $websiteId;
            }
        }

        return null;
    }

    private function normalizeRequestedLocale(mixed $locale): string
    {
        $locale = \trim((string)$locale);
        if ($locale === '') {
            return '';
        }

        $locale = \str_replace('-', '_', $locale);
        if (!\preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(?:_[A-Z]{2})?$/', $locale)) {
            return '';
        }

        return $locale;
    }

    private function syncRequestedLocale(string $locale): void
    {
        Cookie::set('WELINE_USER_LANG', $locale, 3600 * 24 * 30);
        RequestContext::locale($locale);
        WelineEnv::set('user.lang', $locale, 'PageBuilder frontend query locale');
        $_SERVER['WELINE_USER_LANG'] = $locale;
        $this->request->setServer('WELINE_USER_LANG', $locale);
    }

    private function currentHostCandidates(): array
    {
        $candidates = [
            WelineEnv::get('server.http_host', ''),
            WelineEnv::get('http_weline_original_host', ''),
            WelineEnv::get('http_x_forwarded_host', ''),
            WelineEnv::server('HTTP_HOST', ''),
            WelineEnv::server('HTTP_WELINE_ORIGINAL_HOST', ''),
            WelineEnv::server('HTTP_X_FORWARDED_HOST', ''),
            WelineEnv::server('SERVER_NAME', ''),
            WelineEnv::server('WELINE_WEBSITE_URL', ''),
            WelineEnv::server('WELINE_FULL_REQUEST_URI', ''),
            $this->request->getServer('HTTP_HOST') ?: '',
            $this->request->getServer('HTTP_WELINE_ORIGINAL_HOST') ?: '',
            $this->request->getServer('HTTP_X_FORWARDED_HOST') ?: '',
            $this->request->getServer('SERVER_NAME') ?: '',
        ];

        $hosts = [];
        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            foreach (\explode(',', \trim((string)$candidate)) as $part) {
                $host = $this->normalizeHostCandidate($part);
                if ($host !== '') {
                    $hosts[$host] = $host;
                }
            }
        }

        return \array_values($hosts);
    }

    private function normalizeHostCandidate(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }

        if (\str_contains($host, '://')) {
            $parsedHost = \parse_url($host, \PHP_URL_HOST);
            if (\is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }
        } else {
            $host = \preg_replace('#^//#', '', $host) ?? $host;
            $host = \explode('/', $host, 2)[0] ?? $host;
        }

        $host = \trim($host);
        if ($host === '' || $host === 'localhost' || \filter_var($host, \FILTER_VALIDATE_IP)) {
            return '';
        }

        return (string)(\preg_replace('/:\d+$/', '', $host) ?? $host);
    }

    private function findWebsiteIdByHost(string $host): ?int
    {
        $hostNoPort = \strtolower(\trim((string)(\preg_replace('/:\d+$/', '', $host) ?? $host)));
        if ($hostNoPort === '') {
            return null;
        }

        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $domain = clone $domainModel;
            $domain->clearData()->clearQuery()
                ->where(WebsiteDomain::schema_fields_DOMAIN, $hostNoPort)
                ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                ->where(WebsiteDomain::schema_fields_SUB_PATH, '')
                ->find()
                ->fetch();
            if ((int)$domain->getData(WebsiteDomain::schema_fields_ID) > 0
                && (string)$domain->getData(WebsiteDomain::schema_fields_STATUS) === WebsiteDomain::STATUS_ACTIVE
            ) {
                $websiteId = (int)$domain->getData(WebsiteDomain::schema_fields_WEBSITE_ID);
                if ($websiteId > 0) {
                    return $websiteId;
                }
            }

            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            $website = clone $websiteModel;
            $website->clear()
                ->where(Website::schema_fields_URL, "%{$hostNoPort}%", 'like')
                ->find()
                ->fetch();
            if ($website->getId()) {
                return (int)$website->getData(Website::schema_fields_ID);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function syncWebsiteContext(int $websiteId): void
    {
        if ($websiteId <= 0) {
            return;
        }

        WelineEnv::setServer('WELINE_WEBSITE_ID', (string)$websiteId, 'PageBuilder page website');
        RequestContext::setWelineWebsiteId($websiteId);
        Cookie::set('WELINE_WEBSITE_ID', (string)$websiteId, 3600 * 24 * 30);

        try {
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            $website = clone $websiteModel;
            $website->clearData()->clearQuery()->load($websiteId);
            if (!$website->getId()) {
                return;
            }

            WebsiteData::setWebsite($website);

            $websiteCode = (string)($website->getData(Website::schema_fields_CODE) ?? '');
            $websiteUrl = (string)($website->getData(Website::schema_fields_URL) ?? '');
            if ($websiteCode !== '') {
                WelineEnv::setServer('WELINE_WEBSITE_CODE', $websiteCode, 'PageBuilder page website');
                RequestContext::setWelineWebsiteCode($websiteCode);
                Cookie::set('WELINE_WEBSITE_CODE', $websiteCode, 3600 * 24 * 30);
            }
            if ($websiteUrl !== '') {
                WelineEnv::setServer('WELINE_WEBSITE_URL', $websiteUrl, 'PageBuilder page website');
                RequestContext::setWelineWebsiteUrl($websiteUrl);
                Cookie::set('WELINE_WEBSITE_URL', $websiteUrl, 3600 * 24 * 30);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Render the default content block.
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
        // 预览时保留 page_id，确保语言切换后仍预览同一页面
        if (!empty($_GET['page_id'])) {
            $params['page_id'] = (int)$_GET['page_id'];
        }
        // 预览时保留 style_code，确保语言切换后仍预览当前模板
        if (!empty($_GET['style_code'])) {
            $params['style_code'] = (string)$_GET['style_code'];
        }
        
        // 重新构建URL
        $newQuery = http_build_query($params);
        
        return $path . ($newQuery ? '?' . $newQuery : '');
    }
}
