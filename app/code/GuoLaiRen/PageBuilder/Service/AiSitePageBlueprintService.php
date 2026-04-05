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
        $pageLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
        $siteDisplayName = $this->resolveSiteDisplayName($websiteProfile, $scope);
        $pageTitle = \trim((string)($virtualPage['title'] ?? ''));
        if ($pageTitle === '') {
            $pageTitle = $pageType === Page::TYPE_HOME ? $siteDisplayName : $pageLabel;
        }

        $brief = $this->pickString(
            $virtualPage['ai_description'] ?? null,
            $websiteProfile['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null
        );
        $pageInstruction = (string)(Page::getPageTypePromptInstructionsMap()[$pageType] ?? '');
        $siteTagline = $this->pickString($websiteProfile['site_tagline'] ?? null, $scope['site_tagline'] ?? null);
        $sectionRefinements = $this->normalizeStringMap($virtualPage['section_refinements'] ?? []);
        $aiDescription = $this->buildAiDescription($pageLabel, $brief, $pageInstruction, $siteTagline);
        $promptPoints = $this->buildPromptPoints($brief, $pageInstruction, 8);
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
            Page::TYPE_BLOG_LIST => $this->buildBlogSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
            default => $this->buildCustomSections($baseCode, $pageLabel, $pageTitle, $siteDisplayName, $aiDescription, $promptPoints, $heroRefinement, $middleRefinement, $detailRefinement, $ctaRefinement),
        };

        $metaTitle = $pageType === Page::TYPE_HOME
            ? $siteDisplayName
            : ($pageTitle . ' | ' . $siteDisplayName);
        $metaDescription = $this->clipText($aiDescription, 160);
        $metaKeywords = $this->buildMetaKeywords($siteDisplayName, $pageLabel, $brief);

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
            return $siteTitle !== '' ? $siteTitle : 'AI Site';
        }

        if (\preg_match('/印度/u', $brief) === 1 && \preg_match('/棋牌/u', $brief) === 1) {
            return \stripos($brief, 'apk') !== false ? '印度棋牌 APK 平台' : '印度棋牌平台';
        }
        if (\preg_match('/棋牌/u', $brief) === 1) {
            return \stripos($brief, 'apk') !== false ? '棋牌 APK 平台' : '棋牌平台';
        }

        $candidate = \trim((string)\preg_replace('/\s+/', ' ', $brief));
        if ($candidate === '') {
            return $siteTitle !== '' ? $siteTitle : 'AI Site';
        }

        return $this->clipText($candidate, 18);
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
    private function buildBlogSections(
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
            $this->buildCardsSection($baseCode . '-topics', '内容主题', '把博客页的栏目、主题或文章结构安排清楚。', ['栏目聚焦', '读者收益', '持续更新'], $promptPoints, $middleRefinement, 20),
            $this->buildChecklistSection($baseCode . '-structure', '阅读路径', '让读者知道该从哪里开始，以及下一篇应该看什么。', $promptPoints, $detailRefinement, 30),
            $this->buildCtaSection($baseCode . '-cta', '继续浏览内容', '博客类页面也需要承接关注或转化。', $this->resolveCtaLabel(Page::TYPE_BLOG_LIST), $ctaRefinement, 40),
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
}
