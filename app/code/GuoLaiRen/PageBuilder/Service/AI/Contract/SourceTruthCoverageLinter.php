<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceTruthCoverageLinter
{
    private const MIN_COVERAGE = 0.95;

    /**
     * @param array<string,mixed> $sourceTruth
     * @return list<array<string,mixed>>
     */
    public function visibleMustIncludeFacts(array $sourceTruth): array
    {
        return $this->filterVisibleMustIncludeFacts($sourceTruth);
    }

    public function textCoversFact(string $copy, string $fact): bool
    {
        return $this->textContainsFact($copy, $fact);
    }

    /**
     * @param array<string, mixed> $sourceTruth
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $blockPlans
     * @return array{coverage:float, missing_facts:array<string,string>, missing_blocks:list<string>, findings:list<array<string,mixed>>, fallback_used:bool}
     */
    public function lint(array $sourceTruth, array $pagePlan, array $blockPlans): array
    {
        $findings = [];

        $allCopy = $this->extractAllCopy($pagePlan, $blockPlans);

        $facts = $this->filterVisibleMustIncludeFacts($sourceTruth);
        $missedFacts = $this->findMissingFacts($facts, $allCopy);
        $totalFacts = \count($facts);
        $coveredFacts = $totalFacts - \count($missedFacts);
        $coverage = $totalFacts > 0 ? ($coveredFacts / $totalFacts) : 1.0;

        foreach ($missedFacts as $factId => $factText) {
            $findings[] = [
                'severity' => 'error',
                'category' => 'content_quality',
                'contract_type' => 'source_truth',
                'message' => "Missing must-include fact [{$factId}]: {$factText}",
                'path' => 'content_quality.missing_must_include_fact',
            ];
        }

        $missingBlocks = $this->findMissingRequiredBlocks($sourceTruth, $blockPlans);
        foreach ($missingBlocks as $blockKey) {
            $findings[] = [
                'severity' => 'error',
                'category' => 'content_quality',
                'contract_type' => 'page_contract',
                'message' => "Missing required home block: {$blockKey}",
                'path' => 'content_quality.missing_required_block',
            ];
        }

        $forbiddenHits = $this->findForbiddenHits($sourceTruth, $allCopy);
        foreach ($forbiddenHits as $hit) {
            $findings[] = [
                'severity' => 'warning',
                'category' => 'content_quality',
                'contract_type' => 'source_truth',
                'message' => "Forbidden style detected: {$hit}",
                'path' => 'content_quality.forbidden_style_violation',
            ];
        }

        return [
            'coverage' => $coverage,
            'missing_facts' => $missedFacts,
            'missing_blocks' => $missingBlocks,
            'findings' => $findings,
            'fallback_used' => $this->detectFallbackUsed($pagePlan),
        ];
    }

    /**
     * 跨 plan_json.pages 聚合文案做事实/禁忌校验；必填块仅对照 home_page。
     *
     * @param array<string, mixed> $sourceTruth
     * @param array<string, mixed> $planJson 含 pages: array<string, mixed>
     * @return array{coverage:float, missing_facts:array<string,string>, missing_blocks:list<string>, findings:list<array<string,mixed>>, fallback_used:bool}
     */
    public function lintPlanJson(array $sourceTruth, array $planJson): array
    {
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $aggregated = ['page_goal' => '', 'theme_alignment_summary' => ''];
        $allBlocks = [];
        $homeBlocks = [];
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $aggregated['page_goal'] .= ' ' . \trim((string)($page['page_goal'] ?? ''));
            $aggregated['theme_alignment_summary'] .= ' ' . \trim((string)($page['theme_alignment_summary'] ?? ''));
            $aggregated['theme_alignment_summary'] .= ' ' . $this->collectStructuredPlanCopy($page['page_design_plan'] ?? null);
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                $allBlocks[] = $block;
            }
            if ($pageType === 'home_page') {
                foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                    $homeBlocks[] = $block;
                }
            }
        }

        $allCopy = \trim($this->extractAllCopy($aggregated, ['blocks' => $allBlocks]) . ' ' . $this->extractPlanJsonRootCoverageCopy($planJson));
        $facts = $this->filterVisibleMustIncludeFacts($sourceTruth);
        $missedFacts = $this->findMissingFacts($facts, $allCopy);
        $totalFacts = \count($facts);
        $coveredFacts = $totalFacts - \count($missedFacts);
        $coverage = $totalFacts > 0 ? ($coveredFacts / $totalFacts) : 1.0;

        $findings = [];
        foreach ($missedFacts as $factId => $factText) {
            $findings[] = [
                'severity' => 'error',
                'category' => 'content_quality',
                'contract_type' => 'source_truth',
                'message' => "Missing must-include fact [{$factId}]: {$factText}",
                'path' => 'content_quality.missing_must_include_fact',
            ];
        }

        $missingBlocks = $this->findMissingRequiredBlocks($sourceTruth, ['blocks' => $homeBlocks]);
        foreach ($missingBlocks as $blockKey) {
            $findings[] = [
                'severity' => 'error',
                'category' => 'content_quality',
                'contract_type' => 'page_contract',
                'message' => "Missing required home block: {$blockKey}",
                'path' => 'content_quality.missing_required_block',
            ];
        }

        foreach ($this->findForbiddenHits($sourceTruth, $allCopy) as $hit) {
            $findings[] = [
                'severity' => 'warning',
                'category' => 'content_quality',
                'contract_type' => 'source_truth',
                'message' => "Forbidden style detected: {$hit}",
                'path' => 'content_quality.forbidden_style_violation',
            ];
        }

        $fallbackUsed = $this->detectFallbackUsed($pages);
        if ($fallbackUsed) {
            $findings[] = [
                'severity' => 'warning',
                'category' => 'content_quality',
                'contract_type' => 'execution',
                'message' => 'Stage-1 fallback plan was used. Content quality may be degraded.',
                'path' => 'content_quality.fallback_plan_used',
            ];
        }

        return [
            'coverage' => $coverage,
            'missing_facts' => $missedFacts,
            'missing_blocks' => $missingBlocks,
            'findings' => $findings,
            'fallback_used' => $fallbackUsed,
        ];
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $blockPlans
     */
    private function extractAllCopy(array $pagePlan, array $blockPlans): string
    {
        $parts = [];

        foreach (['page_goal', 'theme_alignment_summary'] as $key) {
            $text = \trim((string)($pagePlan[$key] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        foreach (\is_array($blockPlans['blocks'] ?? null) ? $blockPlans['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach (['content', 'goal'] as $key) {
                $text = \trim((string)($block[$key] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
                if (\is_array($field)) {
                    $text = \trim((string)($field['sample'] ?? ''));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
            $script = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
            $text = \trim((string)($script['core_copy'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
            $parts[] = $this->collectStructuredPlanCopy($block['design_tags'] ?? null);
        }

        return \implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function extractPlanJsonRootCoverageCopy(array $planJson): string
    {
        $parts = [];
        foreach (['requirement_expansion', 'site_strategy', 'theme_design', 'navigation_plan', 'footer_plan'] as $key) {
            if (!\array_key_exists($key, $planJson)) {
                continue;
            }
            $parts[] = $this->collectStructuredPlanCopy($planJson[$key]);
        }

        return \trim(\implode(' ', \array_filter($parts, static fn(string $text): bool => $text !== '')));
    }

    private function collectStructuredPlanCopy(mixed $value): string
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            $text = \trim((string)$value);

            return $text !== '' ? $text : '';
        }
        if (!\is_array($value)) {
            return '';
        }

        $json = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!\is_string($json) || $json === '' || $json === 'null' || $json === '[]' || $json === '{}') {
            return '';
        }

        return $json;
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @return array<string, string>
     */
    private function findMissingFacts(array $facts, string $allCopy): array
    {
        $missing = [];
        foreach ($facts as $fact) {
            if (!\is_array($fact)) {
                continue;
            }
            $factText = \trim((string)($fact['text'] ?? ''));
            if ($factText === '') {
                continue;
            }
            if (!$this->textContainsFact($allCopy, $factText)) {
                $missing[(string)($fact['id'] ?? 'unknown')] = $factText;
            }
        }

        return $missing;
    }

    private function textContainsFact(string $haystack, string $needle): bool
    {
        $haystackLower = \mb_strtolower($haystack);
        if (\mb_strpos(\mb_strtolower($haystack), \mb_strtolower($needle)) !== false) {
            return true;
        }
        foreach ($this->factMatchCandidates($needle) as $candidate) {
            if (\mb_strlen($candidate) < 4) {
                continue;
            }
            if (\mb_strpos($haystackLower, \mb_strtolower($candidate)) !== false) {
                return true;
            }
        }
        $factTokens = $this->factSignalTokens($needle);
        if ($factTokens !== []) {
            $matchedTokens = 0;
            foreach ($factTokens as $token) {
                if (\mb_strpos($haystackLower, \mb_strtolower($token)) !== false) {
                    ++$matchedTokens;
                }
            }
            if ($matchedTokens >= \max(2, (int)\ceil(\count($factTokens) * 0.6))) {
                return true;
            }
        }
        $nouns = \array_filter(
            \explode(' ', (string)(\preg_replace('/[^\w\s]/u', '', $needle) ?? '')),
            static fn(string $w): bool => \mb_strlen($w) > 2
        );
        $matched = 0;
        foreach ($nouns as $noun) {
            if (\mb_strpos($haystackLower, \mb_strtolower($noun)) !== false) {
                ++$matched;
            }
        }
        if ($matched >= \max(1, (int)(\count($nouns) * 0.5))) {
            return true;
        }

        $signals = $this->extractFactSignals($needle);
        if ($signals !== []) {
            $signalMatched = 0;
            foreach ($signals as $signal) {
                if (\mb_strpos($haystackLower, \mb_strtolower($signal)) !== false) {
                    ++$signalMatched;
                }
            }

            return $signalMatched >= \max(1, (int)\ceil(\count($signals) * 0.5));
        }

        return false;
    }

    /**
     * @param array<string,mixed> $sourceTruth
     * @return list<array<string,mixed>>
     */
    private function filterVisibleMustIncludeFacts(array $sourceTruth): array
    {
        $facts = [];
        foreach (\is_array($sourceTruth['must_include_facts'] ?? null) ? $sourceTruth['must_include_facts'] : [] as $fact) {
            if (!\is_array($fact)) {
                continue;
            }
            $text = \trim((string)($fact['text'] ?? ''));
            if ($text === '' || $this->isInternalPlanningFact($text) || $this->isDesignOnlyFact($text)) {
                continue;
            }
            $facts[] = $fact;
        }

        return \array_values($facts);
    }

    private function isInternalPlanningFact(string $text): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return true;
        }
        if (\preg_match('/^(?:home_page|about_page|contact_page|custom_page|blog_page|blog_list|blog_category)$/iu', $normalized) === 1) {
            return true;
        }
        if (\preg_match('/(?:页面代码|page\s*(?:type|code)|品牌名保留原文|preserve\s+brand\s+name|用户请求的页面意图|requested\s+page\s+intent)/iu', $text) === 1) {
            return true;
        }

        return false;
    }

    private function isDesignOnlyFact(string $text): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return true;
        }

        $designSignal = \preg_match('/\b(?:hero|immersive|responsive|section|sections|typography|font|layout|visual|aesthetic|palette|color|colour|tone|tones|warm|editorial|premium|polished|website|create|include|strictly|gradient|shadow|spacing|motion|animation|composition|style)\b/iu', $normalized) === 1;
        if (!$designSignal) {
            return false;
        }

        if ($this->hasGenericBusinessFactSignal($normalized)) {
            return false;
        }

        return true;
    }

    private function hasGenericBusinessFactSignal(string $text): bool
    {
        if (\preg_match('/(?:\d|https?:\/\/|www\.|@|[$€£¥₹]|#[a-z0-9_-]{2,})/iu', $text) === 1) {
            return true;
        }
        if (\preg_match('/\b(?:brand|product|products|service|services|menu|price|pricing|offer|offers|customer|client|team|story|location|address|phone|email|hours|contact|support|book|booking|order|preorder|pre-order|reservation|download|install|app|shop|store|restaurant|hotel|clinic|course|program|membership|portfolio|case|testimonial|review|delivery|shipping|warranty)\b/iu', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function factMatchCandidates(string $fact): array
    {
        $fact = \trim($fact);
        if ($fact === '') {
            return [];
        }

        $candidates = [];
        $afterColon = (string)(\preg_replace('/^[^:：]{1,24}[:：]\s*/u', '', $fact) ?? $fact);
        foreach ([$fact, $afterColon] as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate !== '' && !$this->isLowSignalFactToken($candidate)) {
                $candidates[] = $candidate;
            }
        }
        foreach ($this->genericFactAliases($fact) as $alias) {
            if ($alias !== '' && !$this->isLowSignalFactToken($alias)) {
                $candidates[] = $alias;
            }
        }

        return \array_values(\array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private function genericFactAliases(string $fact): array
    {
        $fact = \mb_strtolower(\trim($fact));
        if ($fact === '') {
            return [];
        }

        $aliases = [];
        $normalizedPhrase = \trim((string)\preg_replace('/[_-]+/u', ' ', $fact));
        if ($normalizedPhrase !== '' && $normalizedPhrase !== $fact) {
            $aliases[] = $normalizedPhrase;
        }
        foreach ($this->factSignalTokens($fact) as $token) {
            $token = \mb_strtolower(\trim($token));
            if ($token === '' || \mb_strlen($token) < 4 || $this->isLowSignalFactToken($token)) {
                continue;
            }
            $aliases[] = $token;
            if (\preg_match('/^[a-z][a-z-]+$/iu', $token) !== 1) {
                continue;
            }
            if (\str_ends_with($token, 'ies') && \mb_strlen($token) > 4) {
                $aliases[] = \mb_substr($token, 0, -3) . 'y';
                continue;
            }
            if (\str_ends_with($token, 'es') && \mb_strlen($token) > 4) {
                $aliases[] = \mb_substr($token, 0, -2);
                continue;
            }
            if (\str_ends_with($token, 's') && !\str_ends_with($token, 'ss') && \mb_strlen($token) > 4) {
                $aliases[] = \mb_substr($token, 0, -1);
                continue;
            }
            $aliases[] = $token . 's';
        }

        return \array_values(\array_unique($aliases));
    }

    /**
     * @return list<string>
     */
    private function factSignalTokens(string $fact): array
    {
        $fact = \trim($fact);
        if ($fact === '') {
            return [];
        }
        $fact = (string)(\preg_replace('/^[^:：]{1,24}[:：]\s*/u', '', $fact) ?? $fact);
        $split = \preg_split('/[\s,，、;；。.!！?？:：]+|(?:和|与|及|或)/u', $fact) ?: [];
        $tokens = [];
        foreach ($split as $token) {
            $token = \trim((string)$token);
            $token = (string)(\preg_replace('/(?:必须|需要|应当|应该|引导|通过|用户|页面|关键区块|首屏|目标)$/u', '', $token) ?? $token);
            $token = \trim($token);
            if ($token !== '' && \mb_strlen($token) >= 2 && !$this->isLowSignalFactToken($token)) {
                $tokens[] = $token;
            }
        }

        return \array_values(\array_unique($tokens));
    }

    private function isLowSignalFactToken(string $token): bool
    {
        $token = \mb_strtolower(\trim($token));
        if ($token === '') {
            return true;
        }

        return \in_array($token, [
            '一句话定位',
            '转化目标',
            '页面代码',
            '品牌名保留原文',
            '用户请求的页面意图',
            'page',
            'page type',
            'page code',
            'conversion goal',
        ], true);
    }

    /**
     * @return list<string>
     */
    private function findMissingRequiredBlocks(array $sourceTruth, array $blockPlans): array
    {
        $required = \is_array($sourceTruth['required_home_blocks'] ?? null) ? $sourceTruth['required_home_blocks'] : [];
        if ($required === []) {
            return [];
        }

        $existing = [];
        foreach (\is_array($blockPlans['blocks'] ?? null) ? $blockPlans['blocks'] : [] as $block) {
            if (\is_array($block)) {
                $existing[] = \trim((string)($block['block_key'] ?? ''));
            }
        }

        $missing = [];
        foreach ($required as $key) {
            $aliases = $this->requiredBlockAliases((string)$key);
            $found = false;
            foreach ($existing as $e) {
                foreach ($aliases as $alias) {
                    if ($alias !== '' && (\str_contains($e, $alias) || \str_contains($alias, $e))) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                $missing[] = (string)$key;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function requiredBlockAliases(string $key): array
    {
        $key = \trim(\mb_strtolower($key));
        $aliases = match ($key) {
            'hero_download' => ['hero_download', 'hero_download_main', 'hero_download_banner', 'hero'],
            'game_showcase_or_features', 'game_showcase' => ['game_showcase_or_features', 'game_showcase', 'featured_games', 'highlights'],
            'trust_security' => ['trust_security', 'trust'],
            'seo_faq' => ['seo_faq', 'faq'],
            'final_download_cta', 'final_cta' => ['final_download_cta', 'final_cta', 'download_cta', 'conversion_cta', 'download', 'cta', 'reward_promotion', 'promotion', 'newsletter_cta'],
            default => [$key],
        };

        return \array_values(\array_unique(\array_filter($aliases, static fn(string $alias): bool => $alias !== '')));
    }

    /**
     * @return list<string>
     */
    private function extractFactSignals(string $fact): array
    {
        $signals = [];
        foreach ([
            '印度', '棋牌', '下载', 'apk', 'SEO', 'seo', 'Teen Patti', 'rummy', 'casino', '安全', 'trust', 'FAQ', 'faq',
        ] as $candidate) {
            if ($candidate !== '' && \mb_stripos($fact, $candidate) !== false) {
                $signals[] = \mb_strtolower($candidate);
            }
        }

        return \array_values(\array_unique($signals));
    }

    /**
     * @return list<string>
     */
    private function findForbiddenHits(array $sourceTruth, string $allCopy): array
    {
        $hits = [];
        foreach (\is_array($sourceTruth['must_not_do'] ?? null) ? $sourceTruth['must_not_do'] : [] as $rule) {
            $rule = \trim((string)$rule);
            if ($rule === '') {
                continue;
            }
            if (\mb_strpos(\mb_strtolower($allCopy), \mb_strtolower($rule)) !== false) {
                $hits[] = $rule;
            }
        }

        return $hits;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function detectFallbackUsed(array $payload): bool
    {
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            return false;
        }

        return \str_contains($json, '[假设]') || \str_contains($json, '[unknown]');
    }
}
