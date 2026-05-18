<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSitePageBlueprintService
{
    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array{
     *   page_type:string,
     *   page_label:string,
     *   page_title:string,
     *   ai_description:string,
     *   meta_title:string,
     *   meta_description:string,
     *   meta_keywords:string,
     *   site_display_name:string,
     *   section_refinements:array<string, string>,
     *   sections:list<array{
     *     key:string,
     *     code:string,
     *     name:string,
     *     template:string,
     *     config:array<string, mixed>,
     *     sort_order:int
     *   }>
     * }
     */
    public function buildPageBlueprint(string $pageType, array $scope, array $websiteProfile): array
    {
        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $contentLocale = $this->resolveContentLocale($scope, $websiteProfile);
        $pageLabel = $this->localizePageTypeLabel($pageType, $contentLocale);
        if ($pageLabel === '') {
            $pageLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
        }
        $siteDisplayName = $this->resolveSiteDisplayName($websiteProfile, $scope);
        $pageTitle = \trim((string)($virtualPage['title'] ?? ''));
        if ($pageTitle === '') {
            $pageTitle = $pageType === Page::TYPE_HOME ? $siteDisplayName : $pageLabel;
        }

        $brief = $this->extractReusableBrief($virtualPage, $websiteProfile, $scope);
        $pageInstruction = (string)(Page::getPageTypePromptInstructionsMap()[$pageType] ?? '');
        $siteTagline = $this->pickString($websiteProfile['site_tagline'] ?? null, $scope['site_tagline'] ?? null);
        $sectionRefinements = $this->normalizeStringMap($virtualPage['section_refinements'] ?? []);
        $siteSummary = $this->buildSiteMarketingSummary($websiteProfile, $scope);
        $primaryCtaLabel = $this->resolveScopePrimaryCtaLabel($scope, $pageType, $pageLabel);
        $aiDescription = $this->buildCustomerFacingDescription($pageType, $pageLabel, $siteDisplayName, $siteSummary, $siteTagline);
        $promptPoints = $this->buildCustomerPromptPoints($pageType, $siteDisplayName, $siteSummary, $brief, 8);
        $baseCode = 'content/' . $this->slugify($pageType);

        $heroRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-hero', 'hero');
        $middleRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-highlights', 'highlights');
        $detailRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-details', 'details');
        $ctaRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-cta', 'cta');

        $sections = match ($pageType) {
            Page::TYPE_HOME => $this->buildHomeSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement, $brief, $primaryCtaLabel),
            Page::TYPE_ABOUT => $this->buildAboutSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
            Page::TYPE_CONTACT => $this->buildContactSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY => $this->buildPolicySections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
            Page::TYPE_BLOG,
            Page::TYPE_BLOG_CATEGORY,
            Page::TYPE_BLOG_LIST => $this->buildBlogSections($pageType),
            default => $this->buildCustomSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
        };

        $metaTitle = $pageType === Page::TYPE_HOME
            ? $siteDisplayName
            : ($pageTitle . ' | ' . $siteDisplayName);
        $metaDescription = $this->clipText($aiDescription, 160);
        $metaKeywords = $this->buildMetaKeywords($siteDisplayName, $pageLabel, $siteSummary);

        return [
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_title' => $pageTitle,
            'ai_description' => $aiDescription,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'meta_keywords' => $metaKeywords,
            'site_display_name' => $siteDisplayName,
            'section_refinements' => $sectionRefinements,
            'sections' => $sections,
        ];
    }

    /**
     * 客户在工作台填写的站点名称（唯一权威来源，不读描述/提示词/BuildPlan）。
     *
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    public function resolveUserSiteTitle(array $websiteProfile, array $scope = []): string
    {
        return $this->pickSiteDisplayName(
            $scope['site_title'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            $scope['store_name'] ?? null,
        );
    }

    /**
     * 访客可见站点名：仅用客户填写的 site_title，或从域名推导；禁止用 user_description / brief / BuildPlan 充当标题。
     *
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    public function resolveSiteDisplayName(array $websiteProfile, array $scope = []): string
    {
        $userTitle = $this->resolveUserSiteTitle($websiteProfile, $scope);
        if ($userTitle !== '') {
            return $userTitle;
        }

        $domain = $this->pickString($websiteProfile['target_domain'] ?? null, $scope['target_domain'] ?? null);

        return $this->deriveTitleFromDomain($domain);
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    public function buildSiteMarketingSummary(array $websiteProfile, array $scope = []): string
    {
        $siteDisplayName = $this->resolveSiteDisplayName($websiteProfile, $scope);
        $buildPlanSummary = $this->resolveBuildPlanMarketingSummary($scope);
        if ($buildPlanSummary !== '') {
            return $siteDisplayName !== '' && !\str_contains($buildPlanSummary, $siteDisplayName)
                ? $siteDisplayName . "\n" . $buildPlanSummary
                : $buildPlanSummary;
        }
        $brief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        $siteTagline = $this->pickString($websiteProfile['site_tagline'] ?? null, $scope['site_tagline'] ?? null);
        $signals = $this->analyzeBrief($brief);

        $offer = $signals['offer'] !== '' ? $signals['offer'] : '品牌展示与业务转化站点';
        $goal = $signals['goal'] !== '' ? $signals['goal'] : '价值传达与下一步行动';
        $trust = $signals['trust'] !== '' ? $signals['trust'] : '清晰的信息架构、可信说明与明确行动入口';
        $headline = $siteDisplayName !== '' ? $siteDisplayName : '该网站';
        $market = $signals['market'] !== '' ? $signals['market'] . '，' : '';

        $summary = $headline . ' 面向' . ($market !== '' ? \rtrim($market, '，') : '目标用户') . '提供' . $offer . '，聚焦' . $goal . '。';
        $supporting = '页面突出' . $trust . '。';
        if ($siteTagline !== '') {
            $supporting = $this->normalizeCustomerTagline($headline, $siteTagline) . ' 页面突出' . $trust . '。';
        }

        return $summary . "\n" . $supporting;
    }

    /**
     * @return list<array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }>
     */
    private function buildHomeSections(
        string $baseCode,
        string $pageLabel,
        string $pageTitle,
        string $siteDisplayName,
        string $aiDescription,
        array $promptPoints,
        string $heroRefinement,
        string $middleRefinement,
        string $detailRefinement,
        string $ctaRefinement,
        string $brief = '',
        string $primaryCtaLabel = ''
    ): array {
        $combined = $brief . "\n" . $aiDescription;
        $isDownload = \preg_match('/(?:APK|download|app|安装|下载|推广)/iu', $combined) === 1;
        $isGame = \preg_match('/(?:游戏|game|棋牌|card|Teen\\s*Patti|rummy)/iu', $combined) === 1;
        $isSeo = \preg_match('/(?:SEO|seo|关键词|keyword)/iu', $combined) === 1;
        $isTrust = \preg_match('/(?:信任|trust|安全|secure|放心)/iu', $combined) === 1;

        $sections = [
            $this->buildHeroSection(
                $baseCode . '-hero',
                $pageLabel,
                $pageTitle,
                $siteDisplayName,
                $this->applyRefinement($aiDescription, $heroRefinement),
                $promptPoints,
                10,
                $isDownload ? 'hero_download' : 'hero',
                $primaryCtaLabel
            ),
            $this->buildCardsSection(
                $baseCode . '-highlights',
                $isGame || $isDownload ? '热门内容与下载亮点' : '核心卖点',
                $isGame || $isDownload ? '热门玩法、下载收益和上手路径清晰可见。' : '核心价值、适用场景和行动入口清晰可见。',
                $isGame || $isDownload ? ['热门游戏', '下载亮点', '上手路径'] : ['核心卖点', '投放场景', '下载转化'],
                $promptPoints,
                $middleRefinement,
                20,
                $isGame || $isDownload ? 'game_showcase_or_features' : 'highlights'
            ),
        ];

        if ($isTrust) {
            $sections[] = $this->buildChecklistSection(
                $baseCode . '-trust-security',
                '安全与信任保障',
                '下载安全、规则透明和服务支持一屏可读。',
                $promptPoints,
                $detailRefinement,
                30,
                'trust_security'
            );
        }

        $sections[] = $this->buildChecklistSection(
            $baseCode . ($isSeo ? '-seo-faq' : '-details'),
            $isSeo ? '下载前常见问题' : '转化路径',
            $isSeo ? '下载疑问、玩法入口和安全说明直接可读。' : '浏览重点、下载入口和支持信息清晰可见。',
            $promptPoints,
            $detailRefinement,
            $isTrust ? 40 : 30,
            $isSeo ? 'seo_faq' : 'details'
        );

        $sections[] = $this->buildCtaSection(
            $baseCode . '-cta',
            $isDownload ? '立即开始下载' : '开始承接流量',
            $isDownload ? '安全下载、奖励亮点和客服支持汇聚成明确入口。' : '核心价值和行动入口汇聚在一个清晰模块中。',
            $primaryCtaLabel !== '' ? $primaryCtaLabel : $this->resolveCtaLabel(Page::TYPE_HOME),
            $ctaRefinement,
            $isTrust ? 50 : 40,
            $isDownload ? 'final_download_cta' : 'final_cta'
        );

        if (!$isDownload) {
            $sections = $this->normalizeNonDownloadHomeSections($sections, $primaryCtaLabel);
        }

        return $sections;
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    private function normalizeNonDownloadHomeSections(array $sections, string $primaryCtaLabel): array
    {
        foreach ($sections as $index => $section) {
            if (!\is_array($section)) {
                continue;
            }
            $key = \strtolower((string)($section['key'] ?? ''));
            $template = \strtolower((string)($section['template'] ?? ''));
            $config = \is_array($section['config'] ?? null) ? $section['config'] : [];
            if ($template === 'cards' || $key === 'highlights') {
                $section['name'] = $this->unicodeText('\u6838\u5fc3\u4ef7\u503c');
                $config['section_title'] = $this->unicodeText('\u6838\u5fc3\u4ef7\u503c');
                $config['section_intro'] = $this->unicodeText('\u628a\u4ea7\u54c1\u3001\u4f53\u9a8c\u3001\u54c1\u724c\u4fe1\u4efb\u4e0e\u4e3b\u8981\u884c\u52a8\u5165\u53e3\u6e05\u6670\u5c55\u793a\u3002');
            }
            if ($template === 'checklist' && ($key === 'details' || \str_contains($key, 'detail'))) {
                $section['name'] = $this->unicodeText('\u8f6c\u5316\u8def\u5f84');
                $config['section_title'] = $this->unicodeText('\u8f6c\u5316\u8def\u5f84');
                $config['section_intro'] = $this->unicodeText('\u6d4f\u89c8\u91cd\u70b9\u3001\u9884\u7ea6\u3001\u8ba2\u8d2d\u6216\u8054\u7cfb\u5165\u53e3\u5fc5\u987b\u6e05\u6670\u53ef\u89c1\u3002');
            }
            if ($template === 'cta' || \str_contains($key, 'cta')) {
                $section['name'] = $this->unicodeText('\u7ee7\u7eed\u6df1\u5165\u4f53\u9a8c');
                $config['section_title'] = $this->unicodeText('\u7ee7\u7eed\u6df1\u5165\u4f53\u9a8c');
                $config['section_text'] = $this->unicodeText('\u628a\u4e3b\u8981\u884c\u52a8\u3001\u54c1\u724c\u4fe1\u4efb\u548c\u8054\u7cfb\u5165\u53e3\u96c6\u4e2d\u5448\u73b0\u3002');
                if ($primaryCtaLabel !== '') {
                    $config['button_label'] = $primaryCtaLabel;
                }
            }
            $section['config'] = $config;
            $sections[$index] = $section;
        }

        return $sections;
    }

    /**
     * @param list<string> $promptPoints
     * @return list<array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }>
     */
    private function buildAboutSections(
        string $baseCode,
        string $pageLabel,
        string $pageTitle,
        string $siteDisplayName,
        string $aiDescription,
        array $promptPoints,
        string $heroRefinement,
        string $middleRefinement,
        string $detailRefinement,
        string $ctaRefinement
    ): array {
        return [
            $this->buildHeroSection($baseCode . '-hero', $pageLabel, $pageTitle, $siteDisplayName, $this->applyRefinement($aiDescription, $heroRefinement), $promptPoints, 10),
            $this->buildCardsSection($baseCode . '-story', '品牌与团队', '品牌起点、团队能力和差异化亮点清晰呈现。', ['品牌起点', '团队能力', '市场理解'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-values', '值得信任的理由', '经验、规范和长期投入形成稳定信任。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '继续了解合作方式', '信任信息与咨询入口自然衔接。', $this->resolveCtaLabel(Page::TYPE_ABOUT), $ctaRefinement, 40),
        ];
    }

    /**
     * @param list<string> $promptPoints
     * @return list<array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }>
     */
    private function buildContactSections(
        string $baseCode,
        string $pageLabel,
        string $pageTitle,
        string $siteDisplayName,
        string $aiDescription,
        array $promptPoints,
        string $heroRefinement,
        string $middleRefinement,
        string $detailRefinement,
        string $ctaRefinement
    ): array {
        return [
            $this->buildHeroSection($baseCode . '-hero', $pageLabel, $pageTitle, $siteDisplayName, $this->applyRefinement($aiDescription, $heroRefinement), $promptPoints, 10),
            $this->buildCardsSection($baseCode . '-channels', '联系渠道', '商务、客服和合作咨询路径直接可见。', ['商务咨询', '客服支持', '渠道合作'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-process', '响应预期', '留言后的响应时效、处理流程和支持方式清晰透明。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '现在发起联系', '客服入口、咨询说明和行动按钮集中呈现。', $this->resolveCtaLabel(Page::TYPE_CONTACT), $ctaRefinement, 40),
        ];
    }

    /**
     * @param list<string> $promptPoints
     * @return list<array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }>
     */
    private function buildPolicySections(
        string $baseCode,
        string $pageLabel,
        string $pageTitle,
        string $siteDisplayName,
        string $aiDescription,
        array $promptPoints,
        string $heroRefinement,
        string $middleRefinement,
        string $detailRefinement,
        string $ctaRefinement
    ): array {
        return [
            $this->buildHeroSection($baseCode . '-hero', $pageLabel, $pageTitle, $siteDisplayName, $this->applyRefinement($aiDescription, $heroRefinement), $promptPoints, 10),
            $this->buildChecklistSection($baseCode . '-coverage', '适用范围', '先把这份政策覆盖哪些数据、流程或责任说清楚。', $this->rotatePoints($promptPoints, 0), $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-rights', '用户权利与执行', '列出用户能做什么，以及站点如何响应。', $this->rotatePoints($promptPoints, 2), $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '需要补充说明？', '政策页也需要一个清晰的联系与更新说明。', $this->resolveCtaLabel(Page::TYPE_PRIVACY_POLICY), $ctaRefinement, 40),
        ];
    }

    /**
     * @param list<string> $promptPoints
     * @return list<array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }>
     */
    private function buildBlogSections(string $pageType): array
    {
        $componentCode = match ($pageType) {
            Page::TYPE_BLOG => 'blog-detail',
            Page::TYPE_BLOG_CATEGORY => 'blog-category',
            default => 'blog-list',
        };
        $name = match ($pageType) {
            Page::TYPE_BLOG => 'Blog Detail',
            Page::TYPE_BLOG_CATEGORY => 'Blog Category',
            default => 'Blog List',
        };
        $config = match ($pageType) {
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
            default => [
                'posts_per_page' => 10,
                'show_sidebar' => true,
                'show_categories' => true,
                'show_recent_posts' => true,
                'show_pagination' => true,
                'layout' => 'grid',
            ],
        };

        return [[
            'key' => 'native-blog',
            'code' => $componentCode,
            'name' => $name,
            'template' => 'native-blog',
            'config' => $config,
            'sort_order' => 10,
        ]];
    }

    /**
     * @param list<string> $promptPoints
     * @return list<array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }>
     */
    private function buildCustomSections(
        string $baseCode,
        string $pageLabel,
        string $pageTitle,
        string $siteDisplayName,
        string $aiDescription,
        array $promptPoints,
        string $heroRefinement,
        string $middleRefinement,
        string $detailRefinement,
        string $ctaRefinement
    ): array {
        return [
            $this->buildHeroSection($baseCode . '-hero', $pageLabel, $pageTitle, $siteDisplayName, $this->applyRefinement($aiDescription, $heroRefinement), $promptPoints, 10),
            $this->buildCardsSection($baseCode . '-modules', '信息模块', '把这一页需要承接的重点内容拆成可编辑的区块。', ['内容重点', '展示方式', '转化动作'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-steps', '继续完善建议', '先生成结构，再逐块微调。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '继续微调这一页', '自定义页面更适合按区块迭代。', $this->resolveCtaLabel(Page::TYPE_CUSTOM), $ctaRefinement, 40),
        ];
    }

    /**
     * @param list<string> $promptPoints
     * @return array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }
     */
    private function buildHeroSection(
        string $code,
        string $pageLabel,
        string $pageTitle,
        string $siteDisplayName,
        string $description,
        array $promptPoints,
        int $sortOrder,
        string $sectionKey = 'hero',
        string $primaryCtaLabel = ''
    ): array {
        return [
            'key' => $sectionKey,
            'code' => $code,
            'name' => $pageLabel . ' Hero',
            'template' => 'hero',
            'sort_order' => $sortOrder,
            'config' => [
                'eyebrow' => $pageLabel,
                'headline' => $pageTitle !== '' ? $pageTitle : $siteDisplayName,
                'description' => $description,
                'chips' => \array_slice($promptPoints, 0, 3),
                'primary_cta' => $primaryCtaLabel !== '' ? $primaryCtaLabel : $this->resolveCtaLabel($pageLabel),
                'secondary_note' => $siteDisplayName,
            ],
        ];
    }

    /**
     * @param list<string> $titles
     * @param list<string> $promptPoints
     * @return array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }
     */
    private function buildCardsSection(
        string $code,
        string $sectionTitle,
        string $sectionIntro,
        array $titles,
        array $promptPoints,
        string $refinement,
        int $sortOrder,
        string $sectionKey = 'highlights'
    ): array {
        $items = [];
        foreach ($titles as $index => $title) {
            $items[] = [
                'eyebrow' => '0' . ($index + 1),
                'title' => $title,
                'description' => $this->applyRefinement($promptPoints[$index] ?? $sectionIntro, $index === 0 ? $refinement : ''),
            ];
        }

        return [
            'key' => $sectionKey,
            'code' => $code,
            'name' => $sectionTitle,
            'template' => 'cards',
            'sort_order' => $sortOrder,
            'config' => [
                'section_title' => $sectionTitle,
                'section_intro' => $this->applyRefinement($sectionIntro, $refinement),
                'items' => $items,
            ],
        ];
    }

    /**
     * @param list<string> $promptPoints
     * @return array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }
     */
    private function buildChecklistSection(
        string $code,
        string $sectionTitle,
        string $sectionIntro,
        array $promptPoints,
        string $refinement,
        int $sortOrder,
        string $sectionKeyOverride = ''
    ): array {
        $sectionKey = $sectionKeyOverride !== '' ? $sectionKeyOverride : $this->deriveSectionKeyFromCode($code, 'details');
        return [
            'key' => $sectionKey,
            'code' => $code,
            'name' => $sectionTitle,
            'template' => 'checklist',
            'sort_order' => $sortOrder,
            'config' => [
                'section_title' => $sectionTitle,
                'section_intro' => $this->applyRefinement($sectionIntro, $refinement),
                'points' => \array_slice($this->rotatePoints($promptPoints, 1), 0, 4),
            ],
        ];
    }

    private function deriveSectionKeyFromCode(string $code, string $fallback): string
    {
        $normalized = \trim(\str_replace('\\', '/', $code));
        if ($normalized === '') {
            return $fallback;
        }

        $segment = (string)\preg_replace('/^.*\//', '', $normalized);
        $parts = \preg_split('/[-_]+/', $segment) ?: [];
        $candidate = \trim((string)\end($parts));

        return $candidate !== '' ? $candidate : $fallback;
    }

    /**
     * @return array{
     *   key:string,
     *   code:string,
     *   name:string,
     *   template:string,
     *   config:array<string, mixed>,
     *   sort_order:int
     * }
     */
    private function buildCtaSection(
        string $code,
        string $sectionTitle,
        string $sectionText,
        string $buttonLabel,
        string $refinement,
        int $sortOrder,
        string $sectionKey = 'cta'
    ): array {
        return [
            'key' => $sectionKey,
            'code' => $code,
            'name' => $sectionTitle,
            'template' => 'cta',
            'sort_order' => $sortOrder,
            'config' => [
                'section_title' => $sectionTitle,
                'section_text' => $this->applyRefinement($sectionText, $refinement),
                'button_label' => $buttonLabel,
                'assist_text' => $refinement !== '' ? $refinement : '支持在工作区继续微调这个区块。',
            ],
        ];
    }

    private function resolveCtaLabel(string $value): string
    {
        return match ($value) {
            Page::TYPE_HOME => $this->unicodeText('\u4e86\u89e3\u8be6\u60c5'),
            Page::TYPE_ABOUT => $this->unicodeText('\u4e86\u89e3\u54c1\u724c'),
            Page::TYPE_CONTACT => $this->unicodeText('\u7acb\u5373\u8054\u7cfb'),
            Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY, Page::TYPE_COOKIE_POLICY => $this->unicodeText('\u8054\u7cfb\u652f\u6301'),
            Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST => $this->unicodeText('\u6d4f\u89c8\u66f4\u591a\u5185\u5bb9'),
            default => $this->unicodeText('\u7ee7\u7eed\u4e86\u89e3'),
        };

        return match ($value) {
            Page::TYPE_HOME, '首页' => '开始了解',
            Page::TYPE_ABOUT, '关于我们' => '查看团队与能力',
            Page::TYPE_CONTACT, '联系我们' => '立即联系',
            Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY, Page::TYPE_COOKIE_POLICY, '隐私政策', '服务条款', '退款政策', '配送政策', 'Cookie政策' => '联系支持',
            Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY, Page::TYPE_BLOG_LIST, '博客文章', '博客分类', '博客列表' => '浏览更多内容',
            default => '继续完善',
        };
    }

    private function buildCustomerFacingDescription(
        string $pageType,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $siteTagline
    ): string {
        $headline = $siteDisplayName !== '' ? $siteDisplayName : $pageLabel;
        $summary = $this->clipText($this->stripProfileLabelPrefix($siteSummary), 160);
        $tagline = $this->stripProfileLabelPrefix($siteTagline);
        $base = $summary !== '' ? $summary : $tagline;
        $suffix = $base !== '' ? (' ' . $base) : '';

        return match ($pageType) {
            Page::TYPE_HOME => $headline . ' ' . $this->unicodeText('\u805a\u5408\u6838\u5fc3\u4ef7\u503c\u3001\u7279\u8272\u5185\u5bb9\u3001\u4fe1\u4efb\u4fe1\u606f\u548c\u4e3b\u8981\u884c\u52a8\u5165\u53e3\u3002') . $suffix,
            Page::TYPE_ABOUT => $headline . ' ' . $this->unicodeText('\u5c55\u793a\u54c1\u724c\u5b9a\u4f4d\u3001\u80fd\u529b\u4fe1\u4efb\u548c\u5dee\u5f02\u5316\u4ef7\u503c\u3002') . $suffix,
            Page::TYPE_CONTACT => $headline . ' ' . $this->unicodeText('\u63d0\u4f9b\u6e05\u6670\u7684\u8054\u7cfb\u5165\u53e3\u3001\u54cd\u5e94\u9884\u671f\u548c\u884c\u52a8\u8def\u5f84\u3002') . $suffix,
            default => $headline . ' ' . $this->unicodeText('\u56f4\u7ed5\u8be5\u9875\u76ee\u6807\u7ec4\u7ec7\u5177\u4f53\u5185\u5bb9\u3001\u89c6\u89c9\u5c42\u6b21\u548c\u8f6c\u5316\u5165\u53e3\u3002') . $suffix,
        };

        $headline = $siteDisplayName !== '' ? $siteDisplayName : $pageLabel;
        $pageSummary = match ($pageType) {
            Page::TYPE_HOME => $headline . ' 汇集核心亮点、下载入口、信任信息和新手引导，让访客快速了解服务并安心行动。',
            Page::TYPE_ABOUT => $headline . ' 展示品牌定位、服务经验、长期投入和可信承诺，让访客更快建立信任。',
            Page::TYPE_CONTACT => $headline . ' 提供清晰的咨询入口、响应预期和支持方式，让访客顺畅获得帮助。',
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY => $headline . ' 的' . $pageLabel . '说明适用范围、执行方式和用户权利，语言稳定、明确、可追溯。',
            Page::TYPE_BLOG,
            Page::TYPE_BLOG_CATEGORY,
            Page::TYPE_BLOG_LIST => $headline . ' 提供实用内容、精选主题和继续阅读路径，让访客持续获取有价值的信息。',
            default => $headline . ' 的' . $pageLabel . '提供结构清晰、信息完整、行动入口明确的页面内容。',
        };

        $segments = [$pageSummary];
        if ($siteTagline !== '') {
            $tagline = $this->normalizeCustomerTagline($headline, $siteTagline);
            if ($tagline !== '' && !\str_contains($pageSummary, $tagline)) {
                $segments[] = $tagline;
            }
        }

        return \implode("\n", \array_values(\array_unique(\array_filter($segments, static fn(string $item): bool => \trim($item) !== ''))));
    }

    private function normalizeCustomerTagline(string $headline, string $siteTagline): string
    {
        $tagline = \trim($siteTagline, " \t\n\r\0\x0B。.!！“”\"'");
        if ($tagline === '') {
            return '';
        }
        if (\preg_match('/^(?:是|为|面向|专为|服务于)/u', $tagline) === 1) {
            return $headline . $tagline . '。';
        }

        return $tagline . '。';
    }

    /**
     * @return list<string>
     */
    private function buildCustomerPromptPoints(string $pageType, string $siteDisplayName, string $siteSummary, string $brief, int $limit): array
    {
        $signals = $this->analyzeBrief($brief !== '' ? $brief : $siteSummary);
        $offer = $signals['offer'] !== '' ? $signals['offer'] : '核心服务内容';
        $goal = $signals['goal'] !== '' ? $signals['goal'] : '下一步行动';
        $trust = $signals['trust'] !== '' ? $signals['trust'] : '清晰的信息架构与可信说明';
        $market = $signals['market'] !== '' ? $signals['market'] : '目标用户';
        $brand = $siteDisplayName !== '' ? $siteDisplayName : '当前站点';

        $chunks = match ($pageType) {
            Page::TYPE_HOME => [
                $brand . ' 的核心亮点与差异价值',
                $offer . ' 的玩法、权益与适用场景',
                $goal . ' 的醒目入口',
                $trust,
                '下载、咨询或继续浏览入口清晰可见',
            ],
            Page::TYPE_ABOUT => [
                '品牌定位、团队背景和服务经验',
                $brand . ' 服务 ' . $market . ' 的优势',
                '可信里程碑、服务流程或方法论',
                '长期投入、稳定支持和差异化价值',
                '咨询、下载或继续了解入口',
            ],
            Page::TYPE_CONTACT => [
                '商务、客服与合作咨询入口',
                '响应时效、处理流程和常见咨询方向',
                '不同需求对应的联系路径',
                '联系前需要了解的支持信息',
                $goal . ' 的清晰行动提示',
            ],
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY => [
                '政策覆盖范围与适用对象',
                '用户权利、执行流程与更新时间',
                '第三方、Cookie、退款或配送边界',
                '稳定、准确、便于查阅的说明',
                '联系与补充说明入口',
            ],
            Page::TYPE_BLOG,
            Page::TYPE_BLOG_CATEGORY,
            Page::TYPE_BLOG_LIST => [
                '用户关心的主题栏目与精选文章',
                '读者可获得的信息、方法或帮助',
                '继续阅读、分类浏览和最新内容入口',
                '专业内容与复访价值',
                '顺畅阅读体验和后续行动入口',
            ],
            default => [
                '结构清晰的信息模块',
                $offer . ' 的重点内容',
                '清晰的行动入口与补充说明',
                '品牌表达、信任信息与实际转化',
                '适合继续逐块微调的内容',
            ],
        };

        if ($siteSummary !== '') {
            $chunks[] = $this->clipText($siteSummary, 54);
        }

        while (\count($chunks) < $limit) {
            $chunks[] = '更具体的利益点、信任点和行动入口';
        }

        return \array_slice(\array_values(\array_unique($chunks)), 0, $limit);
    }

    private function buildAiDescription(string $pageLabel, string $brief, string $pageInstruction, string $siteTagline): string
    {
        $segments = [];
        if ($pageInstruction !== '') {
            $segments[] = $pageLabel . ' 页面重点：' . $this->clipText($pageInstruction, 120);
        }
        if ($brief !== '') {
            $segments[] = '站点简报：' . $brief;
        }
        if ($siteTagline !== '') {
            $segments[] = '品牌定位：' . $siteTagline;
        }

        $segments = \array_values(\array_unique(\array_filter($segments, static fn(string $item): bool => \trim($item) !== '')));

        return $segments === []
            ? ($pageLabel . ' 页面内容需要围绕当前站点目标继续完善。')
            : \implode("\n", $segments);
    }

    /**
     * @return list<string>
     */
    private function buildPromptPoints(string $brief, string $pageInstruction, int $limit): array
    {
        $chunks = [];
        $source = \trim($brief . "\n" . $pageInstruction);
        if ($source !== '') {
            $parts = \preg_split('/[\r\n,，。；;！!？?]+/u', $source, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                $candidate = \trim($part);
                if ($candidate === '') {
                    continue;
                }
                $candidate = $this->clipText($candidate, 54);
                if (\in_array($candidate, $chunks, true)) {
                    continue;
                }
                $chunks[] = $candidate;
                if (\count($chunks) >= $limit) {
                    break;
                }
            }
        }

        while (\count($chunks) < $limit) {
            $chunks[] = '继续补充更具体的利益点、信任点和转化动作。';
        }

        return \array_slice($chunks, 0, $limit);
    }

    /**
     * @param list<string> $points
     * @return list<string>
     */
    private function rotatePoints(array $points, int $offset): array
    {
        if ($points === []) {
            return [];
        }

        $offset = $offset % \count($points);
        if ($offset === 0) {
            return $points;
        }

        return \array_merge(\array_slice($points, $offset), \array_slice($points, 0, $offset));
    }

    private function buildMetaKeywords(string $siteDisplayName, string $pageLabel, string $brief): string
    {
        $keywords = [];
        foreach ([$siteDisplayName, $pageLabel, $brief] as $source) {
            $parts = \preg_split('/[\s,，。；;！!？?]+/u', \trim($source), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                $candidate = \trim($part);
                if ($candidate === '' || \in_array($candidate, $keywords, true)) {
                    continue;
                }
                $keywords[] = $candidate;
                if (\count($keywords) >= 8) {
                    break 2;
                }
            }
        }

        return \implode(', ', $keywords);
    }

    /**
     * @param array<string, string> $refinements
     */
    private function resolveRefinement(array $refinements, string $code, string $fallbackKey): string
    {
        if ($code !== '' && isset($refinements[$code])) {
            return $refinements[$code];
        }
        if ($fallbackKey !== '' && isset($refinements[$fallbackKey])) {
            return $refinements[$fallbackKey];
        }

        $suffix = \strrchr($code, '-');
        if (\is_string($suffix) && $suffix !== '' && isset($refinements[\ltrim($suffix, '-')])) {
            return $refinements[\ltrim($suffix, '-')];
        }

        return '';
    }

    private function applyRefinement(string $text, string $refinement): string
    {
        $text = \trim($text);
        $refinement = \trim($refinement);
        if ($refinement === '') {
            return $text;
        }
        if ($text === '') {
            return $refinement;
        }

        return $text . "\n重点微调：" . $refinement;
    }

    /**
     * @param array<string, mixed> $virtualPage
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    private function extractReusableBrief(array $virtualPage, array $websiteProfile, array $scope): string
    {
        $freshBrief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        if ($freshBrief !== '') {
            return $freshBrief;
        }

        $legacy = \trim((string)($virtualPage['ai_description'] ?? ''));
        return $this->sanitizeStoredAiDescription($legacy);
    }

    private function sanitizeStoredAiDescription(string $text): string
    {
        $text = \trim(\preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || $this->containsPromptLeakage($text)) {
            return '';
        }

        return $text;
    }

    private function containsPromptLeakage(string $text): bool
    {
        $normalized = \function_exists('mb_strtolower') ? \mb_strtolower(\trim($text)) : \strtolower(\trim($text));
        foreach ([
            '页面重点',
            '站点简报',
            '客户需求',
            '用户需求',
            '提示词',
            '我想做',
            '我希望',
            '我要做',
            '请帮我',
            '推广apk',
            '推广 apk',
        ] as $needle) {
            $target = \function_exists('mb_strtolower') ? \mb_strtolower($needle) : \strtolower($needle);
            if (\str_contains($normalized, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{market:string,offer:string,goal:string,trust:string}
     */
    private function analyzeBrief(string $brief): array
    {
        $brief = \trim($brief);
        if ($brief === '') {
            return ['market' => '', 'offer' => '', 'goal' => '', 'trust' => ''];
        }

        $market = match (true) {
            \preg_match('/西悉尼|west\s*sydney/iu', $brief) === 1 => '面向西悉尼用户',
            \preg_match('/悉尼|sydney/iu', $brief) === 1 => '面向悉尼用户',
            \preg_match('/印度|india/iu', $brief) === 1 => '面向印度市场',
            \preg_match('/澳洲|澳大利亚|australia/iu', $brief) === 1 => '面向澳洲用户',
            default => '',
        };

        $isGaming = \preg_match('/棋牌|游戏|扑克|德州|poker|rummy|ludo|casino/iu', $brief) === 1;
        $isApp = \preg_match('/apk|app|应用|下载/iu', $brief) === 1;
        $isFinance = \preg_match('/金融|支付|银行|wallet|pay|fintech/iu', $brief) === 1;
        $isFitness = \preg_match('/健身|训练|课程|gym|fitness/iu', $brief) === 1;
        $isSaas = \preg_match('/saas|软件|系统|平台|crm|erp/iu', $brief) === 1;
        $isContent = \preg_match('/博客|blog|资讯|内容/iu', $brief) === 1;
        $needsContact = \preg_match('/咨询|联系|留资|预约|线索|合作/iu', $brief) === 1;

        $offer = match (true) {
            $isGaming && $isApp => '移动棋牌内容与下载引导平台',
            $isGaming => '棋牌与游戏内容展示平台',
            $isFinance => '金融服务与产品介绍站点',
            $isFitness => '健身服务与课程展示站点',
            $isSaas => '产品能力与解决方案展示站点',
            $isContent => '内容与资讯运营站点',
            $isApp => '移动应用下载与功能介绍站点',
            default => '品牌展示与业务转化站点',
        };

        $goal = match (true) {
            $isApp => '下载转化、新手上手与持续留存',
            $needsContact => '咨询转化、线索获取与快速响应',
            $isFinance => '信任建立、服务说明与行动转化',
            $isContent => '内容沉淀、复访与关注转化',
            default => '价值传达、信任建立与下一步行动',
        };

        $trust = match (true) {
            $isGaming || $isApp => '上手指引、规则说明、客服支持和常见问题',
            $isFinance => '专业感、合规说明、安全承诺与透明流程',
            $isSaas => '清晰结构、案例信任、试用行动与长期服务能力',
            $needsContact => '服务流程、响应时效和真实沟通入口',
            default => '清晰的信息架构、可信说明与明确行动入口',
        };

        return [
            'market' => $market,
            'offer' => $offer,
            'goal' => $goal,
            'trust' => $trust,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (!\is_scalar($key) || !\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text === '') {
                continue;
            }
            $map[(string)$key] = $text;
        }

        return $map;
    }

    private function pickString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveBuildPlanSiteDisplayName(array $scope): string
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $siteBrief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];

        return $this->stripProfileLabelPrefix($this->pickString(
            $siteBrief['site_name'] ?? null,
            $requirements['site_name'] ?? null,
            $siteStrategy['site_display_name'] ?? null
        ));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveBuildPlanMarketingSummary(array $scope): string
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $siteBrief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];

        return $this->stripProfileLabelPrefix($this->pickString(
            $requirements['site_goal'] ?? null,
            $requirements['content_direction'] ?? null,
            $requirements['expanded_brief'] ?? null,
            $requirements['planning_summary'] ?? null,
            $siteBrief['summary'] ?? null,
            $requirementExpansion['site_goal'] ?? null,
            $requirementExpansion['content_direction'] ?? null,
            $requirementExpansion['expanded_brief'] ?? null,
            $siteStrategy['core_goal'] ?? null,
            $siteStrategy['content_strategy'] ?? null
        ));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveScopePrimaryCtaLabel(array $scope, string $pageType, string $pageLabel): string
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $raw = $this->pickString(
            $requirements['primary_cta'] ?? null,
            $requirementExpansion['primary_cta'] ?? null,
            $siteStrategy['primary_cta'] ?? null
        );
        if ($raw === '') {
            return $this->resolveCtaLabel($pageType !== '' ? $pageType : $pageLabel);
        }

        return $this->selectCtaLabelForPage($raw, $pageType);
    }

    private function selectCtaLabelForPage(string $primaryCta, string $pageType): string
    {
        $parts = \preg_split('/\s*(?:\/|\||,|\x{FF0C}|\x{3001})\s*/u', $primaryCta, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $labels = [];
        foreach ($parts as $part) {
            $label = $this->stripProfileLabelPrefix((string)$part);
            if ($label !== '' && !\in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        if ($labels === []) {
            return $primaryCta;
        }

        $preferOrder = \preg_match('/custom|menu|product|order|shop|store/u', $pageType) === 1;
        foreach ($labels as $label) {
            if ($preferOrder && \preg_match('/order|buy|shop|purchase|\x{8BA2}\x{8D2D}|\x{8D2D}\x{4E70}/iu', $label) === 1) {
                return $label;
            }
        }

        return $labels[0];
    }

    /**
     * 用户填写的站点标题优先；拒绝提示词/ROLE/长描述泄漏为品牌名。
     */
    public function normalizeSiteDisplayNameCandidate(string $value): string
    {
        $value = $this->stripProfileLabelPrefix($value);
        if ($value === '' || !$this->isUsableSiteDisplayName($value)) {
            return '';
        }

        return $value;
    }

    private function isUsableSiteDisplayName(string $value): bool
    {
        $normalized = \mb_strtolower(\trim($value));
        if ($normalized === '' || $normalized === 'ai site') {
            return false;
        }
        if (\str_contains($normalized, 'websiteprofile') || \str_contains($normalized, 'website profile')) {
            return false;
        }
        if (\preg_match('/^\s*#\s*role\b/u', $normalized) === 1 || \preg_match('/^\s*role\s*:/u', $normalized) === 1) {
            return false;
        }
        if (\preg_match('/\byou are a\b/u', $normalized) === 1 && \mb_strlen($value) > 32) {
            return false;
        }
        if (\preg_match('/\b(?:return only|must not|do not|json|prompt|contract field|visitor-visible|html_content)\b/u', $normalized) === 1) {
            return false;
        }

        $maxLength = 48;
        if (\function_exists('mb_strlen') ? \mb_strlen($value) > $maxLength : \strlen($value) > $maxLength) {
            return false;
        }

        return true;
    }

    /**
     * @param list<mixed> $values
     */
    private function pickSiteDisplayName(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $candidate = $this->normalizeSiteDisplayNameCandidate((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function stripProfileLabelPrefix(string $value): string
    {
        $value = \trim((string)\preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }
        $value = (string)\preg_replace(
            '/^\s*(?:\x{7AD9}\x{70B9}\x{540D}\x{79F0}|\x{7F51}\x{7AD9}\x{540D}\x{79F0}|\x{54C1}\x{724C}\x{540D}|site\s*(?:title|name)|brand\s*name|\x{4E00}\x{53E5}\x{8BDD}\x{5B9A}\x{4F4D}|positioning|tagline)\s*[:\x{FF1A}]\s*/iu',
            '',
            $value
        );
        $value = (string)\preg_replace(
            '/\b(?:website\s*profile|site\s*profile|profile)\b/iu',
            '',
            $value
        );
        $value = \trim((string)\preg_replace('/\s+/u', ' ', $value));

        return \trim($value);
    }

    private function unicodeText(string $jsonEscaped): string
    {
        $decoded = \json_decode('"' . $jsonEscaped . '"');

        return \is_string($decoded) ? $decoded : $jsonEscaped;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'component';
    }

    private function clipText(string $value, int $limit): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            if (\mb_strlen($value) <= $limit) {
                return $value;
            }

            return \rtrim(\mb_substr($value, 0, $limit - 1)) . '...';
        }

        if (\strlen($value) <= $limit) {
            return $value;
        }

        return \rtrim(\substr($value, 0, $limit - 1)) . '...';
    }

    private function deriveTitleFromDomain(string $targetDomain): string
    {
        $domain = \preg_replace('/^https?:\/\//i', '', \trim($targetDomain));
        $domain = \explode('/', (string)$domain)[0] ?? '';
        $domain = \explode('.', (string)$domain)[0] ?? '';
        $domain = \str_replace(['-', '_'], ' ', (string)$domain);
        $domain = \trim((string)\preg_replace('/\s+/', ' ', $domain));

        return $domain !== '' ? $this->clipText(\ucwords($domain), 18) : '';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function resolveContentLocale(array $scope, array $websiteProfile): string
    {
        return \trim((string)(
            $scope['content_locale']
                ?? $websiteProfile['content_locale']
                ?? $scope['default_locale']
                ?? $scope['default_language']
                ?? $websiteProfile['default_locale']
                ?? ''
        ));
    }

    private function localizePageTypeLabel(string $pageType, string $locale): string
    {
        $isZh = $this->isChineseLocale($locale);
        $isJa = $this->isJapaneseLocale($locale);
        $isKo = $this->isKoreanLocale($locale);

        return match ($pageType) {
            Page::TYPE_HOME => $isZh ? '首页' : ($isJa ? 'ホーム' : ($isKo ? '홈' : 'Home')),
            Page::TYPE_ABOUT => $isZh ? '关于我们' : ($isJa ? '私たちについて' : ($isKo ? '회사 소개' : 'About')),
            Page::TYPE_CONTACT => $isZh ? '联系我们' : ($isJa ? 'お問い合わせ' : ($isKo ? '문의하기' : 'Contact')),
            Page::TYPE_BLOG_LIST, Page::TYPE_BLOG => $isZh ? '博客' : ($isJa ? 'ブログ' : ($isKo ? '블로그' : 'Blog')),
            Page::TYPE_PRIVACY_POLICY => $isZh ? '隐私政策' : ($isJa ? 'プライバシーポリシー' : ($isKo ? '개인정보처리방침' : 'Privacy Policy')),
            Page::TYPE_TERMS_OF_SERVICE => $isZh ? '服务条款' : ($isJa ? '利用規約' : ($isKo ? '이용약관' : 'Terms of Service')),
            Page::TYPE_REFUND_POLICY => $isZh ? '退款政策' : ($isJa ? '返金ポリシー' : ($isKo ? '환불 정책' : 'Refund Policy')),
            Page::TYPE_SHIPPING_POLICY => $isZh ? '配送政策' : ($isJa ? '配送ポリシー' : ($isKo ? '배송 정책' : 'Shipping Policy')),
            Page::TYPE_COOKIE_POLICY => $isZh ? 'Cookie 政策' : ($isJa ? 'Cookie ポリシー' : ($isKo ? '쿠키 정책' : 'Cookie Policy')),
            default => '',
        };
    }

    private function isChineseLocale(string $locale): bool
    {
        return \preg_match('/^(zh|zh[_-]hans|zh[_-]cn|zh[_-]sg)/i', $locale) === 1;
    }

    private function isJapaneseLocale(string $locale): bool
    {
        return \preg_match('/^ja(?:[_-]|$)/i', $locale) === 1;
    }

    private function isKoreanLocale(string $locale): bool
    {
        return \preg_match('/^ko(?:[_-]|$)/i', $locale) === 1;
    }
}
