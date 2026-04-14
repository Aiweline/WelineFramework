<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSiteExecutionBlueprintService
{
    public const VERSION = 1;

    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $conversation
     */
    public function buildSourceSignature(array $scope, array $conversation = []): string
    {
        $userConversation = [];
        foreach ($conversation as $entry) {
            if (!\is_array($entry) || (string)($entry['role'] ?? '') !== 'user') {
                continue;
            }
            $content = \trim((string)($entry['content'] ?? ''));
            if ($content !== '') {
                $userConversation[] = $content;
            }
        }

        return \sha1((string)\json_encode([
            'site_title' => \trim((string)($scope['site_title'] ?? '')),
            'site_tagline' => \trim((string)($scope['site_tagline'] ?? '')),
            'target_domain' => \strtolower(\trim((string)($scope['target_domain'] ?? ''))),
            'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? '')),
            'default_locale' => \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'page_types' => \array_values(\array_map('strval', \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [])),
            'conversation' => $userConversation,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string
     * }
     */
    public function buildPlanArtifacts(array $scope, array $websiteProfile, array $payload = []): array
    {
        $pageTypes = $this->expandPageTypes($scope);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $planningScope = \array_replace($scope, [
            'page_types' => $pageTypes,
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
            'plan_instruction' => $instruction,
            'plan_target_scope' => $targetScope,
            'plan_locale' => $planLocale,
        ]);

        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $planningScope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $planningScope);
        $palette = $this->buildPalettePlan($planningScope, $websiteProfile, $instruction);
        $themeStyle = $this->buildThemeStyle($planningScope, $websiteProfile, $palette, $instruction);
        $navigationPlan = $this->buildNavigationPlan($pageTypes);
        $footerPlan = $this->buildFooterPlan($pageTypes);
        $seoStrategy = $this->buildSeoStrategy($siteDisplayName, $planningScope, $pageTypes, $instruction);

        $tasks = [
            $this->buildSharedTask('header', $siteDisplayName, $navigationPlan, $palette, $themeStyle, $seoStrategy),
            $this->buildSharedTask('footer', $siteDisplayName, $footerPlan, $palette, $themeStyle, $seoStrategy),
        ];

        $pages = [];
        $pageBlueprints = [];
        foreach ($pageTypes as $pageType) {
            $pageBlueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $planningScope, $websiteProfile);
            $pageBlueprints[$pageType] = $pageBlueprint;
            $pagePlan = $this->buildPagePlan(
                $pageType,
                $pageBlueprint,
                $pageTypes,
                $siteDisplayName,
                $palette,
                $themeStyle,
                $instruction,
                $targetScope,
                $planLocale
            );
            $pages[$pageType] = $pagePlan;
            foreach ($pagePlan['blocks'] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $tasks[] = $this->buildPageTask($pageType, $pagePlan, $block);
            }
        }

        $executionBlueprint = [
            'version' => self::VERSION,
            'workspace_track' => (string)($planningScope['workspace_track'] ?? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME),
            'page_types' => $pageTypes,
            'page_blueprints' => $pageBlueprints,
            'shared_components' => [
                'header' => $tasks[0],
                'footer' => $tasks[1],
            ],
            'pages' => $pages,
            'tasks' => $tasks,
        ];
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $structured = [
            'site_strategy' => [
                'site_display_name' => $siteDisplayName,
                'summary' => $siteSummary,
                'theme_style' => $themeStyle,
                'palette' => $palette,
                'instruction' => $instruction,
                'target_scope' => $targetScope,
                'plan_locale' => $planLocale,
            ],
            'seo_strategy' => $seoStrategy,
            'palette' => $palette,
            'theme_style' => $themeStyle,
            'navigation_plan' => $navigationPlan,
            'footer_plan' => $footerPlan,
            'page_types' => $pageTypes,
            'pages' => $pages,
            'execution_steps' => $this->buildExecutionSteps($tasks),
        ];
        $planJson = $this->buildPlanJson($structured);

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'derived_scope_patch' => $this->buildDerivedScopePatch($planningScope, $websiteProfile, $structured, $executionBlueprint),
            'markdown' => $this->buildMarkdownPlan($planJson, $planLocale),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   change_scope_report:array<string, mixed>
     * }
     */
    public function refineDraftPlan(array $scope, array $websiteProfile, array $payload): array
    {
        $artifacts = $this->buildPlanArtifacts($scope, $websiteProfile, $payload);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $report = [
            'mode' => 'refine',
            'round' => $round,
            'target_scope' => $targetScope,
            'instruction' => $instruction,
            'updated_at' => \date('Y-m-d H:i:s'),
            'changes' => [
                [
                    'target' => $targetScope !== '' ? $targetScope : 'plan',
                    'reason' => $instruction !== '' ? $instruction : '局部优化当前方案',
                ],
            ],
        ];
        $artifacts['structured']['change_scope_report'] = $report;
        $artifacts['plan_json']['change_scope_report'] = $report;
        $artifacts['execution_blueprint']['signature'] = $this->buildExecutionBlueprintSignature($artifacts['execution_blueprint']);
        $artifacts['change_scope_report'] = $report;

        return $artifacts;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   rebuild_summary:array<string, mixed>
     * }
     */
    public function rebuildDraftPlan(array $scope, array $websiteProfile, array $payload): array
    {
        $artifacts = $this->buildPlanArtifacts($scope, $websiteProfile, $payload);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $summary = [
            'mode' => 'rebuild',
            'round' => $round,
            'instruction' => $instruction,
            'task_count' => \count(\is_array($artifacts['execution_blueprint']['tasks'] ?? null) ? $artifacts['execution_blueprint']['tasks'] : []),
            'page_type_count' => \count(\is_array($artifacts['execution_blueprint']['page_types'] ?? null) ? $artifacts['execution_blueprint']['page_types'] : []),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $artifacts['structured']['rebuild_summary'] = $summary;
        $artifacts['plan_json']['rebuild_summary'] = $summary;
        $artifacts['execution_blueprint']['signature'] = $this->buildExecutionBlueprintSignature($artifacts['execution_blueprint']);
        $artifacts['rebuild_summary'] = $summary;

        return $artifacts;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return list<string>
     */
    private function expandPageTypes(array $scope): array
    {
        $rawPageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
        $pageTypes = [];
        foreach ($rawPageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && isset(Page::getPageTypes()[$pageType])) {
                $pageTypes[] = $pageType;
            }
        }
        if ($pageTypes === []) {
            $pageTypes = [Page::TYPE_HOME];
        }

        return \array_values(\array_unique($pageTypes));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildPalettePlan(array $scope, array $websiteProfile, string $instruction = ''): array
    {
        $brief = \mb_strtolower(\trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? '')));
        $instructionLower = \mb_strtolower($instruction);
        if ($this->containsAny($instructionLower, ['midnight ember', '深色', '暗色', '霓虹', '高对比'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '用户在本轮提示词中明确要求深色高对比路线，采用 Midnight Ember 以强化 CTA 与关键转化入口可见性。',
            ];
        }
        if ($this->containsAny($brief, ['棋牌', 'casino', 'rummy', 'ludo', 'poker', 'aviator', 'satta', 'game', 'gaming'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '需求偏流量转化与强视觉刺激，深底色搭配琥珀与红色更适合突出 CTA、信任徽章与下载入口。',
            ];
        }
        if ($this->containsAny($brief, ['saas', 'finance', 'fintech', 'payment', 'data', 'dashboard', '软件', '金融', '支付'])) {
            return [
                'name' => 'Signal Blue',
                'primary' => '#0f172a',
                'accent' => '#0ea5e9',
                'secondary' => '#14b8a6',
                'surface' => '#e0f2fe',
                'text' => '#0f172a',
                'reason' => '需求偏可信与专业表达，蓝青组合适合 SEO 落地页、数据说明和长期品牌识别。',
            ];
        }
        if ($this->containsAny($brief, ['fitness', 'health', 'wellness', 'sport', '健身', '健康', '运动'])) {
            return [
                'name' => 'Active Green',
                'primary' => '#052e16',
                'accent' => '#22c55e',
                'secondary' => '#84cc16',
                'surface' => '#f0fdf4',
                'text' => '#14532d',
                'reason' => '需求偏活力与正向激励，绿色系更适合强调成长、行动与持续转化。',
            ];
        }

        return [
            'name' => 'Ocean Slate',
            'primary' => '#0f172a',
            'accent' => '#2563eb',
            'secondary' => '#14b8a6',
            'surface' => '#f8fafc',
            'text' => '#0f172a',
            'reason' => '默认采用偏稳健的蓝灰色体系，兼顾信息层级、SEO 内容承载与多行业适配性。',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $palette
     * @return array<string, mixed>
     */
    private function buildThemeStyle(array $scope, array $websiteProfile, array $palette, string $instruction = ''): array
    {
        $brief = \trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $reason = '该风格先保证信息结构可读与可执行，再通过关键区块强化转化动作，适配两阶段方案驱动流程。';
        if ($instruction !== '') {
            $reason = '本轮按用户提示词进行方案决策：' . $this->clipText($instruction, 80);
        }
        return [
            'name' => 'Plan-Driven Hybrid',
            'visual_tone' => '先用内容结构建立 SEO 可信度，再用重点区域强化转化动作。',
            'header_style' => '信息密度适中，优先保证导航清晰、品牌识别稳定、移动端菜单易用。',
            'footer_style' => '围绕品牌说明、政策页、站内入口和补充信任信息组织。',
            'responsive_rule' => '首屏与 CTA 在移动端优先保留可见，导航收纳、卡片堆叠、政策与博客信息延后。',
            'palette_name' => (string)($palette['name'] ?? ''),
            'brief_basis' => $brief,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildNavigationPlan(array $pageTypes): array
    {
        $labels = Page::getPageTypes();
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $allItems[] = [
                'type' => $pageType,
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
        }

        $headerItems = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            foreach ($allItems as $item) {
                if ((string)($item['type'] ?? '') === $type) {
                    $headerItems[] = $item;
                    break;
                }
            }
        }
        foreach ($allItems as $item) {
            if (\count($headerItems) >= 5) {
                break;
            }
            $exists = false;
            foreach ($headerItems as $headerItem) {
                if ((string)($headerItem['type'] ?? '') === (string)($item['type'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $headerItems[] = $item;
            }
        }

        return [
            'header_items' => $headerItems,
            'all_items' => $allItems,
            'why' => 'Header 导航优先承接首页、品牌说明、内容入口和联系动作；其余页面通过页脚与站内链接承接，避免头部过载。',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildFooterPlan(array $pageTypes): array
    {
        $labels = Page::getPageTypes();
        $featured = [];
        $policies = [];
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $item = [
                'type' => $pageType,
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
            $allItems[] = $item;
            if (\in_array($pageType, [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST], true)) {
                $featured[] = $item;
            }
            if (\in_array($pageType, [Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_COOKIE_POLICY, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY], true)) {
                $policies[] = $item;
            }
        }

        return [
            'featured' => $featured !== [] ? $featured : \array_slice($allItems, 0, 4),
            'policies' => $policies,
            'all_items' => $allItems,
            'why' => 'Footer 负责补足政策、信任、补充入口和站内全量链接，兼顾 SEO 爬取与用户兜底导航。',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildSeoStrategy(string $siteDisplayName, array $scope, array $pageTypes, string $instruction = ''): array
    {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $baseKeywords = \array_values(\array_filter([
            $siteDisplayName,
            $brief !== '' ? $this->clipText($brief, 20) : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' 官网') : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' 下载') : '',
        ], static fn(string $value): bool => \trim($value) !== ''));

        return [
            'core_intent' => '先用首页与品牌页承接核心词，再用政策页和内容页补足长尾词与站内结构完整性。',
            'primary_keywords' => \array_values(\array_unique($baseKeywords)),
            'page_type_count' => \count($pageTypes),
            'meta_rule' => '首页突出品牌+核心价值，其余页面突出页面目标+品牌名；meta description 优先概括收益、信任和下一步动作。',
            'reason' => $instruction !== ''
                ? 'SEO 策略同步对齐本轮用户提示词：' . $this->clipText($instruction, 80)
                : 'SEO 策略与页面类型集合保持一致，仅围绕已选择页面构建关键词与内链。',
        ];
    }

    /**
     * @param array<string, mixed> $pageBlueprint
     * @param list<string> $pageTypes
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildPagePlan(
        string $pageType,
        array $pageBlueprint,
        array $pageTypes,
        string $siteDisplayName,
        array $palette,
        array $themeStyle,
        string $instruction = '',
        string $targetScope = '',
        string $locale = ''
    ): array {
        $pageLabel = (string)($pageBlueprint['page_label'] ?? (Page::getPageTypes()[$pageType] ?? $pageType));
        $pageTitle = (string)($pageBlueprint['page_title'] ?? $pageLabel);
        $pageGoal = $this->resolvePageGoal($pageType, $pageLabel, $locale);
        $blocks = [];
        foreach (\is_array($pageBlueprint['sections'] ?? null) ? $pageBlueprint['sections'] : [] as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $blocks[] = $this->buildBlockPlan($pageType, $pageLabel, $pageGoal, $section, $palette, $themeStyle, $siteDisplayName, $locale);
        }

        $appendInstruction = $this->resolveAppendBlockInstruction($instruction, $targetScope, $pageType, $blocks);
        if ($appendInstruction !== null) {
            $blocks[] = $this->buildAppendedBlockPlan($appendInstruction, $pageType, $pageLabel, $pageGoal, $palette, $themeStyle, $locale);
            \usort($blocks, static fn(array $left, array $right): int => ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0)));
        }

        $internalLinks = [];
        foreach ($pageTypes as $candidateType) {
            if ($candidateType === $pageType) {
                continue;
            }
            $internalLinks[] = [
                'type' => $candidateType,
                'label' => (string)(Page::getPageTypes()[$candidateType] ?? $candidateType),
                'href' => $candidateType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($candidateType),
            ];
            if (\count($internalLinks) >= 4) {
                break;
            }
        }

        return [
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_title' => $pageTitle,
            'page_goal' => $pageGoal,
            'why' => $this->resolvePageWhy($pageType, $pageLabel, $locale),
            'decision_reason' => $instruction !== ''
                ? '页面策略按本轮提示词对齐：' . $this->clipText($instruction, 80)
                : '页面策略基于已选页面类型与站点目标自动生成。',
            'slug' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            'nav_label' => $pageLabel,
            'meta_title' => (string)($pageBlueprint['meta_title'] ?? $pageTitle),
            'meta_description' => (string)($pageBlueprint['meta_description'] ?? ''),
            'primary_keywords' => $this->buildPageKeywords($pageTitle, $pageLabel, $siteDisplayName),
            'secondary_keywords' => $this->buildSecondaryKeywords($pageType, $pageLabel),
            'internal_links' => $internalLinks,
            'blocks' => $blocks,
        ];
    }

    private function shouldAppendPartnerBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, ['合作伙伴', '合作品牌', '合作方', 'partner', 'brand logo', 'logo wall'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }

        return $pageType === Page::TYPE_HOME && \str_contains($scope, 'page');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPartnerBlockPlan(string $pageType, string $pageLabel, array $palette): array
    {
        return [
            'block_key' => 'partner',
            'section_code' => 'partner',
            'region' => 'content',
            'component_kind' => 'partner',
            'order' => 990,
            'goal' => '展示合作伙伴与品牌背书，提升页面信任度和转化意愿。',
            'why' => $pageLabel . ' 页增加合作伙伴模块，用真实合作品牌与 Logo 增强可信度，降低用户决策门槛。',
            'style_brief' => [
                'visual_tone' => 'Logo 墙采用低干扰排布，突出品牌识别且不抢主 CTA。',
                'layout_rule' => '默认 4-6 列网格，移动端降为 2 列堆叠。',
                'responsive_rule' => '优先保证品牌 Logo 清晰可见，名称说明可折叠。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '合作伙伴区域以中性背景承载 Logo，避免色彩冲突并保持品牌识别。',
            ],
            'seo_brief' => [
                'intent' => '合作品牌背书与可信信息补充',
                'keywords' => ['合作伙伴', '品牌合作', '合作案例'],
                'anchors' => ['#partner'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => '通过合作伙伴背书增强可信与转化。',
                'why' => '用户在转化前常需信任证据，合作伙伴模块可直接提供品牌背书。',
                'headline_direction' => '展示已合作品牌与生态伙伴',
                'body_direction' => '补充合作类型、合作范围与价值说明',
                'cta_direction' => '提供“查看合作详情/联系合作”入口',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '合作伙伴', 'reason' => '模块标题需要明确背书主题。'],
                ['field' => 'partners', 'sample' => '', 'reason' => '合作伙伴列表是本模块核心数据。'],
                ['field' => 'description', 'sample' => '', 'reason' => '说明合作范围与合作价值。'],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildBlockPlan(
        string $pageType,
        string $pageLabel,
        string $pageGoal,
        array $section,
        array $palette,
        array $themeStyle,
        string $siteDisplayName,
        string $locale = ''
    ): array {
        $sectionKey = \trim((string)($section['key'] ?? 'block'));
        $sectionCode = \trim((string)($section['code'] ?? $sectionKey));
        $template = \trim((string)($section['template'] ?? 'content'));
        $sectionName = \trim((string)($section['name'] ?? $sectionCode));
        $config = \is_array($section['config'] ?? null) ? $section['config'] : [];

        return [
            'block_key' => $sectionKey,
            'section_code' => $sectionCode,
            'region' => 'content',
            'component_kind' => $template,
            'order' => (int)($section['sort_order'] ?? 0),
            'goal' => $this->resolveBlockGoal($template, $pageGoal, $locale),
            'why' => $this->isEnglishLocale($locale)
                ? ($sectionName . ' breaks the page goal of "' . $pageLabel . '" into actionable, scannable, and linkable content.')
                : ($sectionName . ' 用来把“' . $pageLabel . '”页面目标拆成可浏览、可转化、可内链的实际信息块。'),
            'style_brief' => [
                'visual_tone' => (string)($themeStyle['visual_tone'] ?? ''),
                'layout_rule' => $this->resolveLayoutRule($template, $locale),
                'responsive_rule' => $this->resolveResponsiveRule($template, $locale),
            ],
            'palette_usage' => [
                'background' => $template === 'cta' ? (string)($palette['primary'] ?? '') : (string)($palette['surface'] ?? ''),
                'accent' => (string)($palette['accent'] ?? ''),
                'text' => $template === 'cta' ? (string)($palette['text'] ?? '#ffffff') : '#0f172a',
                'reason' => $template === 'hero'
                    ? '首屏需要最高对比度来承接核心关键词与 CTA。'
                    : '内容区块使用更轻的底色，便于承载 SEO 文案和可读信息层级。',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
                'anchors' => ['#' . $this->slugify($sectionKey !== '' ? $sectionKey : $sectionCode)],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => $pageGoal,
                'why' => $sectionName . ' 要同时服务信息理解和下一步行动。',
                'headline_direction' => $this->resolveHeadlineDirection($config, $sectionName, $pageLabel, $locale),
                'body_direction' => $this->resolveBodyDirection($config, $pageGoal, $locale),
                'cta_direction' => $this->resolveCtaDirection($template, $pageLabel, $locale),
            ],
            'field_plan' => $this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale),
            'result_ref' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSharedTask(
        string $component,
        string $siteDisplayName,
        array $payload,
        array $palette,
        array $themeStyle,
        array $seoStrategy
    ): array {
        return [
            'task_key' => 'shared:' . $component,
            'task_type' => 'shared_component',
            'component' => $component,
            'goal' => $component === 'header' ? '构建全站统一导航与品牌入口。' : '构建全站统一页脚、政策入口和补充导航。',
            'site_display_name' => $siteDisplayName,
            'style_brief' => [
                'palette' => $palette,
                'theme_style' => $themeStyle,
            ],
            'seo_brief' => $seoStrategy,
            'payload' => $payload,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildPageTask(string $pageType, array $pagePlan, array $block): array
    {
        $blockKey = \trim((string)($block['block_key'] ?? 'block'));

        return [
            'task_key' => 'page:' . $pageType . ':' . $blockKey,
            'task_type' => 'page_block',
            'page_type' => $pageType,
            'page_label' => (string)($pagePlan['page_label'] ?? $pageType),
            'slug' => (string)($pagePlan['slug'] ?? '/'),
            'block' => $block,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $executionBlueprint
     */
    private function buildExecutionBlueprintSignature(array $executionBlueprint): string
    {
        return \sha1((string)\json_encode($executionBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function buildExecutionSteps(array $tasks): array
    {
        $steps = [];
        foreach ($tasks as $index => $task) {
            $steps[] = [
                'step' => $index + 1,
                'task_key' => (string)($task['task_key'] ?? 'task:' . ($index + 1)),
                'task_type' => (string)($task['task_type'] ?? 'unknown'),
                'status' => 'pending',
            ];
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, mixed>
     */
    private function buildDerivedScopePatch(
        array $scope,
        array $websiteProfile,
        array $structured,
        array $executionBlueprint
    ): array {
        return [
            'site_title' => (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? $websiteProfile['site_tagline'] ?? ''),
            'page_types' => \array_values(\array_map('strval', \is_array($executionBlueprint['page_types'] ?? null) ? $executionBlueprint['page_types'] : [])),
            'theme_style' => $structured['theme_style'] ?? [],
            'palette' => $structured['palette'] ?? [],
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function buildPlanJson(array $structured): array
    {
        $pages = \is_array($structured['pages'] ?? null) ? $structured['pages'] : [];
        $pageBlocks = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $blockRows = [];
            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $contentParts = \array_values(\array_filter([
                    \trim((string)($block['content_brief']['headline_direction'] ?? '')),
                    \trim((string)($block['content_brief']['body_direction'] ?? '')),
                    \trim((string)($block['content_brief']['cta_direction'] ?? '')),
                ], static fn(string $value): bool => $value !== ''));
                $blockRows[] = [
                    'block_key' => (string)($block['block_key'] ?? $block['section_code'] ?? 'block'),
                    'content' => $contentParts !== [] ? \implode(' | ', $contentParts) : \trim((string)($block['goal'] ?? '')),
                    'why' => \trim((string)($block['why'] ?? '')),
                ];
            }
            $pageBlocks[(string)$pageType] = [
                'page_goal' => \trim((string)($pagePlan['page_goal'] ?? '')),
                'why' => \trim((string)($pagePlan['why'] ?? '')),
                'blocks' => $blockRows,
            ];
        }

        return [
            'site_strategy' => \is_array($structured['site_strategy'] ?? null) ? $structured['site_strategy'] : [],
            'theme_style' => \is_array($structured['theme_style'] ?? null) ? $structured['theme_style'] : [],
            'palette' => \is_array($structured['palette'] ?? null) ? $structured['palette'] : [],
            'navigation_plan' => \is_array($structured['navigation_plan'] ?? null) ? $structured['navigation_plan'] : [],
            'footer_plan' => \is_array($structured['footer_plan'] ?? null) ? $structured['footer_plan'] : [],
            'seo_strategy' => \is_array($structured['seo_strategy'] ?? null) ? $structured['seo_strategy'] : [],
            'page_types' => \is_array($structured['page_types'] ?? null) ? $structured['page_types'] : [],
            'pages' => $pageBlocks,
            'execution_steps' => \is_array($structured['execution_steps'] ?? null) ? $structured['execution_steps'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function buildMarkdownPlan(array $planJson, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $site = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $summary = \trim((string)($planJson['site_strategy']['summary'] ?? ''));
        $pageTypes = \is_array($planJson['page_types'] ?? null) ? $planJson['page_types'] : [];
        $themeName = \trim((string)($planJson['theme_style']['name'] ?? ''));
        $paletteName = \trim((string)($planJson['palette']['name'] ?? ''));
        $navigationPlan = \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [];
        $footerPlan = \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [];
        $seoStrategy = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];

        $lines = [];
        $lines[] = $isEn ? '# Stage 1 Execution Plan (Full Blueprint)' : '# 阶段一执行蓝图（完整规划）';
        $lines[] = '';
        $lines[] = ($isEn ? '- Site: ' : '- 站点：') . ($site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点'));
        $lines[] = ($isEn ? '- Summary: ' : '- 摘要：') . ($summary !== '' ? $summary : ($isEn ? 'Pending details' : '待补充'));
        $lines[] = ($isEn ? '- Theme Style: ' : '- 主题风格：') . ($themeName !== '' ? $themeName : 'Plan-Driven Hybrid');
        $lines[] = ($isEn ? '- Theme Decision Reason: ' : '- 风格决策理由：') . '*' . \trim((string)($planJson['theme_style']['reason'] ?? '')) . '*';
        $lines[] = ($isEn ? '- Palette: ' : '- 色盘：') . ($paletteName !== '' ? $paletteName : 'Ocean Slate');
        $lines[] = ($isEn ? '- Palette Decision Reason: ' : '- 色盘决策理由：') . '*' . \trim((string)($planJson['palette']['reason'] ?? '')) . '*';
        $lines[] = ($isEn ? '- Page Count: ' : '- 页面数量：') . (string)\count($pageTypes);
        $lines[] = '';
        $lines[] = $isEn ? '## Site Structure' : '## 全站结构';
        foreach ($pageTypes as $pageType) {
            $lines[] = '- ' . (string)$pageType;
        }
        $lines[] = '';
        $lines[] = $isEn ? '## Shared Global Plan' : '## 全站共享规划';
        $lines[] = ($isEn ? '- Header Navigation: ' : '- Header 导航：') . $this->buildLinkSummary(\is_array($navigationPlan['header_items'] ?? null) ? $navigationPlan['header_items'] : []);
        $lines[] = ($isEn ? '- Footer Sections: ' : '- Footer 栏目：') . $this->buildLinkSummary(\is_array($footerPlan['featured'] ?? null) ? $footerPlan['featured'] : []);
        $lines[] = ($isEn ? '- Footer Policies: ' : '- Footer 政策：') . $this->buildLinkSummary(\is_array($footerPlan['policies'] ?? null) ? $footerPlan['policies'] : []);
        $lines[] = ($isEn ? '- SEO Core Strategy: ' : '- SEO 主策略：') . \trim((string)($seoStrategy['core_intent'] ?? ($isEn ? 'not set' : '未设置')));
        $lines[] = '';
        $lines[] = $isEn ? '## Page And Block Execution Details' : '## 页面与区块执行细化';

        foreach ($pageTypes as $pageType) {
            $pageType = (string)$pageType;
            $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            if ($pagePlan === []) {
                $lines[] = '### ' . $pageType;
                $lines[] = $isEn ? '- Missing page plan: please provide page blueprint.' : '- 页面规划缺失：请补充 page blueprint。';
                $lines[] = '';
                continue;
            }

            $lines[] = '### ' . $pageType;
            $lines[] = ($isEn ? '- Page Goal: ' : '- 页面目标：') . \trim((string)($pagePlan['page_goal'] ?? ''));
            $lines[] = ($isEn ? '- Page Reason: ' : '- 页面原因：') . '*' . \trim((string)($pagePlan['why'] ?? '')) . '*';
            $lines[] = ($isEn ? '- Block Count: ' : '- 区块数量：') . (string)\count(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []);
            $lines[] = '';

            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $index => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockOrder = $index + 1;
                $lines[] = ($isEn ? '#### Block ' : '#### 区块 ') . $blockOrder . '：' . (string)($block['block_key'] ?? 'block');
                $lines[] = ($isEn ? '- Block Content: ' : '- 区块内容：') . \trim((string)($block['content'] ?? ''));
                $lines[] = ($isEn ? '- Block Reason: ' : '- 区块原因：') . '*' . \trim((string)($block['why'] ?? '')) . '*';
                $lines[] = '';
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function buildLinkSummary(array $items): string
    {
        if ($items === []) {
            return '无';
        }
        $parts = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = \trim((string)($item['label'] ?? $item['type'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            $parts[] = $href !== '' ? ($label . '(' . $href . ')') : $label;
            if (\count($parts) >= 8) {
                break;
            }
        }

        return $parts !== [] ? \implode('、', $parts) : '无';
    }

    private function resolvePageGoal(string $pageType, string $pageLabel, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Capture core intent, explain value, and surface primary conversion actions.',
                Page::TYPE_ABOUT => 'Build trust by explaining brand background and delivery capability.',
                Page::TYPE_CONTACT => 'Reduce friction and collect qualified leads quickly.',
                default => $pageLabel . ' should clearly serve page intent and lead users to next actions.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '承接主关键词、首屏价值和关键转化入口。',
            Page::TYPE_ABOUT => '解释品牌背景与能力边界，建立信任。',
            Page::TYPE_CONTACT => '降低咨询门槛，收集有效线索。',
            default => $pageLabel . ' 页面围绕页面意图输出清晰信息并承接下一步动作。',
        };
    }

    private function resolvePageWhy(string $pageType, string $pageLabel, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Home is the core traffic entry and must unify value narrative and navigation.',
                Page::TYPE_ABOUT => 'About builds trust and improves conversion decisions.',
                Page::TYPE_CONTACT => 'Contact captures inquiries and shortens the conversion path.',
                default => $pageLabel . ' completes site structure and supports long-tail search coverage.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '首页作为全站流量主入口，需要统一价值陈述与导航分发。',
            Page::TYPE_ABOUT => '品牌说明页用于增强可信度并提升转化决策效率。',
            Page::TYPE_CONTACT => '联系页用于承接咨询和售前沟通，缩短转化路径。',
            default => $pageLabel . ' 用于补足站点结构完整性与长尾关键词覆盖。',
        };
    }

    /**
     * @return list<string>
     */
    private function buildPageKeywords(string $pageTitle, string $pageLabel, string $siteDisplayName): array
    {
        return \array_values(\array_unique(\array_filter([
            \trim($pageTitle),
            \trim($pageLabel),
            $siteDisplayName !== '' ? ($siteDisplayName . ' ' . $pageLabel) : '',
        ], static fn(string $keyword): bool => $keyword !== '')));
    }

    /**
     * @return list<string>
     */
    private function buildSecondaryKeywords(string $pageType, string $pageLabel): array
    {
        $keywords = [$pageLabel . ' 指南', $pageLabel . ' 常见问题'];
        if ($pageType === Page::TYPE_HOME) {
            $keywords[] = '品牌介绍';
            $keywords[] = '核心优势';
        }

        return \array_values(\array_unique($keywords));
    }

    private function resolveBlockGoal(string $template, string $pageGoal, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => 'Explain value quickly above the fold and drive a primary action.',
                'cta' => 'Focus on one action to reduce decision friction.',
                'features' => 'Present capabilities in a structured and scannable format.',
                default => $pageGoal,
            };
        }
        return match ($template) {
            'hero' => '在首屏快速解释价值并引导关键动作。',
            'cta' => '聚焦单一动作，降低用户决策成本。',
            'features' => '结构化说明能力点，增强对比与理解。',
            default => $pageGoal,
        };
    }

    private function resolveLayoutRule(string $template, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => 'Prefer two-column or centered above-the-fold layout with headline and CTA visible together.',
                'features' => 'Use card grid layout for fast scanning.',
                default => 'Mobile-first single column, then expand by content density on desktop.',
            };
        }
        return match ($template) {
            'hero' => '首屏优先双栏或居中布局，标题与 CTA 同屏出现。',
            'features' => '卡片栅格布局，保证扫描效率。',
            default => '移动端单列优先，桌面端按内容密度扩展。',
        };
    }

    private function resolveResponsiveRule(string $template, string $locale = ''): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero', 'cta' => 'On mobile, keep headline and primary CTA visible first; collapse secondary details.',
                default => 'Stack to single column on small screens to preserve reading order.',
            };
        }
        return match ($template) {
            'hero', 'cta' => '移动端优先保留标题与主按钮，次级说明折叠到后续区域。',
            default => '断点下按单列堆叠，保证阅读顺序与触达效率。',
        };
    }

    /**
     * @return list<string>
     */
    private function buildBlockKeywords(string $siteDisplayName, string $pageLabel, string $template, string $sectionName): array
    {
        $keywords = [
            $pageLabel . ' ' . $sectionName,
            $template . ' ' . $sectionName,
        ];
        if ($siteDisplayName !== '') {
            $keywords[] = $siteDisplayName . ' ' . $sectionName;
        }

        return \array_values(\array_unique(\array_filter($keywords, static fn(string $keyword): bool => \trim($keyword) !== '')));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveHeadlineDirection(array $config, string $sectionName, string $pageLabel, string $locale = ''): string
    {
        $headline = \trim((string)($config['title'] ?? ''));
        if ($headline !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Center headline around "' . $headline . '" to reinforce value and benefit.')
                : ('围绕“' . $headline . '”强化页面主题与用户收益。');
        }
        return $this->isEnglishLocale($locale)
            ? ('Use headline to express "' . $sectionName . '" value under "' . $pageLabel . '".')
            : ('标题围绕“' . $pageLabel . '”中的“' . $sectionName . '”说明核心价值。');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveBodyDirection(array $config, string $pageGoal, string $locale = ''): string
    {
        $description = \trim((string)($config['description'] ?? ''));
        if ($description !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Use concise readable paragraphs and explain: ' . $this->clipText($description, 40))
                : ('正文保持可读短段落，重点解释：' . $this->clipText($description, 40));
        }
        return $this->isEnglishLocale($locale)
            ? ('Structure body content by page goal: ' . $pageGoal)
            : ('正文围绕页面目标组织信息层级：' . $pageGoal);
    }

    private function resolveCtaDirection(string $template, string $pageLabel, string $locale = ''): string
    {
        if ($template === 'cta' || $template === 'hero') {
            return $this->isEnglishLocale($locale)
                ? 'Keep CTA to one primary action: Contact / Start Now / Learn More.'
                : 'CTA 保持单一动作，优先“立即咨询/立即开始/了解更多”之一。';
        }
        return $this->isEnglishLocale($locale)
            ? ($pageLabel . ' should use secondary CTA to support, not compete with, primary CTA.')
            : ($pageLabel . ' 页以次级 CTA 承接，避免与主 CTA 竞争。');
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function buildFieldPlan(array $config, string $sectionName, string $pageGoal, string $template, string $locale = ''): array
    {
        $fields = [];
        foreach (['title', 'subtitle', 'description', 'button_text', 'button_link', 'image'] as $field) {
            if (!\array_key_exists($field, $config)) {
                continue;
            }
            $fields[] = [
                'field' => $field,
                'sample' => (string)($config[$field] ?? ''),
                'reason' => $this->isEnglishLocale($locale)
                    ? ($sectionName . ' needs this field to support goal "' . $pageGoal . '".')
                    : ($sectionName . ' 需要该字段支撑“' . $pageGoal . '”目标。'),
            ];
        }

        if ($fields === []) {
            $fields[] = [
                'field' => 'description',
                'sample' => '',
                'reason' => $this->isEnglishLocale($locale)
                    ? 'Keep description field by default for readability and SEO text coverage.'
                    : '默认至少保留描述字段，保证内容可读与 SEO 文本承载。',
            ];
            if ($template === 'hero' || $template === 'cta') {
                $fields[] = [
                    'field' => 'button_text',
                    'sample' => '',
                    'reason' => $this->isEnglishLocale($locale)
                        ? 'Hero/CTA blocks should expose an actionable entry by default.'
                        : '首屏/CTA 模块默认需要可执行入口。',
                ];
            }
        }

        return $fields;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        if (\trim($haystack) === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && \mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function clipText(string $text, int $maxLength): string
    {
        $text = \trim($text);
        if ($text === '' || $maxLength <= 0) {
            return '';
        }
        if (\mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return \mb_substr($text, 0, $maxLength) . '…';
    }

    private function slugify(string $text): string
    {
        $text = \mb_strtolower(\trim($text));
        if ($text === '') {
            return 'section';
        }
        $text = (string)\preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
        $text = \trim($text, '-');

        return $text !== '' ? $text : 'section';
    }

    private function isEnglishLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '') {
            return false;
        }

        return \str_starts_with($locale, 'en');
    }
}
