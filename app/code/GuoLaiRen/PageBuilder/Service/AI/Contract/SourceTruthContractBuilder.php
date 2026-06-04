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
     * @param array<string, mixed> $referenceImageInsights 宸茶В鏋愮殑鍙傝€冨浘娲炲療
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
        $positiveBrief = $this->removeNegativeConstraintSegments($brief);
        $positiveInstruction = $this->removeNegativeConstraintSegments($instruction);
        $userLocale = $scope['ai_content_locale'] ?? 'zh_Hans_CN';

        $facts = $this->buildMustIncludeFacts($positiveBrief, $positiveInstruction, $userLocale, $contentLocale);
        $keywords = $this->extractKeywords($positiveBrief, $positiveInstruction);
        $visualHonor = $this->extractVisualMustHonor($referenceImageInsights);
        $forbidden = $this->extractForbidden($referenceImageInsights, $brief);
        $requiredBlocks = $this->resolveRequiredHomeBlocks($positiveBrief, $positiveInstruction);

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
            'conversion_goals' => $this->extractConversionGoals($positiveBrief, $pageTypes),
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
            $clauses = $this->splitBriefFactClauses($line);
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

    /**
     * @return list<string>
     */
    private function splitBriefFactClauses(string $line): array
    {
        $parts = \preg_split('/(?<=[.!?;銆傦紒锛燂紱])\s+/u', $line) ?: [];
        $clauses = [];
        foreach ($parts as $part) {
            $part = \trim((string)$part);
            if ($part === '') {
                continue;
            }
            $clauses[] = \trim($part, " \t\n\r\0\x0B.;銆傦紒锛燂紱");
        }

        return \array_values(\array_filter($clauses, static fn(string $part): bool => $part !== ''));
    }

    private function isInternalControlInstruction(string $instruction): bool
    {
        $instruction = \trim($instruction);
        if ($instruction === '') {
            return false;
        }

        return \preg_match('/(?:^\s*\[FORCE\]|queue:run|--force|\s-f\b|寮哄埗閲嶅缓寤虹珯鏂规|閲嶆柊璺戦槦鍒?/iu', $instruction) === 1;
    }

    private function removeNegativeConstraintSegments(string $text): string
    {
        if (\trim($text) === '') {
            return '';
        }

        return \trim((string)\preg_replace(
            '/(?:\b(?:avoid|exclude|excluding|without|no|not|never|forbid|forbidden|do\s+not|don\'t)\b|绂佹|閬垮厤|涓嶈|涓嶅緱|鎺掗櫎|涓嶆槸|闈瀨鍕縷璇峰嬁)[^.;!?銆傦紒锛焅r\n]*/iu',
            '',
            $text
        ));
    }

    private function isStyleOnlyBriefClause(string $clause): bool
    {
        return \preg_match('/(?:椋庢牸|涓濇粦|鍔ㄦ晥|瑙嗚|閰嶈壊|鑹插僵|鍦熻豹姘攟楂樼骇鎰焲姘涘洿鎰焲璐ㄦ劅|\bUI\b|鐣岄潰鏁堟灉|\bvisual\s+direction\b|\baesthetic\b|\bstyle\b|\bpalette\b|\btypography\b|\bmotion\b)/iu', $clause) === 1;
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
        if (\preg_match('/(?:椤甸潰浠ｇ爜|page\s*(?:type|code)|鍝佺墝鍚嶄繚鐣欏師鏂噟preserve\s+brand\s+name|鐢ㄦ埛璇锋眰鐨勯〉闈㈡剰鍥緗requested\s+page\s+intent)/iu', $clause) === 1) {
            return true;
        }

        return \preg_match(
            '/(?:涓嶈|绂佹|涓嶅緱|涓嶈兘|蹇呴』浣跨敤|闄?+澶東璇█|绠€浣撲腑鏂噟鑻辨枃|澶ф鎻忚堪|鍚堝悓瀛楁|鎻愮ず璇峾JSON|do not|must not|forbid|forbidden|except|visible copy|contract field|prompt|json)/iu',
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
            '/(?:鎺ㄥ箍|瀹ｄ紶|promote|\bdownload\b|APK|\bapp\b)\s*(?:涓嬭浇|\bdownload\b)?/iu',
            '/(鍗板害|India|妫嬬墝|card game|\bgame\b|gaming)/iu',
            '/(涓嬭浇|\bdownload\b|\binstall\b|\bapp\b)/iu',
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

        if (\preg_match('/(?:APK|download|鎺ㄥ箍)/iu', $brief)) {
            $forbidden[] = 'generic corporate profile site';
            $forbidden[] = 'flat blue SaaS style';
        }
        foreach ($this->extractNegativeConstraintTerms($brief) as $term) {
            $forbidden[] = 'Do not use excluded user term as visible site category, CTA, copy, or visual style: ' . $term;
        }
        if (\preg_match('/(?:鑻辨枃|澶ф鎻忚堪|language|locale|do not.*English)/iu', $brief)) {
            $forbidden[] = 'large visible copy passages outside the requested website language';
        }
        if (\preg_match('/(?:鍚堝悓瀛楁|鎻愮ず璇峾JSON|contract field|prompt|json)/iu', $brief)) {
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
        $downloadIntent = \preg_match('/(?:APK|\bdownload\b|\bapp\b|瀹夎|涓嬭浇|鎺ㄥ箍)/iu', $combined) === 1;
        $blocks = $downloadIntent
            ? ['hero_download']
            : ['hero'];

        if ($this->hasExplicitFinalCtaIntent($combined)) {
            $blocks[] = $downloadIntent ? 'final_download_cta' : 'final_cta';
        }

        if (\preg_match('/(?:娓告垙|\bgame\b|妫嬬墝|\bcard\b|Teen\s*Patti|rummy)/iu', $combined)) {
            $blocks[] = 'game_showcase_or_features';
        }
        if ($downloadIntent && \preg_match('/(?:娓告垙|\bgame\b|妫嬬墝|\bcard\b|Teen\s*Patti|rummy|APK|\bdownload\b|\bapp\b)/iu', $combined)) {
            $blocks[] = 'player_reviews';
            $blocks[] = 'faq_or_rules';
        }
        if (\preg_match('/(?:淇′换|trust|瀹夊叏|secure|鏀惧績)/iu', $combined)) {
            $blocks[] = 'trust_security';
        }
        if (\preg_match('/(?:SEO|seo|鍏抽敭璇峾keyword)/iu', $combined)) {
            $blocks[] = 'seo_faq';
        }

        return \array_values(\array_unique($blocks));
    }

    private function hasExplicitFinalCtaIntent(string $value): bool
    {
        return \preg_match(
            '/(?:final|bottom|footer|last|end|closing|鏈熬|搴曢儴|鏈€鍚巪鏈€缁坾鏀跺彛).{0,32}(?:cta|download|action|conversion|涓嬭浇|琛屽姩|杞寲|寮曞)/iu',
            $value
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractBrandTerms(string $brief): array
    {
        $terms = [];
        if (\preg_match('/(?:鎺ㄥ箍|鎺ㄤ粙|浠嬬粛)\s*(\S{1,20})/iu', $brief, $m)) {
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
            if (\preg_match('/(?:涓嬭浇|APK|\bapp\b|\bdownload\b)/iu', $brief)) {
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

    /**
     * @return list<string>
     */
    private function extractNegativeConstraintTerms(string $text): array
    {
        if (\trim($text) === '') {
            return [];
        }
        if (\preg_match_all(
            '/(?:\b(?:avoid|exclude|excluding|without|no|not|never|forbid|forbidden|do\s+not|don\'t)\b|绂佹|閬垮厤|涓嶈|涓嶅緱|鎺掗櫎|璇峰嬁)\s*([^.;!?銆傦紒锛焅r\n]+)/iu',
            $text,
            $matches
        ) < 1) {
            return [];
        }

        $terms = [];
        foreach ($matches[1] as $rawTerms) {
            foreach (\preg_split('/[,锛屻€乚|\bor\b|\band\b/iu', (string)$rawTerms) ?: [] as $term) {
                $term = \trim((string)$term);
                if ($term === '' || \mb_strlen($term) > 48) {
                    continue;
                }
                $terms[] = $term;
            }
        }

        return \array_values(\array_unique($terms));
    }
}
