<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceTruthCoverageLinter
{
    private const MIN_COVERAGE = 0.95;

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

        $missedFacts = $this->findMissingFacts($sourceTruth, $allCopy);
        $totalFacts = \count(\is_array($sourceTruth['must_include_facts'] ?? null) ? $sourceTruth['must_include_facts'] : []);
        $coveredFacts = $totalFacts - \count($missedFacts);
        $coverage = $totalFacts > 0 ? ($coveredFacts / $totalFacts) : 1.0;

        foreach ($missedFacts as $factId => $factText) {
            $findings[] = [
                'severity' => $coverage < self::MIN_COVERAGE ? 'error' : 'warning',
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
                'severity' => 'error',
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
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                $allBlocks[] = $block;
            }
            if ($pageType === 'home_page') {
                foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                    $homeBlocks[] = $block;
                }
            }
        }

        $allCopy = $this->extractAllCopy($aggregated, ['blocks' => $allBlocks]);
        $missedFacts = $this->findMissingFacts($sourceTruth, $allCopy);
        $totalFacts = \count(\is_array($sourceTruth['must_include_facts'] ?? null) ? $sourceTruth['must_include_facts'] : []);
        $coveredFacts = $totalFacts - \count($missedFacts);
        $coverage = $totalFacts > 0 ? ($coveredFacts / $totalFacts) : 1.0;

        $findings = [];
        foreach ($missedFacts as $factId => $factText) {
            $findings[] = [
                'severity' => $coverage < self::MIN_COVERAGE ? 'error' : 'warning',
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
                'severity' => 'error',
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
        }

        return \implode(' ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function findMissingFacts(array $sourceTruth, string $allCopy): array
    {
        $missing = [];
        foreach (\is_array($sourceTruth['must_include_facts'] ?? null) ? $sourceTruth['must_include_facts'] : [] as $fact) {
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
        if (\mb_strpos(\mb_strtolower($haystack), \mb_strtolower($needle)) !== false) {
            return true;
        }
        $nouns = \array_filter(
            \explode(' ', (string)(\preg_replace('/[^\w\s]/u', '', $needle) ?? '')),
            static fn(string $w): bool => \mb_strlen($w) > 2
        );
        $haystackLower = \mb_strtolower($haystack);
        $matched = 0;
        foreach ($nouns as $noun) {
            if (\mb_strpos($haystackLower, \mb_strtolower($noun)) !== false) {
                ++$matched;
            }
        }

        return $matched >= \max(1, (int)(\count($nouns) * 0.5));
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
            $found = false;
            foreach ($existing as $e) {
                if (\str_contains($e, $key) || \str_contains($key, $e)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $key;
            }
        }

        return $missing;
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
