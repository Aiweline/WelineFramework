<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContentManifestLinter;

final class AiSiteStageOneContractValidator
{
    public function __construct(
        private readonly ?AiSiteStageOneContractService $contractService = null,
        private readonly ?AiSitePageRouteContractService $pageRouteContractService = null
    ) {
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validateFullPlan(array $planJson, array $contract, array $context = []): array
    {
        $issues = [];
        foreach (\is_array($contract['theme_required_sections'] ?? null) ? $contract['theme_required_sections'] : [] as $sectionKey) {
            $sectionKey = \trim((string)$sectionKey);
            if ($sectionKey !== '' && !\is_array($planJson[$sectionKey] ?? null)) {
                $issues[] = $this->issue('missing_section', $sectionKey, 'high', ['expected' => 'object']);
            }
        }

        $this->validateThemeDesign($planJson, $contract, $issues);
        $this->validateLinkRequirements($planJson, $contract, $issues);

        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        foreach (\is_array($contract['page_types'] ?? null) ? $contract['page_types'] : [] as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }
            $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            $pageReport = $this->validatePagePlan($pageType, $page, $contract, $context);
            foreach (\is_array($pageReport['issues'] ?? null) ? $pageReport['issues'] : [] as $issue) {
                if (\is_array($issue)) {
                    $issues[] = $issue;
                }
            }
        }

        return $this->report($planJson, $contract, $issues, $context);
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validatePagePlan(string $pageType, array $pagePlan, array $contract, array $context = []): array
    {
        $issues = [];
        $pageContract = $this->contractService()->pageContract($contract, $pageType);
        if ($pagePlan === []) {
            $issues[] = $this->issue('missing_page', 'pages.' . $pageType, 'high', ['page_type' => $pageType]);

            return $this->report($pagePlan, $contract, $issues, $context);
        }

        foreach (['page_goal', 'theme_alignment_summary'] as $field) {
            $value = \trim((string)($pagePlan[$field] ?? ''));
            if ($value === '') {
                $issues[] = $this->issue('instruction_like_or_empty', 'pages.' . $pageType . '.' . $field, 'medium', [
                    'page_type' => $pageType,
                    'snippet' => $this->clip($value),
                ]);
            } elseif ($this->isPromptLikeText($value)) {
                $issues[] = $this->issue('instruction_like_or_empty', 'pages.' . $pageType . '.' . $field, 'medium', [
                    'page_type' => $pageType,
                    'snippet' => $this->clip($value),
                ]);
            }
        }

        if (!\is_array($pagePlan['page_design_plan'] ?? null) || $pagePlan['page_design_plan'] === []) {
            $issues[] = $this->issue('missing_page_design_plan', 'pages.' . $pageType . '.page_design_plan', 'high', [
                'page_type' => $pageType,
            ]);
        }

        $blocks = \is_array($pagePlan['blocks'] ?? null) ? \array_values($pagePlan['blocks']) : [];
        $minBlocks = \max(0, (int)($pageContract['min_blocks'] ?? 0));
        $maxBlocks = \max($minBlocks, (int)($pageContract['max_blocks'] ?? $minBlocks));
        if (\count($blocks) < $minBlocks || \count($blocks) > $maxBlocks) {
            $issues[] = $this->issue('invalid_block_count', 'pages.' . $pageType . '.blocks', 'high', [
                'page_type' => $pageType,
                'expected' => ['min' => $minBlocks, 'max' => $maxBlocks],
                'actual' => \count($blocks),
            ]);
        }
        $targetBlocks = \max(0, (int)($pageContract['target_blocks'] ?? 0));
        if ($targetBlocks > 0 && !empty($pageContract['block_count_handoff_required']) && \count($blocks) !== $targetBlocks) {
            $issues[] = $this->issue('target_block_count_mismatch', 'pages.' . $pageType . '.blocks', 'high', [
                'page_type' => $pageType,
                'expected' => $targetBlocks,
                'actual' => \count($blocks),
            ]);
        }

        $requiredBlockKeys = [];
        foreach (\is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [] as $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey !== '') {
                $requiredBlockKeys[$blockKey] = false;
            }
        }
        $requiredBlockOrder = \array_values(\array_keys($requiredBlockKeys));
        $forbiddenBlockKeys = \array_fill_keys(\array_map('strval', \is_array($pageContract['forbidden_block_keys'] ?? null) ? $pageContract['forbidden_block_keys'] : AiSiteStageOneContractService::GENERIC_BLOCK_KEYS), true);
        $seen = [];
        $visualSignatures = [];
        $contentFingerprints = [];
        $requiredImageBlockKeys = [];

        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                $issues[] = $this->issue('malformed_block', 'pages.' . $pageType . '.blocks.' . $index, 'high', ['page_type' => $pageType]);
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? ''));
            $path = 'pages.' . $pageType . '.blocks.' . $index;
            if ($blockKey === '') {
                $issues[] = $this->issue('missing_block_key', $path . '.block_key', 'high', ['page_type' => $pageType]);
                continue;
            }
            $normalizedBlockKey = \mb_strtolower($blockKey);
            if (isset($seen[$normalizedBlockKey])) {
                $issues[] = $this->issue('duplicate_block_key', $path . '.block_key', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                ]);
            }
            $seen[$normalizedBlockKey] = true;
            if (isset($forbiddenBlockKeys[$normalizedBlockKey])) {
                $issues[] = $this->issue('generic_block_key', $path . '.block_key', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                ]);
            }
            if (\array_key_exists($blockKey, $requiredBlockKeys)) {
                $requiredBlockKeys[$blockKey] = true;
            }

            $pageFlowRole = $this->normalizeRoleToken((string)($block['page_flow_role'] ?? ''));
            if ($pageFlowRole === '') {
                $issues[] = $this->issue('missing_page_flow_role', $path . '.page_flow_role', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected' => 'Stage-1 must declare the exact block identity/page flow role. BuildPlan must not infer it from block_key, section type, page type, or legacy defaults.',
                ]);
            }

            $content = \trim((string)($block['content'] ?? ''));
            if ($content === '') {
                $issues[] = $this->issue('instruction_like_or_empty', $path . '.content', 'medium', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'snippet' => $this->clip($content),
                ]);
            } elseif ($this->isPromptLikeText($content)) {
                $issues[] = $this->issue('instruction_like_or_empty', $path . '.content', 'medium', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'snippet' => $this->clip($content),
                ]);
            }

            $this->validateDesignTags($block, $pageContract, $path, $pageType, $blockKey, $issues);
            $this->validateFieldPlan($block, $pageContract, $path, $pageType, $blockKey, $issues);
            $this->validateExecutionScript($block, $path, $pageType, $blockKey, $issues);
            $this->validateBuildPlanVisibleBodyCopy($block, $path, $pageType, $blockKey, $issues);
            $this->validateBlockVisibleCopyLocale($block, $path, $pageType, $blockKey, $contract, $context, $issues);
            $this->validateBlockSourceTruthAndPolicyCopy($block, $path, $pageType, $blockKey, $context, $issues);
            $this->validateBlockContentDiversity($block, $path, $pageType, $blockKey, $contentFingerprints, $issues);
            $visualSignatures[] = [
                'index' => $index,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'signature' => $this->validateBlockVisualSignature($block, $pageContract, $path, $pageType, $blockKey, $issues),
            ];
            $this->validateBlockImageIntent($block, $pageContract, $path, $pageType, $blockKey, $issues);
            if ($this->blockImageIntentNeedsImage($block)) {
                $requiredImageBlockKeys[] = $blockKey;
            }
        }

        foreach ($requiredBlockKeys as $requiredBlockKey => $seenRequired) {
            if (!$seenRequired) {
                $issues[] = $this->issue('missing_required_block_key', 'pages.' . $pageType . '.blocks.block_key', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $requiredBlockKey,
                    'expected' => $requiredBlockKey,
                ]);
            }
        }
        $this->validateVisualSignatureDiversity($visualSignatures, $pageType, $pageContract, $issues);
        $this->validatePageImageCoverage($blocks, $requiredImageBlockKeys, $pageContract, $pageType, $issues);

        return $this->report($pagePlan, $contract, $issues, $context);
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $contract
     * @param list<array<string, mixed>> $issues
     */
    private function validateThemeDesign(array $planJson, array $contract, array &$issues): void
    {
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        $themeFields = \is_array($contract['theme_required_fields'] ?? null) ? $contract['theme_required_fields'] : [];
        foreach (\is_array($themeFields['theme_design'] ?? null) ? $themeFields['theme_design'] : [] as $field) {
            $field = \trim((string)$field);
            if ($field !== '' && !$this->hasNonEmptyValue($themeDesign[$field] ?? null)) {
                $issues[] = $this->issue('missing_theme_field', 'theme_design.' . $field, 'high');
            }
        }

        foreach (['theme_design.color_scheme' => 'color_scheme', 'theme_design.typography_spacing_radius' => 'typography_spacing_radius'] as $contractPath => $section) {
            $values = \is_array($themeDesign[$section] ?? null) ? $themeDesign[$section] : [];
            foreach (\is_array($themeFields[$contractPath] ?? null) ? $themeFields[$contractPath] : [] as $field) {
                $field = \trim((string)$field);
                if ($field !== '' && \trim((string)($values[$field] ?? '')) === '') {
                    $issues[] = $this->issue('missing_theme_field', $contractPath . '.' . $field, 'high');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $contract
     * @param list<array<string, mixed>> $issues
     */
    private function validateLinkRequirements(array $planJson, array $contract, array &$issues): void
    {
        $routeContract = \is_array($contract['page_route_contract'] ?? null) ? $contract['page_route_contract'] : [];
        $allowedPaths = $this->pageRouteContractService()->allowedPathMap($routeContract);
        foreach (\is_array($contract['shared_link_requirements'] ?? null) ? $contract['shared_link_requirements'] : [] as $path) {
            $path = \trim((string)$path);
            if ($path === '') {
                continue;
            }
            $fieldAllowedPaths = $this->allowedPathsForLinkRequirement($routeContract, $path);
            if ($fieldAllowedPaths === []) {
                $fieldAllowedPaths = $allowedPaths;
            }
            $links = $this->valueAtPath($planJson, $path);
            if (!\is_array($links) || $links === []) {
                $issues[] = $this->issue('missing_link_list', $path, 'high');
                continue;
            }
            foreach (\array_values($links) as $index => $link) {
                if (!\is_array($link) || \trim((string)($link['label'] ?? '')) === '' || \trim((string)($link['href'] ?? '')) === '') {
                    $issues[] = $this->issue('invalid_link_row', $path . '.' . $index, 'high');
                    continue;
                }
                if ($allowedPaths === []) {
                    continue;
                }
                $href = \trim((string)($link['href'] ?? ''));
                if (!$this->isExactInternalRoutePath($href)) {
                    $issues[] = $this->issue('link_href_not_exact_route_path', $path . '.' . $index . '.href', 'high', [
                        'href' => $href,
                        'allowed_internal_paths' => \array_values($fieldAllowedPaths),
                    ]);
                    continue;
                }
                $resolvedPath = $this->pageRouteContractService()->normalizeHrefToContractPath($routeContract, $href);
                if ($resolvedPath === '') {
                    $resolvedPath = $this->pageRouteContractService()->normalizeHrefPath($href);
                }
                if ($resolvedPath === '' || !isset($fieldAllowedPaths[$resolvedPath])) {
                    $issues[] = $this->issue('link_href_not_in_route_contract', $path . '.' . $index . '.href', 'high', [
                        'href' => $href,
                        'resolved_path' => $resolvedPath,
                        'allowed_internal_paths' => \array_values($fieldAllowedPaths),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $routeContract
     * @return array<string, string>
     */
    private function allowedPathsForLinkRequirement(array $routeContract, string $requirementPath): array
    {
        $linkGroup = \is_array($routeContract['link_groups'][$requirementPath] ?? null) ? $routeContract['link_groups'][$requirementPath] : [];
        if ($linkGroup !== []) {
            $paths = [];
            foreach (\is_array($linkGroup['allowed_paths'] ?? null) ? $linkGroup['allowed_paths'] : [] as $path) {
                $path = $this->pageRouteContractService()->normalizeHrefPath((string)$path);
                if ($path !== '') {
                    $paths[$path] = $path;
                }
            }
            if ($paths !== []) {
                return $paths;
            }
        }

        $routeTypesKey = match ($requirementPath) {
            'navigation_plan.header_items' => 'header_route_types',
            'footer_plan.featured' => 'footer_featured_route_types',
            'footer_plan.policies' => 'footer_policy_route_types',
            default => '',
        };
        if ($routeTypesKey === '') {
            return [];
        }

        $routesByType = $this->pageRouteContractService()->routesByType($routeContract);
        $paths = [];
        foreach (\is_array($routeContract[$routeTypesKey] ?? null) ? $routeContract[$routeTypesKey] : [] as $pageType) {
            $pageType = \trim((string)$pageType);
            $path = \is_array($routesByType[$pageType] ?? null) ? \trim((string)($routesByType[$pageType]['path'] ?? '')) : '';
            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return $paths;
    }

    private function isExactInternalRoutePath(string $href): bool
    {
        $href = \trim($href);
        if ($href === '' || $href === '#') {
            return false;
        }
        if (\preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1 || \str_starts_with($href, '//')) {
            return false;
        }
        if (\str_contains($href, '#') || \str_contains($href, '?')) {
            return false;
        }

        return \str_starts_with($href, '/');
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageContract
     * @param list<array<string, mixed>> $issues
     */
    private function validateDesignTags(array $block, array $pageContract, string $path, string $pageType, string $blockKey, array &$issues): void
    {
        $designTags = \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [];
        $requiredTagKeys = \is_array($pageContract['required_design_tag_keys'] ?? null)
            ? $pageContract['required_design_tag_keys']
            : AiSiteStageOneContractService::DESIGN_TAG_KEYS;
        foreach ($requiredTagKeys as $tagKey) {
            $tagKey = \trim((string)$tagKey);
            if ($tagKey === '') {
                continue;
            }
            $value = $designTags[$tagKey] ?? null;
            $ok = \is_array($value) ? $value !== [] : \trim((string)$value) !== '';
            if (!$ok) {
                $issues[] = $this->issue('missing_design_tag', $path . '.design_tags.' . $tagKey, 'medium', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageContract
     * @param list<array<string, mixed>> $issues
     */
    private function validateFieldPlan(array $block, array $pageContract, string $path, string $pageType, string $blockKey, array &$issues): void
    {
        $fieldPlan = \is_array($block['field_plan'] ?? null) ? \array_values($block['field_plan']) : [];
        $expectedCount = (int)($pageContract['field_plan_count'] ?? AiSiteStageOneContractService::FIELD_PLAN_COUNT);
        if ($fieldPlan === [] && $expectedCount > 0) {
            $issues[] = $this->issue('missing_field_plan', $path . '.field_plan', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected' => $expectedCount,
                'actual' => 0,
            ]);
        } elseif (\count($fieldPlan) !== $expectedCount) {
            $issues[] = $this->issue('invalid_field_plan_count', $path . '.field_plan', 'medium', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected' => $expectedCount,
                'actual' => \count($fieldPlan),
            ]);
        }

        foreach ($fieldPlan as $index => $row) {
            if (!\is_array($row)) {
                $issues[] = $this->issue('malformed_field_plan_row', $path . '.field_plan.' . $index, 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                ]);
                continue;
            }
            foreach (['field', 'sample', 'implementation_note'] as $field) {
                $value = \trim((string)($row[$field] ?? ''));
                if ($value === '') {
                    $issues[] = $this->issue('instruction_like_or_empty', $path . '.field_plan.' . $index . '.' . $field, $field === 'field' ? 'high' : 'medium', [
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'field_index' => $index,
                        'snippet' => $this->clip($value),
                    ]);
                } elseif ($this->isPromptLikeText($value)) {
                    $issues[] = $this->issue('instruction_like_or_empty', $path . '.field_plan.' . $index . '.' . $field, 'medium', [
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'field_index' => $index,
                        'snippet' => $this->clip($value),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $issues
     */
    private function validateExecutionScript(array $block, string $path, string $pageType, string $blockKey, array &$issues): void
    {
        $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $coreCopy = \trim((string)($executionScript['core_copy'] ?? ''));
        if ($coreCopy === '' || $this->isPromptLikeText($coreCopy)) {
            $issues[] = $this->issue('instruction_like_or_empty', $path . '.execution_script.core_copy', 'medium', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'snippet' => $this->clip($coreCopy),
            ]);
        }
    }

    /**
     * Keep Stage-1 validation aligned with BuildPlan extraction. The builder does not have a
     * local copy fallback, so a block that only contains layout/planning text must be repaired
     * before confirmation.
     *
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $issues
     */
    private function validateBuildPlanVisibleBodyCopy(array $block, string $path, string $pageType, string $blockKey, array &$issues): void
    {
        if ($this->blockHasBuildPlanVisibleBodyCopy($block)) {
            return;
        }

        $issues[] = $this->issue('missing_visible_body_copy', $path . '.field_plan', 'high', [
            'page_type' => $pageType,
            'block_key' => $blockKey,
            'expected' => 'Every Stage-1 block must provide visitor-visible body copy that BuildPlan can consume: execution_script.core_copy, a field_plan supporting_copy/body/copy row, realtime_content.supporting_copy, feature_points, or block.content. Layout/planning instructions do not count.',
        ]);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $issues
     */
    private function validateBlockVisibleCopyLocale(
        array $block,
        string $path,
        string $pageType,
        string $blockKey,
        array $contract,
        array $context,
        array &$issues
    ): void {
        $contentLocale = $this->resolveValidationContentLocale($contract, $context);
        if ($contentLocale === '') {
            return;
        }

        foreach ($this->collectBlockVisibleTexts($block, $path) as $row) {
            $text = \trim((string)($row['text'] ?? ''));
            if ($text === '' || !$this->looksLikeVisibleLocaleLeak($text, $contentLocale)) {
                continue;
            }

            $issues[] = $this->issue('visible_copy_locale_mismatch', (string)($row['path'] ?? $path), 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'content_locale' => $contentLocale,
                'field_name' => (string)($row['field_name'] ?? ''),
                'snippet' => $this->clip($text),
                'expected' => 'Visitor-visible Stage-1 copy must use content_locale. Plan language and customer brief language are context only.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $issues
     */
    private function validateBlockSourceTruthAndPolicyCopy(
        array $block,
        string $path,
        string $pageType,
        string $blockKey,
        array $context,
        array &$issues
    ): void {
        $visibleTexts = $this->collectBlockVisibleTexts($block, $path);
        foreach ($visibleTexts as $row) {
            $text = (string)($row['text'] ?? '');
            if ($text === '') {
                continue;
            }
        }

        $role = $this->normalizeRoleToken((string)($block['page_flow_role'] ?? ''));
        if ($role === 'cta' && !$this->blockHasExplicitActionCopy($block)) {
            $issues[] = $this->issue('cta_role_missing_block_action', $path . '.field_plan', 'medium', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected' => 'A cta role block must include its own cta_label, action_label, button_text, form_label, submit_label, or realtime_content.ctas value.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array{text:string,path:string,field_name?:string}>
     */
    private function collectBlockVisibleTexts(array $block, string $path): array
    {
        $texts = [];
        foreach (['content'] as $field) {
            $value = \trim((string)($block[$field] ?? ''));
            if ($value !== '') {
                $texts[] = ['text' => $value, 'path' => $path . '.' . $field, 'field_name' => $field];
            }
        }

        foreach (\is_array($block['field_plan'] ?? null) ? \array_values($block['field_plan']) : [] as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fieldName = \trim((string)($row['field'] ?? $row['name'] ?? ''));
            $sample = \trim((string)($row['sample'] ?? $row['value'] ?? $row['text'] ?? ''));
            if ($sample !== '') {
                $texts[] = [
                    'text' => $sample,
                    'path' => $path . '.field_plan.' . $index . '.sample',
                    'field_name' => $fieldName,
                ];
            }
        }

        $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $coreCopy = \trim((string)($executionScript['core_copy'] ?? ''));
        if ($coreCopy !== '') {
            $texts[] = ['text' => $coreCopy, 'path' => $path . '.execution_script.core_copy', 'field_name' => 'core_copy'];
        }
        foreach (\is_array($executionScript['feature_points'] ?? null) ? \array_values($executionScript['feature_points']) : [] as $index => $feature) {
            $featureText = \trim((string)$feature);
            if ($featureText !== '') {
                $texts[] = [
                    'text' => $featureText,
                    'path' => $path . '.execution_script.feature_points.' . $index,
                    'field_name' => 'feature_point',
                ];
            }
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockHasExplicitActionCopy(array $block): bool
    {
        foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fieldName = \trim((string)($row['field'] ?? $row['name'] ?? ''));
            if (\preg_match('/(?:cta|button|action|submit|form_label)/iu', $fieldName) !== 1) {
                continue;
            }
            $sample = \trim((string)($row['sample'] ?? $row['value'] ?? $row['text'] ?? ''));
            if ($sample !== '') {
                return true;
            }
        }

        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        foreach (\is_array($realtime['ctas'] ?? null) ? $realtime['ctas'] : [] as $cta) {
            if (!\is_array($cta)) {
                continue;
            }
            if (\trim((string)($cta['text'] ?? $cta['label'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockHasBuildPlanVisibleBodyCopy(array $block): bool
    {
        $execution = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $candidates = [
            $execution['core_copy'] ?? null,
            $this->extractStageOneFieldPlanText($block, ['description', 'body', 'copy', 'subtitle', 'supporting_copy', 'intro', 'paragraph']),
        ];

        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        if (\is_array($realtime['supporting_copy'] ?? null)) {
            $candidates[] = \implode(' ', \array_map('strval', $realtime['supporting_copy']));
        }

        if (\is_array($execution['feature_points'] ?? null)) {
            $candidates[] = \implode(' ', \array_map('strval', $execution['feature_points']));
        }

        $candidates[] = $block['content'] ?? null;

        foreach ($candidates as $candidate) {
            if (!$this->isSafeBuildPlanVisibleCopy($candidate)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $fieldNames
     */
    private function extractStageOneFieldPlanText(array $block, array $fieldNames): string
    {
        $wanted = \array_fill_keys(\array_map('strtolower', $fieldNames), true);
        foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $name = \strtolower(\trim((string)($field['field'] ?? $field['name'] ?? '')));
            if (!isset($wanted[$name])) {
                continue;
            }
            $text = \trim((string)($field['sample'] ?? $field['value'] ?? $field['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function isSafeBuildPlanVisibleCopy(mixed $value): bool
    {
        if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
            return false;
        }
        $text = \trim((string)\preg_replace('/\s+/u', ' ', (string)$value));
        if ($text === '' || $this->isPromptLikeText($text)) {
            return false;
        }

        return !BuildPlanContentManifestLinter::isPlanningOrImplementationCopy($text);
    }

    private function isPolicyPageType(string $pageType): bool
    {
        return \in_array($pageType, [
            'privacy_policy',
            'terms_of_service',
            'refund_policy',
            'shipping_policy',
            'cookie_policy',
        ], true);
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $context
     */
    private function resolveValidationContentLocale(array $contract, array $context): string
    {
        foreach ([
            $context['content_locale'] ?? null,
            $contract['content_locale'] ?? null,
            $context['default_locale'] ?? null,
            $contract['default_locale'] ?? null,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $locale = \trim((string)$value);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function looksLikeVisibleLocaleLeak(string $value, string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '' || $value === '') {
            return false;
        }
        if ($this->isCjkLocale($locale)) {
            return $this->hasDominantLatinCopy($value);
        }

        return $this->hasAnyCjkCopy($value);
    }

    private function isCjkLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        return $locale === 'zh'
            || \str_starts_with($locale, 'zh_')
            || \str_starts_with($locale, 'zh-')
            || $locale === 'ja'
            || \str_starts_with($locale, 'ja_')
            || \str_starts_with($locale, 'ja-')
            || $locale === 'ko'
            || \str_starts_with($locale, 'ko_')
            || \str_starts_with($locale, 'ko-');
    }

    private function hasDominantLatinCopy(string $value): bool
    {
        $allowed = \array_fill_keys(['apk', 'app', 'seo', 'ios', 'android', 'upi', 'ssl', 'vip', 'faq', 'url', 'www'], true);
        \preg_match_all('/\b[A-Za-z][A-Za-z0-9\'-]{2,}\b/u', $value, $matches);
        $words = [];
        $properNounOnly = true;
        foreach ($matches[0] ?? [] as $word) {
            $rawWord = \trim((string)$word, " \t\n\r\0\x0B'\"-");
            $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
            if ($normalized === '' || isset($allowed[$normalized])) {
                continue;
            }
            $words[] = $normalized;
            if (\preg_match('/^[A-Z][A-Za-z0-9\'-]*$/', $rawWord) !== 1) {
                $properNounOnly = false;
            }
        }
        if ($words === []) {
            return false;
        }
        if ($properNounOnly && $this->hasMeaningfulCjkCopy($value)) {
            return false;
        }

        $letterCount = 0;
        foreach ($words as $word) {
            $letterCount += \strlen($word);
        }

        return \count($words) >= 5 && $letterCount >= 28;
    }

    private function hasMeaningfulCjkCopy(string $value): bool
    {
        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $value, $matches) <= 0) {
            return false;
        }

        $total = 0;
        foreach ($matches[0] ?? [] as $segment) {
            $length = \function_exists('mb_strlen') ? \mb_strlen((string)$segment) : \strlen((string)$segment);
            $total += $length;
            if ($length >= 8) {
                return true;
            }
        }

        return $total >= 12;
    }

    private function hasAnyCjkCopy(string $value): bool
    {
        return \preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value) === 1;
    }

    private function normalizeRoleToken(string $value): string
    {
        return \mb_strtolower(\trim((string)\preg_replace('/[^a-z0-9_ -]+/i', '', $value)));
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, string> $fingerprints
     * @param list<array<string, mixed>> $issues
     */
    private function validateBlockContentDiversity(array $block, string $path, string $pageType, string $blockKey, array &$fingerprints, array &$issues): void
    {
        $values = [
            'content' => \trim((string)($block['content'] ?? '')),
            'core_copy' => \trim((string)($block['execution_script']['core_copy'] ?? '')),
        ];
        foreach ($values as $field => $value) {
            $fingerprint = $this->buildTextDiversityFingerprint($value);
            if ($fingerprint === '') {
                continue;
            }
            $key = $field . ':' . $fingerprint;
            if (isset($fingerprints[$key]) && $fingerprints[$key] !== $blockKey) {
                $issues[] = $this->issue('duplicate_block_message', $path . '.' . ($field === 'core_copy' ? 'execution_script.core_copy' : $field), 'medium', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'duplicate_of' => $fingerprints[$key],
                    'field' => $field,
                    'snippet' => $this->clip($value),
                ]);
                continue;
            }
            $fingerprints[$key] = $blockKey;
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $requiredBlockOrder
     * @param list<array<string, mixed>> $issues
     */
    private function validateRequiredBlockOrder(array $blocks, array $requiredBlockOrder, string $pageType, array &$issues): void
    {
        foreach ($requiredBlockOrder as $expectedIndex => $expectedBlockKey) {
            $expectedBlockKey = \trim((string)$expectedBlockKey);
            if ($expectedBlockKey === '') {
                continue;
            }
            $actualBlock = \is_array($blocks[$expectedIndex] ?? null) ? $blocks[$expectedIndex] : [];
            $actualBlockKey = \trim((string)($actualBlock['block_key'] ?? ''));
            if ($actualBlockKey === $expectedBlockKey) {
                continue;
            }
            $issues[] = $this->issue('required_block_order_mismatch', 'pages.' . $pageType . '.blocks.' . $expectedIndex . '.block_key', 'high', [
                'page_type' => $pageType,
                'expected' => $expectedBlockKey,
                'actual' => $actualBlockKey,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageContract
     * @param list<array<string, mixed>> $issues
     * @return array<string, string>
     */
    private function validateBlockVisualSignature(array $block, array $pageContract, string $path, string $pageType, string $blockKey, array &$issues): array
    {
        $signature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
        if ($signature === []) {
            $issues[] = $this->issue('missing_visual_signature', $path . '.visual_signature', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
            ]);
        }

        $normalized = [];
        $requiredKeys = \is_array($pageContract['visual_signature_keys'] ?? null)
            ? $pageContract['visual_signature_keys']
            : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS;
        foreach ($requiredKeys as $key) {
            $key = \trim((string)$key);
            if ($key === '') {
                continue;
            }
            $value = $this->normalizeSignatureText($signature[$key] ?? null);
            $normalized[$key] = $value;
            if ($key === 'interaction_pattern' && $value === '') {
                continue;
            }
            if ($value === '' || $this->isPromptLikeText($value)) {
                $issues[] = $this->issue('invalid_visual_signature', $path . '.visual_signature.' . $key, 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected' => 'concrete block-specific visual signature text',
                    'actual' => $this->clip($value),
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageContract
     * @param list<array<string, mixed>> $issues
     */
    private function validateBlockImageIntent(array $block, array $pageContract, string $path, string $pageType, string $blockKey, array &$issues): void
    {
        if (empty($pageContract['requires_image_intent'])) {
            return;
        }
        $intent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
        if ($intent === []) {
            $issues[] = $this->issue('missing_image_intent', $path . '.image_intent', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
            ]);
            return;
        }

        [$hasNeedsImage, $needsImage] = $this->normalizeImageIntentNeedsImage($intent['needs_image'] ?? null);
        if (!$hasNeedsImage) {
            $issues[] = $this->issue('invalid_image_intent_needs_image', $path . '.image_intent.needs_image', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
            ]);
        }

        foreach ($this->collectPlaceholderImagePlanningTexts($block, $path) as $row) {
            $text = (string)($row['text'] ?? '');
            if (!$this->isPlaceholderImagePlanningText($text)) {
                continue;
            }
            $issues[] = $this->issue('placeholder_image_planning_forbidden', (string)($row['path'] ?? ($path . '.image_intent')), 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'snippet' => $this->clip($text),
                'expected' => 'Image/media planning must describe a real generated asset slot or a complete CSS-only motif; placeholders, dummy images, fake images, and temporary media are forbidden.',
            ]);
        }

        if ($needsImage) {
            foreach (['image_role', 'image_subject', 'placement', 'visual_atmosphere', 'image_treatment', 'reuse_policy'] as $field) {
                $value = $this->normalizeSignatureText($intent[$field] ?? null);
                if ($value === '' || $this->isPromptLikeText($value)) {
                    $issues[] = $this->issue('invalid_image_intent_field', $path . '.image_intent.' . $field, 'high', [
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'expected' => 'concrete image role, subject, placement, visual atmosphere, image treatment, and reuse policy',
                    ]);
                }
            }
            $subject = $this->normalizeSignatureText($intent['image_subject'] ?? null);
            if ($this->isIconOnlyImageSubject($subject)) {
                $issues[] = $this->issue('icon_only_image_subject', $path . '.image_intent.image_subject', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected' => 'block-level scene, editorial photo, product visual, or premium illustration subject',
                    'actual' => $this->clip($subject),
                ]);
            }
            if ($this->blockDeclaresCssOnlyVisual($block)) {
                $issues[] = $this->issue('image_intent_conflicts_with_block_plan', $path . '.image_intent.needs_image', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected' => 'needs_image=true requires visual_signature.media_strategy to describe the real generated image asset and must not declare CSS-only/no generated image',
                    'actual' => 'image_intent.needs_image=true but media planning declares CSS-only/no generated image',
                ]);
            }
            return;
        }

        foreach (['css_motif', 'visual_atmosphere', 'image_treatment'] as $field) {
            $value = $this->normalizeSignatureText($intent[$field] ?? null);
            if ($value === '' || $this->isPromptLikeText($value)) {
                $issues[] = $this->issue('missing_css_motif_for_no_image_block', $path . '.image_intent.' . $field, 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected' => 'needs_image=false must still declare css_motif, visual_atmosphere, and image_treatment so build does not guess the visual companion',
                ]);
            }
        }

        $imageConflict = $this->detectNoImageIntentPlanConflict($block);
        if ($imageConflict !== null) {
            $issues[] = $this->issue('image_intent_conflicts_with_block_plan', $path . '.image_intent.needs_image', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected' => 'needs_image=true with concrete image role, subject, placement, and reuse policy, or remove media asset planning and declare a CSS-only motif',
                'actual' => $imageConflict,
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param list<string> $requiredImageBlockKeys
     * @param array<string,mixed> $pageContract
     * @param list<array<string,mixed>> $issues
     */
    private function validatePageImageCoverage(array $blocks, array $requiredImageBlockKeys, array $pageContract, string $pageType, array &$issues): void
    {
        if (!$this->pageShouldRequireGeneratedVisual($pageType, $blocks)) {
            return;
        }
        $firstBlock = \is_array($blocks[0] ?? null) ? $blocks[0] : [];
        $firstBlockKey = \trim((string)($firstBlock['block_key'] ?? ''));
        if ($requiredImageBlockKeys !== []) {
            return;
        }

        $issues[] = $this->issue('page_missing_generated_image_intent', 'pages.' . $pageType . '.blocks.image_intent', 'high', [
            'page_type' => $pageType,
            'block_key' => $firstBlockKey !== '' ? $firstBlockKey : '__page__',
            'expected' => 'At least one real generated image slot on non-policy pages, with image_intent.needs_image=true and a concrete scene/product/interface subject.',
        ]);
    }

    /**
     * @param list<array<string,mixed>> $blocks
     */
    private function pageShouldRequireGeneratedVisual(string $pageType, array $blocks): bool
    {
        if ($this->isPolicyPageType($pageType)) {
            return false;
        }
        if ($blocks === []) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $block
     */
    private function blockImageIntentNeedsImage(array $block): bool
    {
        $intent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
        if ($intent === []) {
            return false;
        }

        [, $needsImage] = $this->normalizeImageIntentNeedsImage($intent['needs_image'] ?? null);

        return $needsImage;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function detectNoImageIntentPlanConflict(array $block): ?string
    {
        if ($this->blockDeclaresCssOnlyVisual($block)) {
            return null;
        }

        $role = $this->normalizeSignatureText($block['page_flow_role'] ?? null);
        if (\in_array($role, ['opening', 'hero', 'proof'], true)) {
            return 'opening/proof block declared without an image or CSS-only visual plan';
        }

        $mediaText = $this->normalizeSignatureText([
            $block['execution_script']['media_assets'] ?? null,
            $block['media_assets'] ?? null,
            $block['visual_signature']['media_strategy'] ?? null,
        ]);
        if ($mediaText !== '' && $this->mentionsGeneratedImageMedia($mediaText)) {
            return 'block plans media assets while image_intent.needs_image=false';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockDeclaresCssOnlyVisual(array $block): bool
    {
        $intent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
        $text = $this->normalizeSignatureText([
            $intent['css_motif'] ?? null,
            $block['visual_signature']['media_strategy'] ?? null,
        ]);
        if ($text === '') {
            return false;
        }

        if (\preg_match('/\b(?:css-only|css only|no image|without image|no generated image|no[_-]?generated[_-]?image|decorative css|css illustration|css icon)\b/u', $text) === 1) {
            return true;
        }
        if (\str_contains($text, 'css绘制') || \str_contains($text, 'css 绘制')) {
            return true;
        }

        $hasChineseCssMarker = \str_contains($text, 'css')
            || \str_contains($text, '渐变')
            || \str_contains($text, '纹理')
            || \str_contains($text, '图标')
            || \str_contains($text, '线性')
            || \str_contains($text, '光晕')
            || \str_contains($text, '徽章')
            || \str_contains($text, '卡片');
        $hasChineseNoImageMarker = \str_contains($text, '无生成图片')
            || \str_contains($text, '无需生成图片')
            || \str_contains($text, '不需要生成图片')
            || \str_contains($text, '无图片')
            || \str_contains($text, '无需图片')
            || \str_contains($text, '不用图片');

        return $hasChineseCssMarker && $hasChineseNoImageMarker;
    }

    private function mentionsGeneratedImageMedia(string $text): bool
    {
        if (\preg_match('/\b(?:image|photo|photograph|illustration|screenshot|mockup|scene|hero\s+image|banner\s+image|background\s+image|avatar)\b/u', $text) === 1) {
            return true;
        }

        foreach (['图片', '照片', '摄影', '插画', '截图', '模型图', '样机', '场景图', '头像', '背景图', '主视觉图'] as $marker) {
            if (\str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array{text:string,path:string}>
     */
    private function collectPlaceholderImagePlanningTexts(array $block, string $path): array
    {
        $rows = [];
        foreach (['design_tags', 'visual_signature', 'image_intent'] as $field) {
            $value = $block[$field] ?? null;
            if ($value === null) {
                continue;
            }
            $text = $this->normalizeSignatureText($value);
            if ($text !== '') {
                $rows[] = ['text' => $text, 'path' => $path . '.' . $field];
            }
        }

        $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        foreach (['media_assets', 'background_direction'] as $field) {
            $text = $this->normalizeSignatureText($executionScript[$field] ?? null);
            if ($text !== '') {
                $rows[] = ['text' => $text, 'path' => $path . '.execution_script.' . $field];
            }
        }

        foreach (\is_array($block['field_plan'] ?? null) ? \array_values($block['field_plan']) : [] as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fieldName = $this->normalizeSignatureText($row['field'] ?? $row['name'] ?? '');
            if (!$this->fieldNameLooksMediaRelated($fieldName)) {
                continue;
            }
            foreach (['sample', 'implementation_note'] as $field) {
                $text = $this->normalizeSignatureText($row[$field] ?? '');
                if ($text !== '') {
                    $rows[] = ['text' => $text, 'path' => $path . '.field_plan.' . $index . '.' . $field];
                }
            }
        }

        return $rows;
    }

    private function fieldNameLooksMediaRelated(string $fieldName): bool
    {
        if ($fieldName === '') {
            return false;
        }

        return \preg_match('/(?:image|media|asset|visual|photo|screenshot|mockup|\x{56FE}\x{7247}|\x{56FE}\x{50CF}|\x{7D20}\x{6750}|\x{89C6}\x{89C9}|\x{622A}\x{56FE})/iu', $fieldName) === 1;
    }

    private function isPlaceholderImagePlanningText(string $text): bool
    {
        $text = \mb_strtolower(\trim((string)\preg_replace('/\s+/u', ' ', $text)));
        if ($text === '') {
            return false;
        }

        $negatedPlaceholderPattern = '/(?:\b(?:avoid|reject|forbid|without|no|not|never|do\s+not|don\'t)\b.{0,24}(?:placeholder|dummy|fake|temporary|blank|gray\s+box|grey\s+box)|(?:\x{907F}\x{514D}|\x{4E0D}\x{4F7F}\x{7528}|\x{4E0D}\x{8981}|\x{7981}\x{6B62}|\x{62D2}\x{7EDD}).{0,16}(?:\x{5360}\x{4F4D}|\x{5047}\x{56FE}|\x{4E34}\x{65F6}|\x{6682}\x{65F6}))/iu';
        if (\preg_match($negatedPlaceholderPattern, $text) === 1) {
            return false;
        }

        return \preg_match('/(?:\bplaceholder\b|\bdummy\b|\blorem\b|\bfake\s+(?:image|photo|media|asset|screenshot)\b|\btemporary\s+(?:image|photo|media|asset|screenshot)\b|\bblank\s+(?:image|photo|media|asset|screenshot|box)\b|\bgray\s+box\b|\bgrey\s+box\b|\x{5360}\x{4F4D}|\x{5047}\x{56FE}|\x{4E34}\x{65F6}(?:\x{56FE}\x{7247}|\x{56FE}\x{50CF}|\x{7D20}\x{6750}|\x{89C6}\x{89C9})|\x{6682}\x{65F6}(?:\x{56FE}\x{7247}|\x{56FE}\x{50CF}|\x{7D20}\x{6750}|\x{89C6}\x{89C9}))/iu', $text) === 1;
    }

    private function isIconOnlyImageSubject(string $subject): bool
    {
        if ($subject === '') {
            return false;
        }
        $mentionsIcon = \preg_match('/\b(?:svg|icon|glyph|chevron|sparkle|badge|line\s+art|line\s+icon|symbol)\b/u', $subject) === 1;
        if (!$mentionsIcon) {
            return false;
        }

        return \preg_match('/\b(?:scene|photo|photograph|illustration|cinematic|editorial|environment|people|players|table|room|product|device|phone|smartphone|mobile|screen|interface|app\s+screen|screenshot|mockup|dashboard|workflow|approval|timeline|card|panel|route|alert|chart|graph)\b/u', $subject) !== 1;
    }

    /**
     * @param list<array<string, mixed>> $visualSignatures
     * @param list<array<string, mixed>> $issues
     */
    private function validateVisualSignatureDiversity(array $visualSignatures, string $pageType, array $pageContract, array &$issues): void
    {
        $uniquenessScope = \trim((string)($pageContract['visual_signature_uniqueness_scope'] ?? 'same_page_adjacent_blocks'));
        $adjacentSeverity = \trim((string)($pageContract['visual_signature_duplicate_severity'] ?? 'medium'));
        if ($adjacentSeverity === '') {
            $adjacentSeverity = 'medium';
        }
        if (\in_array($adjacentSeverity, ['high', 'blocking'], true)) {
            $adjacentSeverity = 'medium';
        }
        $checkAdjacent = \in_array($uniquenessScope, ['same_page_adjacent_blocks', 'same_page_adjacent_blocks_soft'], true);
        $checkCompositionOveruse = !empty($pageContract['forbid_repeated_composition_patterns_within_page'])
            || \trim((string)($pageContract['composition_overuse_severity'] ?? '')) !== '';
        $compositionOveruseSeverity = \trim((string)($pageContract['composition_overuse_severity'] ?? 'medium'));
        if ($compositionOveruseSeverity === '') {
            $compositionOveruseSeverity = 'medium';
        }
        if (\in_array($compositionOveruseSeverity, ['high', 'blocking'], true)) {
            $compositionOveruseSeverity = 'medium';
        }

        $previousFingerprint = '';
        $compositionCounts = [];
        foreach ($visualSignatures as $row) {
            $signature = \is_array($row['signature'] ?? null) ? $row['signature'] : [];
            $fingerprint = $this->visualSignatureFingerprint($signature, ['composition_pattern', 'surface_treatment', 'media_strategy']);
            if ($checkAdjacent && $fingerprint !== '' && $fingerprint === $previousFingerprint) {
                $issues[] = $this->issue('adjacent_visual_signature_duplicate', 'pages.' . $pageType . '.blocks.' . (int)($row['index'] ?? 0) . '.visual_signature', $adjacentSeverity, [
                    'page_type' => $pageType,
                    'block_key' => (string)($row['block_key'] ?? ''),
                    'fingerprint' => $fingerprint,
                    'contract_scope' => $uniquenessScope,
                ]);
            }
            $previousFingerprint = $fingerprint;

            $composition = $this->normalizeSignatureText($signature['composition_pattern'] ?? '');
            if ($composition !== '') {
                $compositionCounts[$composition] = ($compositionCounts[$composition] ?? 0) + 1;
            }
        }

        if (!$checkCompositionOveruse) {
            return;
        }
        foreach ($compositionCounts as $composition => $count) {
            if ($count <= 2) {
                continue;
            }
            $issues[] = $this->issue('overused_composition_pattern', 'pages.' . $pageType . '.blocks.visual_signature.composition_pattern', $compositionOveruseSeverity, [
                'page_type' => $pageType,
                'composition_pattern' => $composition,
                'count' => $count,
            ]);
        }
    }

    /**
     * @return array{0:bool,1:bool}
     */
    private function normalizeImageIntentNeedsImage(mixed $value): array
    {
        if (\is_bool($value)) {
            return [true, $value];
        }
        if (\is_int($value) || \is_float($value)) {
            return [true, ((int)$value) === 1];
        }
        $normalized = \mb_strtolower(\trim((string)$value));
        if (\in_array($normalized, ['true', 'yes', 'y', '1', 'required', 'needed', 'generated_image', 'needs_generated_image'], true)) {
            return [true, true];
        }
        if (\in_array($normalized, ['false', 'no', 'n', '0', 'none', 'not_needed', 'no_image', 'no-image', 'no_generated_image', 'no-generated-image', 'css_only', 'css-only'], true)) {
            return [true, false];
        }

        return [false, false];
    }

    private function normalizeSignatureText(mixed $value): string
    {
        if (\is_array($value)) {
            $value = \implode(' ', \array_filter(\array_map(
                static fn($item): string => \is_scalar($item) ? \trim((string)$item) : '',
                $value
            ), static fn(string $item): bool => $item !== ''));
        } elseif (!\is_scalar($value) && $value !== null) {
            return '';
        }

        return \mb_strtolower(\trim((string)\preg_replace('/\s+/', ' ', (string)$value)));
    }

    /**
     * @param array<string, string> $signature
     * @param list<string> $keys
     */
    private function visualSignatureFingerprint(array $signature, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $value = $this->normalizeSignatureText($signature[$key] ?? '');
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? '' : \implode('|', $parts);
    }

    private function buildTextDiversityFingerprint(string $value): string
    {
        $value = \mb_strtolower(\trim((string)\preg_replace('/\s+/', ' ', $value)));
        if ($value === '' || $this->isPromptLikeText($value)) {
            return '';
        }
        $words = \preg_split('/[^\p{L}\p{N}]+/u', $value, -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($words) || \count($words) < 5) {
            return '';
        }
        $leadWords = \array_slice($words, 0, 12);
        $leadText = \implode(' ', $leadWords);
        if (\mb_strlen($leadText) < 28) {
            return '';
        }

        return \sha1($leadText);
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function report(array $artifact, array $contract, array $issues, array $context): array
    {
        $blocking = \array_values(\array_filter($issues, static fn(array $issue): bool => \in_array((string)($issue['severity'] ?? ''), ['high', 'blocking'], true)));
        $recoveryCount = \max(0, (int)($context['recovery_count'] ?? 0));
        $retryableFailureCount = \max(0, (int)($context['retryable_failure_count'] ?? 0));
        $localRepairRounds = \max(0, (int)($context['local_repair_rounds'] ?? 0));
        $strictRetryCount = \max(0, (int)($context['strict_retry_count'] ?? 0));
        $strictRetrySignals = \is_array($context['strict_retry_signals'] ?? null) ? $context['strict_retry_signals'] : [];
        $firstPass = $blocking === []
            && $recoveryCount === 0
            && $retryableFailureCount === 0
            && $localRepairRounds === 0
            && $strictRetryCount === 0
            && empty($context['retry_from_previous_failure']);

        return [
            'version' => 1,
            'validator' => self::class,
            'passed' => $blocking === [],
            'first_pass' => $firstPass,
            'contract_version' => (string)($contract['contract_version'] ?? AiSiteStageOneContractService::CONTRACT_VERSION),
            'contract_hash' => (string)($contract['contract_hash'] ?? ''),
            'artifact_hash' => \sha1((string)\json_encode($artifact, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
            'issues' => \array_values($issues),
            'blocking_issue_count' => \count($blocking),
            'issue_count' => \count($issues),
            'generation_attempts' => \is_array($context['generation_attempts'] ?? null) ? $context['generation_attempts'] : [],
            'recovery_count' => $recoveryCount,
            'retryable_failure_count' => $retryableFailureCount,
            'local_repair_rounds' => $localRepairRounds,
            'strict_retry_count' => $strictRetryCount,
            'strict_retry_signals' => \array_values($strictRetrySignals),
            'validated_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function issue(string $code, string $path, string $severity, array $extra = []): array
    {
        return \array_replace([
            'code' => $code,
            'reason_code' => $code,
            'severity' => $severity,
            'path' => $path,
            'field_path' => $path,
            'retry_scope' => 'stage1_contract',
            'prompt_hint' => 'Regenerate the affected Stage-1 section from the contract instead of applying local fallback content.',
        ], $extra);
    }

    private function isPromptLikeText(string $value): bool
    {
        $value = \trim($value);
        if ($value === '') {
            return true;
        }
        if (\mb_strlen($value) <= 2) {
            return true;
        }
        if (\in_array(\mb_strtolower($value), ['string', 'sentence', 'text', 'copy', 'content', 'details', 'item', 'placeholder', 'todo', 'n/a'], true)) {
            return true;
        }

        if (\preg_match('/^(?:how this page obeys|explaining how|schema|placeholder|prompt|instruction|return only|final visitor copy|website content locale)$/iu', $value) === 1) {
            return true;
        }

        if (\preg_match('/\bvisible\s+[a-z0-9_-]+\s+content\s+for\b/iu', $value) === 1) {
            return true;
        }

        return \preg_match('/^(?:write|rewrite|describe\s+(?:the|this)\s+(?:block|section|field|content|layout|purpose)|use this field|do not output|围绕|突出|说明|完善|优化)\b/iu', $value) === 1;
    }

    private function hasNonEmptyValue(mixed $value): bool
    {
        if (\is_array($value)) {
            return $value !== [];
        }

        return \trim((string)$value) !== '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function valueAtPath(array $source, string $path): mixed
    {
        $current = $source;
        foreach (\explode('.', $path) as $part) {
            if (!\is_array($current) || !\array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    private function clip(string $value, int $max = 160): string
    {
        $value = \trim((string)\preg_replace('/\s+/', ' ', $value));
        if (\mb_strlen($value) <= $max) {
            return $value;
        }

        return \mb_substr($value, 0, $max) . '...';
    }

    private function contractService(): AiSiteStageOneContractService
    {
        return $this->contractService ?? new AiSiteStageOneContractService();
    }

    private function pageRouteContractService(): AiSitePageRouteContractService
    {
        return $this->pageRouteContractService ?? new AiSitePageRouteContractService();
    }
}
