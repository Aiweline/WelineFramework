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
        $aiDescription = $this->buildCustomerFacingDescription($pageType, $pageLabel, $siteDisplayName, $siteSummary, $siteTagline);
        $promptPoints = $this->buildCustomerPromptPoints($pageType, $siteDisplayName, $siteSummary, $brief, 8);
        $baseCode = 'content/' . $this->slugify($pageType);

        $heroRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-hero', 'hero');
        $middleRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-highlights', 'highlights');
        $detailRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-details', 'details');
        $ctaRefinement = $this->resolveRefinement($sectionRefinements, $baseCode . '-cta', 'cta');

        $sections = match ($pageType) {
            Page::TYPE_HOME => $this->buildHomeSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
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
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    public function resolveSiteDisplayName(array $websiteProfile, array $scope = []): string
    {
        $siteTitle = $this->pickString($websiteProfile['site_title'] ?? null, $scope['site_title'] ?? null);
        if ($siteTitle !== '' && \strtolower($siteTitle) !== 'ai site') {
            return $siteTitle;
        }

        $brief = $this->pickString(
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        if ($brief === '') {
            $domain = $this->pickString($websiteProfile['target_domain'] ?? null, $scope['target_domain'] ?? null);
            return $siteTitle !== '' ? $siteTitle : $this->deriveTitleFromDomain($domain);
        }

        if (\preg_match('/印度/u', $brief) === 1 && \preg_match('/棋牌/u', $brief) === 1) {
            return \stripos($brief, 'apk') !== false ? '印度棋牌 APK 平台' : '印度棋牌平台';
        }
        if (\preg_match('/棋牌/u', $brief) === 1) {
            return \stripos($brief, 'apk') !== false ? '棋牌 APK 平台' : '棋牌平台';
        }

        $candidate = \trim((string)\preg_replace('/\s+/', ' ', $brief));
        if ($candidate === '') {
            $domain = $this->pickString($websiteProfile['target_domain'] ?? null, $scope['target_domain'] ?? null);
            return $siteTitle !== '' ? $siteTitle : $this->deriveTitleFromDomain($domain);
        }

        return $this->clipText($candidate, 18);
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     */
    public function buildSiteMarketingSummary(array $websiteProfile, array $scope = []): string
    {
        $siteDisplayName = $this->resolveSiteDisplayName($websiteProfile, $scope);
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

        $summary = $headline . ' 是一个' . $market . $offer . '，内容重点围绕' . $goal . '来组织。';
        $supporting = '整体表达强调' . $trust . '。';
        if ($siteTagline !== '') {
            $supporting = '整体表达延续“' . $siteTagline . '”的品牌语气，并强调' . $trust . '。';
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
        string $ctaRefinement
    ): array {
        return [
            $this->buildHeroSection($baseCode . '-hero', $pageLabel, $pageTitle, $siteDisplayName, $this->applyRefinement($aiDescription, $heroRefinement), $promptPoints, 10),
            $this->buildCardsSection($baseCode . '-highlights', '核心卖点', '把首页第一屏以下最值得点击的理由放出来。', ['核心卖点', '投放场景', '下载转化'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-details', '转化路径', '让用户知道进入站点后应该先看什么、再做什么。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '开始承接流量', '把 APK 推广、站内信任感和转化动作串成一条明确路径。', $this->resolveCtaLabel(Page::TYPE_HOME), $ctaRefinement, 40),
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
            $this->buildCardsSection($baseCode . '-story', '品牌与团队', '把故事、能力和差异化表达清楚。', ['品牌起点', '团队能力', '市场理解'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-values', '为什么值得信任', '用更稳妥的方式展示经验、规范和长期投入。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '继续了解合作方式', '关于页需要承接信任感，并把用户带去下一步动作。', $this->resolveCtaLabel(Page::TYPE_ABOUT), $ctaRefinement, 40),
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
            $this->buildCardsSection($baseCode . '-channels', '联系渠道', '把商务、客服和合作咨询的路径拆得足够直接。', ['商务咨询', '客服支持', '渠道合作'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-process', '响应预期', '让访客知道留言后会发生什么。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '现在发起联系', '联系页的目标是缩短决策路径，避免用户找不到入口。', $this->resolveCtaLabel(Page::TYPE_CONTACT), $ctaRefinement, 40),
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
        int $sortOrder
    ): array {
        return [
            'key' => 'hero',
            'code' => $code,
            'name' => $pageLabel . ' Hero',
            'template' => 'hero',
            'sort_order' => $sortOrder,
            'config' => [
                'eyebrow' => $pageLabel,
                'headline' => $pageTitle !== '' ? $pageTitle : $siteDisplayName,
                'description' => $description,
                'chips' => \array_slice($promptPoints, 0, 3),
                'primary_cta' => $this->resolveCtaLabel($pageLabel),
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
        int $sortOrder
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
            'key' => 'highlights',
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
        int $sortOrder
    ): array {
        return [
            'key' => 'details',
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
        int $sortOrder
    ): array {
        return [
            'key' => 'cta',
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
        $pageSummary = match ($pageType) {
            Page::TYPE_HOME => $headline . ' 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。',
            Page::TYPE_ABOUT => $headline . ' 的关于页重点呈现品牌定位、团队能力、服务经验和长期投入，帮助访客快速建立信任。',
            Page::TYPE_CONTACT => $headline . ' 的联系页要把咨询入口、响应预期和合作路径讲清楚，减少用户发起联系前的犹豫。',
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY => $headline . ' 的' . $pageLabel . '需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。',
            Page::TYPE_BLOG,
            Page::TYPE_BLOG_CATEGORY,
            Page::TYPE_BLOG_LIST => $headline . ' 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。',
            default => $headline . ' 的' . $pageLabel . '需要围绕页面目标组织完整内容，保证结构清楚、信息完整、行动入口明确。',
        };

        $segments = [$pageSummary];
        if ($siteSummary !== '') {
            $segments[] = $siteSummary;
        }
        if ($siteTagline !== '') {
            $segments[] = '文案语气延续“' . $siteTagline . '”的表达，但保持面向访客的自然品牌口吻。';
        }

        return \implode("\n", \array_values(\array_unique(\array_filter($segments, static fn(string $item): bool => \trim($item) !== ''))));
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
                '用一句话讲清 ' . $brand . ' 的核心价值与差异点',
                '突出 ' . $offer . ' 的主要亮点与适用场景',
                '让访客迅速理解为什么现在就要继续了解或开始行动',
                '补足 ' . $trust,
                '首屏与首屏下方都保留明确的转化入口',
            ],
            Page::TYPE_ABOUT => [
                '说明品牌定位、团队背景和服务经验',
                '解释为什么由 ' . $brand . ' 来服务 ' . $market,
                '用里程碑、流程或方法论建立信任',
                '把长期投入、稳定支持和差异化讲清楚',
                '让关于页自然衔接到咨询或下一步动作',
            ],
            Page::TYPE_CONTACT => [
                '把商务、客服与合作咨询入口拆分清楚',
                '说明响应时效、处理流程和常见咨询方向',
                '帮助用户快速判断该走哪条联系路径',
                '减少发起联系前的顾虑',
                '用清晰的行动提示承接 ' . $goal,
            ],
            Page::TYPE_PRIVACY_POLICY,
            Page::TYPE_TERMS_OF_SERVICE,
            Page::TYPE_REFUND_POLICY,
            Page::TYPE_SHIPPING_POLICY,
            Page::TYPE_COOKIE_POLICY => [
                '明确政策覆盖范围与适用对象',
                '拆分用户权利、执行流程与更新时间',
                '对第三方、Cookie、退款或配送边界做清楚说明',
                '保持语言稳定、准确、便于查阅',
                '预留联系与补充说明入口',
            ],
            Page::TYPE_BLOG,
            Page::TYPE_BLOG_CATEGORY,
            Page::TYPE_BLOG_LIST => [
                '围绕用户关心的主题组织栏目与文章结构',
                '说明读者能获得什么信息或帮助',
                '给出继续阅读、分类浏览和最新内容入口',
                '用内容建立专业度与复访理由',
                '兼顾阅读体验与后续转化',
            ],
            default => [
                '围绕页面目标组织完整信息模块',
                '把 ' . $offer . ' 的重点内容拆成可编辑区块',
                '保留清晰的行动入口与补充说明',
                '兼顾品牌表达、信任信息与实际转化',
                '确保内容适合继续逐块微调',
            ],
        };

        if ($siteSummary !== '') {
            $chunks[] = $this->clipText($siteSummary, 54);
        }

        while (\count($chunks) < $limit) {
            $chunks[] = '继续补充更具体的利益点、信任点和转化动作。';
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
