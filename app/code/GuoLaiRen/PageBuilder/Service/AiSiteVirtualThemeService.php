<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponentVersion;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;
use Weline\Framework\Manager\ObjectManager;

/**
 * PageBuilder AI 寤虹珯铏氭嫙涓婚鏈嶅姟
 * 鎵€鏈夋暟鎹瓨鍌ㄥ湪 PageBuilder 鑷湁鐨勮〃涓紝涓嶄緷璧?Weline\Theme 妯″潡
 */
class AiSiteVirtualThemeService
{
    private const BLOG_PAGE_COMPONENTS = [
        Page::TYPE_BLOG_LIST => 'blog-list',
        Page::TYPE_BLOG_CATEGORY => 'blog-category',
        Page::TYPE_BLOG => 'blog-detail',
    ];

    private const BLOG_PAGE_COMPONENT_NAMES = [
        Page::TYPE_BLOG_LIST => 'Blog List',
        Page::TYPE_BLOG_CATEGORY => 'Blog Category',
        Page::TYPE_BLOG => 'Blog Detail',
    ];

    private const BLOG_PAGE_DEFAULT_CONFIG = [
        Page::TYPE_BLOG_LIST => [
            'posts_per_page' => 10,
            'show_sidebar' => true,
            'show_categories' => true,
            'show_recent_posts' => true,
            'show_pagination' => true,
            'layout' => 'grid',
        ],
        Page::TYPE_BLOG_CATEGORY => [
            'posts_per_page' => 10,
            'show_sidebar' => true,
            'show_categories' => true,
            'show_recent_posts' => true,
            'show_pagination' => true,
            'layout' => 'grid',
            'show_category_header' => true,
            'show_category_description' => true,
        ],
        Page::TYPE_BLOG => [
            'show_author' => true,
            'show_date' => true,
            'show_categories' => true,
            'show_tags' => true,
            'show_share_buttons' => true,
            'show_related_posts' => true,
            'show_comments' => false,
            'related_posts_count' => 3,
        ],
    ];

    private const BLOG_RUNTIME_DATA_KEYS = [
        'blog_categories',
        'current_category',
        'current_post',
        'related_posts',
        'blog_posts',
        'pagination',
        'recent_posts',
        'all_tags',
    ];

    public function __construct(
        private readonly ?AiSitePageBlueprintService $pageBlueprintService = null,
        private readonly ?AiSitePageComponentGenerationService $pageComponentGenerationService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array{virtual_theme_id:int,theme:VirtualTheme}
     */
    public function ensureThemeShell(array $scope, array $websiteProfile, int $sessionId = 0): array
    {
        $theme = $this->loadOrCreateTheme((int)($scope['virtual_theme_id'] ?? 0), $websiteProfile, $sessionId);
        if (!$theme->getId()) {
            $theme->save();
        }

        return [
            'virtual_theme_id' => (int)$theme->getId(),
            'theme' => $theme,
        ];
    }

    /**
     * @return array{
     *   header?:array<string, mixed>,
     *   footer?:array<string, mixed>
     * }
     */
    public function loadSharedComponents(int $themeId): array
    {
        if ($themeId <= 0) {
            return [];
        }

        $shared = [];
        foreach ([
            'header' => VirtualThemeComponent::CATEGORY_HEADER,
            'footer' => VirtualThemeComponent::CATEGORY_FOOTER,
        ] as $region => $category) {
            $component = $this->loadThemeComponentByCategory($themeId, $category);
            if (!$component instanceof VirtualThemeComponent || !$component->getId()) {
                continue;
            }

            $shared[$region] = [
                'code' => (string)$component->getComponentCode(),
                'name' => (string)$component->getName(),
                'region' => $region,
                'phtml' => (string)$component->getTemplateContent(),
                'html' => '',
                'default_config' => $this->sanitizeConfigAssetUrls($component->getDefaultConfig()),
                'ai_data' => [],
            ];
        }

        return $shared;
    }

    /**
     * @param array<string, mixed> $component
     */
    public function saveGeneratedSharedComponent(int $themeId, array $component): void
    {
        $region = \trim((string)($component['region'] ?? ''));
        if (!\in_array($region, ['header', 'footer'], true)) {
            throw new \InvalidArgumentException((string)__('Unsupported shared component region: %{1}', [$region]));
        }

        $category = $region === 'header'
            ? VirtualThemeComponent::CATEGORY_HEADER
            : VirtualThemeComponent::CATEGORY_FOOTER;

        $this->saveThemeComponent(
            $themeId,
            (string)($component['code'] ?? ''),
            VirtualThemeComponent::AREA_FRONTEND,
            $category,
            (string)($component['name'] ?? ''),
            (string)($component['phtml'] ?? ''),
            $this->sanitizeConfigAssetUrls(\is_array($component['default_config'] ?? null) ? $component['default_config'] : []),
            ['position' => [$region], 'page_layouts' => ['*'], 'sort_order' => $region === 'header' ? 10 : 20]
        );
    }

    /**
     * @param array<string, mixed> $component
     */
    public function saveGeneratedContentComponent(int $themeId, string $pageType, array $component): void
    {
        $componentCode = \trim((string)($component['code'] ?? ''));
        if ($themeId <= 0 || $pageType === '' || $componentCode === '') {
            throw new \InvalidArgumentException((string)__('Invalid generated content component payload'));
        }

        $this->saveThemeComponent(
            $themeId,
            $componentCode,
            VirtualThemeComponent::AREA_FRONTEND,
            VirtualThemeComponent::CATEGORY_CONTENT,
            (string)($component['name'] ?? $componentCode),
            (string)($component['phtml'] ?? ''),
            \is_array($component['default_config'] ?? null) ? $component['default_config'] : [],
            [
                'position' => ['content'],
                'page_layouts' => [$pageType],
                'sort_order' => (int)($component['sort_order'] ?? 0),
                'section_key' => (string)($component['key'] ?? ''),
            ]
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $component
     * @return array<string, mixed>
     */
    public function mergeGeneratedContentIntoLayout(array $layout, array $component): array
    {
        $componentCode = \trim((string)($component['code'] ?? ''));
        if ($componentCode === '') {
            return $layout;
        }

        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        $next = [
            'code' => $componentCode,
            'enabled' => true,
            'config' => \is_array($component['default_config'] ?? null) ? $component['default_config'] : [],
            'instance_id' => '',
            'sort_order' => (int)($component['sort_order'] ?? 0),
        ];

        $merged = [];
        $replaced = false;
        foreach ($content as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (\trim((string)($row['code'] ?? '')) === $componentCode) {
                $merged[] = $next;
                $replaced = true;
                continue;
            }
            $merged[] = $row;
        }
        if (!$replaced) {
            $merged[] = $next;
        }
        \usort($merged, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $layout['content'] = $merged;

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     */
    public function saveGeneratedPageLayout(int $themeId, string $pageType, array $layout): int
    {
        if ($themeId <= 0 || $pageType === '') {
            throw new \InvalidArgumentException((string)__('Invalid generated page layout payload'));
        }

        return $this->saveThemeLayout($themeId, $pageType, $this->sanitizeLayoutAssetUrls($layout));
    }

    /**
     * Concurrent build batches may carry only the current in-memory page subset.
     * Before appending a newly generated block, merge the already persisted
     * virtual-theme content rows back in so later batches cannot overwrite earlier
     * materialized blocks.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function mergePersistedContentIntoGeneratedLayout(int $themeId, string $pageType, array $layout): array
    {
        if ($themeId <= 0 || $pageType === '') {
            return $layout;
        }

        $persisted = $this->loadGeneratedPageLayout($themeId, $pageType);
        $persistedContent = \is_array($persisted['content'] ?? null) ? $persisted['content'] : [];
        if ($persistedContent === []) {
            return $layout;
        }

        $currentContent = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        $merged = [];
        foreach (\array_merge($persistedContent, $currentContent) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row['code'] ?? $row['component'] ?? ''));
            if ($code === '') {
                continue;
            }
            $merged[$code] = $row;
        }
        if ($merged === []) {
            return $layout;
        }

        \uasort($merged, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $layout['content'] = \array_values($merged);

        return $layout;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadGeneratedPageLayout(int $themeId, string $pageType): array
    {
        if ($themeId <= 0 || $pageType === '') {
            return [];
        }

        /** @var VirtualThemeLayout $themeLayout */
        $themeLayout = clone ObjectManager::getInstance(VirtualThemeLayout::class);
        $themeLayout->clearData()->clearQuery()
            ->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
            ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();
        if ((int)$themeLayout->getId() <= 0) {
            return [];
        }

        $config = $themeLayout->getConfig();
        return \is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @return array{virtual_theme_id:int,resolved_layouts:array<string, array<string, mixed>>,theme:VirtualTheme}
     */
    public function ensureVirtualTheme(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        array|int $pageTypeLayouts,
        int $sessionId = 0
    ): array {
        if (\is_int($pageTypeLayouts)) {
            $sessionId = $pageTypeLayouts;
            $pageTypeLayouts = [];
        }

        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $siteDisplayName = $pageBlueprintService->resolveSiteDisplayName($websiteProfile, $scope);
        $theme = $this->loadOrCreateTheme((int)($scope['virtual_theme_id'] ?? 0), $websiteProfile, $sessionId);
        if (!$theme->getId()) {
            $theme->save();
        }

        $themeId = (int)$theme->getId();
        $headerCode = 'header/ai-site-header';
        $footerCode = 'footer/ai-site-footer';
        $headerConfig = [
            'site_title' => $siteDisplayName,
            'site_tagline' => (string)($websiteProfile['site_tagline'] ?? ''),
            'logo' => (string)($websiteProfile['logo'] ?? ''),
            'nav_hint' => (string)__('Home | Pages | Contact'),
        ];
        $footerConfig = [
            'site_title' => $siteDisplayName,
            'brief_description' => (string)($websiteProfile['brief_description'] ?? ''),
            'target_domain' => (string)($websiteProfile['target_domain'] ?? ''),
        ];

        $this->saveThemeComponent(
            $themeId,
            $headerCode,
            VirtualThemeComponent::AREA_FRONTEND,
            VirtualThemeComponent::CATEGORY_HEADER,
            'AI Site Header',
            $this->buildHeaderTemplate(),
            $headerConfig,
            ['position' => ['header'], 'page_layouts' => ['*'], 'sort_order' => 10]
        );

        $this->saveThemeComponent(
            $themeId,
            $footerCode,
            VirtualThemeComponent::AREA_FRONTEND,
            VirtualThemeComponent::CATEGORY_FOOTER,
            'AI Site Footer',
            $this->buildFooterTemplate(),
            $footerConfig,
            ['position' => ['footer'], 'page_layouts' => ['*'], 'sort_order' => 20]
        );

        $resolvedLayouts = [];
        foreach ($pageTypes as $pageType) {
            if ($this->isBlogPageType($pageType)) {
                $layout = $this->buildNativeBlogPageLayout(
                    $pageType,
                    $scope,
                    \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [],
                    $headerCode,
                    $headerConfig,
                    $footerCode,
                    $footerConfig,
                    false
                );
                $this->saveNativeBlogContentComponent($themeId, $pageType, $scope);
                $resolvedLayouts[$pageType] = $layout;
                $this->saveThemeLayout($themeId, $pageType, $layout);
                continue;
            }

            $blueprint = $pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
            $generatedContent = [];

            foreach ($blueprint['sections'] as $section) {
                $generatedContent[] = [
                    'code' => (string)$section['code'],
                    'enabled' => true,
                    'config' => [],
                    'instance_id' => '',
                    'sort_order' => (int)($section['sort_order'] ?? 0),
                ];

                $this->saveThemeComponent(
                    $themeId,
                    (string)$section['code'],
                    VirtualThemeComponent::AREA_FRONTEND,
                    VirtualThemeComponent::CATEGORY_CONTENT,
                    (string)($section['name'] ?? $blueprint['page_label']),
                    $this->resolveContentTemplate((string)($section['template'] ?? 'hero')),
                    \is_array($section['config'] ?? null) ? $section['config'] : [],
                    [
                        'position' => ['content'],
                        'page_layouts' => [$pageType],
                        'sort_order' => (int)($section['sort_order'] ?? 100),
                        'section_key' => (string)($section['key'] ?? ''),
                    ]
                );
            }

            $persistedLayout = $this->loadGeneratedPageLayout($themeId, $pageType);
            $layout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
            if ($layout === [] && $persistedLayout !== []) {
                $layout = $persistedLayout;
            } elseif (
                !\is_array($layout['content'] ?? null)
                && \is_array($persistedLayout['content'] ?? null)
                && $persistedLayout['content'] !== []
            ) {
                $layout['content'] = $persistedLayout['content'];
            }
            $layout['header'] = \is_array($layout['header'] ?? null) ? $layout['header'] : ['component' => '', 'config' => []];
            $layout['footer'] = \is_array($layout['footer'] ?? null) ? $layout['footer'] : ['component' => '', 'config' => []];
            $layout['content'] = \is_array($layout['content'] ?? null) ? $layout['content'] : [];

            if (!$this->isGeneratedVirtualThemeComponentCode((string)($layout['header']['component'] ?? ''))) {
                $layout['header'] = ['component' => $headerCode, 'config' => []];
            }
            if (!$this->isGeneratedVirtualThemeComponentCode((string)($layout['footer']['component'] ?? ''))) {
                $layout['footer'] = ['component' => $footerCode, 'config' => []];
            }

            if ($this->shouldInjectGeneratedContent($layout['content'])) {
                $layout['content'] = $generatedContent;
            }

            $layout['version'] = '1.0';
            $layout['page_id'] = (int)($layout['page_id'] ?? 0);
            $layout['use_original_template'] = false;
            $resolvedLayouts[$pageType] = $layout;

            $this->saveThemeLayout($themeId, $pageType, $layout);
        }

        $config = $theme->getConfig();
        $config['source'] = VirtualTheme::SOURCE_PAGEBUILDER_AI;
        $config['scope_session_id'] = $sessionId;
        $config['website_profile'] = $websiteProfile;
        $config['selected_page_types'] = $pageTypes;
        $config['virtual_page_layouts'] = $resolvedLayouts;
        $theme->setConfig($config);
        $theme->save();

        return [
            'virtual_theme_id' => $themeId,
            'resolved_layouts' => $resolvedLayouts,
            'theme' => $theme,
        ];
    }

    /**
     * 鍩轰簬鐪熷疄 AI 鐢熸垚 header / footer / page sections锛屽苟鍐欏叆铏氭嫙涓婚缁勪欢銆?     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>>|int $pageTypeLayouts
     * @return array{virtual_theme_id:int,resolved_layouts:array<string, array<string, mixed>>,theme:VirtualTheme}
     */
    public function ensureAiGeneratedVirtualTheme(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        array|int $pageTypeLayouts,
        int $sessionId = 0,
        bool $regenerateSharedComponents = true,
        array $prebuiltSharedComponents = []
    ): array {
        if (\is_int($pageTypeLayouts)) {
            $sessionId = $pageTypeLayouts;
            $pageTypeLayouts = [];
        }

        $pageBlueprintService = $this->pageBlueprintService ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $pageComponentGenerationService = $this->pageComponentGenerationService ?? ObjectManager::getInstance(AiSitePageComponentGenerationService::class);

        $theme = $this->loadOrCreateTheme((int)($scope['virtual_theme_id'] ?? 0), $websiteProfile, $sessionId);
        if (!$theme->getId()) {
            $theme->save();
        }

        $themeId = (int)$theme->getId();
        if ($prebuiltSharedComponents !== []) {
            $sharedComponents = $prebuiltSharedComponents;
        } elseif ($this->resolveBuiltPlanJsonSharedComponents($scope) !== []) {
            $sharedComponents = $this->resolveBuiltPlanJsonSharedComponents($scope);
        } elseif (!$regenerateSharedComponents) {
            $sharedComponents = $this->loadSharedComponents($themeId);
            if (
                !\is_array($sharedComponents['header'] ?? null)
                || !\is_array($sharedComponents['footer'] ?? null)
            ) {
                $sharedComponents = $pageComponentGenerationService->generateSharedComponents($websiteProfile, $scope);
                $regenerateSharedComponents = true;
            }
        } else {
            $sharedComponents = $pageComponentGenerationService->generateSharedComponents($websiteProfile, $scope);
        }
        $headerCode = (string)($sharedComponents['header']['code'] ?? 'header/ai-site-header');
        $footerCode = (string)($sharedComponents['footer']['code'] ?? 'footer/ai-site-footer');
        $headerConfig = \is_array($sharedComponents['header']['default_config'] ?? null) ? $sharedComponents['header']['default_config'] : [];
        $footerConfig = \is_array($sharedComponents['footer']['default_config'] ?? null) ? $sharedComponents['footer']['default_config'] : [];

        if ($regenerateSharedComponents || !$this->themeComponentExists($themeId, $headerCode, VirtualThemeComponent::AREA_FRONTEND)) {
            $this->saveThemeComponent(
                $themeId,
                $headerCode,
                VirtualThemeComponent::AREA_FRONTEND,
                VirtualThemeComponent::CATEGORY_HEADER,
                (string)($sharedComponents['header']['name'] ?? 'AI Site Header'),
                (string)($sharedComponents['header']['phtml'] ?? ''),
                $headerConfig,
                ['position' => ['header'], 'page_layouts' => ['*'], 'sort_order' => 10]
            );
        }

        if ($regenerateSharedComponents || !$this->themeComponentExists($themeId, $footerCode, VirtualThemeComponent::AREA_FRONTEND)) {
            $this->saveThemeComponent(
                $themeId,
                $footerCode,
                VirtualThemeComponent::AREA_FRONTEND,
                VirtualThemeComponent::CATEGORY_FOOTER,
                (string)($sharedComponents['footer']['name'] ?? 'AI Site Footer'),
                (string)($sharedComponents['footer']['phtml'] ?? ''),
                $footerConfig,
                ['position' => ['footer'], 'page_layouts' => ['*'], 'sort_order' => 20]
            );
        }

        $resolvedLayouts = [];
        foreach ($pageTypes as $pageType) {
            if ($this->isBlogPageType($pageType)) {
                $layout = $this->buildNativeBlogPageLayout(
                    $pageType,
                    $scope,
                    \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [],
                    $headerCode,
                    $headerConfig,
                    $footerCode,
                    $footerConfig,
                    true
                );
                $this->saveNativeBlogContentComponent($themeId, $pageType, $scope);
                $resolvedLayouts[$pageType] = $layout;
                $this->saveThemeLayout($themeId, $pageType, $layout);
                continue;
            }

            $pageSections = $pageComponentGenerationService->generatePageSections($pageType, $websiteProfile, $scope);
            $blueprint = \is_array($pageSections['blueprint'] ?? null)
                ? $pageSections['blueprint']
                : $pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
            $generatedContent = [];

            foreach (($pageSections['sections'] ?? []) as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $componentCode = \trim((string)($section['code'] ?? ''));
                if ($componentCode === '') {
                    continue;
                }

                $componentConfig = \is_array($section['default_config'] ?? null) ? $section['default_config'] : [];
                $generatedContent[] = [
                    'code' => $componentCode,
                    'enabled' => true,
                    'config' => $componentConfig,
                    'instance_id' => '',
                    'sort_order' => (int)($section['sort_order'] ?? 0),
                ];

                $this->saveThemeComponent(
                    $themeId,
                    $componentCode,
                    VirtualThemeComponent::AREA_FRONTEND,
                    VirtualThemeComponent::CATEGORY_CONTENT,
                    (string)($section['name'] ?? ($blueprint['page_label'] ?? $componentCode)),
                    (string)($section['phtml'] ?? ''),
                    $componentConfig,
                    [
                        'position' => ['content'],
                        'page_layouts' => [$pageType],
                        'sort_order' => (int)($section['sort_order'] ?? 100),
                        'section_key' => (string)($section['key'] ?? ''),
                    ]
                );
            }

            $layout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
            $layout['header'] = \is_array($layout['header'] ?? null) ? $layout['header'] : ['component' => '', 'config' => []];
            $layout['footer'] = \is_array($layout['footer'] ?? null) ? $layout['footer'] : ['component' => '', 'config' => []];
            $layout['content'] = \is_array($layout['content'] ?? null) ? $layout['content'] : [];

            $layout['header'] = ['component' => $headerCode, 'config' => $headerConfig];
            $layout['footer'] = ['component' => $footerCode, 'config' => $footerConfig];
            if ($generatedContent !== []) {
                $layout['content'] = $generatedContent;
            }

            $layout['version'] = '1.0';
            $layout['page_id'] = (int)($layout['page_id'] ?? 0);
            $layout['use_original_template'] = false;
            $resolvedLayouts[$pageType] = $layout;

            $this->saveThemeLayout($themeId, $pageType, $layout);
        }

        $config = $theme->getConfig();
        $config['source'] = VirtualTheme::SOURCE_PAGEBUILDER_AI;
        $config['scope_session_id'] = $sessionId;
        $config['website_profile'] = $websiteProfile;
        $config['selected_page_types'] = $pageTypes;
        $config['virtual_page_layouts'] = $resolvedLayouts;
        $theme->setConfig($config);
        $theme->save();

        return [
            'virtual_theme_id' => $themeId,
            'resolved_layouts' => $resolvedLayouts,
            'theme' => $theme,
        ];
    }

    /**
     * Regenerate exactly one page inside an existing AI virtual theme.
     *
     * The full-site build loop owns initial generation and shared component
     * creation. Page-level rebuilds must preserve other page layouts and only
     * replace the target page content, otherwise a single tab action can become
     * an expensive all-site rebuild.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>>|int $pageTypeLayouts
     * @return array{virtual_theme_id:int,resolved_layouts:array<string, array<string, mixed>>,theme:VirtualTheme,page_blueprint:array<string,mixed>,section_count:int}
     */
    public function regenerateAiGeneratedVirtualThemePage(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        array|int $pageTypeLayouts,
        string $pageType,
        int $sessionId = 0
    ): array {
        if (\is_int($pageTypeLayouts)) {
            $sessionId = $pageTypeLayouts;
            $pageTypeLayouts = [];
        }

        $pageType = \trim($pageType);
        if ($pageType === '' || !\in_array($pageType, $pageTypes, true)) {
            throw new \InvalidArgumentException((string)__('Invalid generated page type'));
        }

        $pageComponentGenerationService = $this->pageComponentGenerationService ?? ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $theme = $this->loadOrCreateTheme((int)($scope['virtual_theme_id'] ?? 0), $websiteProfile, $sessionId);
        if (!$theme->getId()) {
            $theme->save();
        }

        $themeId = (int)$theme->getId();
        $themeConfig = $theme->getConfig();
        $storedLayouts = \is_array($themeConfig['virtual_page_layouts'] ?? null) ? $themeConfig['virtual_page_layouts'] : [];
        $resolvedLayouts = \array_replace(
            $this->normalizeLayoutMap($storedLayouts),
            $this->normalizeLayoutMap(\is_array($pageTypeLayouts) ? $pageTypeLayouts : [])
        );

        $sharedComponents = $this->resolveBuiltPlanJsonSharedComponents($scope);
        if ($sharedComponents === []) {
            $sharedComponents = $this->loadSharedComponents($themeId);
        }
        if (!\is_array($sharedComponents['header'] ?? null) || !\is_array($sharedComponents['footer'] ?? null)) {
            $generatedShared = $pageComponentGenerationService->generateSharedComponents($websiteProfile, $scope);
            foreach (['header', 'footer'] as $region) {
                if (!\is_array($sharedComponents[$region] ?? null) && \is_array($generatedShared[$region] ?? null)) {
                    $sharedComponents[$region] = $generatedShared[$region];
                    $this->saveGeneratedSharedComponent($themeId, $generatedShared[$region]);
                }
            }
        }

        $headerCode = (string)($sharedComponents['header']['code'] ?? 'header/ai-site-header');
        $footerCode = (string)($sharedComponents['footer']['code'] ?? 'footer/ai-site-footer');
        $headerConfig = \is_array($sharedComponents['header']['default_config'] ?? null) ? $sharedComponents['header']['default_config'] : [];
        $footerConfig = \is_array($sharedComponents['footer']['default_config'] ?? null) ? $sharedComponents['footer']['default_config'] : [];
        $blueprint = [];
        $sectionCount = 0;

        if ($this->isBlogPageType($pageType)) {
            $layout = $this->buildNativeBlogPageLayout(
                $pageType,
                $scope,
                \is_array($resolvedLayouts[$pageType] ?? null) ? $resolvedLayouts[$pageType] : [],
                $headerCode,
                $headerConfig,
                $footerCode,
                $footerConfig,
                true
            );
            $this->saveNativeBlogContentComponent($themeId, $pageType, $scope);
            $resolvedLayouts[$pageType] = $layout;
            $this->saveThemeLayout($themeId, $pageType, $layout);
        } else {
            $pageSections = $pageComponentGenerationService->generatePageSections($pageType, $websiteProfile, $scope);
            $blueprint = \is_array($pageSections['blueprint'] ?? null) ? $pageSections['blueprint'] : [];
            $generatedContent = [];
            foreach (($pageSections['sections'] ?? []) as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $componentCode = \trim((string)($section['code'] ?? ''));
                if ($componentCode === '') {
                    continue;
                }
                $componentConfig = \is_array($section['default_config'] ?? null) ? $section['default_config'] : [];
                $generatedContent[] = [
                    'code' => $componentCode,
                    'enabled' => true,
                    'config' => $componentConfig,
                    'instance_id' => '',
                    'sort_order' => (int)($section['sort_order'] ?? 0),
                ];
                $this->saveThemeComponent(
                    $themeId,
                    $componentCode,
                    VirtualThemeComponent::AREA_FRONTEND,
                    VirtualThemeComponent::CATEGORY_CONTENT,
                    (string)($section['name'] ?? ($blueprint['page_label'] ?? $componentCode)),
                    (string)($section['phtml'] ?? ''),
                    $componentConfig,
                    [
                        'position' => ['content'],
                        'page_layouts' => [$pageType],
                        'sort_order' => (int)($section['sort_order'] ?? 100),
                        'section_key' => (string)($section['key'] ?? ''),
                    ]
                );
            }
            $sectionCount = \count($generatedContent);

            $layout = \is_array($resolvedLayouts[$pageType] ?? null) ? $resolvedLayouts[$pageType] : [];
            if ($layout === []) {
                $layout = $this->loadGeneratedPageLayout($themeId, $pageType);
            }
            $layout['header'] = \is_array($layout['header'] ?? null) ? $layout['header'] : ['component' => '', 'config' => []];
            $layout['footer'] = \is_array($layout['footer'] ?? null) ? $layout['footer'] : ['component' => '', 'config' => []];
            if (\trim((string)($layout['header']['component'] ?? '')) === '') {
                $layout['header'] = ['component' => $headerCode, 'config' => $headerConfig];
            }
            if (\trim((string)($layout['footer']['component'] ?? '')) === '') {
                $layout['footer'] = ['component' => $footerCode, 'config' => $footerConfig];
            }
            if ($generatedContent !== []) {
                $layout['content'] = $generatedContent;
            } else {
                $layout['content'] = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
            }
            $layout['version'] = '1.0';
            $layout['page_id'] = (int)($layout['page_id'] ?? 0);
            $layout['use_original_template'] = false;
            $resolvedLayouts[$pageType] = $layout;
            $this->saveThemeLayout($themeId, $pageType, $layout);
        }

        $themeConfig = $theme->getConfig();
        $themeConfig['source'] = VirtualTheme::SOURCE_PAGEBUILDER_AI;
        $themeConfig['scope_session_id'] = $sessionId;
        $themeConfig['website_profile'] = $websiteProfile;
        $themeConfig['selected_page_types'] = $pageTypes;
        $themeConfig['virtual_page_layouts'] = $resolvedLayouts;
        $theme->setConfig($themeConfig);
        $theme->save();

        return [
            'virtual_theme_id' => $themeId,
            'resolved_layouts' => $resolvedLayouts,
            'theme' => $theme,
            'page_blueprint' => $blueprint,
            'section_count' => $sectionCount,
        ];
    }

    /**
     * @param mixed $layouts
     * @return array<string, array<string, mixed>>
     */
    private function normalizeLayoutMap(mixed $layouts): array
    {
        if (!\is_array($layouts)) {
            return [];
        }

        $normalized = [];
        foreach ($layouts as $pageType => $layout) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '' || !\is_array($layout)) {
                continue;
            }
            $normalized[$pageType] = $layout;
        }

        return $normalized;
    }

    private function isBlogPageType(string $pageType): bool
    {
        return isset(self::BLOG_PAGE_COMPONENTS[$pageType]);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{header?:array<string,mixed>,footer?:array<string,mixed>}
     */
    private function resolveBuiltPlanJsonSharedComponents(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];
        $resolved = [];
        foreach (['header', 'footer'] as $region) {
            $component = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
            if ($component === []) {
                continue;
            }
            $code = \trim((string)($component['code'] ?? $component['component_code'] ?? ''));
            $html = \trim((string)($component['html'] ?? $component['html_content'] ?? $component['phtml'] ?? ''));
            if ($code === '' || $html === '') {
                continue;
            }
            $component['region'] = $region;
            $component['code'] = $code;
            $resolved[$region] = $component;
        }

        return \is_array($resolved['header'] ?? null) && \is_array($resolved['footer'] ?? null) ? $resolved : [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $existingLayout
     * @param array<string, mixed> $headerConfig
     * @param array<string, mixed> $footerConfig
     * @return array<string, mixed>
     */
    private function buildNativeBlogPageLayout(
        string $pageType,
        array $scope,
        array $existingLayout,
        string $headerCode,
        array $headerConfig,
        string $footerCode,
        array $footerConfig,
        bool $replaceSharedRegions
    ): array {
        $preferredStyleCode = $this->resolvePromptStyleCode($scope, $pageType);
        $layoutRelativePath = 'layouts/default/' . $pageType . '.json';
        $layoutStyleCode = $this->resolveNativeBlogTemplateStyleCode($preferredStyleCode, $layoutRelativePath);
        $layoutPayload = $layoutStyleCode !== ''
            ? $this->readStyleTemplateJson($layoutStyleCode, $layoutRelativePath)
            : [];

        $layout = $existingLayout;
        $layout['header'] = \is_array($layout['header'] ?? null) ? $layout['header'] : ['component' => '', 'config' => []];
        $layout['footer'] = \is_array($layout['footer'] ?? null) ? $layout['footer'] : ['component' => '', 'config' => []];

        if ($replaceSharedRegions || \trim((string)($layout['header']['component'] ?? '')) === '') {
            $layout['header'] = ['component' => $headerCode, 'config' => $headerConfig];
        }
        if ($replaceSharedRegions || \trim((string)($layout['footer']['component'] ?? '')) === '') {
            $layout['footer'] = ['component' => $footerCode, 'config' => $footerConfig];
        }

        $layout['content'] = $this->buildNativeBlogContentRows($pageType, $preferredStyleCode, $layoutPayload);
        $layout['version'] = '1.0';
        $layout['page_id'] = (int)($layout['page_id'] ?? 0);
        $layout['use_original_template'] = false;
        $layout['native_blog_template'] = true;
        $layout['blog_page_type'] = $pageType;
        $layout['blog_component_code'] = self::BLOG_PAGE_COMPONENTS[$pageType];
        $layout['blog_runtime_data_keys'] = self::BLOG_RUNTIME_DATA_KEYS;
        if ($layoutStyleCode !== '') {
            $layout['native_blog_layout_template_code'] = $layoutStyleCode;
        }
        if (\is_array($layoutPayload['inherit_regions'] ?? null)) {
            $layout['inherit_regions'] = $layoutPayload['inherit_regions'];
        }

        return $layout;
    }

    /**
     * @param array<string, mixed> $layoutPayload
     * @return list<array<string, mixed>>
     */
    private function buildNativeBlogContentRows(string $pageType, string $preferredStyleCode, array $layoutPayload): array
    {
        $componentCode = self::BLOG_PAGE_COMPONENTS[$pageType];
        $componentRelativePath = 'components/content/' . $componentCode . '.phtml';
        $componentStyleCode = $this->resolveNativeBlogTemplateStyleCode($preferredStyleCode, $componentRelativePath);
        $layoutConfig = \is_array($layoutPayload['layout_config'] ?? null) ? $layoutPayload['layout_config'] : [];
        $contentRows = \is_array($layoutConfig['content'] ?? null) ? $layoutConfig['content'] : [];
        $rows = [];
        $sortOrder = 10;

        foreach ($contentRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row['code'] ?? $row['component'] ?? ''));
            if ($code === '') {
                $code = $componentCode;
            }
            if ($code !== $componentCode) {
                continue;
            }

            $config = \array_replace(
                self::BLOG_PAGE_DEFAULT_CONFIG[$pageType] ?? [],
                \is_array($row['config'] ?? null) ? $row['config'] : []
            );
            $contentRow = [
                'code' => $componentCode,
                'enabled' => (bool)($row['enabled'] ?? true),
                'config' => $config,
                'instance_id' => (string)($row['instance_id'] ?? $componentCode . '-native'),
                'sort_order' => (int)($row['sort_order'] ?? $sortOrder),
                'native_blog_template' => true,
            ];
            if ($componentStyleCode !== '') {
                $contentRow['template_code'] = $componentStyleCode;
            }
            $rows[] = $contentRow;
            $sortOrder += 10;
        }

        if ($rows === []) {
            $row = [
                'code' => $componentCode,
                'enabled' => true,
                'config' => self::BLOG_PAGE_DEFAULT_CONFIG[$pageType] ?? [],
                'instance_id' => $componentCode . '-native',
                'sort_order' => 10,
                'native_blog_template' => true,
            ];
            if ($componentStyleCode !== '') {
                $row['template_code'] = $componentStyleCode;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function saveNativeBlogContentComponent(int $themeId, string $pageType, array $scope): void
    {
        $componentCode = self::BLOG_PAGE_COMPONENTS[$pageType] ?? '';
        if ($themeId <= 0 || $componentCode === '') {
            return;
        }

        $preferredStyleCode = $this->resolvePromptStyleCode($scope, $pageType);
        $componentRelativePath = 'components/content/' . $componentCode . '.phtml';
        $componentStyleCode = $this->resolveNativeBlogTemplateStyleCode($preferredStyleCode, $componentRelativePath);
        $templateContent = $componentStyleCode !== ''
            ? $this->readStyleTemplateContent($componentStyleCode, $componentRelativePath)
            : '';
        if ($templateContent === '') {
            $templateContent = $this->buildFallbackNativeBlogComponentTemplate($pageType);
        }

        $this->saveThemeComponent(
            $themeId,
            $componentCode,
            VirtualThemeComponent::AREA_FRONTEND,
            VirtualThemeComponent::CATEGORY_CONTENT,
            self::BLOG_PAGE_COMPONENT_NAMES[$pageType] ?? $componentCode,
            $templateContent,
            self::BLOG_PAGE_DEFAULT_CONFIG[$pageType] ?? [],
            [
                'position' => ['content'],
                'page_layouts' => [$pageType],
                'sort_order' => 10,
                'section_key' => 'native-blog:' . $pageType,
                'native_blog_template' => true,
                'runtime_data_keys' => self::BLOG_RUNTIME_DATA_KEYS,
                'source_style_code' => $componentStyleCode !== '' ? $componentStyleCode : null,
            ]
        );
    }

    private function resolvePromptStyleCode(array $scope, string $pageType): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $planPage = \is_array($planPages[$pageType] ?? null) ? $planPages[$pageType] : [];
        $styleCode = \trim((string)($planPage['style_code'] ?? $scope['style_code'] ?? 'default'));

        return $styleCode !== '' ? $styleCode : 'default';
    }

    private function resolveNativeBlogTemplateStyleCode(string $preferredStyleCode, string $relativePath): string
    {
        $candidates = [];
        foreach ([$preferredStyleCode, 'default'] as $styleCode) {
            $styleCode = \trim($styleCode);
            if ($styleCode !== '' && !\in_array($styleCode, $candidates, true)) {
                $candidates[] = $styleCode;
            }
        }

        foreach ($candidates as $styleCode) {
            if (\is_file($this->buildStyleTemplatePath($styleCode, $relativePath))) {
                return $styleCode;
            }
        }

        $styleRoot = $this->getStyleTemplateRoot();
        if (!\is_dir($styleRoot)) {
            return '';
        }

        $entries = \scandir($styleRoot);
        if (!\is_array($entries)) {
            return '';
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'default' || $entry === $preferredStyleCode) {
                continue;
            }
            if (\str_starts_with($entry, '.')) {
                continue;
            }
            $candidatePath = $styleRoot . $entry . '/' . $relativePath;
            if (\is_file($candidatePath)) {
                return $entry;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function readStyleTemplateJson(string $styleCode, string $relativePath): array
    {
        $path = $this->buildStyleTemplatePath($styleCode, $relativePath);
        if (!\is_file($path)) {
            return [];
        }

        $decoded = \json_decode((string)\file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function readStyleTemplateContent(string $styleCode, string $relativePath): string
    {
        $path = $this->buildStyleTemplatePath($styleCode, $relativePath);
        if (!\is_file($path)) {
            return '';
        }

        return (string)\file_get_contents($path);
    }

    private function buildStyleTemplatePath(string $styleCode, string $relativePath): string
    {
        return $this->getStyleTemplateRoot() . \trim($styleCode, '/\\') . '/' . \ltrim($relativePath, '/\\');
    }

    private function getStyleTemplateRoot(): string
    {
        return BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
    }

    private function buildFallbackNativeBlogComponentTemplate(string $pageType): string
    {
        return match ($pageType) {
            Page::TYPE_BLOG => $this->buildFallbackBlogDetailTemplate(),
            Page::TYPE_BLOG_CATEGORY => $this->buildFallbackBlogCategoryTemplate(),
            default => $this->buildFallbackBlogListTemplate(),
        };
    }

    private function buildFallbackBlogListTemplate(): string
    {
        return <<<'PHTML'
<?php
$posts = is_array($blog_posts ?? null) ? $blog_posts : [];
$categories = is_array($blog_categories ?? null) ? $blog_categories : [];
$recentPosts = is_array($recent_posts ?? null) ? $recent_posts : [];
$paginationData = is_array($pagination ?? null) ? $pagination : [];
?>
<section class="pb-native-blog pb-native-blog-list" style="padding:48px 24px;background:#ffffff;color:#0f172a;">
    <div style="max-width:1120px;margin:0 auto;display:grid;gap:28px;">
        <header style="display:grid;gap:10px;">
            <span style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">Blog</span>
            <h1 style="margin:0;font-size:38px;line-height:1.12;">Latest Articles</h1>
        </header>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:28px;align-items:start;">
            <main style="display:grid;gap:18px;">
                <?php if ($posts === []): ?>
                    <p style="margin:0;color:#64748b;">No blog posts are available yet.</p>
                <?php endif; ?>
                <?php foreach ($posts as $post): ?>
                    <?php if (!is_array($post)) { continue; } ?>
                    <?php $url = (string)($post['url'] ?? (!empty($post['slug']) ? '/blog?slug=' . rawurlencode((string)$post['slug']) : '#')); ?>
                    <article style="padding:22px;border:1px solid #e5e7eb;border-radius:20px;background:#f8fafc;">
                        <h2 style="margin:0 0 8px;font-size:24px;line-height:1.2;"><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars((string)($post['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a></h2>
                        <?php if (!empty($post['excerpt'])): ?>
                            <p style="margin:0;color:#475569;line-height:1.7;"><?= htmlspecialchars((string)$post['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!empty($paginationData['total_pages']) && (int)$paginationData['total_pages'] > 1): ?>
                    <nav style="font-size:14px;color:#64748b;">Page <?= (int)($paginationData['current_page'] ?? 1) ?> / <?= (int)$paginationData['total_pages'] ?></nav>
                <?php endif; ?>
            </main>
            <aside style="display:grid;gap:18px;">
                <?php if ($categories !== []): ?>
                    <section style="padding:18px;border:1px solid #e5e7eb;border-radius:18px;">
                        <strong>Categories</strong>
                        <ul style="margin:12px 0 0;padding-left:18px;display:grid;gap:8px;">
                            <?php foreach ($categories as $category): ?>
                                <?php if (!is_array($category)) { continue; } ?>
                                <li><?= htmlspecialchars((string)($category['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
                <?php if ($recentPosts !== []): ?>
                    <section style="padding:18px;border:1px solid #e5e7eb;border-radius:18px;">
                        <strong>Recent Posts</strong>
                        <ul style="margin:12px 0 0;padding-left:18px;display:grid;gap:8px;">
                            <?php foreach ($recentPosts as $post): ?>
                                <?php if (!is_array($post)) { continue; } ?>
                                <li><?= htmlspecialchars((string)($post['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>
PHTML;
    }

    private function buildFallbackBlogCategoryTemplate(): string
    {
        return <<<'PHTML'
<?php
$category = is_array($current_category ?? null) ? $current_category : [];
$posts = is_array($blog_posts ?? null) ? $blog_posts : [];
?>
<section class="pb-native-blog pb-native-blog-category" style="padding:48px 24px;background:#ffffff;color:#0f172a;">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:24px;">
        <header style="padding:24px;border-radius:24px;background:#f8fafc;border:1px solid #e5e7eb;">
            <span style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">Category</span>
            <h1 style="margin:8px 0 0;font-size:36px;line-height:1.12;"><?= htmlspecialchars((string)($category['name'] ?? 'Blog Category'), ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if (!empty($category['description'])): ?>
                <p style="margin:10px 0 0;color:#475569;line-height:1.7;"><?= htmlspecialchars((string)$category['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </header>
        <main style="display:grid;gap:18px;">
            <?php if ($posts === []): ?>
                <p style="margin:0;color:#64748b;">No posts are available in this category yet.</p>
            <?php endif; ?>
            <?php foreach ($posts as $post): ?>
                <?php if (!is_array($post)) { continue; } ?>
                <?php $url = (string)($post['url'] ?? (!empty($post['slug']) ? '/blog?slug=' . rawurlencode((string)$post['slug']) : '#')); ?>
                <article style="padding:22px;border:1px solid #e5e7eb;border-radius:20px;background:#ffffff;">
                    <h2 style="margin:0 0 8px;font-size:24px;line-height:1.2;"><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars((string)($post['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a></h2>
                    <?php if (!empty($post['excerpt'])): ?>
                        <p style="margin:0;color:#475569;line-height:1.7;"><?= htmlspecialchars((string)$post['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </main>
    </div>
</section>
PHTML;
    }

    private function buildFallbackBlogDetailTemplate(): string
    {
        return <<<'PHTML'
<?php
$post = is_array($current_post ?? null) ? $current_post : [];
$relatedPosts = is_array($related_posts ?? null) ? $related_posts : [];
$tags = is_array($post['tags'] ?? null) ? $post['tags'] : [];
?>
<article class="pb-native-blog pb-native-blog-detail" style="padding:48px 24px;background:#ffffff;color:#0f172a;">
    <div style="max-width:860px;margin:0 auto;display:grid;gap:24px;">
        <header style="display:grid;gap:12px;">
            <span style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;">Article</span>
            <h1 style="margin:0;font-size:42px;line-height:1.08;"><?= htmlspecialchars((string)($post['title'] ?? 'Blog Article'), ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if (!empty($post['published_at']) || !empty($post['author_name'])): ?>
                <p style="margin:0;color:#64748b;"><?= htmlspecialchars(trim((string)($post['author_name'] ?? '') . ' ' . (string)($post['published_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </header>
        <div style="font-size:17px;line-height:1.8;color:#334155;">
            <?php if (!empty($post['content'])): ?>
                <?= (string)$post['content'] ?>
            <?php elseif (!empty($post['excerpt'])): ?>
                <p><?= htmlspecialchars((string)$post['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
                <p>This article is not available yet.</p>
            <?php endif; ?>
        </div>
        <?php if ($tags !== []): ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($tags as $tag): ?>
                    <span style="padding:6px 10px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:13px;"><?= htmlspecialchars((string)$tag, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($relatedPosts !== []): ?>
            <section style="padding-top:20px;border-top:1px solid #e5e7eb;">
                <h2 style="margin:0 0 12px;font-size:24px;">Related Posts</h2>
                <ul style="margin:0;padding-left:18px;display:grid;gap:8px;">
                    <?php foreach ($relatedPosts as $related): ?>
                        <?php if (!is_array($related)) { continue; } ?>
                        <li><?= htmlspecialchars((string)($related['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</article>
PHTML;
    }

    /**
     * @param array<string, mixed> $websiteProfile
     */
    private function loadOrCreateTheme(int $themeId, array $websiteProfile, int $sessionId): VirtualTheme
    {
        /** @var VirtualTheme $theme */
        $theme = clone ObjectManager::getInstance(VirtualTheme::class);
        $theme->clearData()->clearQuery();
        if ($themeId > 0) {
            $theme->load($themeId);
        }

        if ($theme->getId()) {
            return $theme;
        }

        $name = \trim((string)($websiteProfile['site_title'] ?? ''));
        if ($name === '') {
            $name = 'PageBuilder AI Draft';
        }

        $slug = $this->slugify($name);
        $theme->setName($name . ' Theme')
            ->setSessionId($sessionId)
            ->setPath('ai/pagebuilder-' . $slug . '-' . ($sessionId > 0 ? $sessionId : \substr(\md5((string)\microtime(true)), 0, 8)))
            ->setSource(VirtualTheme::SOURCE_PAGEBUILDER_AI)
            ->setIsActive(false)
            ->setConfig([
                'source' => VirtualTheme::SOURCE_PAGEBUILDER_AI,
                'website_profile' => $websiteProfile,
            ]);

        return $theme;
    }

    private function themeComponentExists(int $themeId, string $componentCode, string $area): bool
    {
        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery();
        $component->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->where(VirtualThemeComponent::schema_fields_AREA, $area)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return (int)$component->getId() > 0;
    }

    private function loadThemeComponentByCategory(int $themeId, string $category): ?VirtualThemeComponent
    {
        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery();
        $component->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
            ->where(VirtualThemeComponent::schema_fields_CATEGORY, $category)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $component->getId() ? $component : null;
    }

    private function saveThemeComponent(
        int $themeId,
        string $componentCode,
        string $area,
        string $category,
        string $name,
        string $templateContent,
        array $defaultConfig,
        array $meta
    ): void {
        $componentCode = $this->normalizeUtf8String($componentCode);
        $area = $this->normalizeUtf8String($area);
        $category = $this->normalizeUtf8String($category);
        $name = $this->normalizeUtf8String($name);
        $templateContent = $this->normalizeUtf8String($templateContent);
        $defaultConfig = $this->normalizeUtf8Array($defaultConfig);
        $meta = $this->normalizeUtf8Array($meta);

        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery();
        $component->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->where(VirtualThemeComponent::schema_fields_AREA, $area)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        if (!$component->getId()) {
            $component->setVirtualThemeId($themeId)
                ->setComponentCode($componentCode)
                ->setArea($area)
                ->setCategory($category)
                ->setName($name)
                ->setTemplateContent($templateContent)
                ->setDefaultConfig($defaultConfig)
                ->setMeta(\array_merge($meta, [
                    'source_type' => VirtualThemeComponent::SOURCE_TYPE_VIRTUAL,
                ]))
                ->setIsAiGenerated(true)
                ->setIsActive(true)
                ->save();

            $this->saveComponentVersion($component, $templateContent, $defaultConfig, $meta);
        } else {
            $component->setTemplateContent($templateContent)
                ->setDefaultConfig($defaultConfig)
                ->setMeta(\array_merge($meta, [
                    'source_type' => VirtualThemeComponent::SOURCE_TYPE_VIRTUAL,
                ]))
                ->setIsAiGenerated(true)
                ->save();

            $this->saveComponentVersion($component, $templateContent, $defaultConfig, $meta);
        }
    }

    /**
     * @param array<string,mixed> $layout
     * @return array<string,mixed>
     */
    private function sanitizeLayoutAssetUrls(array $layout): array
    {
        foreach (['header', 'footer'] as $region) {
            if (!\is_array($layout[$region] ?? null)) {
                continue;
            }
            $row = $layout[$region];
            if (\is_array($row['config'] ?? null)) {
                $row['config'] = $this->sanitizeConfigAssetUrls($row['config']);
            }
            $layout[$region] = $row;
        }

        if (\is_array($layout['content'] ?? null)) {
            $content = [];
            foreach ($layout['content'] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                if (\is_array($row['config'] ?? null)) {
                    $row['config'] = $this->sanitizeConfigAssetUrls($row['config']);
                }
                $content[] = $row;
            }
            $layout['content'] = $content;
        }

        return $layout;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function sanitizeConfigAssetUrls(array $config): array
    {
        foreach (['logo', 'logo.image', 'logo.url', 'brand.logo', 'brand.image', 'brand.logo_url'] as $field) {
            if (!\array_key_exists($field, $config)) {
                continue;
            }
            $config[$field] = \is_scalar($config[$field])
                ? $this->normalizePublishableMediaUrl((string)$config[$field])
                : '';
        }

        return $config;
    }

    private function normalizePublishableMediaUrl(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $lower = \strtolower($value);
        if (\str_starts_with($lower, 'data:') || \str_contains($lower, '<svg')) {
            return '';
        }
        if (\preg_match('/placeholder|example\.com|picsum\.photos|source\.unsplash\.com|images\.unsplash\.com|dummyimage\.com|placehold\.co|via\.placeholder/iu', $value) === 1) {
            return '';
        }

        $path = \parse_url($value, \PHP_URL_PATH);
        $path = \is_string($path) ? \trim($path) : $value;
        if ($path === '' || \str_ends_with($path, '/')) {
            return '';
        }
        if (\preg_match('/\.(?:png|jpe?g|webp|gif|avif|svg)(?:$|\?)/i', $path) !== 1) {
            return '';
        }

        return $value;
    }

    private function saveComponentVersion(
        VirtualThemeComponent $component,
        string $templateContent,
        array $defaultConfig,
        array $meta
    ): void {
        $lastVersionNo = 1;
        /** @var VirtualThemeComponentVersion $lastVersion */
        $lastVersion = clone ObjectManager::getInstance(VirtualThemeComponentVersion::class);
        $lastVersion->clearData()->clearQuery();
        $lastVersion->where(VirtualThemeComponentVersion::schema_fields_COMPONENT_ID, $component->getId())
            ->order(VirtualThemeComponentVersion::schema_fields_VERSION_NO, 'DESC')
            ->find()
            ->fetch();
        if ($lastVersion->getId()) {
            $lastVersionNo = $lastVersion->getVersionNo() + 1;
        }

        /** @var VirtualThemeComponentVersion $version */
        $version = clone ObjectManager::getInstance(VirtualThemeComponentVersion::class);
        $version->setComponentId($component->getId())
            ->setVersionNo($lastVersionNo)
            ->setStatus(VirtualThemeComponentVersion::STATUS_PUBLISHED)
            ->setTemplateContent($templateContent)
            ->setDefaultConfig($defaultConfig)
            ->setMeta($meta)
            ->save();

        $component->setPublishedVersionId($version->getId());
        $component->save();
    }

    private function normalizeUtf8String(string $value): string
    {
        if ($value === '' || \preg_match('//u', $value) === 1) {
            return $value;
        }

        $encoded = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (\is_string($encoded)) {
            $decoded = \json_decode($encoded, true);
            if (\is_string($decoded) && \preg_match('//u', $decoded) === 1) {
                return $decoded;
            }
        }

        $converted = \function_exists('iconv') ? @\iconv('UTF-8', 'UTF-8//IGNORE', $value) : false;
        if (\is_string($converted) && \preg_match('//u', $converted) === 1) {
            return $converted;
        }

        return '';
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function normalizeUtf8Array(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = \is_string($key) ? $this->normalizeUtf8String($key) : $key;
            if (\is_string($item)) {
                $result[$normalizedKey] = $this->normalizeUtf8String($item);
            } elseif (\is_array($item)) {
                $result[$normalizedKey] = $this->normalizeUtf8Array($item);
            } else {
                $result[$normalizedKey] = $item;
            }
        }

        return $result;
    }

    private function saveThemeLayout(int $themeId, string $pageType, array $layout): int
    {
        /** @var VirtualThemeLayout $themeLayout */
        $themeLayout = clone ObjectManager::getInstance(VirtualThemeLayout::class);
        $themeLayout->clearData()->clearQuery();
        $themeLayout->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $themeId)
            ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
            ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
            ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        $themeLayout->setVirtualThemeId($themeId)
            ->setPageType($pageType)
            ->setArea('frontend')
            ->setConfig($layout)
            ->setVersion((string)($layout['version'] ?? '1.0'))
            ->setUseOriginalTemplate((bool)($layout['use_original_template'] ?? false))
            ->setPageId((int)($layout['page_id'] ?? 0))
            ->save();

        return (int)$themeLayout->getId();
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function shouldInjectGeneratedContent(array $content): bool
    {
        if ($content === []) {
            return true;
        }

        $hasGeneratedComponent = false;
        foreach ($content as $component) {
            if (!\is_array($component)) {
                continue;
            }
            $code = \trim((string)($component['code'] ?? $component['component'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($this->isGeneratedVirtualThemeComponentCode($code)) {
                $hasGeneratedComponent = true;
                break;
            }
        }

        return !$hasGeneratedComponent;
    }

    private function isGeneratedVirtualThemeComponentCode(string $code): bool
    {
        $code = \trim($code);
        if ($code === '') {
            return false;
        }

        return \str_contains($code, '/')
            || \str_contains($code, 'ai-site')
            || \str_starts_with($code, 'content-');
    }

    private function buildHeaderTemplate(): string
    {
        return <<<'PHTML'
<header class="pb-ai-theme-header" style="padding:24px 32px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:16px;background:#ffffff;">
    <div style="display:flex;align-items:center;gap:12px;min-width:0;">
        <?php if (!empty($logo)): ?>
            <img src="<?= htmlspecialchars((string)$logo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($site_title ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="height:40px;width:auto;display:block;">
        <?php endif; ?>
        <div style="min-width:0;">
            <div style="font-size:20px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string)($site_title ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($site_tagline)): ?>
                <div style="font-size:13px;color:#64748b;"><?= htmlspecialchars((string)$site_tagline, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    </div>
    <nav style="font-size:14px;color:#334155;"><?= htmlspecialchars((string)($nav_hint ?? ''), ENT_QUOTES, 'UTF-8') ?></nav>
</header>
PHTML;
    }

    private function buildFooterTemplate(): string
    {
        return <<<'PHTML'
<footer class="pb-ai-theme-footer" style="padding:28px 32px;border-top:1px solid #e5e7eb;background:#f8fafc;color:#334155;">
    <div style="max-width:960px;margin:0 auto;display:grid;gap:8px;">
        <strong style="font-size:16px;color:#0f172a;"><?= htmlspecialchars((string)($site_title ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if (!empty($brief_description)): ?>
            <p style="margin:0;line-height:1.6;"><?= htmlspecialchars((string)$brief_description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($target_domain)): ?>
            <span style="font-size:12px;color:#64748b;"><?= htmlspecialchars((string)$target_domain, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
</footer>
PHTML;
    }

    private function resolveContentTemplate(string $template): string
    {
        return match ($template) {
            'cards' => $this->buildCardsTemplate(),
            'checklist' => $this->buildChecklistTemplate(),
            'cta' => $this->buildCtaTemplate(),
            default => $this->buildHeroTemplate(),
        };
    }

    private function buildHeroTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-hero" style="padding:64px 32px 48px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:18px;">
        <?php if (!empty($eyebrow)): ?>
            <span style="font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#2563eb;"><?= htmlspecialchars((string)$eyebrow, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <h1 style="margin:0;font-size:42px;line-height:1.08;color:#0f172a;max-width:900px;"><?= htmlspecialchars((string)($headline ?? $site_title ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if (!empty($description)): ?>
            <p style="margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($chips) && is_array($chips)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">
                <?php foreach ($chips as $chip): ?>
                    <?php if (!is_scalar($chip) || trim((string)$chip) === '') { continue; } ?>
                    <span style="display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;"><?= htmlspecialchars((string)$chip, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <?php if (!empty($primary_cta)): ?>
                <span style="display:inline-flex;align-items:center;padding:12px 20px;border-radius:999px;background:#0f172a;color:#ffffff;font-size:14px;font-weight:700;"><?= htmlspecialchars((string)$primary_cta, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if (!empty($secondary_note)): ?>
                <span style="font-size:13px;color:#64748b;"><?= htmlspecialchars((string)$secondary_note, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>
PHTML;
    }

    private function buildCardsTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-cards" style="padding:20px 32px 40px;background:#ffffff;">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:18px;">
        <?php if (!empty($section_title)): ?>
            <h2 style="margin:0;font-size:28px;line-height:1.2;color:#0f172a;"><?= htmlspecialchars((string)$section_title, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php if (!empty($section_intro)): ?>
            <p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$section_intro, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($items) && is_array($items)): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;">
                <?php foreach ($items as $item): ?>
                    <?php if (!is_array($item)) { continue; } ?>
                    <article style="display:grid;gap:10px;padding:22px;border-radius:22px;background:#f8fafc;border:1px solid #e5e7eb;">
                        <?php if (!empty($item['eyebrow'])): ?>
                            <span style="font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#2563eb;"><?= htmlspecialchars((string)$item['eyebrow'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <h3 style="margin:0;font-size:20px;line-height:1.2;color:#0f172a;"><?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if (!empty($item['description'])): ?>
                            <p style="margin:0;font-size:15px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
PHTML;
    }

    private function buildChecklistTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-checklist" style="padding:8px 32px 40px;background:#ffffff;">
    <div style="max-width:1080px;margin:0 auto;display:grid;gap:18px;">
        <?php if (!empty($section_title)): ?>
            <h2 style="margin:0;font-size:26px;line-height:1.25;color:#0f172a;"><?= htmlspecialchars((string)$section_title, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php if (!empty($section_intro)): ?>
            <p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;white-space:pre-line;"><?= htmlspecialchars((string)$section_intro, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($points) && is_array($points)): ?>
            <div style="display:grid;gap:12px;">
                <?php foreach ($points as $index => $point): ?>
                    <?php if (!is_scalar($point) || trim((string)$point) === '') { continue; } ?>
                    <div style="display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#ffffff;font-size:12px;font-weight:700;"><?= (int)$index + 1 ?></span>
                        <p style="margin:0;font-size:15px;line-height:1.7;color:#334155;white-space:pre-line;"><?= htmlspecialchars((string)$point, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
PHTML;
    }

    private function buildCtaTemplate(): string
    {
        return <<<'PHTML'
<section class="pb-ai-generated-section pb-ai-generated-section-cta" style="padding:16px 32px 64px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);">
    <div style="max-width:1080px;margin:0 auto;padding:28px;border-radius:28px;background:#0f172a;color:#ffffff;display:grid;gap:14px;">
        <?php if (!empty($section_title)): ?>
            <h2 style="margin:0;font-size:28px;line-height:1.2;color:#ffffff;"><?= htmlspecialchars((string)$section_title, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php if (!empty($section_text)): ?>
            <p style="margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,0.82);white-space:pre-line;"><?= htmlspecialchars((string)$section_text, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <?php if (!empty($button_label)): ?>
                <span style="display:inline-flex;align-items:center;padding:12px 20px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;"><?= htmlspecialchars((string)$button_label, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if (!empty($assist_text)): ?>
                <span style="font-size:13px;color:rgba(255,255,255,0.72);"><?= htmlspecialchars((string)$assist_text, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>
PHTML;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'component';
    }
}
