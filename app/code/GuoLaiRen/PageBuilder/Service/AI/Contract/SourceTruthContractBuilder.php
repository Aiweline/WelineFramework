<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceTruthContractBuilder
{
    private const TEMPLATE_SCAFFOLD_BRAND_TERMS = [
        'LudoEmpire',
        'PokerArena',
        'Poker Arena',
        'Satta King 786',
        'Satta King',
        'BharatPlay',
        'RummyRoyal',
        'Teen Patti Royal',
    ];

    public function __construct(
        private readonly ?ContractMetaBuilder $metaBuilder = null,
        private readonly ?PermissionMatrix $permissionMatrix = null,
        private readonly ?QaGateHelper $qaGateHelper = null
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $referenceImageInsights 已解析的参考图洞察
     * @param string $instruction
     * @param list<string> $pageTypes
     * @param string $contentLocale
     * @return array<string, mixed>
     */
    public function build(
        array $scope,
        array $websiteProfile,
        array $referenceImageInsights,
        string $instruction,
        array $pageTypes,
        string $contentLocale
    ): array {
        $brief = $this->extractBrief($scope, $websiteProfile);
        $userLocale = $scope['ai_content_locale'] ?? 'zh_Hans_CN';

        $facts = $this->buildMustIncludeFacts($brief, $instruction, $userLocale, $contentLocale);
        $keywords = $this->extractKeywords($brief, $instruction);
        $visualHonor = $this->extractVisualMustHonor($referenceImageInsights);
        $forbidden = $this->extractForbidden($referenceImageInsights, $brief);
        $requiredBlocks = $this->resolveRequiredHomeBlocks($brief, $instruction);

        $siteName = \trim((string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''));
        $brandTerms = $this->normalizeStringList(\array_merge(
            [$siteName, (string)($scope['site_name'] ?? ''), (string)($websiteProfile['site_name'] ?? '')],
            $this->extractBrandTerms($brief)
        ));
        $forbiddenTemplateBrands = $this->forbiddenTemplateBrandTerms($brandTerms);
        foreach ($forbiddenTemplateBrands as $term) {
            $forbidden[] = 'Do not use stale template/example brand as visible site identity: ' . $term;
        }

        $frozenFields = [
            'site_identity',
            'must_include_facts',
            'must_include_keywords',
            'conversion_goals',
            'required_home_blocks',
            'visual_must_honor',
            'must_not_do',
            'content_locale',
            'input_locale',
        ];
        $qa = $this->qaGateHelper ?? new QaGateHelper();
        $metaBuilder = $this->metaBuilder ?? new ContractMetaBuilder();

        return [
            'contract_type' => 'source_truth',
            'version' => 'v1',
            'contract_meta' => $metaBuilder->build(
                ContractType::TYPE_SOURCE_TRUTH,
                ContractType::STAGE_STAGE1,
                ContractType::STATUS_DRAFT,
                'source_truth_builder',
                'json_strict',
                [
                    'site_name' => $siteName,
                    'page_type_count' => \count($pageTypes),
                ]
            ),
            'permission_matrix' => ($this->permissionMatrix ?? new PermissionMatrix())->forStage(ContractType::STAGE_STAGE1),
            'frozen_fields' => $frozenFields,
            'mutable_fields' => ['qa_gates.*'],
            'source_contracts' => [],
            'qa_gates' => [
                'schema_shape' => $qa->gate(
                    'schema_shape',
                    QaGateHelper::STATUS_PASS,
                    'Source truth contract shape is present.'
                ),
                'human_review' => $qa->gate(
                    'human_review',
                    QaGateHelper::STATUS_PENDING,
                    'Human review is required before downstream stages treat source truth as frozen.'
                ),
            ],
            'content_locale' => $contentLocale,
            'input_locale' => $userLocale,
            'site_identity' => [
                'site_name' => $siteName,
                'brand_terms' => $brandTerms,
                'allowed_brand_terms' => $brandTerms,
                'forbidden_template_brand_terms' => $forbiddenTemplateBrands,
                'template_scaffold_rule' => 'Style template defaults/examples are structure references only; rewrite visible copy for the current brand.',
            ],
            'must_include_facts' => $facts,
            'must_include_keywords' => $keywords,
            'conversion_goals' => $this->extractConversionGoals($brief, $pageTypes),
            'required_home_blocks' => $requiredBlocks,
            'visual_must_honor' => $visualHonor,
            'must_not_do' => $forbidden,
        ];
    }

    /**
     * @return list<array{id:string, source:string, text:string, visible_copy_requirement:string, weight:int}>
     */
    private function buildMustIncludeFacts(string $brief, string $instruction, string $inputLocale, string $contentLocale): array
    {
        $facts = [];
        $id = 0;

        foreach (\explode("\n", $brief) as $line) {
            $line = \trim($line);
            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }
            $clauses = \preg_split('/[，,；;。！？!?]+/u', $line) ?: [];
            $addedFromLine = false;
            foreach ($clauses as $clause) {
                $clause = \trim((string)$clause);
                if ($clause === '' || $this->isStyleOnlyBriefClause($clause) || $this->isConstraintOnlyBriefClause($clause)) {
                    continue;
                }
                ++$id;
                $facts[] = [
                    'id' => 'f' . \str_pad((string)$id, 2, '0', \STR_PAD_LEFT),
                    'source' => 'user_brief',
                    'text' => $clause,
                    'visible_copy_requirement' => $inputLocale !== $contentLocale
                        ? "Translate meaning into {$contentLocale}, preserve core intent"
                        : 'Use directly in website copy',
                    'weight' => $id <= 3 ? 10 : 8,
                ];
                $addedFromLine = true;
            }
            if (!$addedFromLine && !$this->isConstraintOnlyBriefClause($line)) {
                ++$id;
                $facts[] = [
                    'id' => 'f' . \str_pad((string)$id, 2, '0', \STR_PAD_LEFT),
                    'source' => 'user_brief',
                    'text' => $line,
                    'visible_copy_requirement' => $inputLocale !== $contentLocale
                        ? "Translate meaning into {$contentLocale}, preserve core intent"
                        : 'Use directly in website copy',
                    'weight' => $id <= 3 ? 10 : 8,
                ];
            }
        }

        if ($instruction !== '' && !$this->isInternalControlInstruction($instruction)) {
            ++$id;
            $facts[] = [
                'id' => 'f' . \str_pad((string)$id, 2, '0', \STR_PAD_LEFT),
                'source' => 'instruction',
                'text' => $instruction,
                'visible_copy_requirement' => 'Must be reflected in page content and design decisions',
                'weight' => 9,
            ];
        }

        return $facts;
    }

    private function isInternalControlInstruction(string $instruction): bool
    {
        $instruction = \trim($instruction);
        if ($instruction === '') {
            return false;
        }

        return \preg_match('/(?:^\s*\[FORCE\]|queue:run|--force|\s-f\b|强制重建建站方案|重新跑队列)/iu', $instruction) === 1;
    }

    private function isStyleOnlyBriefClause(string $clause): bool
    {
        return \preg_match('/(?:风格|丝滑|动效|视觉|配色|色彩|土豪气|高级感|氛围感|质感|UI|界面效果)/iu', $clause) === 1;
    }

    private function isConstraintOnlyBriefClause(string $clause): bool
    {
        $clause = \trim($clause);
        if ($clause === '') {
            return false;
        }
        $lower = \mb_strtolower($clause);
        if (\preg_match('/^(?:home_page|about_page|contact_page|custom_page|blog_page|blog_list|blog_category)$/iu', $lower) === 1) {
            return true;
        }
        if (\preg_match('/(?:页面代码|page\s*(?:type|code)|品牌名保留原文|preserve\s+brand\s+name|用户请求的页面意图|requested\s+page\s+intent)/iu', $clause) === 1) {
            return true;
        }

        return \preg_match(
            '/(?:不要|禁止|不得|不能|必须使用|除.+外|语言|简体中文|英文|大段描述|合同字段|提示词|JSON|do not|must not|forbid|forbidden|except|visible copy|contract field|prompt|json)/iu',
            $clause
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $brief, string $instruction): array
    {
        $keywords = [];
        $combined = $brief . "\n" . $instruction;

        $patterns = [
            '/(?:推广|宣传|promote|download|APK|app)\s*(?:下载|download)?/iu',
            '/(印度|India|棋牌|card game|game|gaming)/iu',
            '/(下载|download|install|app)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (\preg_match_all($pattern, $combined, $matches)) {
                foreach ($matches[0] as $match) {
                    $normalized = \trim(\mb_strtolower($match));
                    if ($normalized !== '' && !\in_array($normalized, $keywords, true)) {
                        $keywords[] = $normalized;
                    }
                }
            }
        }

        return \array_slice($keywords, 0, 12);
    }

    /**
     * @return list<string>
     */
    private function extractVisualMustHonor(array $referenceImageInsights): array
    {
        $visual = [];

        foreach (['style_keywords', 'layout_cues', 'component_cues', 'typography_cues'] as $key) {
            foreach (\is_array($referenceImageInsights[$key] ?? null) ? $referenceImageInsights[$key] : [] as $item) {
                $item = \trim((string)$item);
                if ($item !== '' && !\in_array($item, $visual, true)) {
                    $visual[] = $item;
                }
            }
        }

        return $visual;
    }

    /**
     * @return list<string>
     */
    private function extractForbidden(array $referenceImageInsights, string $brief): array
    {
        $forbidden = [];
        foreach (\is_array($referenceImageInsights['do_not_use'] ?? null) ? $referenceImageInsights['do_not_use'] : [] as $item) {
            $item = \trim((string)$item);
            if ($item !== '') {
                $forbidden[] = $item;
            }
        }

        if (\preg_match('/(?:APK|download|推广)/iu', $brief)) {
            $forbidden[] = 'generic corporate profile site';
            $forbidden[] = 'flat blue SaaS style';
        }
        if (\preg_match('/(?:英文|大段描述|language|locale|do not.*English)/iu', $brief)) {
            $forbidden[] = 'large visible copy passages outside the requested website language';
        }
        if (\preg_match('/(?:合同字段|提示词|JSON|contract field|prompt|json)/iu', $brief)) {
            $forbidden[] = 'internal contract fields, prompt text, JSON names, or implementation identifiers in visitor-visible copy';
        }

        return $forbidden;
    }

    /**
     * @return list<string>
     */
    private function resolveRequiredHomeBlocks(string $brief, string $instruction): array
    {
        $combined = $brief . "\n" . $instruction;
        $downloadIntent = \preg_match('/(?:APK|download|app|安装|下载|推广)/iu', $combined) === 1;
        $blocks = $downloadIntent
            ? ['hero_download']
            : ['hero'];

        if ($this->hasExplicitFinalCtaIntent($combined)) {
            $blocks[] = $downloadIntent ? 'final_download_cta' : 'final_cta';
        }

        if (\preg_match('/(?:游戏|game|棋牌|card|Teen\s*Patti|rummy)/iu', $combined)) {
            $blocks[] = 'game_showcase_or_features';
        }
        if ($downloadIntent && \preg_match('/(?:游戏|game|棋牌|card|Teen\s*Patti|rummy|APK|download|app)/iu', $combined)) {
            $blocks[] = 'player_reviews';
            $blocks[] = 'faq_or_rules';
        }
        if (\preg_match('/(?:信任|trust|安全|secure|放心)/iu', $combined)) {
            $blocks[] = 'trust_security';
        }
        if (\preg_match('/(?:SEO|seo|关键词|keyword)/iu', $combined)) {
            $blocks[] = 'seo_faq';
        }

        return \array_values(\array_unique($blocks));
    }

    private function hasExplicitFinalCtaIntent(string $value): bool
    {
        return \preg_match(
            '/(?:final|bottom|footer|last|end|closing|末尾|底部|最后|最终|收口).{0,32}(?:cta|download|action|conversion|下载|行动|转化|引导)/iu',
            $value
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractBrandTerms(string $brief): array
    {
        $terms = [];
        if (\preg_match('/(?:推广|推介|介绍)\s*(\S{1,20})/iu', $brief, $m)) {
            $terms[] = \trim($m[1]);
        }

        return $terms;
    }

    /**
     * @param list<string> $allowedTerms
     * @return list<string>
     */
    private function forbiddenTemplateBrandTerms(array $allowedTerms): array
    {
        $allowedLookup = \array_fill_keys(\array_map(static fn(string $term): string => \mb_strtolower($term), $allowedTerms), true);
        $forbidden = [];
        foreach (self::TEMPLATE_SCAFFOLD_BRAND_TERMS as $term) {
            if (!isset($allowedLookup[\mb_strtolower($term)])) {
                $forbidden[] = $term;
            }
        }

        return $forbidden;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            $key = \mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractConversionGoals(string $brief, array $pageTypes): array
    {
        $goals = [];

        if (\in_array('home_page', $pageTypes, true)) {
            if (\preg_match('/(?:下载|APK|app|download)/iu', $brief)) {
                $goals[] = 'Drive APK/app download click';
            }
            $goals[] = 'Introduce products or services above the fold';
            $goals[] = 'Build trust before conversion';
        }

        return $goals;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function extractBrief(array $scope, array $websiteProfile): string
    {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        if ($brief === '') {
            $brief = \trim((string)($websiteProfile['brief_description'] ?? $websiteProfile['description'] ?? ''));
        }

        return $brief;
    }
}
