<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

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
            if ($value === '' || $this->isPromptLikeText($value)) {
                $issues[] = $this->issue('instruction_like_or_empty', 'pages.' . $pageType . '.' . $field, 'high', [
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

            $content = \trim((string)($block['content'] ?? ''));
            if ($content === '' || $this->isPromptLikeText($content)) {
                $issues[] = $this->issue('instruction_like_or_empty', $path . '.content', 'high', [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'snippet' => $this->clip($content),
                ]);
            }

            $this->validateDesignTags($block, $pageContract, $path, $pageType, $blockKey, $issues);
            $this->validateFieldPlan($block, $pageContract, $path, $pageType, $blockKey, $issues);
            $this->validateExecutionScript($block, $path, $pageType, $blockKey, $issues);
            $this->validateBlockContentDiversity($block, $path, $pageType, $blockKey, $contentFingerprints, $issues);
            $visualSignatures[] = [
                'index' => $index,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'signature' => $this->validateBlockVisualSignature($block, $pageContract, $path, $pageType, $blockKey, $issues),
            ];
            $this->validateBlockImageIntent($block, $pageContract, $path, $pageType, $blockKey, $issues);
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
        $this->validateRequiredBlockOrder($blocks, $requiredBlockOrder, $pageType, $issues);
        $this->validateVisualSignatureDiversity($visualSignatures, $pageType, $issues);

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
                $issues[] = $this->issue('missing_design_tag', $path . '.design_tags.' . $tagKey, 'high', [
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
        if (\count($fieldPlan) !== $expectedCount) {
            $issues[] = $this->issue('invalid_field_plan_count', $path . '.field_plan', 'high', [
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
                if ($value === '' || $this->isPromptLikeText($value)) {
                    $issues[] = $this->issue('instruction_like_or_empty', $path . '.field_plan.' . $index . '.' . $field, $field === 'implementation_note' ? 'medium' : 'high', [
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
            $issues[] = $this->issue('instruction_like_or_empty', $path . '.execution_script.core_copy', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'snippet' => $this->clip($coreCopy),
            ]);
        }
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
                $issues[] = $this->issue('duplicate_block_message', $path . '.' . ($field === 'core_copy' ? 'execution_script.core_copy' : $field), 'high', [
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

        if ($needsImage) {
            foreach (['image_role', 'image_subject', 'placement', 'reuse_policy'] as $field) {
                $value = $this->normalizeSignatureText($intent[$field] ?? null);
                if ($value === '' || $this->isPromptLikeText($value)) {
                    $issues[] = $this->issue('invalid_image_intent_field', $path . '.image_intent.' . $field, 'high', [
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'expected' => 'concrete image role, subject, placement, and reuse policy',
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
            return;
        }

        $cssMotif = $this->normalizeSignatureText($intent['css_motif'] ?? null);
        $rationale = $this->normalizeSignatureText($intent['rationale'] ?? null);
        if (($cssMotif === '' || $this->isPromptLikeText($cssMotif)) && ($rationale === '' || $this->isPromptLikeText($rationale))) {
            $issues[] = $this->issue('missing_css_motif_for_no_image_block', $path . '.image_intent.css_motif', 'high', [
                'page_type' => $pageType,
                'block_key' => $blockKey,
            ]);
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
     * @param array<string, mixed> $block
     */
    private function detectNoImageIntentPlanConflict(array $block): ?string
    {
        if ($this->blockDeclaresCssOnlyVisual($block)) {
            return null;
        }

        $role = $this->normalizeSignatureText($block['page_flow_role'] ?? null);
        if (\in_array($role, ['opening', 'hero', 'proof'], true)) {
            return 'opening/proof block declared without an image or CSS-only visual rationale';
        }

        $mediaText = $this->normalizeSignatureText([
            $block['execution_script']['media_assets'] ?? null,
            $block['media_assets'] ?? null,
            $block['visual_signature']['media_strategy'] ?? null,
        ]);
        if ($mediaText !== '' && \preg_match('/\b(?:image|photo|visual|illustration|screenshot|mockup|scene|hero|banner|card|avatar|icon)\b/u', $mediaText) === 1) {
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
            $intent['rationale'] ?? null,
            $block['visual_signature']['media_strategy'] ?? null,
        ]);
        if ($text === '') {
            return false;
        }

        return \preg_match('/\b(?:css-only|css only|no image|without image|no generated image|gradient|pattern|motif|shape|decorative css|css illustration|css icon)\b/u', $text) === 1;
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

        return \preg_match('/\b(?:scene|photo|photograph|illustration|cinematic|editorial|environment|people|players|table|room|product|device|screenshot|mockup)\b/u', $subject) !== 1;
    }

    /**
     * @param list<array<string, mixed>> $visualSignatures
     * @param list<array<string, mixed>> $issues
     */
    private function validateVisualSignatureDiversity(array $visualSignatures, string $pageType, array &$issues): void
    {
        $previousFingerprint = '';
        $compositionCounts = [];
        foreach ($visualSignatures as $row) {
            $signature = \is_array($row['signature'] ?? null) ? $row['signature'] : [];
            $fingerprint = $this->visualSignatureFingerprint($signature, ['composition_pattern', 'surface_treatment', 'media_strategy']);
            if ($fingerprint !== '' && $fingerprint === $previousFingerprint) {
                $issues[] = $this->issue('adjacent_visual_signature_duplicate', 'pages.' . $pageType . '.blocks.' . (int)($row['index'] ?? 0) . '.visual_signature', 'high', [
                    'page_type' => $pageType,
                    'block_key' => (string)($row['block_key'] ?? ''),
                    'fingerprint' => $fingerprint,
                ]);
            }
            $previousFingerprint = $fingerprint;

            $composition = $this->normalizeSignatureText($signature['composition_pattern'] ?? '');
            if ($composition !== '') {
                $compositionCounts[$composition] = ($compositionCounts[$composition] ?? 0) + 1;
            }
        }

        foreach ($compositionCounts as $composition => $count) {
            if ($count <= 2) {
                continue;
            }
            $issues[] = $this->issue('overused_composition_pattern', 'pages.' . $pageType . '.blocks.visual_signature.composition_pattern', 'medium', [
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
        if (\in_array($normalized, ['true', 'yes', 'y', '1', 'required', 'needed'], true)) {
            return [true, true];
        }
        if (\in_array($normalized, ['false', 'no', 'n', '0', 'none', 'not_needed'], true)) {
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
        $firstPass = $blocking === []
            && $recoveryCount === 0
            && $retryableFailureCount === 0
            && $localRepairRounds === 0
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

        return \preg_match('/^(?:write|rewrite|describe|use this field|do not output|围绕|突出|说明|完善|优化)\b/iu', $value) === 1;
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
