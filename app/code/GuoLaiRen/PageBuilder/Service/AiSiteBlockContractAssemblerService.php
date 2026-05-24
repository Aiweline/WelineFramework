<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteBlockContractAssemblerService
{
    private const CONTRACT_VERSION = '2.2';

    public function __construct(
        private readonly ?AiSiteBlockMorphologyRegistry $morphologyRegistry = null,
        private readonly ?AiSiteDesignDirectorService $designDirector = null
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $pagePlans
     * @param array<string, mixed> $siteDesignSystem
     * @param array<string, mixed> $assetManifest
     * @return array<string, mixed>
     */
    public function assemble(
        array $scope,
        array $websiteProfile,
        array $planJson,
        array $pagePlans,
        array $siteDesignSystem,
        array $assetManifest = []
    ): array {
        if ($siteDesignSystem === []) {
            $siteDesignSystem = $this->designDirector()->materialize(
                $scope,
                $websiteProfile,
                \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [],
                $pagePlans
            );
        }

        $normalizedPagePlans = $this->normalizePagePlans($planJson, $pagePlans);
        $updatedPages = [];
        $distribution = [
            'version' => '1.0',
            'density' => (string)($siteDesignSystem['media_strategy']['density'] ?? 'rich'),
            'page_asset_density' => (string)($siteDesignSystem['media_strategy']['page_asset_density'] ?? 'medium'),
            'per_page' => [],
        ];

        foreach ($normalizedPagePlans as $pageType => $pagePlan) {
            $blocks = $this->normalizeBlocks($pagePlan);
            $imageTargets = $this->pageImageTargets((string)$pageType, \count($blocks), $siteDesignSystem, $scope);
            $requiredImageIndexes = $this->selectRequiredImageIndexes(
                (string)$pageType,
                $blocks,
                $siteDesignSystem,
                $scope,
                $imageTargets
            );
            $previousMorphologyId = '';
            $usedMorphologyIds = [];
            $requiredSlots = [];
            $cssOnlyBlocks = [];
            foreach ($blocks as $index => $block) {
                $role = $this->normalizeRole((string)($block['page_flow_role'] ?? ''));
                if ($role === '') {
                    $role = $this->inferRole((int)$index, (string)($block['block_key'] ?? $block['section_code'] ?? ''));
                }
                $needsImage = \in_array((int)$index, $requiredImageIndexes, true);
                $morphology = $this->selectMorphology(
                    (string)$pageType,
                    $role,
                    $needsImage,
                    $previousMorphologyId,
                    $usedMorphologyIds,
                    $siteDesignSystem
                );
                $previousForContract = $previousMorphologyId;
                $morphologyId = (string)$morphology['id'];
                $previousMorphologyId = $morphologyId;
                $usedMorphologyIds[] = $morphologyId;

                $blockKey = $this->resolveBlockKey($block, (int)$index);
                $sectionCode = $this->resolveSectionCode((string)$pageType, $blockKey, $block);
                $contract = $this->buildBlockContract(
                    (string)$pageType,
                    $blockKey,
                    $sectionCode,
                    $role,
                    $block,
                    $morphology,
                    $needsImage,
                    $siteDesignSystem,
                    $previousForContract
                );

                $block['page_flow_role'] = $role;
                $block['morphology_id'] = $morphologyId;
                $block['block_contract'] = $contract;
                $block['visual_signature'] = $this->buildVisualSignature($contract);
                $block['image_intent'] = $this->buildImageIntent($contract);
                $block['asset_requirements'] = $needsImage
                    ? [$this->buildAssetRequirement($contract)]
                    : [];

                if ($needsImage) {
                    $requiredSlots[] = (string)($contract['media_strategy']['asset_slot_id'] ?? '');
                } else {
                    $cssOnlyBlocks[] = $blockKey;
                }

                $blocks[$index] = $block;
            }

            $pagePlan['blocks'] = \array_values($blocks);
            $pagePlan['asset_distribution_policy'] = $this->buildAssetDistributionPolicy(
                (string)$pageType,
                $pagePlan['blocks'],
                $requiredSlots,
                $cssOnlyBlocks,
                $imageTargets
            );
            $updatedPages[(string)$pageType] = $pagePlan;
            $distribution['per_page'][(string)$pageType] = $pagePlan['asset_distribution_policy'];
        }

        return [
            'site_design_system' => $siteDesignSystem,
            'page_plans' => $updatedPages,
            'asset_distribution_policy' => $distribution,
            'asset_manifest_ref' => $this->assetManifestRef($assetManifest),
            'contract_summary' => $this->buildContractSummary($updatedPages),
        ];
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $pagePlans
     * @return array<string, array<string, mixed>>
     */
    public function attachToPagePlans(
        array $scope,
        array $websiteProfile,
        array $planJson,
        array $pagePlans,
        array $siteDesignSystem,
        array $assetManifest = []
    ): array {
        $assembled = $this->assemble($scope, $websiteProfile, $planJson, $pagePlans, $siteDesignSystem, $assetManifest);

        return \is_array($assembled['page_plans'] ?? null) ? $assembled['page_plans'] : [];
    }

    private function morphologyRegistry(): AiSiteBlockMorphologyRegistry
    {
        return $this->morphologyRegistry ?? new AiSiteBlockMorphologyRegistry();
    }

    private function designDirector(): AiSiteDesignDirectorService
    {
        return $this->designDirector ?? new AiSiteDesignDirectorService($this->morphologyRegistry());
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $pagePlans
     * @return array<string, array<string, mixed>>
     */
    private function normalizePagePlans(array $planJson, array $pagePlans): array
    {
        $pages = [];
        if ($pagePlans !== []) {
            foreach ($pagePlans as $key => $plan) {
                if (!\is_array($plan)) {
                    continue;
                }
                $pageType = \is_string($key) && !\is_numeric($key)
                    ? $key
                    : (string)($plan['page_type'] ?? $plan['page_key'] ?? 'home_page');
                $pages[$this->normalizeToken($pageType)] = $plan;
            }
        }
        if ($pages === [] && \is_array($planJson['pages'] ?? null)) {
            foreach ($planJson['pages'] as $key => $plan) {
                if (!\is_array($plan)) {
                    continue;
                }
                $pageType = \is_string($key) && !\is_numeric($key)
                    ? $key
                    : (string)($plan['page_type'] ?? $plan['page_key'] ?? 'home_page');
                $pages[$this->normalizeToken($pageType)] = $plan;
            }
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @return list<array<string, mixed>>
     */
    private function normalizeBlocks(array $pagePlan): array
    {
        foreach (['blocks', 'sections', 'components'] as $key) {
            if (\is_array($pagePlan[$key] ?? null)) {
                return \array_values(\array_filter($pagePlan[$key], 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $siteDesignSystem
     * @param array<string, mixed> $scope
     * @return list<int>
     */
    private function selectRequiredImageIndexes(
        string $pageType,
        array $blocks,
        array $siteDesignSystem,
        array $scope,
        array $targets
    ): array
    {
        if ($blocks === [] || $this->isLowImagery($siteDesignSystem, $scope) || $this->isPolicyPage($pageType)) {
            return $this->existingRequiredImageIndexes($blocks);
        }

        $required = $this->existingRequiredImageIndexes($blocks);
        $target = (int)($targets['target_real_image_slots'] ?? 1);
        $minNonHero = (int)($targets['min_non_hero_real_image_slots'] ?? 0);
        $preferredRoles = $this->stringList($targets['preferred_roles'] ?? ['opening', 'proof', 'details', 'support']);

        if ($target <= 0) {
            return [];
        }

        if ($minNonHero >= 2 && \count($blocks) >= 4) {
            $required = $this->addFirstEligibleByRoles($blocks, $required, ['opening', 'hero'], 1);
            $required = $this->addFirstEligibleByRoles($blocks, $required, ['proof', 'details', 'support'], $target);
            $required = $this->addFirstEligibleNonHero($blocks, $required, $target);
        } else {
            $required = $this->addFirstEligibleByRoles($blocks, $required, $preferredRoles, $target);
            $required = $this->addFirstEligibleNonHero($blocks, $required, $target);
        }

        return \array_slice(\array_values(\array_unique($required)), 0, \max($target, \count($required)));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<int>
     */
    private function existingRequiredImageIndexes(array $blocks): array
    {
        $indexes = [];
        foreach ($blocks as $index => $block) {
            $intent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
            if (\array_key_exists('needs_image', $intent) && $this->truthy($intent['needs_image'] ?? false)) {
                $indexes[] = (int)$index;
            }
        }

        return $indexes;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<int> $required
     * @param list<string> $roles
     * @return list<int>
     */
    private function addFirstEligibleByRoles(array $blocks, array $required, array $roles, int $target): array
    {
        foreach ($roles as $role) {
            foreach ($blocks as $index => $block) {
                if (\in_array((int)$index, $required, true)) {
                    continue;
                }
                $blockRole = $this->normalizeRole((string)($block['page_flow_role'] ?? ''));
                if ($blockRole === '') {
                    $blockRole = $this->inferRole((int)$index, (string)($block['block_key'] ?? $block['section_code'] ?? ''));
                }
                if ($blockRole !== $this->normalizeRole($role)) {
                    continue;
                }
                $required[] = (int)$index;
                if (\count($required) >= $target) {
                    return $required;
                }
            }
        }

        return $required;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<int> $required
     * @return list<int>
     */
    private function addFirstEligibleNonHero(array $blocks, array $required, int $target): array
    {
        foreach ($blocks as $index => $block) {
            if (\count($required) >= $target) {
                break;
            }
            if (\in_array((int)$index, $required, true) || (int)$index === 0) {
                continue;
            }
            $role = $this->normalizeRole((string)($block['page_flow_role'] ?? ''));
            if ($role === 'cta') {
                continue;
            }
            $required[] = (int)$index;
        }

        return $required;
    }

    /**
     * @return array{
     *   page_type:string,
     *   target_real_image_slots:int,
     *   min_non_hero_real_image_slots:int,
     *   max_real_image_slots:int,
     *   preferred_roles:list<string>,
     *   avoid_roles:list<string>
     * }
     */
    private function pageImageTargets(string $pageType, int $blockCount, array $siteDesignSystem, array $scope): array
    {
        $normalizedPageType = $this->normalizeToken($pageType);
        $lowImagery = $this->isLowImagery($siteDesignSystem, $scope);
        $policyPage = $this->isPolicyPage($normalizedPageType);
        $target = 0;
        $minNonHero = 0;
        $max = 0;
        $preferred = ['opening', 'proof', 'details', 'support'];
        $avoid = ['cta'];

        if (!$lowImagery && !$policyPage && $blockCount > 0) {
            if (\in_array($normalizedPageType, ['home', 'homepage', 'home_page'], true)) {
                $target = $blockCount >= 4 ? 3 : \min(2, $blockCount);
                $minNonHero = $blockCount >= 4 ? 2 : \max(0, $target - 1);
                $max = \min(5, $blockCount);
                $preferred = ['opening', 'proof', 'details', 'support'];
            } elseif (\str_contains($normalizedPageType, 'service')) {
                $target = \min(\max(3, $blockCount >= 5 ? 4 : 3), $blockCount);
                $minNonHero = \min(3, \max(1, $target - 1));
                $max = \min(5, $blockCount);
                $preferred = ['details', 'proof', 'support', 'opening'];
            } elseif (\str_contains($normalizedPageType, 'about')) {
                $target = \min(\max(2, $blockCount >= 4 ? 3 : 2), $blockCount);
                $minNonHero = \min(2, \max(1, $target - 1));
                $max = \min(4, $blockCount);
                $preferred = ['proof', 'details', 'support', 'opening'];
            } elseif (\str_contains($normalizedPageType, 'contact') || \str_contains($normalizedPageType, 'support')) {
                $target = \min(\max(1, $blockCount >= 3 ? 2 : 1), $blockCount);
                $minNonHero = \min(1, \max(0, $target - 1));
                $max = \min(3, $blockCount);
                $preferred = ['support', 'details', 'opening'];
            } else {
                $target = $blockCount >= 4 ? \min(3, $blockCount) : \min(1, $blockCount);
                $minNonHero = $blockCount >= 4 ? \min(2, \max(0, $target - 1)) : 0;
                $max = \min(4, $blockCount);
            }
        }

        return [
            'page_type' => $normalizedPageType,
            'target_real_image_slots' => $target,
            'min_non_hero_real_image_slots' => $minNonHero,
            'max_real_image_slots' => $max,
            'preferred_roles' => $preferred,
            'avoid_roles' => $avoid,
        ];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param list<string> $requiredSlots
     * @param list<string> $cssOnlyBlocks
     * @param array<string,mixed> $targets
     * @return array<string,mixed>
     */
    private function buildAssetDistributionPolicy(
        string $pageType,
        array $blocks,
        array $requiredSlots,
        array $cssOnlyBlocks,
        array $targets
    ): array {
        $requiredSlots = \array_values(\array_filter($requiredSlots));
        $nonHeroRequired = $this->countNonHeroRequiredImages($blocks);

        return [
            'page_type' => $pageType,
            'target_real_image_slots' => (int)($targets['target_real_image_slots'] ?? \count($requiredSlots)),
            'min_non_hero_real_image_slots' => (int)($targets['min_non_hero_real_image_slots'] ?? 0),
            'max_real_image_slots' => (int)($targets['max_real_image_slots'] ?? \count($requiredSlots)),
            'preferred_roles' => $this->stringList($targets['preferred_roles'] ?? []),
            'avoid_roles' => $this->stringList($targets['avoid_roles'] ?? []),
            'required_image_slots' => $requiredSlots,
            'required_image_count' => \count($requiredSlots),
            'non_hero_required_image_count' => $nonHeroRequired,
            'css_only_blocks' => $cssOnlyBlocks,
        ];
    }

    /**
     * @param list<string> $usedMorphologyIds
     * @param array<string, mixed> $siteDesignSystem
     * @return array<string, mixed>
     */
    private function selectMorphology(
        string $pageType,
        string $role,
        bool $needsImage,
        string $previousMorphologyId,
        array $usedMorphologyIds,
        array $siteDesignSystem
    ): array {
        $pool = \array_fill_keys($this->stringList($siteDesignSystem['morphology_pool'] ?? []), true);
        $candidates = $this->morphologyRegistry()->selectCandidates($pageType, $role, [
            'needs_image' => $needsImage,
        ]);
        $fallback = [];
        foreach ($candidates as $candidate) {
            $id = (string)($candidate['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($pool !== [] && !isset($pool[$id])) {
                $fallback[] = $candidate;
                continue;
            }
            if ($id !== $previousMorphologyId && !\in_array($id, $usedMorphologyIds, true)) {
                return $candidate;
            }
        }
        foreach (\array_merge($candidates, $fallback) as $candidate) {
            $id = (string)($candidate['id'] ?? '');
            if ($id !== '' && $id !== $previousMorphologyId) {
                return $candidate;
            }
        }
        if ($candidates !== []) {
            return $candidates[0];
        }

        return $this->morphologyRegistry()->get('editorial_split_media');
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $morphology
     * @param array<string, mixed> $siteDesignSystem
     * @return array<string, mixed>
     */
    private function buildBlockContract(
        string $pageType,
        string $blockKey,
        string $sectionCode,
        string $role,
        array $block,
        array $morphology,
        bool $needsImage,
        array $siteDesignSystem,
        string $previousMorphologyId
    ): array {
        $morphologyId = (string)($morphology['id'] ?? '');
        $goal = $this->firstMeaningfulString([
            $block['goal'] ?? null,
            $block['block_goal'] ?? null,
            $block['content'] ?? null,
            $block['title'] ?? null,
        ], $role . ' section for ' . $pageType);
        $assetSlotId = $needsImage ? $this->assetSlotId($pageType, $blockKey, $role) : '';
        $cssMotif = $this->cssMotif($morphologyId, $role, $goal);

        return [
            'version' => self::CONTRACT_VERSION,
            'page_type' => $pageType,
            'block_key' => $blockKey,
            'section_code' => $sectionCode,
            'page_flow_role' => $role,
            'block_goal' => $goal,
            'morphology_id' => $morphologyId,
            'composition_pattern' => [
                'layout_keywords' => $this->stringList($morphology['layout_keywords'] ?? []),
                'required_html_signals' => $this->stringList($morphology['required_html_signals'] ?? []),
                'css_signals' => $this->stringList($morphology['css_signals'] ?? []),
            ],
            'content_hierarchy' => [
                'primary' => $this->firstMeaningfulString([$block['title'] ?? null, $block['headline'] ?? null, $goal], $goal),
                'secondary' => $this->firstMeaningfulString([$block['content'] ?? null, $block['summary'] ?? null], $goal),
                'action' => $this->firstMeaningfulString([$block['cta_label'] ?? null, $block['action_label'] ?? null], 'primary action aligned to this block'),
            ],
            'media_strategy' => [
                'needs_real_image' => $needsImage,
                'asset_slot_id' => $assetSlotId,
                'placement' => $needsImage ? (string)($morphology['default_media_placement'] ?? 'media_panel') : 'inline_visual',
                'image_subject' => $needsImage ? $this->imageSubject($goal, $role, $pageType) : 'CSS motif expressing ' . $goal,
                'image_treatment' => $needsImage
                    ? 'responsive crop, integrated frame, accessible alt text, and palette-compatible overlay'
                    : 'structured CSS motif with visible layers, labels, dividers, and responsive spacing',
                'css_motif' => $needsImage ? '' : $cssMotif,
                'allow_css_only' => !$needsImage,
            ],
            'style_tokens' => [
                'color_roles' => \is_array($siteDesignSystem['tokens']['color_roles'] ?? null)
                    ? $siteDesignSystem['tokens']['color_roles']
                    : [],
                'typography' => \is_array($siteDesignSystem['tokens']['typography'] ?? null)
                    ? $siteDesignSystem['tokens']['typography']
                    : [],
                'surface_role' => $role === 'opening' ? 'surface.canvas' : 'surface.elevated',
            ],
            'responsive_contract' => [
                'desktop' => 'use the morphology grid and keep text, media, and actions inside the section rhythm',
                'tablet' => 'reduce columns while preserving hierarchy and image crop',
                'mobile' => 'single-column stack with media before or after copy according to the block role',
            ],
            'diversity_constraints' => [
                'previous_morphology_id' => $previousMorphologyId,
                'must_differ_from_previous_block' => ['morphology_id', 'media_placement', 'background_layer'],
                'forbidden_repetition' => [
                    'same title+paragraph+button skeleton',
                    'same two-column split repeated three times',
                ],
                'must_not_repeat_adjacent_morphology' => true,
                'must_not_render_as_title_paragraph_cta_only' => true,
            ],
            'acceptance_checks' => \array_values(\array_unique(\array_merge(
                $this->stringList($morphology['acceptance_checks'] ?? []),
                [
                    'block_contract_fields_present',
                    $needsImage ? 'required_real_image_slot_present' : 'css_motif_present_when_no_real_image',
                    'responsive_desktop_mobile_pass',
                ]
            ))),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, string>
     */
    private function buildVisualSignature(array $contract): array
    {
        $morphologyId = (string)($contract['morphology_id'] ?? '');
        $media = \is_array($contract['media_strategy'] ?? null) ? $contract['media_strategy'] : [];
        $needsImage = (bool)($media['needs_real_image'] ?? false);
        $role = (string)($contract['page_flow_role'] ?? 'details');

        return [
            'composition_pattern' => $morphologyId . ' for ' . $role . ' content',
            'spatial_rhythm' => 'morphology-led spacing with clear copy, proof, and action zones',
            'media_strategy' => $needsImage
                ? 'Generated image slot ' . (string)($media['asset_slot_id'] ?? '') . ' integrated through ' . (string)($media['placement'] ?? 'media_panel')
                : 'CSS-only/no generated image; ' . (string)($media['css_motif'] ?? 'structured motif'),
            'surface_treatment' => 'palette-driven surfaces, restrained depth, and visible section boundaries',
            'interaction_pattern' => 'subtle hover and focus states without layout shift',
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildImageIntent(array $contract): array
    {
        $media = \is_array($contract['media_strategy'] ?? null) ? $contract['media_strategy'] : [];
        $needsImage = (bool)($media['needs_real_image'] ?? false);
        $role = (string)($contract['page_flow_role'] ?? 'details');

        return [
            'needs_image' => $needsImage,
            'image_role' => $needsImage ? ($role === 'opening' ? 'hero_image' : 'section_image') : 'css_motif',
            'image_subject' => (string)($media['image_subject'] ?? ''),
            'placement' => (string)($media['placement'] ?? ($needsImage ? 'media_panel' : 'inline_visual')),
            'visual_atmosphere' => 'aligned with the confirmed site design system and block goal',
            'image_treatment' => (string)($media['image_treatment'] ?? ''),
            'reuse_policy' => $needsImage ? 'reuse_when_intent_matches' : 'no_generated_image',
            'css_motif' => $needsImage ? '' : (string)($media['css_motif'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildAssetRequirement(array $contract): array
    {
        $media = \is_array($contract['media_strategy'] ?? null) ? $contract['media_strategy'] : [];

        return [
            'slot_id' => (string)($media['asset_slot_id'] ?? ''),
            'kind' => (string)($contract['page_flow_role'] ?? '') === 'opening' ? 'hero_image' : 'section_image',
            'required' => true,
            'subject' => (string)($media['image_subject'] ?? ''),
            'placement' => (string)($media['placement'] ?? 'media_panel'),
            'treatment' => (string)($media['image_treatment'] ?? ''),
            'contract_ref' => 'block_contract.media_strategy',
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function countNonHeroRequiredImages(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $index => $block) {
            if ((int)$index === 0) {
                continue;
            }
            $contract = \is_array($block['block_contract'] ?? null) ? $block['block_contract'] : [];
            $media = \is_array($contract['media_strategy'] ?? null) ? $contract['media_strategy'] : [];
            if (!empty($media['needs_real_image'])) {
                $count++;
            }
        }

        return $count;
    }

    private function resolveBlockKey(array $block, int $index): string
    {
        $key = $this->normalizeToken((string)($block['block_key'] ?? $block['section_code'] ?? $block['id'] ?? ''));
        return $key !== '' ? $key : 'block_' . ($index + 1);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveSectionCode(string $pageType, string $blockKey, array $block): string
    {
        $code = \trim((string)($block['section_code'] ?? ''));
        if ($code !== '') {
            return $code;
        }

        return 'content/' . \str_replace('_', '-', $pageType) . '-' . \str_replace('_', '-', $blockKey);
    }

    private function assetSlotId(string $pageType, string $blockKey, string $role): string
    {
        return 'page:' . $this->normalizeToken($pageType) . ':' . $this->normalizeToken($blockKey) . ':' . $this->normalizeToken($role) . ':image';
    }

    private function imageSubject(string $goal, string $role, string $pageType): string
    {
        return $role . ' visual for ' . $pageType . ': ' . $goal;
    }

    private function cssMotif(string $morphologyId, string $role, string $goal): string
    {
        return $morphologyId . ' CSS motif for ' . $role . ': ' . $goal;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstMeaningfulString(array $values, string $fallback): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '' && !\in_array(\strtolower($text), ['string', 'placeholder', 'same as above'], true)) {
                return $text;
            }
        }

        return $fallback;
    }

    private function inferRole(int $index, string $blockKey): string
    {
        $key = $this->normalizeToken($blockKey);
        if ($index === 0 || \str_contains($key, 'hero') || \str_contains($key, 'intro')) {
            return 'opening';
        }
        if (\str_contains($key, 'proof') || \str_contains($key, 'trust') || \str_contains($key, 'review') || \str_contains($key, 'metric')) {
            return 'proof';
        }
        if (\str_contains($key, 'faq') || \str_contains($key, 'support') || \str_contains($key, 'contact')) {
            return 'support';
        }
        if (\str_contains($key, 'cta') || \str_contains($key, 'action') || \str_contains($key, 'contact')) {
            return 'cta';
        }

        return 'details';
    }

    private function normalizeRole(string $role): string
    {
        $role = $this->normalizeToken($role);
        return match ($role) {
            'hero', 'intro', 'lead', 'opening_conversion' => 'opening',
            'trust', 'evidence', 'validation', 'metric', 'metrics' => 'proof',
            'feature', 'features', 'service', 'services', 'detail' => 'details',
            'faq', 'help', 'contact' => 'support',
            'conversion', 'action', 'final_cta', 'download_cta' => 'cta',
            default => $role,
        };
    }

    private function isLowImagery(array $siteDesignSystem, array $scope): bool
    {
        $density = \strtolower(\trim((string)($siteDesignSystem['media_strategy']['density'] ?? '')));
        if ($density === 'minimal') {
            return true;
        }
        $brief = \strtolower(\implode(' ', \array_filter(\array_map(static function (mixed $value): string {
            return \is_scalar($value) ? (string)$value : '';
        }, [
            $scope['brief_description'] ?? null,
            $scope['user_prompt'] ?? null,
            $scope['style_preference'] ?? null,
        ]))));

        foreach (['no image', 'no images', 'without images', 'minimal imagery', 'text only'] as $needle) {
            if ($brief !== '' && \str_contains($brief, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPolicyPage(string $pageType): bool
    {
        $pageType = $this->normalizeToken($pageType);
        foreach (['privacy', 'terms', 'cookie', 'refund', 'policy', 'legal'] as $needle) {
            if (\str_contains($pageType, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $assetManifest
     * @return array<string, mixed>
     */
    private function assetManifestRef(array $assetManifest): array
    {
        if ($assetManifest === []) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'hash' => (string)($assetManifest['hash'] ?? $assetManifest['manifest_hash'] ?? ''),
            'slot_count' => \is_array($assetManifest['slots'] ?? null) ? \count($assetManifest['slots']) : 0,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $pages
     * @return array<string, mixed>
     */
    private function buildContractSummary(array $pages): array
    {
        $totalBlocks = 0;
        $withContract = 0;
        $requiredImages = 0;
        $morphologies = [];
        foreach ($pages as $page) {
            foreach ($this->normalizeBlocks($page) as $block) {
                $totalBlocks++;
                $contract = \is_array($block['block_contract'] ?? null) ? $block['block_contract'] : [];
                if ($contract !== []) {
                    $withContract++;
                    $morphology = (string)($contract['morphology_id'] ?? '');
                    if ($morphology !== '') {
                        $morphologies[] = $morphology;
                    }
                    if (!empty($contract['media_strategy']['needs_real_image'])) {
                        $requiredImages++;
                    }
                }
            }
        }

        return [
            'total_blocks' => $totalBlocks,
            'blocks_with_contract' => $withContract,
            'required_image_blocks' => $requiredImages,
            'unique_morphology_count' => \count(\array_unique($morphologies)),
        ];
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            $values = [$values];
        }

        $out = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return \array_values(\array_unique($out));
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((int)$value) === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'required', 'needed'], true);
    }

    private function normalizeToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? $value;
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_-');
    }
}
