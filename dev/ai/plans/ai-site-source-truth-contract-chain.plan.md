# AI 建站：源事实合同链路 — 分步落地计划

## 依赖关系

```
Phase-1 ──→ Phase-2 ──→ Phase-3 ──→ Phase-4 ──→ Phase-5 ──→ Phase-6
  (无依赖)    (依赖P1)    (依赖P2)    (依赖P3)    (依赖P2+3)   (依赖P1-5)
```

**必须先做 Phase-1，再做 Phase-2**，后续阶段顺序可调。每个阶段内的小步也按编号顺序执行。

---

# Phase-1：止血（无前置依赖）

## 1.1 移除 "2-3 blocks only" 硬限制

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php`

### Step 1.1.1 — 新增 block budget 方法

在第 600 行附近（`hasStageOneThemeCheckpoint` 后面），新增：

```php
/**
 * @param array<string, mixed> $scope
 * @return array{min:int, max:int, required:list<string>}
 */
private function resolveStageOneBlockBudget(string $pageType, array $scope): array
{
    $required = [];
    if ($pageType === 'home_page') {
        $required = [
            'hero_download',
            'game_showcase_or_features',
            'trust_security',
            'final_download_cta',
        ];
    } elseif (\in_array($pageType, ['about_page', 'contact_page'], true)) {
        $required = [];
    }
    return [
        'min' => $pageType === 'home_page' ? 5 : 3,
        'max' => $pageType === 'home_page' ? 7 : 5,
        'required' => $required,
    ];
}
```

### Step 1.1.2 — 替换 4 处 Prompt

**替换①**（行 1858）：

原文：
```
'JSON size rule: produce 2-3 blocks only; even legal/support pages must stay compact and split dense policy details into concise block fields.',
```

改：
```
'Block budget: min=3, max=5, required=' . \json_encode([], \JSON_UNESCAPED_UNICODE) . '; even legal/support pages must stay compact and split dense policy details into concise block fields.',
```

---

**替换②**（行 1880）：

原文：
```
'- page_design_plan.section_flow must describe the visual rhythm across 2-3 blocks: opening impact, middle information/proof layer, and closing action or reassurance layer.',
```

改：
```
'- page_design_plan.section_flow must describe the visual rhythm across the block sequence: opening impact, middle information/proof layer, and closing action or reassurance layer.',
```

---

**替换③**（行 1898）：

原文：
```
'Hard rules: output 2-3 blocks only; each block exactly 3 field_plan rows; ...'
```

改：
```
'Hard rules: output blocks according to budget; each block exactly 3 field_plan rows; ...'
```

---

**替换④**（行 2785，全局方案 Prompt）：

原文：
```
'- Output budget is mandatory: each selected page MUST contain 2-3 blocks only; ...'
```

改：
```
'- Output budget is mandatory: home_page MUST contain 5-7 blocks, other pages 3-5 blocks; ...'
```

### Step 1.1.3 — 将 budget 注入 page-level Prompt

在 `buildAiStageOnePagePlanPrompt()` 方法（行 1840 附近），先在函数开头解析 budget，再拼入 Prompt。修改步骤：

a) 在方法开头添加：
```php
$blockBudget = $this->resolveStageOneBlockBudget($pageType, $scope);
```

b) 在 Prompt 的 `'Hard rules: ...'` 行（1898）之前插入：
```php
'Block budget: min=' . $blockBudget['min'] . ', max=' . $blockBudget['max'] . ', required=' . \json_encode($blockBudget['required'], \JSON_UNESCAPED_UNICODE) . '.',
'You MUST include every required_block_key unless SourceTruthContract marks it irrelevant.',
```

---

## 1.2 Checkpoint signature 追加字段

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php`

**位置：** `buildStageOneCheckpointSignature()` 方法，行 571-579

在 `'target_scope' => $targetScope,` 后面追加：

```php
'reference_image_insights_signature' => (string)($scope['reference_image_insights_signature'] ?? ''),
'source_truth_contract_hash'          => (string)($scope['source_truth_contract_hash'] ?? ''),
'asset_manifest_hash'                 => (string)($scope['asset_manifest_hash'] ?? ''),
'contract_schema_version'             => 'source_truth_v1',
```

---

## 1.3 QA 急拦截

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/ContractQaReportBuilder.php`

### Step 1.3.1 — 新增 content quality finding 工厂方法

在 `finding()` 方法（行 195）后面追加：

```php
/**
 * @param array<string, mixed> $args
 * @return list<array<string, mixed>>
 */
public function buildContentQualityFindings(array $args): array
{
    $findings = [];

    // missing_must_include_fact
    foreach (\is_array($args['missing_facts'] ?? null) ? $args['missing_facts'] : [] as $factId => $factText) {
        $findings[] = $this->finding(
            'error',
            'content_quality',
            $args['contract_type'] ?? 'source_truth',
            "Missing must-include fact [{$factId}]: {$factText}",
            'content_quality.missing_must_include_fact'
        );
    }

    // missing_required_block
    foreach (\is_array($args['missing_blocks'] ?? null) ? $args['missing_blocks'] : [] as $blockKey) {
        $findings[] = $this->finding(
            'error',
            'content_quality',
            $args['contract_type'] ?? 'page_contract',
            "Missing required block: {$blockKey}",
            'content_quality.missing_required_block'
        );
    }

    // fallback_plan_used
    if (!empty($args['fallback_used'])) {
        $findings[] = $this->finding(
            'warning',
            'content_quality',
            $args['contract_type'] ?? 'execution',
            'Stage-1 fallback plan was used. Content quality may be degraded.',
            'content_quality.fallback_plan_used'
        );
    }

    return $findings;
}
```

### Step 1.3.2 — 使 content_quality findings 能触发 FAIL

当前 `buildGates()` 方法（行 296）中 `content_quality` gate 已经通过 `gateFromContentFindings()` 处理。只需确保调用方传入的 `$contentQualityFindings` 中 `severity=error` 即可触发 FAIL，无需额外改动。

**确认：** `resolveReportStatus()`（行 234）中，`error_count > 0` → `STATUS_FAIL`。`buildContentQualityPayload()`（行 263）使用 `resolveReportStatus()`。`buildGates()` 的 gate 使用 `resolveReportStatus()`。链路完整，不需改。

### Step 1.3.3 — 新增 SourceTruthCoverageLinter 调用出口

`build()` 方法的 `$contentQualityFindings` 参数已存在。后续 Phase-2 的 `SourceTruthCoverageLinter` 输出直接传到此处：

```php
// 调用方改为：
$coverageLint = $sourceTruthLinter->lint($sourceTruthContract, $pagePlan, $blockPlans);
$contentQualityFindings = array_merge(
    $contentQualityFindings,
    $this->buildContentQualityFindings([
        'contract_type' => 'source_truth',
        'missing_facts' => $coverageLint['missing_facts'] ?? [],
        'missing_blocks' => $coverageLint['missing_blocks'] ?? [],
        'fallback_used' => $coverageLint['fallback_used'] ?? false,
    ])
);
```

---

# Phase-2：源事实合同（依赖 Phase-1）

## 2.1 新增 `SourceTruthContractBuilder.php`

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/SourceTruthContractBuilder.php`

```php
<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceTruthContractBuilder
{
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

        return [
            'contract_type' => 'source_truth',
            'version' => 'v1',
            'content_locale' => $contentLocale,
            'input_locale' => $userLocale,
            'site_identity' => [
                'site_name' => \trim((string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')),
                'brand_terms' => $this->extractBrandTerms($brief),
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

        // 从 brief 提取事实
        foreach (\explode("\n", $brief) as $line) {
            $line = \trim($line);
            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }
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

        // 从 instruction 提取额外事实
        if ($instruction !== '') {
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
    private function extractKeywords(string $brief, string $instruction): array
    {
        // 关键词提取逻辑 — 从 brief/instruction 中识别重要名词
        $keywords = [];
        $combined = $brief . "\n" . $instruction;

        // 提取明显的关键词片段
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

        // 检测是否为 APK/download 类站点，自动追加
        if (\preg_match('/(?:APK|download|推广)/iu', $brief)) {
            $forbidden[] = 'generic corporate profile site';
            $forbidden[] = 'flat blue SaaS style';
        }

        return $forbidden;
    }

    /**
     * @return list<string>
     */
    private function resolveRequiredHomeBlocks(string $brief, string $instruction): array
    {
        // 根据 brief 特征判断需要的首页块
        $blocks = ['hero_download', 'final_download_cta'];

        if (\preg_match('/(?:游戏|game|棋牌|card|Teen\s*Patti|rummy)/iu', $brief)) {
            $blocks[] = 'game_showcase';
        }
        if (\preg_match('/(?:信任|trust|安全|secure|放心)/iu', $brief)) {
            $blocks[] = 'trust_security';
        }
        if (\preg_match('/(?:SEO|seo|关键词|keyword)/iu', $brief)) {
            $blocks[] = 'seo_faq';
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function extractBrandTerms(string $brief): array
    {
        $terms = [];
        // 尝试提取品牌名
        if (\preg_match('/(?:推广|推介|介绍)\s*(\S{1,20})/iu', $brief, $m)) {
            $terms[] = \trim($m[1]);
        }
        return $terms;
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
```

---

## 2.2 新增 `SourceTruthContractValidator.php`

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/SourceTruthContractValidator.php`

```php
<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceTruthContractValidator
{
    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool, errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];

        if (($contract['contract_type'] ?? '') !== 'source_truth') {
            $errors[] = 'contract_type must be "source_truth"';
        }

        if (empty($contract['site_identity']['site_name'])) {
            $errors[] = 'site_identity.site_name is required';
        }

        $facts = \is_array($contract['must_include_facts'] ?? null) ? $contract['must_include_facts'] : [];
        if ($facts === []) {
            $errors[] = 'must_include_facts must not be empty';
        }
        foreach ($facts as $i => $fact) {
            if (!\is_array($fact)) {
                $errors[] = "must_include_facts[{$i}] must be an object";
                continue;
            }
            if (empty($fact['id'])) {
                $errors[] = "must_include_facts[{$i}].id is required";
            }
            if (empty($fact['text'])) {
                $errors[] = "must_include_facts[{$i}].text is required";
            }
            $weight = (int)($fact['weight'] ?? 0);
            if ($weight < 1 || $weight > 10) {
                $errors[] = "must_include_facts[{$i}].weight must be 1-10, got {$weight}";
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
```

---

## 2.3 新增 `SourceTruthCoverageLinter.php`

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/SourceTruthCoverageLinter.php`

```php
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

        // 提取所有文案
        $allCopy = $this->extractAllCopy($pagePlan, $blockPlans);

        // 检查 must_include_facts 覆盖率
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

        // 检查 required_home_blocks
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

        // 检查 must_not_do 是否出现
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
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $blockPlans
     * @return string 提取出的所有可见文案
     */
    private function extractAllCopy(array $pagePlan, array $blockPlans): string
    {
        $parts = [];

        // 页面层级文案
        foreach (['page_goal', 'theme_alignment_summary'] as $key) {
            $text = \trim((string)($pagePlan[$key] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        // 块层级文案
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
            // field_plan
            foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
                if (\is_array($field)) {
                    $text = \trim((string)($field['sample'] ?? ''));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }
            // execution_script
            $script = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
            $text = \trim((string)($script['core_copy'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return \implode(' ', $parts);
    }

    /**
     * @return array<string, string> factId => factText
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
            // 检查事实原文或其核心名词是否出现在文案中
            $found = $this->textContainsFact($allCopy, $factText);
            if (!$found) {
                $missing[(string)($fact['id'] ?? 'unknown')] = $factText;
            }
        }
        return $missing;
    }

    private function textContainsFact(string $haystack, string $needle): bool
    {
        // 尝试完整匹配
        if (\mb_strpos(\mb_strtolower($haystack), \mb_strtolower($needle)) !== false) {
            return true;
        }
        // 提取核心名词尝试匹配
        $nouns = \array_filter(\explode(' ', \preg_replace('/[^\w\s]/u', '', $needle)), fn(string $w): bool => \mb_strlen($w) > 2);
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

    private function detectFallbackUsed(array $pagePlan): bool
    {
        $json = \json_encode($pagePlan, \JSON_UNESCAPED_UNICODE);
        return \str_contains($json, '[假设]') || \str_contains($json, '[假设]') || \str_contains($json, '[unknown]');
    }
}
```

---

## 2.4 接入执行链路

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php`

**位置：** `buildPlanArtifactsByStagedAiStream()` 方法，参考图理解完成后（行 380 之后）、requirement_expand（行 412）之前。

在第 380 行 `}`（参考图理解的闭合括号）和第 381 行 `$oneLineRequirement = ...` 之间插入：

```php
// ── 源事实合同 ──
$sourceTruthContract = $this->getSourceTruthContractBuilder()->build(
    $scope,
    $websiteProfile,
    \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [],
    $instruction,
    $pageTypes,
    $contentLocale
);
$scope['source_truth_contract'] = $sourceTruthContract;
$scope['source_truth_contract_hash'] = \sha1((string)\json_encode($sourceTruthContract, \JSON_UNESCAPED_UNICODE));
```

### 添加依赖注入

在类中新增字段和 getter：

```php
private ?SourceTruthContractBuilder $sourceTruthContractBuilder = null;

private function getSourceTruthContractBuilder(): SourceTruthContractBuilder
{
    if ($this->sourceTruthContractBuilder === null) {
        $this->sourceTruthContractBuilder = \Weline\Framework\Manager\ObjectManager::getInstance(SourceTruthContractBuilder::class);
    }
    return $this->sourceTruthContractBuilder;
}
```

---

## 2.5 Stage-1/Stage-2 Prompt 注入

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php`

### 2.5a — page-level Prompt 追加

在 `buildAiStageOnePagePlanPrompt()` 中，`'Self-check before return:'` 行（1899）之后追加：

```php
($sourceTruthContract = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : []) !== []
    ? 'SourceTruthContract is non-negotiable. The following facts MUST appear in visible copy: '
        . \json_encode(
            \array_map(fn(array $f): string => $f['text'] ?? '',
                \is_array($sourceTruthContract['must_include_facts'] ?? null) ? $sourceTruthContract['must_include_facts'] : []),
            \JSON_UNESCAPED_UNICODE
        )
        . ' Visual must-honor: '
        . \json_encode($sourceTruthContract['visual_must_honor'] ?? [], \JSON_UNESCAPED_UNICODE)
        . ' Conversion goals: '
        . \json_encode($sourceTruthContract['conversion_goals'] ?? [], \JSON_UNESCAPED_UNICODE)
    : '',
```

### 2.5b — Stage-2 Prompt 追加

找到 Stage-2 块生成 Prompt 所在位置（搜索 `Stage-2` 或 `block_visual_contract`），在每个块 Prompt 末尾追加：

```php
'SourceTruthContract facts for context: '
    . \json_encode(
        \array_map(fn(array $f): string => $f['text'] ?? '',
            \is_array($scope['source_truth_contract']['must_include_facts'] ?? null) ? $scope['source_truth_contract']['must_include_facts'] : []),
        \JSON_UNESCAPED_UNICODE
    ),
'This block must address at least one SourceTruthContract fact. Cite which fact_id(s) this block fulfills.',
'Do not compress unrelated facts into one vague block.',
```

---

# Phase-3：视觉合同（依赖 Phase-2）

## 3.1 升级 ReferenceImageInsight schema

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteReferenceImageInsightService.php`

### 3.1a — 扩展 Prompt schema

行 316，在 schema JSON 末尾 `per_image...[xxx]...}}` 之后追加：

```json
,"visual_contract":{"hero_composition":{"nav":"string","headline":"string","media":"string","side_cards":"string","background":"string"},"cta_rule":{"primary_color":"string","label_intent":"string","must_be_above_fold":true},"asset_usage_rule":{"reference_image_role":"style_reference_only|hero_reference","max_same_image_usage":1,"forbid_repeated_raw_screenshot":true},"forbidden_visuals":["string"]}
```

规则追加：
```
'- visual_contract is a structured, executable visual constraint. Each field describes what MUST be preserved from the reference image into the final design.',
'- hero_composition describes the above-fold layout structure.',
'- cta_rule describes button color, label intent, and placement constraint.',
'- asset_usage_rule describes how the reference image assets should be used.',
'- forbidden_visuals lists visual patterns from the reference that MUST NOT be copied.',
```

### 3.1b — 扩展 normalizeInsights()

在 `normalizeInsights()`（行 612）中，现有字段后追加：

```php
$visualContract = \is_array($insights['visual_contract'] ?? null) ? $insights['visual_contract'] : [];
'visual_contract' => $visualContract === [] ? [] : [
    'hero_composition' => \is_array($visualContract['hero_composition'] ?? null)
        ? [
            'nav' => \trim((string)($visualContract['hero_composition']['nav'] ?? '')),
            'headline' => \trim((string)($visualContract['hero_composition']['headline'] ?? '')),
            'media' => \trim((string)($visualContract['hero_composition']['media'] ?? '')),
            'side_cards' => \trim((string)($visualContract['hero_composition']['side_cards'] ?? '')),
            'background' => \trim((string)($visualContract['hero_composition']['background'] ?? '')),
        ]
        : [],
    'cta_rule' => \is_array($visualContract['cta_rule'] ?? null)
        ? [
            'primary_color' => \trim((string)($visualContract['cta_rule']['primary_color'] ?? '')),
            'label_intent' => \trim((string)($visualContract['cta_rule']['label_intent'] ?? '')),
            'must_be_above_fold' => (bool)($visualContract['cta_rule']['must_be_above_fold'] ?? true),
        ]
        : [],
    'asset_usage_rule' => \is_array($visualContract['asset_usage_rule'] ?? null)
        ? [
            'reference_image_role' => \trim((string)($visualContract['asset_usage_rule']['reference_image_role'] ?? 'style_reference_only')),
            'max_same_image_usage' => (int)($visualContract['asset_usage_rule']['max_same_image_usage'] ?? 1),
            'forbid_repeated_raw_screenshot' => (bool)($visualContract['asset_usage_rule']['forbid_repeated_raw_screenshot'] ?? true),
        ]
        : [],
    'forbidden_visuals' => $this->normalizeStringList($visualContract['forbidden_visuals'] ?? [], 6),
],
```

## 3.2 视觉合同注入 Theme Prompt

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php`

在 `buildAiStageOneRequirementExpansionPrompt()`（生成 theme_design 的 Prompt）中，在 reference image insights 段落附近追加：

```php
'Visual contract rules (non-negotiable when present): '
    . \json_encode(
        \is_array($scope['reference_image_insights']['visual_contract'] ?? null)
            ? $scope['reference_image_insights']['visual_contract']
            : [],
        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
    ),
'You MUST convert visual_contract into theme_design.art_direction and the page design plans.',
'Every page_design_plan must cite which visual_contract items it implements.',
'Every block must map at least one visual_contract item or explicitly state not_applicable.',
'Do not output visual patterns listed in forbidden_visuals.',
```

## 3.3 QA 视觉覆盖率检查

### 文件：`app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/ContractQaReportBuilder.php`

在 `buildContentQualityFindings()` 方法中追加：

```php
// visual_contract 未使用
if (!empty($args['visual_contract_unused'])) {
    foreach (\is_array($args['visual_contract_unused']) ? $args['visual_contract_unused'] : [] as $item) {
        $findings[] = $this->finding(
            'warning',
            'content_quality',
            $args['contract_type'] ?? 'page_contract',
            "Visual contract item not used in any block: {$item}",
            'content_quality.visual_contract_not_used'
        );
    }
}

// forbidden_visuals 命中
foreach (\is_array($args['forbidden_visuals_hit'] ?? null) ? $args['forbidden_visuals_hit'] : [] as $hit) {
    $findings[] = $this->finding(
        'error',
        'content_quality',
        $args['contract_type'] ?? 'theme_design',
        "Forbidden visual pattern detected: {$hit}",
        'content_quality.forbidden_visuals_violation'
    );
}
```

---

# Phase-4：图片资产治理（依赖 Phase-3）

## 4.1 升级 AssetManifestService

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AiSiteAssetManifestService.php`

### 新增常量和方法

在 `SLOT_TYPES` 常量后追加：

```php
private const MAX_USAGE_DEFAULT = 1;
```

### 新增 `forBlock()` 方法

```php
/**
 * @param array<string, mixed> $manifest
 * @return list<array<string, mixed>>
 */
public function forBlock(array $manifest, string $pageType, string $blockKey): array
{
    $normalized = $this->normalize($manifest);
    $allowed = [];
    foreach ($normalized['slots'] ?? [] as $slot) {
        if (!\is_array($slot)) {
            continue;
        }
        $allowedPages = \is_array($slot['allowed_pages'] ?? null) ? $slot['allowed_pages'] : ['*'];
        if (!\in_array('*', $allowedPages, true) && !\in_array($pageType, $allowedPages, true)) {
            continue;
        }
        $allowedBlocks = \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : ['*'];
        if (!\in_array('*', $allowedBlocks, true) && !\in_array($blockKey, $allowedBlocks, true)) {
            continue;
        }
        $allowed[] = $slot;
    }
    return $allowed;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $usedAssetIds
 * @return list<string> 违反 max_usage 的 asset_id 列表
 */
public function validateBlockUsage(array $manifest, string $blockKey, array $usedAssetIds): array
{
    $violations = [];
    $normalized = $this->normalize($manifest);
    foreach ($usedAssetIds as $assetId) {
        $slot = null;
        foreach ($normalized['slots'] ?? [] as $s) {
            if (\is_array($s) && ($s['slot_id'] ?? '') === $assetId) {
                $slot = $s;
                break;
            }
        }
        if ($slot === null) {
            continue;
        }
        $maxUsage = (int)($slot['max_usage'] ?? self::MAX_USAGE_DEFAULT);
        $allowedBlocks = \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : [];

        // 统计该 asset 在 allowed_blocks 中出现的次数
        $usageCount = 0;
        foreach ($normalized['slots'] ?? [] as $s2) {
            if (\is_array($s2) && ($s2['slot_id'] ?? '') === $assetId) {
                ++$usageCount;
            }
        }
        if ($usageCount > $maxUsage) {
            $violations[] = "{$assetId}: used {$usageCount} times, max {$maxUsage}";
        }
    }
    return $violations;
}
```

### 更新 `normalizeSlot()` 保持额外字段

当前 `normalizeSlot()` 方法可能丢弃 `allowed_pages`、`allowed_blocks`、`max_usage` 等字段。需确认它保留至少：

```php
// 在 normalizeSlot() 返回值中保留:
'allowed_pages' => \is_array($slot['allowed_pages'] ?? null) ? $slot['allowed_pages'] : ['*'],
'allowed_blocks' => \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : ['*'],
'max_usage' => (int)($slot['max_usage'] ?? self::MAX_USAGE_DEFAULT),
'reuse_policy' => \trim((string)($slot['reuse_policy'] ?? 'do_not_repeat_raw_image')),
```

## 4.2 Stage-2 块生成传参

在 Stage-2 块生成 Prompt 中，将 `$allowedAssets = $this->getAssetManifestService()->forBlock($manifest, $pageType, $blockKey)` 结果传入 Prompt，通知模型哪些图片可用、最多用几次。

## 4.3 新增 `VisualAssetUsageValidator.php`

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/VisualAssetUsageValidator.php`

```php
<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class VisualAssetUsageValidator
{
    /**
     * @param array<string, mixed> $assetManifest
     * @return array{valid:bool, violations:list<string>}
     */
    public function validate(array $assetManifest, string $renderedHtml): array
    {
        $violations = [];

        // 提取所有图片引用
        $srcUsages = $this->extractImageSources($renderedHtml);

        // 检查相同 src 使用次数
        foreach ($srcUsages as $src => $count) {
            $maxUsage = $this->resolveMaxUsage($assetManifest, $src);
            if ($count > $maxUsage) {
                $violations[] = "Image {$src} used {$count} times (max {$maxUsage})";
            }
        }

        return [
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @return array<string, int> src => count
     */
    private function extractImageSources(string $html): array
    {
        $sources = [];

        // <img src="...">
        \preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/si', $html, $matches);
        foreach ($matches[1] ?? [] as $src) {
            $src = \trim($src);
            if ($src !== '') {
                $sources[$src] = ($sources[$src] ?? 0) + 1;
            }
        }

        // CSS url(...)
        \preg_match_all('/url\(["\']?([^)"\']+)["\']?\)/si', $html, $matches);
        foreach ($matches[1] ?? [] as $url) {
            $url = \trim($url);
            if ($url !== '' && !\str_starts_with($url, 'data:')) {
                $sources[$url] = ($sources[$url] ?? 0) + 1;
            }
        }

        return $sources;
    }

    private function resolveMaxUsage(array $manifest, string $src): int
    {
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : $manifest;
        foreach ($slots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '' && \str_contains($src, $finalUrl)) {
                return (int)($slot['max_usage'] ?? 1);
            }
        }
        return 1;
    }
}
```

---

# Phase-5：Banner Recipe（依赖 Phase-2 + Phase-3）

## 5.1 新增 `BlockRecipeRegistry.php`

**文件：** `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/BlockRecipeRegistry.php`

```php
<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BlockRecipeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $recipes;

    public function __construct()
    {
        $this->recipes = $this->loadDefaultRecipes();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        return $this->recipes[$key] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getAllowedRecipes(string $blockKey, string $pageType): array
    {
        $allowed = [];
        foreach ($this->recipes as $key => $recipe) {
            $pageTypes = \is_array($recipe['page_types'] ?? null) ? $recipe['page_types'] : ['*'];
            if (\in_array('*', $pageTypes, true) || \in_array($pageType, $pageTypes, true)) {
                $allowed[] = $key;
            }
        }
        return $allowed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPromptContext(string $blockKey, string $pageType): array
    {
        $allowed = $this->getAllowedRecipes($blockKey, $pageType);
        $context = [];
        foreach ($allowed as $key) {
            $recipe = $this->recipes[$key] ?? [];
            $context[$key] = [
                'required_slots' => \array_keys($recipe['required_slots'] ?? []),
                'layout' => $recipe['layout'] ?? [],
                'forbidden' => $recipe['forbidden'] ?? [],
            ];
        }
        return $context;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadDefaultRecipes(): array
    {
        return [
            'hero_download_gaming_apk' => [
                'page_types' => ['home_page', 'game_landing'],
                'required_slots' => [
                    'eyebrow' => 'SEO / APK / India keyword line',
                    'headline' => 'APK download headline with game name',
                    'subheadline' => 'Game + bonus + trust summary',
                    'primary_cta' => 'Download APK button',
                    'secondary_trust' => 'Secure / smooth / fast app experience',
                    'hero_media' => 'Poster or app visual',
                    'floating_motifs' => 'Cards / coins / chips',
                ],
                'layout' => [
                    'desktop' => 'Two-column or centered poster with surrounding feature cards',
                    'mobile' => 'Headline, CTA, poster, trust chips stacked',
                    'max_hero_height' => '900px',
                    'above_fold_cta' => true,
                ],
                'forbidden' => [
                    'unframed raw image',
                    'flat single-color background without texture',
                    'generic three-card SaaS hero',
                ],
            ],
            'game_showcase_grid' => [
                'page_types' => ['home_page'],
                'required_slots' => [
                    'section_title' => 'Games category heading',
                    'game_cards' => 'Array of game cards with icon, name, description',
                    'cta_link' => 'View all or Play now link',
                ],
                'layout' => ['desktop' => '3-4 column grid', 'mobile' => 'single column scroll'],
                'forbidden' => ['single row without scrolling on mobile'],
            ],
            'apk_install_steps' => [
                'page_types' => ['home_page', 'game_landing', 'about_page'],
                'required_slots' => [
                    'step_1' => 'Pick APK',
                    'step_2' => 'Install',
                    'step_3' => 'Join table',
                    'step_4' => 'Play',
                ],
                'layout' => ['desktop' => 'horizontal numbered steps', 'mobile' => 'vertical numbered list'],
                'forbidden' => ['generic text list without numbered steps'],
            ],
            'trust_badge_strip' => [
                'page_types' => ['home_page', 'about_page'],
                'required_slots' => [
                    'badge_1' => 'Trust badge with icon and label',
                    'badge_2' => 'Security badge',
                    'badge_3' => 'User count or rating badge',
                ],
                'layout' => ['desktop' => 'horizontal strip', 'mobile' => '2x2 grid'],
                'forbidden' => [],
            ],
            'final_download_cta_luxury' => [
                'page_types' => ['home_page', 'game_landing'],
                'required_slots' => [
                    'headline' => 'Final download call-to-action',
                    'subheadline' => 'Reinforce trust and urgency',
                    'primary_cta' => 'Download APK button',
                    'trust_line' => 'Security and compatibility note',
                ],
                'layout' => ['desktop' => 'full-width centered', 'mobile' => 'full-width stacked'],
                'forbidden' => ['bare text link as primary CTA'],
            ],
        ];
    }
}
```

## 5.2 Stage-2 Prompt 改造

在 Stage-2 块生成 Prompt 中，注入 Recipe 上下文（`$recipeRegistry->getPromptContext($blockKey, $pageType)`），并在 Prompt 中追加：

```text
Choose one recipe from the allowed_recipes list above.
Fill every required_slot with visible content using the Website content locale.
Map every CTA-related slot to a concrete download/install action label.
Use theme tokens for colors, radii, fonts — do not invent new CSS values.
State which recipe key was chosen in the block_visual_contract.
```

---

# Phase-6：回归测试集（依赖以上全部）

## 目录结构

```
test/Functional/AiSite/
├── AbstractAiSiteFunctionalTest.php    # 基类
├── TestCaseIndiaCardGameAPK.php        # 1. 印度棋牌APK
├── TestCaseLocalRestaurant.php         # 2. 本地餐厅
├── TestCaseEducation.php               # 3. 教育培训
├── TestCaseSaaS.php                    # 4. SaaS官网
├── TestCaseLawFirm.php                 # 5. 律师事务所
├── TestCaseTravelLanding.php           # 6. 旅游落地页
├── TestCaseGamePromo.php               # 7. 游戏推广
├── TestCaseEcommerceHome.php           # 8. 电商首页
├── TestCaseMedicalConsultation.php     # 9. 医疗咨询
└── TestCaseB2BFactory.php             # 10. B2B工厂站
```

## 基类 `AbstractAiSiteFunctionalTest.php` 核心结构

```php
abstract class AbstractAiSiteFunctionalTest extends \PHPUnit\Framework\TestCase
{
    abstract protected function getTestCaseData(): array;
    // returns ['brief' => '...', 'instruction' => '...', 'expected_keywords' => [...], 'expected_home_blocks' => [...], 'expected_locale' => '...']

    protected function buildSourceTruth(): SourceTruthContractBuilder { ... }
    protected function buildContractValidator(): SourceTruthContractValidator { ... }
    protected function buildCoverageLinter(): SourceTruthCoverageLinter { ... }

    public function testSourceTruthContracts(): void
    {
        $data = $this->getTestCaseData();
        $builder = $this->buildSourceTruth();
        $contract = $builder->build(
            ['brief_description' => $data['brief']],
            [],
            [],
            $data['instruction'] ?? '',
            $data['page_types'] ?? ['home_page'],
            $data['expected_locale'] ?? 'en_US'
        );

        // 验证合同完整性
        $validator = $this->buildContractValidator();
        $result = $validator->validate($contract);
        $this->assertTrue($result['valid'], \implode("\n", $result['errors']));

        // 验证关键词覆盖
        $this->assertNotEmpty($contract['must_include_facts']);
        $this->assertNotEmpty($contract['conversion_goals']);
    }

    public function testSourceTruthCoverageLint(): void
    {
        $data = $this->getTestCaseData();
        // ... 跑 linter 验证覆盖率
    }
}
```

## 运行方式

```bash
# 跑全部 AI 建站测试
php bin/w test:run --directory=app/code/GuoLaiRen/PageBuilder/test/Functional/AiSite

# 跑单个测试
php bin/w test:run --filter=testSourceTruthContracts --class=TestCaseIndiaCardGameAPK
```

---

# 执行顺序建议

| 顺序 | 阶段 | 估计时间 | 可并行 |
|------|------|----------|--------|
| 1 | Phase-1 止血（3 步） | 1-2 天 | 否（顺序依赖） |
| 2 | Phase-2 源事实合同（5 步） | 2-3 天 | Step 2.1-2.3 可并行写 |
| 3 | Phase-3 视觉合同（3 步） | 2-3 天 | 可与 Phase-2 并行 |
| 4 | Phase-4 图片治理（3 步） | 2-4 天 | 依赖 Phase-3 |
| 5 | Phase-5 Banner Recipe（2 步） | 3-5 天 | 依赖 Phase-2+3 |
| 6 | Phase-6 回归测试 | 持续 | 每完成一个 Phase 追加测试 |

**总计：约 10-17 天，其中核心链路（Phase-1 + Phase-2）占 3-5 天。**
