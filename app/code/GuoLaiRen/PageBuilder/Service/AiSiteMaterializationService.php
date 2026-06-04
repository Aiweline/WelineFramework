<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\PageLayout;
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlRewrite;

class AiSiteMaterializationService
{
    public function __construct(
        private readonly Page $pageModel,
        private readonly PageLayout $pageLayoutModel,
        private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return array{
     *   materialized_pages_by_type:array<string, array<string, mixed>>,
     *   home_page_id:int,
     *   preview_page_id:int,
     *   preview_page_type:string
     * }
     */
    public function materialize(
        int $websiteId,
        array $websiteProfile,
        array $pageTypes,
        array $pageTypeLayouts,
        array $virtualPagesByType = []
    ): array {
        if ($websiteId <= 0) {
            throw new \InvalidArgumentException((string)__('PageBuilder materialization requires a real website_id'));
        }

        $pageTypes = $this->normalizeMaterializationPageTypes($pageTypes);
        $layouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($pageTypeLayouts, $pageTypes);
        $layouts = $this->applyWebsiteIdentityToLayouts($layouts, $websiteProfile);
        foreach ($layouts as $layoutPageType => $layout) {
            $layouts[$layoutPageType] = $this->scopeCompatibilityService->localizeSharedLayoutConfigForScope($layout, [
                'website_profile' => $websiteProfile,
                'plan_json' => \is_array($websiteProfile['plan_json'] ?? null) ? $websiteProfile['plan_json'] : [],
                'content_locale' => $websiteProfile['content_locale'] ?? $websiteProfile['default_locale'] ?? '',
                'default_locale' => $websiteProfile['default_locale'] ?? '',
                'brief_description' => $websiteProfile['brief_description'] ?? '',
            ], (string)$layoutPageType);
        }
        $virtualPages = $this->scopeCompatibilityService->normalizeVirtualPagesByType($virtualPagesByType, $pageTypes);
        $homePageId = $this->resolveExistingHomePageId($websiteId, $pageTypes);
        $pagesByType = [];
        $pageLogo = $this->normalizePageAssetPath((string)($websiteProfile['logo'] ?? ''));
        $pageIcon = $this->normalizePageAssetPath((string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''));

        foreach ($pageTypes as $pageType) {
            $page = $this->loadPageByType($websiteId, $pageType) ?? $this->createNewPage();
            $defaults = $this->buildPageDefaults($pageType, $websiteProfile);
            $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
            $styleCode = $this->resolveMaterializedStyleCode($virtualPage);
            $styleSettings = $this->buildMaterializedStyleSettings($styleCode, $virtualPage);
            $pageLocale = $this->resolveMaterializedLocale($virtualPage, $websiteProfile);
            $pageLocales = $this->resolveMaterializedLocales($pageLocale, $websiteProfile);
            $pageHandle = \trim((string)($virtualPage['handle'] ?? $defaults['handle']));
            $layoutConfig = $layouts[$pageType] ?? $this->scopeCompatibilityService->normalizeLayoutConfig([]);
            $materializedLayoutConfig = $this->resolveMaterializedLayoutConfig($layoutConfig);
            $blocks = $this->resolveCanonicalPlanJsonAiHtmlBlocks($virtualPage);
            $hasGeneratedLayout = $blocks === [] && $this->layoutHasGeneratedContentComponents($materializedLayoutConfig);
            if ($blocks === [] && !$hasGeneratedLayout) {
                throw new \RuntimeException((string)__('AI virtual theme page has no generated layout or blocks: %{1}', [$pageType]));
            }
            $aiLayout = ['blocks' => $blocks];
            $renderMode = $hasGeneratedLayout ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;
            $aiLayoutJson = $hasGeneratedLayout ? null : \json_encode($aiLayout, \JSON_UNESCAPED_UNICODE);

            $page->setData(Page::schema_fields_TYPE, $pageType)
                ->setData(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(Page::schema_fields_NAME, $defaults['name'])
                ->setData(Page::schema_fields_TITLE, $defaults['title'])
                ->setData(Page::schema_fields_HANDLE, $this->resolveUniqueHandle(
                    $websiteId,
                    $pageHandle !== '' ? $pageHandle : $defaults['handle'],
                    (int)$page->getId()
                ))
                ->setData(Page::schema_fields_CONTENT, '')
                ->setData(Page::schema_fields_PARENT_ID, $pageType === Page::TYPE_HOME ? 0 : $homePageId)
                ->setData(Page::schema_fields_STYLE, $styleCode)
                ->setData(Page::schema_fields_STYLE_SETTING, \json_encode($styleSettings, \JSON_UNESCAPED_UNICODE))
                ->setData(Page::schema_fields_RENDER_MODE, $renderMode)
                ->setData(Page::schema_fields_AI_LAYOUT, $aiLayoutJson)
                ->setData(Page::schema_fields_AI_PUBLISH_SNAPSHOTS, null)
                ->setData(Page::schema_fields_DEFAULT_LOCALE, $pageLocale)
                ->setData(Page::schema_fields_LOCALES, \json_encode($pageLocales, \JSON_UNESCAPED_UNICODE))
                ->setData(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->setData(Page::schema_fields_LOGO, $pageLogo)
                ->setData(Page::schema_fields_ICON, $pageIcon)
                ->setData(Page::schema_fields_META_TITLE, $this->sanitizeMaterializedTitle($this->resolveMaterializedText($virtualPage['meta_title'] ?? null, $defaults['meta_title']), $defaults['meta_title']))
                ->setData(Page::schema_fields_META_DESCRIPTION, $this->resolveMaterializedText($virtualPage['meta_description'] ?? null, $defaults['meta_description']))
                ->setData(Page::schema_fields_META_KEYWORDS, $this->resolveMaterializedText($virtualPage['meta_keywords'] ?? null, $defaults['meta_keywords']))
                ->setData(Page::schema_fields_AI_DESCRIPTION, $this->resolveMaterializedText($virtualPage['ai_description'] ?? null, (string)($websiteProfile['brief_description'] ?? '')));

            $page->save(true);

            $pageId = (int)$page->getId();
            if ($pageId <= 0) {
                throw new \RuntimeException((string)__('Failed to materialize PageBuilder page: %{1}', [$pageType]));
            }

            if ($pageType === Page::TYPE_HOME) {
                $homePageId = $pageId;
            } elseif ($homePageId > 0 && (int)$page->getData(Page::schema_fields_PARENT_ID) !== $homePageId) {
                $page->setData(Page::schema_fields_PARENT_ID, $homePageId)->save(true);
            }
            $this->createOrUpdateUrlRewrite($page);

            $layout = $this->getOrCreateLayout($pageId);
            $layout->importConfig($materializedLayoutConfig)->useOriginalTemplate(false)->save();
            $this->syncLayoutConfigToPage($page, $layout->exportConfig());
            $this->forceRenderModePersistence($page, $renderMode, $aiLayoutJson);
            $this->logMaterializedLayoutContext($page, $pageType, $layoutConfig, $materializedLayoutConfig);

            $pagesByType[$pageType] = [
                'page_id' => $pageId,
                'website_id' => $websiteId,
                'type' => $pageType,
                'name' => (string)$page->getData(Page::schema_fields_NAME),
                'title' => (string)$page->getData(Page::schema_fields_TITLE),
                'handle' => (string)$page->getData(Page::schema_fields_HANDLE),
                'block_nodes' => $blocks,
            ];
        }

        $selection = $this->scopeCompatibilityService->resolvePreviewSelection($pagesByType);

        return [
            'materialized_pages_by_type' => $pagesByType,
            'home_page_id' => $homePageId,
            'preview_page_id' => $selection['preview_page_id'],
            'preview_page_type' => $selection['preview_page_type'],
        ];
    }

    /**
     * ai_html 鏉炪劎澧块崠鏍电窗閸愭瑥鍙?render_mode + ai_layout閿涘奔绗夌挧鎷屾珓閹风喍瀵屾０妯肩矋娴犺泛绔风仦鈧?
     *
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return array{
     *   materialized_pages_by_type:array<string, array<string, mixed>>,
     *   home_page_id:int,
     *   preview_page_id:int,
     *   preview_page_type:string
     * }
     */
    public function materializeHtml(
        int $websiteId,
        array $websiteProfile,
        array $pageTypes,
        array $virtualPagesByType = []
    ): array {
        if ($websiteId <= 0) {
            throw new \InvalidArgumentException((string)__('PageBuilder materialization requires a real website_id'));
        }

        $pageTypes = $this->normalizeMaterializationPageTypes($pageTypes);
        $virtualPages = $this->scopeCompatibilityService->normalizeVirtualPagesByType($virtualPagesByType, $pageTypes);
        $homePageId = $this->resolveExistingHomePageId($websiteId, $pageTypes);
        $pagesByType = [];
        $pageLogo = $this->normalizePageAssetPath((string)($websiteProfile['logo'] ?? ''));
        $pageIcon = $this->normalizePageAssetPath((string)($websiteProfile['icon'] ?? $websiteProfile['favicon'] ?? ''));

        foreach ($pageTypes as $pageType) {
            $page = $this->loadPageByType($websiteId, $pageType) ?? $this->createNewPage();
            $defaults = $this->buildPageDefaults($pageType, $websiteProfile);
            $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
            $pageLocale = $this->resolveMaterializedLocale($virtualPage, $websiteProfile);
            $pageLocales = $this->resolveMaterializedLocales($pageLocale, $websiteProfile);
            $pageHandle = \trim((string)($virtualPage['handle'] ?? $defaults['handle']));
            $blocks = \is_array($virtualPage['blocks'] ?? null) ? $this->filterPageContentAiHtmlBlocks($virtualPage['blocks']) : [];
            if ($blocks === []) {
                throw new \RuntimeException((string)__('AI HTML page has no generated plan_json blocks: %{1}', [$pageType]));
            }
            $aiLayout = ['blocks' => $blocks];
            $aiLayoutJson = \json_encode($aiLayout, \JSON_UNESCAPED_UNICODE);

            $page->setData(Page::schema_fields_TYPE, $pageType)
                ->setData(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(Page::schema_fields_NAME, $defaults['name'])
                ->setData(Page::schema_fields_TITLE, $defaults['title'])
                ->setData(Page::schema_fields_HANDLE, $this->resolveUniqueHandle(
                    $websiteId,
                    $pageHandle !== '' ? $pageHandle : $defaults['handle'],
                    (int)$page->getId()
                ))
                ->setData(Page::schema_fields_CONTENT, '')
                ->setData(Page::schema_fields_PARENT_ID, $pageType === Page::TYPE_HOME ? 0 : $homePageId)
                ->setData(Page::schema_fields_STYLE, 'default')
                ->setData(Page::schema_fields_STYLE_SETTING, '{}')
                ->setData(Page::schema_fields_RENDER_MODE, Page::RENDER_MODE_AI_HTML)
                ->setData(Page::schema_fields_AI_LAYOUT, $aiLayoutJson)
                ->setData(Page::schema_fields_AI_PUBLISH_SNAPSHOTS, null)
                ->setData(Page::schema_fields_DEFAULT_LOCALE, $pageLocale)
                ->setData(Page::schema_fields_LOCALES, \json_encode($pageLocales, \JSON_UNESCAPED_UNICODE))
                ->setData(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->setData(Page::schema_fields_LOGO, $pageLogo)
                ->setData(Page::schema_fields_ICON, $pageIcon)
                ->setData(Page::schema_fields_META_TITLE, $this->sanitizeMaterializedTitle($this->resolveMaterializedText($virtualPage['meta_title'] ?? null, $defaults['meta_title']), $defaults['meta_title']))
                ->setData(Page::schema_fields_META_DESCRIPTION, $this->resolveMaterializedText($virtualPage['meta_description'] ?? null, $defaults['meta_description']))
                ->setData(Page::schema_fields_META_KEYWORDS, $this->resolveMaterializedText($virtualPage['meta_keywords'] ?? null, $defaults['meta_keywords']))
                ->setData(Page::schema_fields_AI_DESCRIPTION, $this->resolveMaterializedText($virtualPage['ai_description'] ?? null, (string)($websiteProfile['brief_description'] ?? '')));

            $page->save(true);

            $pageId = (int)$page->getId();
            if ($pageId <= 0) {
                throw new \RuntimeException((string)__('Failed to materialize PageBuilder page: %{1}', [$pageType]));
            }

            if ($pageType === Page::TYPE_HOME) {
                $homePageId = $pageId;
            } elseif ($homePageId > 0 && (int)$page->getData(Page::schema_fields_PARENT_ID) !== $homePageId) {
                $page->setData(Page::schema_fields_PARENT_ID, $homePageId)->save(true);
            }
            $this->createOrUpdateUrlRewrite($page);

            $layout = $this->getOrCreateLayout($pageId);
            $emptyExport = [
                'version' => '1.0',
                'page_id' => $pageId,
                'use_original_template' => false,
                'header' => ['component' => '', 'config' => []],
                'content' => [],
                'footer' => ['component' => '', 'config' => []],
            ];
            $layout->importConfig($emptyExport)->useOriginalTemplate(false)->save();
            $this->syncLayoutConfigToPage($page, $emptyExport);
            $this->forceRenderModePersistence($page, Page::RENDER_MODE_AI_HTML, $aiLayoutJson);

            $pagesByType[$pageType] = [
                'page_id' => $pageId,
                'website_id' => $websiteId,
                'type' => $pageType,
                'name' => (string)$page->getData(Page::schema_fields_NAME),
                'title' => (string)$page->getData(Page::schema_fields_TITLE),
                'handle' => (string)$page->getData(Page::schema_fields_HANDLE),
                'block_nodes' => $blocks,
            ];
        }

        $selection = $this->scopeCompatibilityService->resolvePreviewSelection($pagesByType);

        return [
            'materialized_pages_by_type' => $pagesByType,
            'home_page_id' => $homePageId,
            'preview_page_id' => $selection['preview_page_id'],
            'preview_page_type' => $selection['preview_page_type'],
        ];
    }

    private function createNewPage(): Page
    {
        $page = clone $this->pageModel;
        $page->clearData()->clearQuery();

        return $page;
    }

    private function createOrUpdateUrlRewrite(Page $page): void
    {
        $pageId = (int)$page->getId();
        if ($pageId <= 0) {
            return;
        }

        $websiteId = (int)($page->getData(Page::schema_fields_WEBSITE_ID) ?? 0);
        $handle = \trim((string)($page->getData(Page::schema_fields_HANDLE) ?? ''));
        $rewritePath = $handle !== '' ? $handle : '';
        $urlIdentify = "pagebuilder_page_{$pageId}";
        $originalPath = "pagebuilder/frontend/page/view?page_id={$pageId}";

        /** @var UrlRewrite $urlRewriteModel */
        $urlRewriteModel = ObjectManager::getInstance(UrlRewrite::class);
        $rewrite = clone $urlRewriteModel;
        $rewrite->clear()
            ->where(UrlRewrite::schema_fields_URL_IDENTIFY, $urlIdentify)
            ->find()
            ->fetch();

        if (!$rewrite->getId()) {
            $rewrite = clone $urlRewriteModel;
            $rewrite->clear()
                ->where(UrlRewrite::schema_fields_URL_ID, "pagebuilder_page_{$pageId}")
                ->find()
                ->fetch();
        }

        if (!$rewrite->getId()) {
            $rewrite = clone $urlRewriteModel;
            $rewrite->clearData();
        }

        $rewrite
            ->setData(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
            ->setData(UrlRewrite::schema_fields_URL_ID, "pagebuilder_page_{$pageId}")
            ->setData(UrlRewrite::schema_fields_URL_IDENTIFY, $urlIdentify)
            ->setData(UrlRewrite::schema_fields_PATH, $originalPath)
            ->setData(UrlRewrite::schema_fields_REWRITE, $rewritePath)
            ->save(true);
    }

    private function getOrCreateLayout(int $pageId): PageLayout
    {
        return PageLayout::getOrCreateForPage($pageId);
    }

    private function loadPageByType(int $websiteId, string $pageType): ?Page
    {
        $page = clone $this->pageModel;
        $page->clearData()->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_TYPE, $pageType)
            ->order(Page::schema_fields_ID, 'ASC')
            ->limit(1)
            ->find()
            ->fetch();

        return $page->getId() > 0 ? $page : null;
    }

    /**
     * Materialization can run on a single changed page during resumable builds.
     * Unlike workspace scope normalization, this must not inject home_page.
     *
     * @return list<string>
     */
    private function normalizeMaterializationPageTypes(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : (\preg_split('/[\s,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        } else {
            $items = [];
        }

        $allowed = \array_keys(Page::getPageTypes());
        $pageTypes = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $pageType = \trim((string)$item);
            if ($pageType === '' || !\in_array($pageType, $allowed, true) || \in_array($pageType, $pageTypes, true)) {
                continue;
            }
            $pageTypes[] = $pageType;
        }

        return $pageTypes !== [] ? \array_values($pageTypes) : $allowed;
    }

    /**
     * @param list<string> $pageTypes
     */
    private function resolveExistingHomePageId(int $websiteId, array $pageTypes): int
    {
        if (\in_array(Page::TYPE_HOME, $pageTypes, true)) {
            return 0;
        }

        $homePage = $this->loadPageByType($websiteId, Page::TYPE_HOME);
        return $homePage instanceof Page ? (int)$homePage->getId() : 0;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @return array{name:string,title:string,handle:string,meta_title:string,meta_description:string,meta_keywords:string}
     */
    private function buildPageDefaults(string $pageType, array $websiteProfile): array
    {
        $siteTitle = $this->sanitizeMaterializedTitle((string)($websiteProfile['site_title'] ?? ''), 'Website');
        $locale = \trim((string)($websiteProfile['content_locale'] ?? $websiteProfile['default_locale'] ?? ''));
        $pageLabel = $this->localizeMaterializedPageLabel($pageType, $locale) ?: (string)(Page::getPageTypes()[$pageType] ?? $pageType);
        $seo = \is_array($websiteProfile['seo'] ?? null) ? $websiteProfile['seo'] : [];

        $name = $pageType === Page::TYPE_HOME ? $siteTitle : $pageLabel;
        $title = $pageType === Page::TYPE_HOME ? $siteTitle : ($pageLabel . ' - ' . $siteTitle);
        $handle = $pageType === Page::TYPE_HOME
            ? $this->slugify($siteTitle)
            : Page::getDefaultHandleForType($pageType);

        return [
            'name' => $name,
            'title' => $title,
            'handle' => $handle !== '' ? $handle : 'home',
            'meta_title' => $pageType === Page::TYPE_HOME
                ? (string)($seo['meta_title'] ?? $title)
                : $title,
            'meta_description' => (string)($seo['meta_description'] ?? $websiteProfile['brief_description'] ?? ''),
            'meta_keywords' => (string)($seo['meta_keywords'] ?? ''),
        ];
    }

    private function sanitizeMaterializedTitle(string $value, string $fallback = ''): string
    {
        $title = \preg_replace('/\b(?:website\s*profile|websiteProfile|site\s*profile|profile_json|scope_json|target_domain)\b/iu', '', $value) ?? $value;
        $title = \preg_replace('/\s+/u', ' ', \trim($title)) ?? \trim($title);
        if ($title !== '') {
            return $title;
        }

        $fallback = \preg_replace('/\b(?:website\s*profile|websiteProfile|site\s*profile|profile_json|scope_json|target_domain)\b/iu', '', $fallback) ?? $fallback;
        $fallback = \preg_replace('/\s+/u', ' ', \trim($fallback)) ?? \trim($fallback);
        return $fallback !== '' ? $fallback : 'Website';
    }

    private function localizeMaterializedPageLabel(string $pageType, string $locale): string
    {
        $family = match (true) {
            \preg_match('/^th(?:[_-]|$)/i', $locale) === 1 => 'th',
            \preg_match('/^(?:hi|hi[_-]in)(?:[_-]|$)/i', $locale) === 1 => 'hi',
            \preg_match('/^(zh|zh[_-]hans|zh[_-]cn|zh[_-]sg)/i', $locale) === 1 => 'zh',
            default => 'en',
        };
        $labels = [
            'en' => [
                Page::TYPE_HOME => 'Home',
                Page::TYPE_ABOUT => 'About',
                Page::TYPE_CONTACT => 'Contact',
                Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
                Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
                Page::TYPE_REFUND_POLICY => 'Refund Policy',
                Page::TYPE_SHIPPING_POLICY => 'Shipping Policy',
                Page::TYPE_COOKIE_POLICY => 'Cookie Policy',
                Page::TYPE_BLOG => 'Blog Post',
                Page::TYPE_BLOG_CATEGORY => 'Blog Categories',
                Page::TYPE_BLOG_LIST => 'Blog',
                Page::TYPE_CUSTOM => 'Page',
            ],
            'th' => [
                Page::TYPE_HOME => 'Home',
                Page::TYPE_ABOUT => 'About',
                Page::TYPE_CONTACT => 'Contact',
                Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
                Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
            ],
            'hi' => [
                Page::TYPE_HOME => 'Home',
                Page::TYPE_ABOUT => 'About',
                Page::TYPE_CONTACT => 'Contact',
                Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
                Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
            ],
            'zh' => [
                Page::TYPE_HOME => 'Home',
                Page::TYPE_ABOUT => 'About',
                Page::TYPE_CONTACT => 'Contact',
                Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
                Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
            ],
        ];

        return (string)($labels[$family][$pageType] ?? '');
    }

    private function resolveUniqueHandle(int $websiteId, string $desiredHandle, int $currentPageId = 0): string
    {
        $desiredHandle = \trim($desiredHandle);
        if ($desiredHandle === '') {
            return '';
        }

        $conflict = clone $this->pageModel;
        $conflict->clearData()->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_HANDLE, $desiredHandle)
            ->order(Page::schema_fields_ID, 'ASC')
            ->limit(1)
            ->find()
            ->fetch();

        if (!$conflict->getId() || (int)$conflict->getId() === $currentPageId) {
            return $desiredHandle;
        }

        return $desiredHandle . '-' . ($currentPageId > 0 ? $currentPageId : \substr(\md5($desiredHandle . $websiteId), 0, 6));
    }

    private function normalizePageAssetPath(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        // Page.logo/icon columns are varchar(255). Long inline data URIs break publish-time materialization.
        return \strlen($value) <= 255 ? $value : '';
    }

    /**
     * @param array<string, array<string,mixed>> $layouts
     * @param array<string,mixed> $websiteProfile
     * @return array<string, array<string,mixed>>
     */
    private function applyWebsiteIdentityToLayouts(array $layouts, array $websiteProfile): array
    {
        $logo = $this->normalizePageAssetPath((string)($websiteProfile['logo'] ?? ''));
        if ($logo === '') {
            return $layouts;
        }

        foreach ($layouts as $pageType => $layout) {
            if (!\is_string($pageType) || !\is_array($layout)) {
                continue;
            }
            if (\is_array($layout['header']['config'] ?? null)) {
                $layout['header']['config'] = $this->applyLogoToComponentConfig($layout['header']['config'], $logo);
            }
            if (\is_array($layout['footer']['config'] ?? null)) {
                $layout['footer']['config'] = $this->applyLogoToComponentConfig($layout['footer']['config'], $logo);
            }
            $layouts[$pageType] = $layout;
        }

        return $layouts;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function applyLogoToComponentConfig(array $config, string $logo): array
    {
        foreach (['logo.url', 'logo.image', 'identity.shared_logo_asset', 'brand.logo'] as $key) {
            if (\trim((string)($config[$key] ?? '')) === '') {
                $config[$key] = $logo;
            }
        }
        if (\is_array($config['logo'] ?? null)) {
            foreach (['url', 'image'] as $key) {
                if (\trim((string)($config['logo'][$key] ?? '')) === '') {
                    $config['logo'][$key] = $logo;
                }
            }
        }
        if (\is_array($config['identity'] ?? null) && \trim((string)($config['identity']['shared_logo_asset'] ?? '')) === '') {
            $config['identity']['shared_logo_asset'] = $logo;
        }
        if (\is_array($config['brand'] ?? null) && \trim((string)($config['brand']['logo'] ?? '')) === '') {
            $config['brand']['logo'] = $logo;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $layoutConfig
     */
    private function syncLayoutConfigToPage(Page $page, array $layoutConfig): void
    {
        $pageLayoutConfig = [
            'header' => [],
            'content' => [],
            'footer' => [],
        ];

        foreach ($layoutConfig['content'] ?? [] as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $pageLayoutConfig['content'][] = [
                'code' => (string)($component['code'] ?? $component['component'] ?? ''),
                'enabled' => !\array_key_exists('enabled', $component) || (bool)$component['enabled'],
                'config' => \is_array($component['config'] ?? null) ? $component['config'] : [],
                'instance_id' => (string)($component['instance_id'] ?? $component['id'] ?? ''),
            ];
        }

        if (!empty($layoutConfig['header']['component'])) {
            $pageLayoutConfig['header'][] = [
                'code' => (string)$layoutConfig['header']['component'],
                'enabled' => true,
                'config' => \is_array($layoutConfig['header']['config'] ?? null) ? $layoutConfig['header']['config'] : [],
            ];
        }

        if (!empty($layoutConfig['footer']['component'])) {
            $pageLayoutConfig['footer'][] = [
                'code' => (string)$layoutConfig['footer']['component'],
                'enabled' => true,
                'config' => \is_array($layoutConfig['footer']['config'] ?? null) ? $layoutConfig['footer']['config'] : [],
            ];
        }

        $layoutJson = \json_encode($pageLayoutConfig, \JSON_UNESCAPED_UNICODE);
        $page->setData(Page::schema_fields_LAYOUT_CONFIG, $layoutJson);
        $page->clearQuery()->where(Page::schema_fields_ID, (int)$page->getId());
        $page->update([
            Page::schema_fields_LAYOUT_CONFIG => $layoutJson,
        ]);
        $page->fetch();
    }

    private function forceRenderModePersistence(Page $page, string $renderMode, ?string $aiLayoutJson): void
    {
        $pageId = (int)$page->getId();
        if ($pageId <= 0) {
            return;
        }

        $page->setData(Page::schema_fields_RENDER_MODE, $renderMode)
            ->setData(Page::schema_fields_AI_LAYOUT, $aiLayoutJson);
        $page->clearQuery()->where(Page::schema_fields_ID, $pageId);
        $page->update([
            Page::schema_fields_RENDER_MODE => $renderMode,
            Page::schema_fields_AI_LAYOUT => $aiLayoutJson,
        ]);
        $page->fetch();
    }

    /**
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private function filterPageContentAiHtmlBlocks(array $blocks): array
    {
        return \array_values(\array_filter($blocks, static function (mixed $block): bool {
            return \is_array($block) && !AiSiteHtmlBlocksBuildService::isSharedLayoutBlock($block);
        }));
    }

    /**
     * @param array<string, mixed> $virtualPage
     * @return list<array<string, mixed>>
     */
    private function resolveCanonicalPlanJsonAiHtmlBlocks(array $virtualPage): array
    {
        $blocks = [];
        foreach ($virtualPage as $key => $node) {
            if (!\is_string($key) || !$this->isCanonicalPlanJsonBlockNode($key, $node)) {
                continue;
            }
            /** @var array<string, mixed> $node */
            $html = \trim((string)($node['html'] ?? $node['html_content'] ?? $node['phtml'] ?? ''));
            if ($html === '') {
                continue;
            }
            $block = $node;
            $block['block_key'] = \trim((string)($block['block_key'] ?? $key));
            $block['html'] = $html;
            $blocks[] = $block;
        }

        if ($blocks !== []) {
            return $this->filterPageContentAiHtmlBlocks($blocks);
        }

        return \is_array($virtualPage['blocks'] ?? null) ? $this->filterPageContentAiHtmlBlocks($virtualPage['blocks']) : [];
    }

    private function isCanonicalPlanJsonBlockNode(string $key, mixed $node): bool
    {
        if (!\is_array($node) || $this->isPlanJsonPageMetadataKey($key)) {
            return false;
        }

        if (\array_key_exists('status', $node) && (int)$node['status'] !== 1) {
            return false;
        }

        $html = \trim((string)($node['html'] ?? $node['html_content'] ?? $node['phtml'] ?? ''));
        return $html !== '';
    }

    private function isPlanJsonPageMetadataKey(string $key): bool
    {
        return \in_array($key, [
            'layout',
            'page_meta',
            'meta',
            'seo',
            'route',
            'assets',
            'blocks',
            'block_previews',
            'sections',
            'components',
            'page_design_plan',
            'primary_keywords',
            'secondary_keywords',
        ], true);
    }

    /**
     * @param array<string, mixed> $sourceLayoutConfig
     * @param array<string, mixed> $materializedLayoutConfig
     */
    private function logMaterializedLayoutContext(Page $page, string $pageType, array $sourceLayoutConfig, array $materializedLayoutConfig): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }

        $componentCodes = $this->extractComponentCodes($sourceLayoutConfig);
        if ($componentCodes === []) {
            return;
        }

        $containsVirtualAiSiteCode = false;
        foreach ($componentCodes as $code) {
            if (\str_contains($code, 'ai-site-') || \str_starts_with($code, 'content/')) {
                $containsVirtualAiSiteCode = true;
                break;
            }
        }

        if (!$containsVirtualAiSiteCode) {
            return;
        }

        w_log_warning(
            '[AiSiteMaterializationService] Materialized page keeps AI virtual component codes while page style is forced to default.'
            . ' page_id=' . (int)$page->getId()
            . ' page_type=' . $pageType
            . ' page_style=' . (string)$page->getData(Page::schema_fields_STYLE)
            . ' component_codes=' . \implode(',', $componentCodes)
            . ' fallback_to_style_defaults=' . ($this->layoutHasGeneratedContentComponents($materializedLayoutConfig) ? '0' : '1'),
            [
                'page_id' => (int)$page->getId(),
                'website_id' => (int)$page->getData(Page::schema_fields_WEBSITE_ID),
                'page_type' => $pageType,
                'page_style' => (string)$page->getData(Page::schema_fields_STYLE),
                'component_codes' => $componentCodes,
            ],
            'pagebuilder'
        );
    }

    /**
     * @param array<string, mixed> $virtualPage
     * @return array<string, array<string, mixed>>
     */
    private function buildMaterializedStyleSettings(string $styleCode, array $virtualPage): array
    {
        $styleSettings = \is_array($virtualPage['style_settings'] ?? null) ? $virtualPage['style_settings'] : [];
        if ($styleSettings === []) {
            return [];
        }

        return [$styleCode => $styleSettings];
    }

    /**
     * @param array<string, mixed> $virtualPage
     * @param array<string, mixed> $websiteProfile
     * @return list<string>
     */
    private function resolveMaterializedLocales(string $pageLocale, array $websiteProfile): array
    {
        $locales = \is_array($websiteProfile['locales'] ?? null) ? $websiteProfile['locales'] : [];
        $normalized = [];

        foreach ($locales as $locale) {
            if (!\is_scalar($locale)) {
                continue;
            }
            $localeCode = \trim((string)$locale);
            if ($localeCode !== '' && !\in_array($localeCode, $normalized, true)) {
                $normalized[] = $localeCode;
            }
        }

        if ($pageLocale !== '' && !\in_array($pageLocale, $normalized, true)) {
            \array_unshift($normalized, $pageLocale);
        }

        if ($normalized === []) {
            $normalized[] = $pageLocale !== '' ? $pageLocale : 'en_US';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $virtualPage
     * @param array<string, mixed> $websiteProfile
     */
    private function resolveMaterializedLocale(array $virtualPage, array $websiteProfile): string
    {
        $locale = \trim((string)($virtualPage['locale'] ?? ''));
        if ($locale !== '') {
            return $locale;
        }

        $defaultLocale = \trim((string)($websiteProfile['default_locale'] ?? 'en_US'));
        return $defaultLocale !== '' ? $defaultLocale : 'en_US';
    }

    /**
     * @param array<string, mixed> $virtualPage
     */
    private function resolveMaterializedStyleCode(array $virtualPage): string
    {
        $styleCode = \trim((string)($virtualPage['style_code'] ?? 'default'));
        return $styleCode !== '' ? $styleCode : 'default';
    }

    /**
     * @param array<string, mixed> $layoutConfig
     * @return array<string, mixed>
     */
    private function resolveMaterializedLayoutConfig(array $layoutConfig): array
    {
        return $layoutConfig;
    }

    /**
     * @param array<string, mixed> $layoutConfig
     */
    private function containsVirtualThemeComponentCodes(array $layoutConfig): bool
    {
        foreach ($this->extractComponentCodes($layoutConfig) as $code) {
            if (\str_contains($code, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $layoutConfig
     */
    private function layoutHasGeneratedContentComponents(array $layoutConfig): bool
    {
        foreach ($layoutConfig['content'] ?? [] as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $code = \trim((string)($component['code'] ?? $component['component'] ?? ''));
            if ($code !== '') {
                return true;
            }
        }

        return false;
    }

    private function resolveMaterializedText(mixed $preferred, string $fallback): string
    {
        $preferred = \trim((string)$preferred);
        return $preferred !== '' ? $preferred : $fallback;
    }

    /**
     * @param array<string, mixed> $layoutConfig
     * @return list<string>
     */
    private function extractComponentCodes(array $layoutConfig): array
    {
        $codes = [];

        $headerCode = (string)($layoutConfig['header']['component'] ?? '');
        if ($headerCode !== '') {
            $codes[] = $headerCode;
        }

        foreach ($layoutConfig['content'] ?? [] as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $code = (string)($component['code'] ?? $component['component'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $footerCode = (string)($layoutConfig['footer']['component'] ?? '');
        if ($footerCode !== '') {
            $codes[] = $footerCode;
        }

        return $codes;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'home';
    }
}
