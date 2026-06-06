<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonContentManifestLinter;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonContractSchema;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;
use Weline\Ai\Service\AiService;
use Weline\Framework\App\Env;
use Weline\Framework\Php\FiberTaskRunner;

final class AiSitePlanJsonGenerationService
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

    private const DEFAULT_POLICY_RULES = [
        'priority.user_requirements_first',
        'priority.default_premium_when_unspecified',
        'layout.grid_alignment',
        'layout.4_8_spacing',
        'typography.refined_font_stack',
        'color.readable_contrast',
        'image.integrated_not_pasted',
        'responsive.no_horizontal_scroll',
        'a11y.alt_focus_semantic',
    ];

    private const STAGE_ONE_PAGE_MAX_AI_ATTEMPTS = 2;

    private readonly ?AiSitePageBlueprintService $pageBlueprintService;

    private readonly ?AiService $aiService;

    private readonly ?AiSiteDesignPolicyRegistry $policyRegistry;

    private readonly ?PlanJsonContractValidator $validator;

    private readonly ?AiSitePlanJsonProjectionService $projectionService;

    private readonly AiSiteStageOneContractService $stageOneContractService;

    private readonly AiSiteStageOneContractValidator $stageOneContractValidator;

    private readonly AiSiteStageOnePromptAssembler $stageOnePromptAssembler;

    private readonly AiSitePlanJsonStateService $planJsonStateService;

    public function __construct(
        mixed $pageBlueprintOrPolicyRegistry = null,
        mixed $aiServiceOrValidator = null,
        ?AiSitePlanJsonProjectionService $projectionService = null,
        ?AiSiteStageOneContractService $stageOneContractService = null,
        ?AiSiteStageOneContractValidator $stageOneContractValidator = null,
        ?AiSiteStageOnePromptAssembler $stageOnePromptAssembler = null,
        ?AiSitePlanJsonStateService $planJsonStateService = null
    ) {
        $this->pageBlueprintService = $pageBlueprintOrPolicyRegistry instanceof AiSitePageBlueprintService
            ? $pageBlueprintOrPolicyRegistry
            : null;
        $this->aiService = $aiServiceOrValidator instanceof AiService ? $aiServiceOrValidator : null;
        $this->policyRegistry = $pageBlueprintOrPolicyRegistry instanceof AiSiteDesignPolicyRegistry
            ? $pageBlueprintOrPolicyRegistry
            : null;
        $this->validator = $aiServiceOrValidator instanceof PlanJsonContractValidator ? $aiServiceOrValidator : null;
        $this->projectionService = $projectionService;
        $this->stageOneContractService = $stageOneContractService ?? new AiSiteStageOneContractService();
        $this->stageOneContractValidator = $stageOneContractValidator ?? new AiSiteStageOneContractValidator($this->stageOneContractService);
        $this->stageOnePromptAssembler = $stageOnePromptAssembler ?? new AiSiteStageOnePromptAssembler();
        $this->planJsonStateService = $planJsonStateService ?? new AiSitePlanJsonStateService();
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function buildFromScope(array $scope, array $websiteProfile = []): array
    {
        $policy = $this->policyRegistry()->get();
        $policyRef = $this->policyRegistry()->policyRef();
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];

        $sourcePlan = $this->selectStageOneSourcePlan($planJson);
        if ($sourcePlan === []) {
            throw new \RuntimeException('PlanJson contract failed: stage-one plan JSON is missing. Regenerate the plan.');
        }
        $sourceSignature = $this->sourceSignature($scope, $sourcePlan);
        $expectedPageTypes = $this->resolvePageTypes($scope, $sourcePlan);

        $profile = \array_replace(
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $websiteProfile
        );
        $siteStrategy = \is_array($sourcePlan['site_strategy'] ?? null) ? $sourcePlan['site_strategy'] : [];
        $siteName = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $siteStrategy['site_display_name'] ?? null,
            $profile['site_title'] ?? null,
            $profile['site_name'] ?? null,
            $scope['store_name'] ?? null,
            'AI Site',
        ]);
        $primaryGoal = $this->resolvePlanJsonPrimaryGoal($scope, $profile);
        $locale = $this->resolvePlanJsonContentLocale($scope, $profile, $sourcePlan);
        $contractId = 'plan_json_' . \substr($sourceSignature, 0, 16);
        $sourceContracts = $this->buildSourceContractRefs($scope, $sourceSignature);
        $sourceOfTruth = $this->planJsonJsonSourceOfTruth(
            [],
            $scope,
            $sourcePlan,
            $sourceSignature,
            $siteName,
            $primaryGoal,
            $expectedPageTypes
        );

        [$pages, $blocks, $contentItems] = $this->buildPageBlockGraph(
            $scope,
            $sourcePlan,
            $siteName,
            $primaryGoal,
            $locale
        );

        $contentItems = \array_replace(
            [
                'site.name' => $siteName,
                'site.primary_goal' => $primaryGoal,
                'site.allowed_brand_terms' => \implode(', ', $this->buildAllowedBrandTerms($scope, $profile, $siteName)),
                'site.forbidden_template_brand_terms' => \implode(', ', $this->buildForbiddenTemplateBrandTerms($scope, $profile, $siteName)),
            ],
            $contentItems
        );

        $contract = [
            'contract_meta' => [
                'id' => $contractId,
                'version' => PlanJsonContractSchema::VERSION,
                'type' => 'plan_json',
                'stage' => ContractType::STAGE_STAGE1,
                'status' => 'draft',
                'creator' => 'AiSitePlanQueue',
                'adapter_type' => 'plan_json_contract_v2_2',
                'created_at' => \date('Y-m-d H:i:s'),
                'source_signature' => $sourceSignature,
            ],
            'source_of_truth' => $sourceOfTruth,
            'policy_ref' => $policyRef,
            'policy_projection' => [
                'applied_rule_ids' => self::DEFAULT_POLICY_RULES,
                'banned_rule_ids' => ['ban.reason_fields', 'ban.lorem_ipsum'],
                'quality_floor' => \is_array($policy['quality_floor'] ?? null) ? $policy['quality_floor'] : [],
                'user_overrides' => [],
            ],
            'site_brief' => [
                'site_name' => $siteName,
                'primary_goal' => $primaryGoal,
                'summary' => $primaryGoal,
                'locale' => $locale,
            ],
            'design_manifest' => $this->planJsonJsonDesignManifest($policy, $sourcePlan),
            'i18n' => [
                'primary_locale' => $locale,
                'required_locales' => [$locale],
            ],
            'content_manifest' => [
                'primary_locale' => $locale,
                'items' => $contentItems,
            ],
            'pages' => $pages,
            'source_contracts' => $sourceContracts,
            'permission_matrix' => [
                'read' => ['policy_ref', 'policy_projection', 'design_manifest', 'content_manifest', 'pages'],
                'create' => ['task_results', 'qa_report', 'repair_patch'],
                'patch' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
                'forbidden' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages'],
                'read_only' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages'],
            ],
            'frozen_fields' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages'],
            'mutable_fields' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
            'qa_gates' => [
                ['id' => 'schema_valid', 'status' => 'pending'],
                ['id' => 'policy_ref_valid', 'status' => 'pending'],
                ['id' => 'responsive_ready', 'status' => 'pending'],
            ],
            'presentation_projection' => [
                'never_feed_to_build' => true,
                'headline_key' => 'site.name',
                'summary_key' => 'site.primary_goal',
            ],
        ];

        AiSiteWorkflowTrace::log('plan_json_contract_built', [
            'contract_id' => $contractId,
            'page_count' => \count($pages),
            'block_count' => \count($blocks),
            'locale' => $locale,
            'site_name' => $siteName,
            'primary_goal' => $primaryGoal,
        ]);
        if (AiSiteWorkflowTrace::verbose()) {
            AiSiteWorkflowTrace::json('plan_json_contract_detail', $contract, [
                'contract_id' => $contractId,
            ]);
        }

        return $contract;
    }

    /**
     * @param array<string, mixed> $stageOne
     * @return array<string, mixed>
     */
    private function PlanJsonJson(array $stageOne): array
    {
        $pages = [];
        foreach (\is_array($stageOne['pages'] ?? null) ? $stageOne['pages'] : [] as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $normalizedPage = [
                'page_type' => (string)($page['page_type'] ?? $pageType),
                'page_goal' => (string)($page['page_goal'] ?? $page['goal'] ?? ''),
                'theme_alignment_summary' => (string)($page['theme_alignment_summary'] ?? ''),
                'page_design_plan' => \is_array($page['page_design_plan'] ?? null) ? $page['page_design_plan'] : [],
            ];
            foreach ($this->collectPlanJsonPageBlocks($page) as $block) {
                $blockKey = \trim((string)($block['block_key'] ?? ''));
                if ($blockKey === '') {
                    continue;
                }
                $normalizedBlock = $block;
                $normalizedBlock['block_key'] = $blockKey;
                $normalizedBlock['section_code'] = (string)($block['section_code'] ?? '');
                $normalizedBlock['sort_order'] = (int)($block['sort_order'] ?? 0);
                $normalizedBlock['status'] = AiSitePlanJsonStateService::STATUS_PENDING;
                $normalizedBlock['content'] = (string)($block['content'] ?? '');
                if (!\is_array($normalizedBlock['fields'] ?? null)) {
                    $normalizedBlock['fields'] = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                }
                $normalizedPage[$blockKey] = $normalizedBlock;
            }
            $pages[(string)$pageType] = $normalizedPage;
        }

        $planJson = \array_replace($stageOne, [
            'content_locale' => (string)($stageOne['i18n']['locale'] ?? $stageOne['content_locale'] ?? 'en_US'),
            'site_strategy' => \is_array($stageOne['site_strategy'] ?? null) ? $stageOne['site_strategy'] : [],
            'pages' => $pages,
        ]);

        return $this->planJsonStateService->normalizePlanJson($planJson);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function PlanJsonArtifacts(array $scope, array $websiteProfile = []): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!\is_array($planJson['pages'] ?? null) || $planJson['pages'] === []) {
            if ((int)($scope['fake_mode'] ?? 0) !== 1) {
                throw new \RuntimeException('PlanJson contract failed: plan_json.pages is missing. Regenerate the plan.');
            }
            $planJson = $this->buildFakeStageOnePlanJson($scope, $websiteProfile);
        }
        $planJson = $this->planJsonStateService->normalizePlanJson($planJson);
        $contract = $this->buildCurrentStageOneContract($scope, $planJson);
        $validation = $this->stageOneContractValidator->validateFullPlan($planJson, $contract, [
            'validation_page_types' => \array_values(\array_keys(\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [])),
            'generation_attempts' => \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [],
        ]);
        $planJson['stage1_validation_report'] = $validation;
        $planJson['stage1_first_pass'] = !empty($validation['first_pass']) ? 1 : 0;

        return [
            'ai_generated' => 0,
            'plan_json' => $this->planJsonStateService->normalizePlanJson($planJson),
            'structured' => $this->planJsonStateService->normalizePlanJson($planJson),
            'markdown' => $this->renderPlanJsonMarkdown($planJson),
            'derived_scope_patch' => $this->buildDerivedScopePatchFromPlanJson($planJson),
            'partial_retry_required' => 0,
            'retryable_ai_failures' => [],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildFakeStageOnePlanJson(array $scope, array $websiteProfile = []): array
    {
        $pageTypes = $this->resolveStageOnePageTypes($scope);
        if ($pageTypes === []) {
            $pageTypes = [Page::TYPE_HOME];
        }
        $locale = $this->resolveStageOneLocale($scope, $websiteProfile);
        $siteName = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            $scope['store_name'] ?? null,
            'Fake Mode Site',
        ]);
        $brief = $this->stageOneBriefText($scope, $websiteProfile);
        if ($brief === '') {
            $brief = 'Clear product story, practical proof, and a direct next step for visitors.';
        }

        $contract = $this->stageOneContractService->build($scope, $pageTypes, $locale, $locale, 'stage1');
        $pages = [];
        foreach ($pageTypes as $pageType) {
            $pages[$pageType] = $this->buildFakeStageOnePage($pageType, $scope, $siteName, $brief, $locale);
        }

        $palette = [
            'primary' => '#1D4ED8',
            'secondary' => '#0F766E',
            'accent' => '#F59E0B',
            'background' => '#F8FAFC',
            'body' => '#111827',
            'button' => '#1D4ED8',
        ];
        $planJson = [
            'confirmed' => 0,
            'content_locale' => $locale,
            'requirement_expansion' => [
                'primary_goal' => $brief,
                'primary_cta' => 'Start now',
                'audience' => 'Qualified visitors comparing the offer',
            ],
            'site_strategy' => [
                'site_display_name' => $siteName,
                'primary_goal' => $brief,
                'audience' => 'Visitors who need a quick, credible overview before taking action.',
            ],
            'theme_style' => [
                'style_name' => 'Fake Mode Clean Product Site',
                'tone' => 'Clear, credible, and action oriented.',
                'layout_direction' => 'Dense but readable sections with strong hierarchy.',
            ],
            'palette' => $palette,
            'theme' => [
                'logo_generation' => $this->buildStageOneLogoGenerationPlan($siteName, $brief, $palette, $locale),
            ],
            'theme_design' => [
                'theme_purpose' => 'Turn the supplied brief into a readable site plan that can be built locally without AI calls.',
                'style_signature' => 'Clean editorial product layout with compact proof panels and calm surfaces.',
                'art_direction' => 'Crisp sections, high contrast text, practical imagery guidance, and minimal decoration.',
                'color_scheme' => $palette,
                'typography_spacing_radius' => [
                    'font_family' => 'Inter, system-ui, sans-serif',
                    'heading_scale' => 'Compact display headings with clear section hierarchy.',
                    'body_scale' => 'Readable body text with short paragraphs.',
                    'spacing_scale' => '8px base spacing with larger section rhythm.',
                    'radius_scale' => '6px content cards and 4px controls.',
                ],
                'visual_keywords' => ['clear structure', 'measured contrast', 'conversion proof'],
                'tone_of_voice' => 'Confident, direct, and practical.',
                'cta_tone' => 'Low-friction and specific.',
                'forbidden_styles' => ['generic placeholder copy', 'invented routes', 'oversized decoration'],
                'selection_reason' => 'Fake mode needs deterministic content that passes the same Stage-1 contract as generated plans.',
            ],
            'navigation_plan' => [
                'header_items' => $this->normalizeStageOneLinks([], $contract, 'navigation_plan.header_items'),
            ],
            'footer_plan' => [
                'featured' => $this->normalizeStageOneLinks([], $contract, 'footer_plan.featured'),
                'policies' => $this->normalizeStageOneLinks([], $contract, 'footer_plan.policies'),
            ],
            'seo_strategy' => [
                'primary_keywords' => [$siteName, 'trusted service', 'site overview'],
                'secondary_keywords' => ['customer proof', 'fast start', 'clear offer'],
                'meta_title_pattern' => $siteName . ' - Clear service overview',
                'meta_description_pattern' => 'A concise overview with proof, benefits, and the next action.',
            ],
            'shared_components' => [
                'buttons' => ['primary_action', 'secondary_link'],
                'cards' => ['proof_panel', 'feature_panel'],
            ],
            'page_route_contract' => \is_array($contract['page_route_contract'] ?? null) ? $contract['page_route_contract'] : [],
            'stage1_generation_attempts' => \array_fill_keys($pageTypes, [
                'success' => true,
                'attempt_no' => 1,
                'fake_mode' => 1,
            ]),
            'pages' => $pages,
        ];

        return $this->planJsonStateService->normalizePlanJson($planJson);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildFakeStageOnePage(string $pageType, array $scope, string $siteName, string $brief, string $locale): array
    {
        $label = $this->localizedPageTitleFallback($pageType, $siteName, $locale);
        $page = [
            'page_type' => $pageType,
            'title' => $label,
            'page_title' => $label,
            'page_goal' => $label . ' presents the offer clearly and moves visitors toward a confident next step.',
            'theme_alignment_summary' => 'This page uses the shared clean product theme with restrained contrast and practical proof.',
            'page_design_plan' => [
                'structure' => 'Ordered sections move from value, to proof, to supporting details, to action.',
                'density' => 'Compact blocks keep the page scannable while retaining concrete copy.',
                'handoff' => 'Each direct block node includes its own content, fields, visual signature, and image intent.',
            ],
            'primary_keywords' => [$siteName, $label],
            'secondary_keywords' => ['proof', 'service details', 'next step'],
            'seo' => [
                'meta_title' => $label . ' - ' . $siteName,
                'meta_description' => 'A concise ' . \strtolower($label) . ' page plan for local fake-mode generation.',
            ],
            'status' => AiSitePlanJsonStateService::STATUS_PENDING,
        ];

        foreach ($this->resolveFakeStageOneBlockKeys($pageType, $scope) as $index => $blockKey) {
            $page[$blockKey] = $this->buildFakeStageOneBlock($pageType, $blockKey, (int)$index, $siteName, $brief);
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function resolveFakeStageOneBlockKeys(string $pageType, array $scope): array
    {
        $budget = $this->stageOneContractService->resolveBlockBudget($pageType, $scope);
        $keys = [];
        foreach (['required', 'optional'] as $bucket) {
            foreach (\is_array($budget[$bucket] ?? null) ? $budget[$bucket] : [] as $blockKey) {
                $blockKey = \trim((string)$blockKey);
                if ($blockKey !== '' && !\in_array($blockKey, $keys, true)) {
                    $keys[] = $blockKey;
                }
            }
        }
        $target = \max(1, (int)($budget['target'] ?? \count($keys)));
        while (\count($keys) < $target) {
            $keys[] = 'supporting_story_' . ((int)\count($keys) + 1);
        }

        return \array_slice($keys, 0, $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFakeStageOneBlock(string $pageType, string $blockKey, int $index, string $siteName, string $brief): array
    {
        $label = $this->humanizeIdentifier($blockKey);
        $pageLabel = $this->humanizeIdentifier($pageType);
        $role = $this->fakeStageOneBlockRole($blockKey, $index);
        $headline = $this->fakeStageOneHeadline($blockKey, $siteName, $index);
        $supportingCopy = $label . ' connects ' . $siteName . ' with the visitor need: ' . $brief;
        $actionLabel = $role === 'cta' ? 'Start now' : 'View details';
        $compositionPatterns = ['split_intro', 'metric_grid', 'proof_stack', 'process_steps', 'question_panel', 'cta_band', 'editorial_strip'];
        $composition = $compositionPatterns[$index % \count($compositionPatterns)];
        $needsImage = $index === 0 || \in_array($role, ['hero', 'proof'], true);

        return [
            'block_key' => $blockKey,
            'section_code' => $blockKey,
            'block_type' => $blockKey,
            'page_flow_role' => $role,
            'status' => AiSitePlanJsonStateService::STATUS_PENDING,
            'sort_order' => ($index + 1) * 10,
            'title' => $headline,
            'content' => $supportingCopy,
            'field_plan' => [
                [
                    'field' => 'headline',
                    'sample' => $headline,
                    'implementation_note' => 'Primary visible heading for the section.',
                ],
                [
                    'field' => 'supporting_copy',
                    'sample' => $supportingCopy,
                    'implementation_note' => 'Short body copy that explains why this section matters.',
                ],
                [
                    'field' => $role === 'cta' ? 'cta_label' : 'proof_detail',
                    'sample' => $role === 'cta' ? $actionLabel : $pageLabel . ' proof point ' . ((int)$index + 1),
                    'implementation_note' => 'Concrete action or proof detail used by the block.',
                ],
            ],
            'fields' => [
                'headline' => $headline,
                'supporting_copy' => $supportingCopy,
                $role === 'cta' ? 'cta_label' : 'proof_detail' => $role === 'cta' ? $actionLabel : $pageLabel . ' proof point ' . ((int)$index + 1),
            ],
            'execution_script' => [
                'core_copy' => $supportingCopy,
                'feature_points' => [
                    $label . ' keeps the message specific.',
                    'The section supports local verification without an AI call.',
                    'The final HTML can be written back to this same block node.',
                ],
            ],
            'design_tags' => [
                'visual' => $label . ' uses a focused content band with strong text hierarchy.',
                'motion' => 'Subtle hover states with reduced-motion fallback.',
                'interaction' => $role === 'cta' ? 'Primary action control is prominent and keyboard reachable.' : 'Cards and links expose clear hover and focus states.',
                'texture' => 'Soft borders, light surfaces, and restrained shadows.',
                'responsive' => 'Desktop uses columns where useful; mobile stacks content in source order.',
                'color_layering' => 'Primary actions use blue, proof surfaces use teal accents, and warnings use amber only sparingly.',
                'implementation_note' => 'Deterministic fake-mode block used for local queue verification.',
            ],
            'visual_signature' => [
                'composition_pattern' => $composition,
                'spatial_rhythm' => 'Section rhythm ' . ((int)$index + 1) . ' uses compact vertical spacing and clear grouping.',
                'media_strategy' => $needsImage ? 'One contextual image or CSS-backed media area supports the copy.' : 'CSS-only motif keeps the section light without image dependency.',
                'surface_treatment' => 'Flat background with one bordered proof surface and precise spacing.',
                'interaction_pattern' => $role === 'cta' ? 'Single direct action with visible focus state.' : 'Low-noise reveal states for supporting details.',
            ],
            'image_intent' => [
                'needs_image' => $needsImage,
                'image_role' => $needsImage ? 'contextual section media' : 'no generated image',
                'image_subject' => $needsImage ? $siteName . ' service context for ' . $label : 'CSS-only motif for ' . $label,
                'placement' => $index === 0 ? 'leading media area' : 'inline supporting area',
                'visual_atmosphere' => 'Clean, practical, and credible.',
                'image_treatment' => $needsImage ? 'Cropped rectangle with natural lighting and no text baked into the image.' : 'No image; use decorative CSS lines and small icon treatment.',
                'reuse_policy' => 'Reuse verified assets when available; otherwise render the CSS motif.',
                'css_motif' => $needsImage ? 'Optional gradient-free frame lines behind media.' : 'CSS-only pattern of fine rules and compact chips.',
            ],
            'realtime_content' => [
                'headline' => $headline,
                'supporting_copy' => [$supportingCopy],
                'ctas' => [
                    ['text' => $actionLabel, 'href' => '/'],
                ],
            ],
        ];
    }

    private function fakeStageOneBlockRole(string $blockKey, int $index): string
    {
        $normalized = \strtolower($blockKey);
        if ($index === 0 || \str_contains($normalized, 'hero')) {
            return 'hero';
        }
        if (\str_contains($normalized, 'cta') || \str_contains($normalized, 'contact') || \str_contains($normalized, 'newsletter')) {
            return 'cta';
        }
        if (\str_contains($normalized, 'proof') || \str_contains($normalized, 'trust') || \str_contains($normalized, 'rights') || \str_contains($normalized, 'rules')) {
            return 'proof';
        }
        if (\str_contains($normalized, 'faq') || \str_contains($normalized, 'steps') || \str_contains($normalized, 'process')) {
            return 'guidance';
        }

        return 'support';
    }

    private function fakeStageOneHeadline(string $blockKey, string $siteName, int $index): string
    {
        $label = $this->humanizeIdentifier($blockKey);
        if ($index === 0) {
            return $siteName . ' starts with a clear promise';
        }
        if (\str_contains(\strtolower($blockKey), 'cta')) {
            return 'Take the next step with ' . $siteName;
        }

        return $label . ' that makes the offer easier to trust';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function PlanJsonArtifactsByAiStream(
        array $scope,
        array $websiteProfile = [],
        array $options = [],
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array {
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            return $this->PlanJsonArtifacts($scope, $websiteProfile);
        }

        $pageTypes = $this->resolveStageOnePageTypes($scope);
        if ($pageTypes === []) {
            throw new \RuntimeException('PlanJson generation failed: selected page_types are missing.');
        }
        $locale = $this->resolveStageOneLocale($scope, $websiteProfile);
        $requirementExpansion = $this->buildRequirementExpansion($scope, $websiteProfile, $pageTypes);
        $contract = $this->stageOneContractService->build($scope, $pageTypes, $locale, $locale, 'stage1');
        $themePrompt = $this->buildStageOneThemePrompt($scope, $websiteProfile, $requirementExpansion, $contract, $locale);
        $themePayload = $this->decodeAiJsonPayload($this->callStageOneAiStream($themePrompt, $onChunk, $locale));
        $themePayload = $this->normalizeThemePayload($themePayload, $scope, $websiteProfile, $requirementExpansion, $contract, $locale);

        $planJson = \array_replace(
            $this->buildMinimalStageOneRoot($scope, $websiteProfile, $requirementExpansion, $locale),
            $themePayload,
            [
                'requirement_expansion' => $requirementExpansion,
                'content_locale' => $locale,
                'stage1_generation_attempts' => [],
                'pages' => [],
            ]
        );
        $fanoutProgress = [
            'concurrency' => $this->resolveStageOnePageFanoutConcurrency(\count($pageTypes)),
            'groups' => [
                'pending' => $pageTypes,
                'running' => [],
                'done' => [],
                'failed' => [],
            ],
        ];
        $this->emitStageOnePageFanoutProgress($pageTypes, $fanoutProgress, $onProgress);

        $tasks = [];
        foreach ($pageTypes as $pageType) {
            $tasks[$pageType] = function () use (
                $pageType,
                $scope,
                $websiteProfile,
                $requirementExpansion,
                $contract,
                $locale,
                $onChunk,
                $onProgress
            ): array {
                return $this->generateStageOnePageByAiWithAttemptLimit(
                    $pageType,
                    $scope,
                    $websiteProfile,
                    $requirementExpansion,
                    $contract,
                    $locale,
                    $onChunk,
                    $onProgress
                );
            };
        }
        $retryableFailures = [];
        $stageOneProgressStateCallback = \is_callable($options['on_stage1_progress_state'] ?? null)
            ? $options['on_stage1_progress_state']
            : null;
        $persistSettledPage = function (string|int $pageKey, array $result) use (
            &$planJson,
            &$retryableFailures,
            &$fanoutProgress,
            $pageTypes,
            $contract,
            $stageOneProgressStateCallback,
            $onProgress
        ): void {
            $pageType = (string)$pageKey;
            if ($pageType === '' || !\in_array($pageType, $pageTypes, true)) {
                return;
            }

            $page = \is_array($result['page'] ?? null) ? $result['page'] : [];
            if ($page === []) {
                $page = $this->buildFailedStageOnePage($pageType, 'Stage-one page generation returned no usable page.', self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS, $contract);
                $result['success'] = false;
                $result['message'] = 'Stage-one page generation returned no usable page.';
            }
            $planJson['pages'][$pageType] = $page;
            $planJson['stage1_generation_attempts'][$pageType] = [
                'attempt_no' => \max(1, (int)($result['attempt_no'] ?? 1)),
                'max_attempts' => self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS,
                'status' => !empty($result['success']) ? 'success' : 'failed',
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
            if (empty($result['success'])) {
                $retryableFailures[$pageType] = $this->buildRetryableStageOnePageFailure(
                    $pageType,
                    (string)($result['message'] ?? 'Stage-one page generation failed after automatic attempts.'),
                    \is_array($result['validation_issues'] ?? null) ? $result['validation_issues'] : [],
                    (int)($result['attempt_no'] ?? self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS)
                );
                if (!\in_array($pageType, $fanoutProgress['groups']['failed'], true)) {
                    $fanoutProgress['groups']['failed'][] = $pageType;
                }
            } elseif (!\in_array($pageType, $fanoutProgress['groups']['done'], true)) {
                $fanoutProgress['groups']['done'][] = $pageType;
            }
            $fanoutProgress['groups']['pending'] = \array_values(\array_filter(
                $fanoutProgress['groups']['pending'],
                static fn(string $candidate): bool => $candidate !== $pageType
            ));
            $this->emitStageOnePageFanoutProgress($pageTypes, $fanoutProgress, $onProgress);
            if ($stageOneProgressStateCallback !== null) {
                $stageOneProgressStateCallback([
                    'step' => 'stage1_page_fanout',
                    'page_types' => [$pageType],
                    'plan_json' => $this->planJsonStateService->normalizePlanJson($planJson),
                    'retryable_ai_failures' => \array_values($retryableFailures),
                    'message' => !empty($result['success'])
                        ? 'Stage-one plan page generated: ' . $pageType
                        : 'Stage-one plan page requires retry: ' . $pageType,
                    'updated_at' => \date('Y-m-d H:i:s'),
                ]);
            }
        };

        $settled = $this->runStageOnePageFanoutTasks($tasks, [], $pageTypes, $persistSettledPage);

        foreach ($pageTypes as $index => $pageType) {
            if (isset($planJson['pages'][$pageType])) {
                continue;
            }
            $result = \is_array($settled[$pageType] ?? null)
                ? $settled[$pageType]
                : (\is_array($settled[$index] ?? null) ? $settled[$index] : []);
            $page = \is_array($result['page'] ?? null) ? $result['page'] : [];
            if ($page === []) {
                $page = $this->buildFailedStageOnePage($pageType, 'Stage-one page generation returned no usable page.', self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS, $contract);
                $result['success'] = false;
                $result['message'] = 'Stage-one page generation returned no usable page.';
            }
            $planJson['pages'][$pageType] = $page;
            $planJson['stage1_generation_attempts'][$pageType] = [
                'attempt_no' => \max(1, (int)($result['attempt_no'] ?? 1)),
                'max_attempts' => self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS,
                'status' => !empty($result['success']) ? 'success' : 'failed',
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
            if (empty($result['success'])) {
                $retryableFailures[$pageType] = $this->buildRetryableStageOnePageFailure(
                    $pageType,
                    (string)($result['message'] ?? 'Stage-one page generation failed after automatic attempts.'),
                    \is_array($result['validation_issues'] ?? null) ? $result['validation_issues'] : [],
                    (int)($result['attempt_no'] ?? self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS)
                );
                $fanoutProgress['groups']['failed'][] = $pageType;
            } else {
                $fanoutProgress['groups']['done'][] = $pageType;
            }
            $fanoutProgress['groups']['pending'] = \array_values(\array_filter(
                $fanoutProgress['groups']['pending'],
                static fn(string $candidate): bool => $candidate !== $pageType
            ));
            $this->emitStageOnePageFanoutProgress($pageTypes, $fanoutProgress, $onProgress);
        }

        $planJson = $this->repairAiStageOnePlanJsonBeforeValidation(
            $this->planJsonStateService->normalizePlanJson($planJson),
            $pageTypes,
            $locale,
            $this->stageOneBriefText($scope, $websiteProfile)
        );
        $validation = $this->stageOneContractValidator->validateFullPlan($planJson, $contract, [
            'validation_page_types' => $pageTypes,
            'retryable_failure_count' => \count($retryableFailures),
            'generation_attempts' => \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [],
        ]);
        $planJson['stage1_validation_report'] = $validation;
        $planJson['stage1_first_pass'] = !empty($validation['first_pass']) ? 1 : 0;

        return [
            'ai_generated' => 1,
            'plan_json' => $this->planJsonStateService->normalizePlanJson($planJson),
            'structured' => $this->planJsonStateService->normalizePlanJson($planJson),
            'markdown' => $this->renderPlanJsonMarkdown($planJson),
            'derived_scope_patch' => $this->buildDerivedScopePatchFromPlanJson($planJson),
            'partial_retry_required' => $retryableFailures !== [] ? 1 : 0,
            'retryable_ai_failures' => \array_values($retryableFailures),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function refineDraftPlan(
        array $scope,
        array $websiteProfile = [],
        array $options = [],
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array {
        $options['prompt_mode'] = (string)($options['prompt_mode'] ?? 'refine');
        return $this->PlanJsonArtifactsByAiStream($scope, $websiteProfile, $options, $onChunk, $onProgress);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function rebuildDraftPlan(
        array $scope,
        array $websiteProfile = [],
        array $options = [],
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array {
        $scope['plan_json'] = [];
        $options['prompt_mode'] = (string)($options['prompt_mode'] ?? 'rebuild');
        return $this->PlanJsonArtifactsByAiStream($scope, $websiteProfile, $options, $onChunk, $onProgress);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $blockConfig
     * @return array<string, mixed>
     */
    public function mutateDraftPlanBlock(array $scope, string $pageType, string $action, string $blockKey = '', array $blockConfig = []): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planJson = $this->planJsonStateService->normalizePlanJson($planJson);
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null)
            ? $pages[$pageType]
            : ['page_type' => $pageType, 'status' => AiSitePlanJsonStateService::STATUS_PENDING];
        $action = \strtolower(\trim($action));
        $blockKey = \trim($blockKey);
        if ($action === 'delete') {
            unset($page[$blockKey]);
        } else {
            if ($blockKey === '') {
                $blockKey = $this->slugify((string)($blockConfig['block_key'] ?? $blockConfig['title'] ?? 'custom_block'));
            }
            $current = \is_array($page[$blockKey] ?? null) ? $page[$blockKey] : [];
            $page[$blockKey] = \array_replace($current, $blockConfig, [
                'block_key' => $blockKey,
                'status' => (int)($current['status'] ?? 0),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
        }
        $planJson['pages'][$pageType] = $page;
        $planJson = $this->planJsonStateService->normalizePlanJson($planJson);

        return [
            'ai_generated' => 1,
            'plan_json' => $planJson,
            'structured' => $planJson,
            'markdown' => $this->renderPlanJsonMarkdown($planJson),
            'derived_scope_patch' => $this->buildDerivedScopePatchFromPlanJson($planJson),
            'mutation_summary' => [
                'action' => $action,
                'page_type' => $pageType,
                'block_key' => $blockKey,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function refineDraftPlanPage(array $scope, string $pageType, string $instruction = '', array $options = []): array
    {
        unset($instruction, $options);
        $pageTypes = [$pageType];
        $scope['page_types'] = $pageTypes;
        return $this->PlanJsonArtifactsByAiStream($scope, [], ['prompt_mode' => 'refine_page']);
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $orderedBlockKeys
     * @return array<string, mixed>
     */
    public function reorderDraftPlanBlocks(array $scope, string $pageType, array $orderedBlockKeys): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planJson = $this->planJsonStateService->normalizePlanJson($planJson);
        $page = \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : [];
        if ($page === []) {
            throw new \RuntimeException('PlanJson reorder failed: page not found: ' . $pageType);
        }
        $ordered = [];
        foreach ($page as $key => $value) {
            if (!\is_array($value) || !$this->isStageOneDynamicBlockKey((string)$key)) {
                $ordered[$key] = $value;
            }
        }
        $sort = 10;
        foreach ($orderedBlockKeys as $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey === '' || !\is_array($page[$blockKey] ?? null)) {
                continue;
            }
            $block = $page[$blockKey];
            $block['sort_order'] = $sort;
            $ordered[$blockKey] = $block;
            $sort += 10;
        }
        foreach ($page as $key => $value) {
            if (!\array_key_exists((string)$key, $ordered)) {
                $ordered[$key] = $value;
            }
        }
        $planJson['pages'][$pageType] = $ordered;
        $planJson = $this->planJsonStateService->normalizePlanJson($planJson);

        return [
            'ai_generated' => 1,
            'plan_json' => $planJson,
            'structured' => $planJson,
            'markdown' => $this->renderPlanJsonMarkdown($planJson),
            'derived_scope_patch' => $this->buildDerivedScopePatchFromPlanJson($planJson),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function prepareStageOnePlanScopeForConfirmation(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        if ((int)($scope['fake_mode'] ?? 0) === 1 && ($pages === [] || !$this->stageOnePagesHaveDirectBlocks($pages))) {
            $planJson = $this->buildFakeStageOnePlanJson(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : []
            );
            $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        }
        if ($planJson === [] || $pages === []) {
            throw new \RuntimeException('PlanJson confirmation failed: plan_json.pages is missing.');
        }
        $scope['plan_json'] = $this->planJsonStateService->normalizePlanJson($planJson);

        return $scope;
    }

    /**
     * @param array<string, mixed> $pages
     */
    private function stageOnePagesHaveDirectBlocks(array $pages): bool
    {
        foreach ($pages as $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach ($page as $key => $value) {
                if (!$this->isStageOneDirectBlockKey((string)$key) || !\is_array($value)) {
                    continue;
                }
                if (\trim((string)($value['block_type'] ?? $value['type'] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function isStageOneDirectBlockKey(string $key): bool
    {
        if (\trim($key) === '') {
            return false;
        }

        return !\in_array($key, [
            'page_type',
            'title',
            'page_title',
            'handle',
            'locale',
            'status',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'route_path',
            'style_code',
            'style_settings',
            'seo',
            'primary_keywords',
            'secondary_keywords',
            'page_goal',
            'page_design_plan',
            'theme_alignment_summary',
            'ai_description',
            'visual_edit_url',
            'preview_full_url',
            'virtual_edit_url',
            'visual_preview_url',
            'virtual_preview_url',
            'materialized_page_id',
            'section_refinements',
            'last_generated_at',
        ], true);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function buildSourceSignature(array $scope): string
    {
        return $this->sourceSignature($scope, [
            'page_types' => $this->resolveStageOnePageTypes($scope),
            'site_title' => (string)($scope['site_title'] ?? ''),
            'brief_description' => (string)($scope['brief_description'] ?? $scope['user_description'] ?? ''),
            'plan_locale' => $this->resolveStageOneLocale($scope),
        ]);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function confirm(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $confirmedAt = \date('Y-m-d H:i:s');
        $meta['version'] = (string)($meta['version'] ?? PlanJsonContractSchema::VERSION);
        $meta['status'] = 'confirmed';
        $meta['confirmed_at'] = (string)($meta['confirmed_at'] ?? $confirmedAt);
        $meta['signature'] = $this->contractSignature(\array_replace($contract, ['contract_meta' => \array_diff_key($meta, ['signature' => true])]));
        $contract['contract_meta'] = $meta;

        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        return $this->validator()->validate($contract);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function projection(array $contract): array
    {
        return $this->projectionService()->build($contract);
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function selectStageOneSourcePlan(array $planJson): array
    {
        return $planJson;
    }

    /**
     * @param array<string, mixed> $sourcePlan
     */
    private function stageOneSourcePlanHasPages(array $sourcePlan): bool
    {
        return $this->normalizePagesSource($sourcePlan['pages'] ?? null) !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $sourcePlan
     */
    private function resolvePlanJsonContentLocale(
        array $scope,
        array $profile,
        array $sourcePlan = []
    ): string {
        return $this->firstNonEmpty([
            $scope['ai_content_locale'] ?? null,
            $scope['selected_content_locale'] ?? null,
            $scope['selected_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $profile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['content_locale'] ?? null,
            $profile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $sourcePlan['i18n']['content_locale'] ?? null,
            $sourcePlan['i18n']['primary_locale'] ?? null,
            $sourcePlan['i18n']['locale'] ?? null,
            $sourcePlan['content_locale'] ?? null,
            $scope['plan_generated_locale'] ?? null,
            $sourcePlan['plan_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $scope['default_language'] ?? null,
            $profile['default_language'] ?? null,
            $scope['website_profile']['default_language'] ?? null,
            'zh_Hans_CN',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLanguageRuntimeContract(string $locale): array
    {
        $localeProfile = $this->buildLocalePromptProfile($locale);

        return [
            'source_of_truth_locale' => $locale,
            'locale_profile' => $localeProfile,
            'visible_copy_rule' => 'All visitor-facing copy for headings, body, buttons, navigation, footer, form labels, alt/title/aria/placeholder text must use source_of_truth_locale.',
            'plan_text_rule' => 'Stage-one and PlanJson text is intent only; translate or rewrite it before rendering visible copy.',
            'proper_noun_rule' => 'Brand names, product names, domain names, URLs, acronyms, model names, and user-provided proper nouns may retain original spelling when natural.',
            'script_rule' => 'Use locale_profile.required_visible_script and locale_profile.text_direction for all planned visitor-facing text. Do not leave Chinese, English, or planning-language prose unless it is an approved proper noun.',
            'forbidden_visible_sources' => ['page_goal', 'block_goal', 'task_goal', 'why_this_block', 'planning_reason', 'block_contract', 'visual_signature', 'image_intent', 'asset_requirements', 'execution_script'],
            'failure_mode' => 'Visible copy in a different main language is a build contract violation.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalePromptProfile(string $locale): array
    {
        $normalized = \strtolower(\str_replace('-', '_', \trim($locale)));
        $languageName = 'the selected locale';
        $script = 'locale-native script';
        $direction = 'ltr';
        $requiredScript = 'the native writing system for the selected locale';

        if ($normalized === 'zh' || \str_starts_with($normalized, 'zh_')) {
            $languageName = \str_contains($normalized, 'hant') ? 'Traditional Chinese' : 'Simplified Chinese';
            $script = 'Han Chinese';
            $requiredScript = 'Chinese characters';
        } elseif ($normalized === 'ar' || \str_starts_with($normalized, 'ar_')) {
            $languageName = 'Arabic';
            $script = 'Arabic';
            $direction = 'rtl';
            $requiredScript = 'Arabic script';
        } elseif ($normalized === 'ru' || \str_starts_with($normalized, 'ru_')) {
            $languageName = 'Russian';
            $script = 'Cyrillic';
            $requiredScript = 'Cyrillic Russian text';
        } elseif ($normalized === 'th' || \str_starts_with($normalized, 'th_')) {
            $languageName = 'Thai';
            $script = 'Thai';
            $requiredScript = 'Thai script';
        } elseif ($normalized === 'hi' || \str_starts_with($normalized, 'hi_')) {
            $languageName = 'Hindi';
            $script = 'Devanagari';
            $requiredScript = 'Devanagari Hindi text';
        } elseif ($normalized === 'de' || \str_starts_with($normalized, 'de_')) {
            $languageName = 'German';
            $script = 'Latin';
            $requiredScript = 'German prose';
        } elseif ($normalized === 'fr' || \str_starts_with($normalized, 'fr_')) {
            $languageName = 'French';
            $script = 'Latin';
            $requiredScript = 'French prose';
        } elseif ($normalized === 'es' || \str_starts_with($normalized, 'es_')) {
            $languageName = 'Spanish';
            $script = 'Latin';
            $requiredScript = 'Spanish prose';
        } elseif ($normalized === 'it' || \str_starts_with($normalized, 'it_')) {
            $languageName = 'Italian';
            $script = 'Latin';
            $requiredScript = 'Italian prose';
        } elseif ($normalized === 'ja' || \str_starts_with($normalized, 'ja_')) {
            $languageName = 'Japanese';
            $script = 'Japanese';
            $requiredScript = 'Japanese text';
        } elseif ($normalized === 'ko' || \str_starts_with($normalized, 'ko_')) {
            $languageName = 'Korean';
            $script = 'Hangul';
            $requiredScript = 'Korean Hangul text';
        } elseif ($normalized === 'pt' || \str_starts_with($normalized, 'pt_')) {
            $languageName = 'Portuguese';
            $script = 'Latin';
            $requiredScript = 'Portuguese prose';
        } elseif ($normalized === 'en' || \str_starts_with($normalized, 'en_')) {
            $languageName = 'English';
            $script = 'Latin';
            $requiredScript = 'English prose';
        }

        return [
            'locale' => $locale,
            'language_name' => $languageName,
            'script' => $script,
            'text_direction' => $direction,
            'required_visible_script' => $requiredScript,
            'copy_instruction' => 'Write natural customer-facing ' . $languageName . ' copy. Translate or rewrite planning text before it appears in plan_json visitor-copy fields.',
            'forbidden_visible_copy' => [
                'Chinese/CJK planning prose when source_of_truth_locale is not CJK',
                'English boilerplate or section labels when source_of_truth_locale is not English',
                'raw page_goal/block_goal/task_goal/why_this_block/planning_reason sentences',
                'schema keys, prompt labels, or contract field names',
            ],
        ];
    }

    private function buildLogoTextLanguagePrompt(string $locale): string
    {
        $locale = \strtolower(\str_replace('-', '_', \trim($locale)));
        if ($locale === '') {
            return 'Visible logo text ban (HARD): generate symbol-only icon marks. Do not include readable letters, initials, monograms, words, brand names, slogans, user requirement text, placeholder text, or pseudo text in any language.';
        }
        if ($locale === 'ru' || \str_starts_with($locale, 'ru_')) {
            return 'Visible logo text ban (HARD): selected content_locale=' . $locale . '. Generate symbol-only icon marks. Do not include Cyrillic, Latin, CJK, initials, monograms, placeholder, pseudo, user requirement, or mixed-language text.';
        }
        if ($locale === 'zh' || \str_starts_with($locale, 'zh_')) {
            return 'Visible logo text ban (HARD): selected content_locale=' . $locale . '. Generate symbol-only icon marks. Do not include Chinese characters, Latin words, initials, monograms, placeholder, pseudo, user requirement, or mixed-language text.';
        }

        return 'Visible logo text ban (HARD): selected content_locale=' . $locale . '. Generate symbol-only icon marks. Do not include readable letters, initials, monograms, words, brand names, slogans, user requirement text, placeholder text, pseudo text, or CJK characters.';
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function contractPageTypes(array $contract): array
    {
        $result = [];
        foreach (\is_array($contract['pages'] ?? null) ? $contract['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? ''));
            if ($pageType !== '') {
                $result[] = $pageType;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function buildSourceContractRefs(array $scope, string $sourceSignature): array
    {
        $refs = [];
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];

        foreach ([
            ContractType::TYPE_SOURCE_TRUTH => $sourceTruth,
        ] as $type => $contract) {
            if (\is_array($contract) && $contract !== []) {
                $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
                $id = \trim((string)($meta['id'] ?? $meta['contract_id'] ?? ''));
                if ($id !== '') {
                    $refs[] = [
                        'id' => $id,
                        'type' => $type,
                        'version' => \trim((string)($meta['version'] ?? ContractType::VERSION_V1)),
                        'status' => \trim((string)($meta['status'] ?? ContractType::STATUS_DRAFT)),
                    ];
                    continue;
                }
            }

            $refs[] = [
                'id' => 'compat_' . $type . '_' . \substr($sourceSignature, 0, 16),
                'type' => $type,
                'version' => ContractType::VERSION_V1,
                'status' => ContractType::STATUS_COMPATIBILITY,
            ];
        }

        return (new SourceContractHelper())->normalize($refs);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function planJsonJsonSourceOfTruth(
        array $existing,
        array $scope,
        array $sourcePlan,
        string $sourceSignature,
        string $siteName,
        string $primaryGoal,
        array $pageTypes
    ): array {
        $source = $existing;
        $source['stage_one_plan_signature'] = (string)($scope['plan_generated_source_signature'] ?? $sourceSignature);
        $source['design_policy_id'] = AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID;

        $existingRequirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $source['user_requirements'] = \array_replace(
            $existingRequirements,
            $this->planJsonJsonUserRequirements($sourcePlan, $siteName, $primaryGoal, $pageTypes)
        );

        return $source;
    }

    /**
     * @param array<string, mixed> $sourcePlan
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function planJsonJsonUserRequirements(
        array $sourcePlan,
        string $siteName,
        string $primaryGoal,
        array $pageTypes
    ): array {
        $requirementExpansion = \is_array($sourcePlan['requirement_expansion'] ?? null) ? $sourcePlan['requirement_expansion'] : [];
        $siteStrategy = \is_array($sourcePlan['site_strategy'] ?? null) ? $sourcePlan['site_strategy'] : [];
        $themeDesign = \is_array($sourcePlan['theme_design'] ?? null) ? $sourcePlan['theme_design'] : [];

        $requirements = [
            'site_name' => $siteName,
            'primary_goal' => $primaryGoal,
            'page_types' => \array_values($pageTypes),
            'page_type_contract' => 'Page types: ' . \implode(', ', \array_values($pageTypes)),
        ];

        foreach ([
            'stage_one_original_brief' => $requirementExpansion['original_brief'] ?? null,
            'expanded_brief' => $requirementExpansion['expanded_brief'] ?? $sourcePlan['overview_expanded_brief'] ?? null,
            'planning_summary' => $requirementExpansion['planning_summary'] ?? null,
            'site_goal' => $requirementExpansion['site_goal'] ?? $siteStrategy['core_goal'] ?? null,
            'content_direction' => $requirementExpansion['content_direction'] ?? $siteStrategy['content_strategy'] ?? null,
            'conversion_strategy' => $requirementExpansion['conversion_strategy'] ?? $siteStrategy['conversion_path'] ?? null,
            'primary_cta' => $requirementExpansion['primary_cta'] ?? $siteStrategy['primary_cta'] ?? null,
            'visual_style_signature' => $themeDesign['style_signature'] ?? null,
        ] as $key => $value) {
            $text = $this->compactSourceText($value);
            if ($text !== '') {
                $requirements[$key] = $text;
            }
        }

        $pageIntentContracts = $this->extractPageIntentContracts($requirementExpansion['page_strategy'] ?? null);
        if ($pageIntentContracts !== []) {
            $requirements['requested_page_intents'] = $pageIntentContracts;
        }

        return \array_filter($requirements, static fn(mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * @return list<array<string, string>>
     */
    private function extractPageIntentContracts(mixed $pageStrategy): array
    {
        if (!\is_array($pageStrategy)) {
            return [];
        }

        $result = [];
        foreach ($pageStrategy as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $entry = [];
            foreach (['page_type', 'intent', 'content_focus', 'conversion_role'] as $field) {
                $text = $this->compactSourceText($item[$field] ?? null, 600);
                if ($text !== '') {
                    $entry[$field] = $text;
                }
            }
            if ($entry !== []) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $sourcePlan
     * @return array<string, mixed>
     */
    private function planJsonJsonDesignManifest(array $policy, array $sourcePlan): array
    {
        $manifest = [
            'policy_id' => AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID,
            'tokens' => \is_array($policy['default_tokens'] ?? null) ? $policy['default_tokens'] : [],
            'recipes' => \is_array($policy['default_recipes'] ?? null) ? $policy['default_recipes'] : [],
        ];

        foreach ([
            'theme_style' => $sourcePlan['theme_style'] ?? null,
            'palette' => $sourcePlan['palette'] ?? null,
        ] as $key => $value) {
            if (\is_array($value) && $value !== []) {
                $manifest[$key] = $this->stripPlanJsonExplanatoryFields($value);
            }
        }

        $themeDesign = \is_array($sourcePlan['theme_design'] ?? null) ? $sourcePlan['theme_design'] : [];
        $visualContract = [];
        foreach ([
            'theme_purpose',
            'style_signature',
            'art_direction',
            'color_scheme',
            'typography_spacing_radius',
            'visual_keywords',
            'tone_of_voice',
            'cta_tone',
            'forbidden_styles',
        ] as $field) {
            if (\array_key_exists($field, $themeDesign) && $themeDesign[$field] !== '' && $themeDesign[$field] !== []) {
                $visualContract[$field] = $themeDesign[$field];
            }
        }
        if ($visualContract !== []) {
            $manifest['visual_contract'] = $this->stripPlanJsonExplanatoryFields($visualContract);
        }

        foreach ([
            $sourcePlan['site_design_system'] ?? null,
            $sourcePlan['shared_plan']['site_design_system'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                $manifest['site_design_system'] = $this->stripPlanJsonExplanatoryFields($candidate);
                break;
            }
        }

        foreach ([
            $sourcePlan['asset_distribution_policy'] ?? null,
            $sourcePlan['shared_plan']['asset_distribution_policy'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                $manifest['asset_distribution_policy'] = $this->stripPlanJsonExplanatoryFields($candidate);
                break;
            }
        }

        $themeContext = \is_array($sourcePlan['theme_context_snapshot'] ?? null) ? $sourcePlan['theme_context_snapshot'] : [];
        if ($themeContext !== []) {
            $manifest['theme_context_snapshot'] = $this->stripPlanJsonExplanatoryFields($themeContext);
        }

        return $manifest;
    }

    private function stripPlanJsonExplanatoryFields(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if ($this->isPlanJsonExplanatoryField((string)$key)) {
                continue;
            }
            $result[$key] = $this->stripPlanJsonExplanatoryFields($item);
        }

        return $result;
    }

    private function isPlanJsonExplanatoryField(string $key): bool
    {
        $normalized = \strtolower(\trim($key));
        if ($normalized === '') {
            return false;
        }

        foreach (['reason', 'why', 'rationale', 'thinking', 'analysis', 'explanation', 'chain_of_thought', 'design_reason', 'reasoning'] as $forbidden) {
            if ($normalized === $forbidden) {
                return true;
            }
            if (\preg_match('/(^|[_\-])' . \preg_quote($forbidden, '/') . '($|[_\-])/i', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * PlanJson content_manifest is visitor copy. Do not seed it from the raw
     * brief because the brief can include prompt controls such as language bans
     * or JSON/contract leakage constraints.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $profile
     */
    private function resolvePlanJsonPrimaryGoal(array $scope, array $profile): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];

        $locale = $this->firstNonEmpty([
            $scope['ai_content_locale'] ?? null,
            $planJson['i18n']['content_locale'] ?? null,
            $planJson['i18n']['primary_locale'] ?? null,
            $planJson['i18n']['locale'] ?? null,
            $scope['content_locale'] ?? null,
            $scope['plan_generated_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $profile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $profile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            'zh_Hans_CN',
        ]);

        return $this->firstSafeLocalizedVisibleCopy([
            $profile['primary_goal'] ?? null,
            $siteStrategy['core_goal'] ?? null,
            $siteStrategy['summary'] ?? null,
            $requirementExpansion['site_goal'] ?? null,
            $requirementExpansion['planning_summary'] ?? null,
            $this->sourceTruthVisibleSummary($scope),
            $this->isCjkLocale($locale)
                ? 'Present the business clearly and convert qualified visitors.'
                : 'Present the business clearly and convert qualified visitors.',
        ], $locale);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function sourceTruthVisibleSummary(array $scope): string
    {
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $facts = [];
        foreach (\is_array($sourceTruth['must_include_facts'] ?? null) ? $sourceTruth['must_include_facts'] : [] as $fact) {
            if (!\is_array($fact)) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)($fact['text'] ?? ''));
            if ($text !== '' && !$this->looksLikeInternalControlCopy($text)) {
                $facts[] = $text;
            }
        }

        return \implode(', ', \array_values(\array_unique($facts)));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $profile
     * @return list<string>
     */
    private function buildAllowedBrandTerms(array $scope, array $profile, string $siteName): array
    {
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $siteIdentity = \is_array($sourceTruth['site_identity'] ?? null) ? $sourceTruth['site_identity'] : [];
        $terms = [
            $siteName,
            (string)($scope['site_title'] ?? ''),
            (string)($scope['site_name'] ?? ''),
            (string)($profile['site_title'] ?? ''),
            (string)($profile['site_name'] ?? ''),
            (string)($siteIdentity['site_name'] ?? ''),
        ];
        foreach (\is_array($siteIdentity['brand_terms'] ?? null) ? $siteIdentity['brand_terms'] : [] as $term) {
            $terms[] = (string)$term;
        }
        foreach (\is_array($siteIdentity['allowed_brand_terms'] ?? null) ? $siteIdentity['allowed_brand_terms'] : [] as $term) {
            $terms[] = (string)$term;
        }

        return $this->uniqueNonEmptyStrings($terms);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $profile
     * @return list<string>
     */
    private function buildForbiddenTemplateBrandTerms(array $scope, array $profile, string $siteName): array
    {
        $allowed = $this->buildAllowedBrandTerms($scope, $profile, $siteName);
        $allowedLookup = \array_fill_keys(\array_map(static fn(string $term): string => \mb_strtolower($term), $allowed), true);
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
    private function uniqueNonEmptyStrings(array $values): array
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
     * @param list<mixed> $values
     */
    private function firstSafeVisibleCopy(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)$value);
            if ($text === '' || $this->looksLikeUnusablePlanJsonVisibleCopy($text)) {
                continue;
            }

            return $text;
        }

        return '';
    }

    /**
     * @param list<mixed> $values
     */
    private function firstSafeLocalizedVisibleCopy(array $values, string $locale): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)$value);
            if ($text === '' || $this->looksLikeUnusablePlanJsonVisibleCopy($text)) {
                continue;
            }
            if ($this->looksLikeVisibleLocaleLeak($text, $locale)) {
                continue;
            }

            return $text;
        }

        return '';
    }

    private function looksLikeUnusablePlanJsonVisibleCopy(string $value): bool
    {
        return $this->looksLikeInternalControlCopy($value)
            || PlanJsonContentManifestLinter::isPlanningOrImplementationCopy($value);
    }

    private function looksLikeInternalControlCopy(string $value): bool
    {
        if (\trim($value) === '') {
            return true;
        }

        return \preg_match(
            '/(?:闁告艾鐗嗛幃鎾垛偓娑欘殕椤斿瘝闁圭粯鍔楅妵姘辨嫚瀹勭叮SON|闁告瑯鍨甸～鍡樸亜閻㈠憡妗▅濞戞挸绉烽々锕傚礄閾忕懓绠泑缂佸倷鐒﹂??:閺夊牊鎸搁崵鐡呴柣銏㈠枑閸ㄦ畝濞达綀娉曢弫顦㈤柛鎴ｆ楠?|濞戞挸绉寸欢??:閺夊牊鎸搁崵鐡呴柣銏㈠枑閸ㄦ畝濞达綀娉曢弫顦㈤柛鎴ｆ楠?|濞戞挸绉烽崗??:閺夊牊鎸搁崵鐡呴柣銏㈠枑閸ㄦ畝濞达綀娉曢弫顦㈤柛鎴ｆ楠?|闊洤鎳橀妴蹇旀媴鐠恒劍鏆弢闂傚嫨鍊撶花?.+濞寸姰鍎遍ˇ绮卨anguage|locale|prompt|contract|field|visible copy|do not|must not|forbidden)/iu',
            $value
        ) === 1;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildBlockVisualImplementationContract(array $block, string $blockType, string $blockKey): array
    {
        $visualSignature = $this->normalizeBlockVisualSignatureForPlanJson($block['visual_signature'] ?? []);
        $imageIntent = $this->normalizeBlockImageIntentForPlanJson($block['image_intent'] ?? []);
        $blockContract = $this->normalizeBlockContractForPlanJson($block['block_contract'] ?? []);

        return [
            'visual_signature' => $visualSignature,
            'image_intent' => $imageIntent,
            'block_contract' => $blockContract,
            'image_integration' => 'Integrate imagery as part of the section composition, with responsive crop and readable overlays when needed. Non-policy narrative, proof, support, contact, and article blocks should use a verified/generated image when planned or a substantial CSS media surface when image_intent.needs_image=false; contact/support media should read as a help-desk, app-support, phone-assistance, or safe-download support scene; policy/legal blocks may remain text-dense.',
            'responsive_layout_contract' => $this->buildBlockResponsiveContract($blockType, $blockKey),
            'implementation_slices' => $this->buildBlockImplementationSlices($blockType, $blockKey),
            'composition_guards' => [
                'no_horizontal_scroll',
                'no_fixed_width_wider_than_container',
                'no_absolute_panel_outside_root',
                'no_media_or_decor_layer_covering_text_or_form',
                'all_grid_flex_children_min_width_zero',
            ],
            'source_design_tags' => $this->stripPlanJsonExplanatoryFields(
                \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : []
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlockVisualSignatureForPlanJson(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $signature = [];
        foreach ([
            'composition_pattern',
            'spatial_rhythm',
            'media_strategy',
            'surface_treatment',
            'interaction_pattern',
        ] as $key) {
            $text = \trim((string)($value[$key] ?? ''));
            if ($text !== '') {
                $signature[$key] = $text;
            }
        }

        return $signature;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlockImageIntentForPlanJson(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $intent = [];
        foreach ([
            'needs_image',
            'image_role',
            'image_subject',
            'placement',
            'visual_atmosphere',
            'image_treatment',
            'reuse_policy',
            'css_motif',
        ] as $key) {
            if (!\array_key_exists($key, $value)) {
                continue;
            }
            $raw = $value[$key];
            if ($key === 'needs_image') {
                $bool = $this->normalizePlanJsonBoolean($raw);
                if ($bool !== null) {
                    $intent[$key] = $bool;
                }
                continue;
            }
            if (\is_bool($raw)) {
                $intent[$key] = $raw;
                continue;
            }
            if (\is_scalar($raw)) {
                $text = \trim((string)$raw);
                if ($text !== '') {
                    $intent[$key] = $text;
                }
            }
        }

        if (($intent['needs_image'] ?? false) === true) {
            $subject = $this->normalizeGeneratedImagePromptText((string)($intent['image_subject'] ?? ''));
            if ($subject === '') {
                $subject = 'Concrete generated image for this block narrative, with subject-specific scene, supporting props, composition, lighting, and no text baked into the image.';
            }
            $intent['image_subject'] = $subject;
            $intent['image_role'] = $this->normalizeGeneratedImagePromptText((string)($intent['image_role'] ?? 'section_image')) ?: 'section_image';
            $intent['image_treatment'] = $this->normalizeGeneratedImagePromptText((string)($intent['image_treatment'] ?? '')) ?: 'Polished generated image with responsive crop, natural depth, and no placeholder or CSS-only treatment.';
            $intent['reuse_policy'] = 'generate_or_reuse_verified_image_for_exact_slot';
        }

        return $intent;
    }

    /**
     * @return bool|null
     */
    private function normalizePlanJsonBoolean(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((int)$value) === 1;
        }
        if (!\is_scalar($value)) {
            return null;
        }

        $text = \strtolower(\trim((string)$value));
        return match ($text) {
            '1', 'true', 'yes', 'y', 'on', 'required', 'needs_image' => true,
            '0', 'false', 'no', 'n', 'off', 'none', 'no_image', 'no generated image' => false,
            default => null,
        };
    }

    private function normalizeGeneratedImagePromptText(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if ($this->isGeneratedImagePromptAbsenceText($value)) {
            return '';
        }

        $value = (string)\preg_replace('/^\s*(?:css[- ]?only|css[- ]?backed|css\s+motif|css)\s+/iu', '', $value);
        $value = (string)\preg_replace('/\b(?:css[- ]?only|css[- ]?backed|css\s+motif|css\s+pattern|decorative\s+css|no\s+generated\s+image|no\s+image|without\s+image|placeholder\s+image|fake\s+screenshot)\b/iu', '', $value);
        $value = (string)\preg_replace('/\s+/u', ' ', $value);
        $value = \trim($value, " \t\n\r\0\x0B-:;,.|");

        if ($value === '' || $this->isGeneratedImagePromptAbsenceText($value)) {
            return '';
        }
        if ($value === '' || \preg_match('/^(?:motif|pattern|icon|shape|gradient|decoration)$/iu', $value) === 1) {
            return '';
        }

        return $value;
    }

    private function isGeneratedImagePromptAbsenceText(string $value): bool
    {
        $value = (string)\preg_replace('/\s+/u', ' ', \trim($value));
        if ($value === '') {
            return false;
        }

        foreach ([
            '/\b(?:no\s+generated\s+image|no\s+image|without\s+image|placeholder\s+image|fake\s+screenshot)\b/iu',
            '/(?:لا\s*(?:توجد|يوجد)\s*(?:صورة|صور|صوره)\s*(?:مولدة|مطلوبة)?|بدون\s*(?:صورة|صور|صوره)|(?:صورة|صور|صوره)\s*(?:غير\s*)?مطلوبة|لا\s*حاجة\s*(?:إلى|ل)?\s*(?:صورة|صور|صوره))/u',
            '/(?:无(?:需|须)?(?:生成)?(?:图片|图像)|没有(?:生成)?(?:图片|图像)|不(?:需要|生成|使用)(?:图片|图像)|无需(?:生成)?(?:图片|图像)|无图|无图片)/u',
            '/(?:sin\s+imagen|sin\s+imagen\s+generada|sans\s+image|sem\s+imagem|ohne\s+bild|nessuna\s+immagine)/iu',
        ] as $pattern) {
            if (\preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    private function normalizeAssetRequirementsForPlanJson(mixed $value): mixed
    {
        $value = $this->stripPlanJsonExplanatoryFields($value);
        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $required = $this->normalizePlanJsonBoolean($item['required'] ?? false) === true;
            if (!$required) {
                $value[$index] = $item;
                continue;
            }

            $item['required'] = true;
            foreach (['subject', 'prompt', 'brief', 'treatment'] as $key) {
                if (!\is_scalar($item[$key] ?? null)) {
                    continue;
                }
                $cleaned = $this->normalizeGeneratedImagePromptText((string)$item[$key]);
                if ($cleaned !== '') {
                    $item[$key] = $cleaned;
                } elseif ($key === 'subject') {
                    $item[$key] = 'Concrete generated image for this block narrative, with subject-specific scene, supporting props, composition, lighting, and no text baked into the image.';
                } elseif ($key === 'treatment') {
                    $item[$key] = 'Polished generated image with responsive crop, natural depth, and no placeholder or CSS-only treatment.';
                } else {
                    unset($item[$key]);
                }
            }
            if (\trim((string)($item['subject'] ?? '')) === '') {
                $item['subject'] = 'Concrete generated image for this block narrative, with subject-specific scene, supporting props, composition, lighting, and no text baked into the image.';
            }
            $value[$index] = $item;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlockContractForPlanJson(mixed $value): array
    {
        if (!\is_array($value) || $value === []) {
            return [];
        }

        $contract = [];
        foreach ([
            'version',
            'page_type',
            'block_key',
            'section_code',
            'page_flow_role',
            'block_goal',
            'morphology_id',
        ] as $key) {
            $text = \trim((string)($value[$key] ?? ''));
            if ($text !== '') {
                $contract[$key] = $text;
            }
        }
        foreach ([
            'composition_pattern',
            'content_hierarchy',
            'media_strategy',
            'style_tokens',
            'responsive_contract',
            'diversity_constraints',
            'acceptance_checks',
        ] as $key) {
            if (\is_array($value[$key] ?? null)) {
                $contract[$key] = $this->stripPlanJsonExplanatoryFields($value[$key]);
            }
        }

        return $contract;
    }

    private function normalizePlanRoleToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        $value = \preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? $value;
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_-');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlockResponsiveContract(string $blockType, string $blockKey): array
    {
        unset($blockKey);

        $blockType = $this->normalizePlanRoleToken($blockType);
        $hasFormOrSupport = \in_array($blockType, [
            'contact',
            'contact_methods',
            'contact_form',
            'support',
            'support_form',
            'support_faq',
            'faq',
            'lead_form',
            'query_form',
            'consult_form',
        ], true);
        $isHeroOrCta = \in_array($blockType, [
            'hero',
            'banner',
            'home_hero',
            'hero_banner',
            'cta',
            'final_cta',
            'download_cta',
            'conversion_cta',
        ], true);

        return [
            'breakpoints' => [
                'desktop' => '>=1024px: multi-column is allowed only inside a centered max-width container; every column uses minmax(0, 1fr) or flex-basis with min-width:0.',
                'tablet' => '<=900px: media, copy, and action/form panels must stack or become a safe two-row layout; no side panel may remain absolutely offset outside the grid.',
                'mobile' => '<=420px: single column; width:100%; max-width:100%; images height auto or fixed-ratio cover; long headings, brand/logo text, badges/chips, labels, and CTA text wrap instead of clipping; CTA/form controls fit available width without overflow.',
            ],
            'required_parts' => \array_values(\array_filter([
                'root_shell',
                'inner_container',
                'copy_panel',
                $isHeroOrCta ? 'cta_cluster' : 'content_cluster',
                'media_panel',
                $hasFormOrSupport ? 'form_or_support_panel' : '',
                'decorative_layers',
            ])),
            'overflow_guards' => [
                'root_shell must set box-sizing:border-box and overflow-x:hidden only for decoration, not as a way to hide broken content',
                'inner_container must use width:min(100%, max-width) or max-width:calc(100% - safe gutters)',
                'all grid/flex children, cards, media frames, and form panels must set min-width:0',
                'all text-bearing children, including headings, brand/logo text, nav labels, chips/badges, card titles, media captions, and CTA labels, must allow wrapping with max-width:100% and overflow-wrap:anywhere',
                'form inputs, buttons, and textareas must use width:100%; max-width:100%; box-sizing:border-box',
                'mobile rules must not use white-space:nowrap on real copy or hide overflow to mask clipped content',
                'decorative absolute layers must use pointer-events:none and stay behind content with z-index below panels',
            ],
            'media_text_safety' => [
                'text over image requires an overlay plus a local text panel/scrim',
                'media frames cannot overlap form or CTA panels at tablet/mobile breakpoints',
                'object-fit cover is allowed only inside a bounded frame; do not crop visitor text or controls',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function buildBlockImplementationSlices(string $blockType, string $blockKey): array
    {
        unset($blockKey);

        $blockType = $this->normalizePlanRoleToken($blockType);
        $slices = [
            'copy: headline, supporting copy, proof/detail row, CTA labels',
            'layout: root shell, safe inner container, responsive grid/flex structure',
            'visual: background system, surface treatment, media frame, decorative layers',
            'interaction: hover/focus states and reduced-motion-safe animation',
            'responsive: desktop/tablet/mobile layout with no overflow',
        ];
        if (\in_array($blockType, [
            'contact',
            'contact_methods',
            'contact_form',
            'support',
            'support_form',
            'lead_form',
            'query_form',
            'consult_form',
        ], true)) {
            $slices[] = 'form: labels, inputs, textarea, submit CTA, support contact details, stacked mobile state';
        }
        if (\in_array($blockType, [
            'hero',
            'banner',
            'home_hero',
            'hero_banner',
            'cta',
            'final_cta',
            'download_cta',
            'conversion_cta',
        ], true)) {
            $slices[] = 'conversion: primary action cluster, trust cue, readable image overlay, mobile first-screen stacking';
        }

        return \array_values(\array_unique($slices));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array<string,string>}
     */
    private function buildPageBlockGraph(
        array $scope,
        array $sourcePlan,
        string $siteName,
        string $primaryGoal,
        string $locale
    ): array {
        $pagesByType = $this->resolvePagesByType($scope, $sourcePlan);
        $pages = [];
        $blocks = [];
        $contentItems = [];
        $themeRuntimeContext = $this->resolveTaskThemeRuntimeContext($sourcePlan);
        $sharedRuntimeContext = $this->resolveTaskSharedPromptRuntimeContext($sourcePlan, $siteName, $primaryGoal, $locale);
        $siteRuntimeContext = [
            'site_name' => $siteName,
            'primary_goal' => $primaryGoal,
            'locale' => $locale,
            'content_locale' => $locale,
            'language_contract' => $this->buildLanguageRuntimeContract($locale),
        ];

        unset($themeRuntimeContext, $sharedRuntimeContext, $siteRuntimeContext);

        foreach ($pagesByType as $pageIndex => $page) {
            $pageType = (string)($page['page_type'] ?? 'home_page');
            $pageId = $this->slugify($pageType);
            $pageTitleKey = 'page.' . $pageId . '.title';
            $pageDescriptionKey = 'page.' . $pageId . '.description';
            $pageTitle = $this->firstSafeLocalizedVisibleCopy([
                $page['title'] ?? null,
                $page['page_title'] ?? null,
                Page::getPageTypes()[$pageType] ?? null,
                $siteName,
            ], $locale);
            $pageDescription = $this->firstSafeLocalizedVisibleCopy([
                $page['description'] ?? null,
                $page['page_goal'] ?? null,
                $page['goal'] ?? null,
                $primaryGoal,
            ], $locale);
            $pageTitle = $pageTitle !== '' ? $pageTitle : $this->localizedPageTitleFallback($pageType, $siteName, $locale);
            $pageDescription = $pageDescription !== '' ? $pageDescription : $this->localizedDefaultCopy($locale);
            $contentItems[$pageTitleKey] = $pageTitle;
            $contentItems[$pageDescriptionKey] = $pageDescription;

            $pageBlockIds = [];
            $pageBlocks = $this->collectPlanJsonPageBlocks($page);
            if ($pageBlocks === []) {
                throw new \RuntimeException('PlanJson contract failed: page ' . $pageType . ' has no stage-one blocks. Regenerate the plan.');
            }

            foreach ($pageBlocks as $blockIndex => $rawBlock) {
                if (!\is_array($rawBlock)) {
                    continue;
                }
                $blockKey = $this->resolveBlockKey($rawBlock, $blockIndex);
                $blockId = $pageId . '.' . $this->slugify($blockKey);
                $sectionCode = 'content/' . \str_replace('_', '-', $this->slugify($pageType)) . '-' . \str_replace('_', '-', $this->slugify($blockKey));
                $titleKey = 'block.' . $blockId . '.title';
                $copyKey = 'block.' . $blockId . '.copy';
                $ctaKey = 'block.' . $blockId . '.cta';
                $blockType = $this->resolveBlockType($rawBlock, $blockIndex, $blockKey);
                $pageFlowRole = $this->normalizePlanRoleToken((string)($rawBlock['page_flow_role'] ?? ''));
                $blockTitle = $this->extractBlockTitle($rawBlock, $blockKey, $locale);
                $blockCopy = $this->extractBlockCopy($rawBlock, $blockKey, $locale);
                $blockCta = $this->extractBlockCta($rawBlock, $locale);
                $contentKeys = [$titleKey, $copyKey];
                $contentItems[$titleKey] = $blockTitle;
                $contentItems[$copyKey] = $blockCopy;
                if ($blockCta !== '') {
                    $contentItems[$ctaKey] = $blockCta;
                    $contentKeys[] = $ctaKey;
                }
                $visualSignature = $this->normalizeBlockVisualSignatureForPlanJson($rawBlock['visual_signature'] ?? []);
                $designTags = $this->stripPlanJsonExplanatoryFields(
                    \is_array($rawBlock['design_tags'] ?? null) ? $rawBlock['design_tags'] : []
                );
                $imageIntent = $this->normalizeBlockImageIntentForPlanJson($rawBlock['image_intent'] ?? []);
                $blockContract = $this->normalizeBlockContractForPlanJson($rawBlock['block_contract'] ?? []);
                $assetRequirements = \is_array($rawBlock['asset_requirements'] ?? null)
                    ? $this->normalizeAssetRequirementsForPlanJson($rawBlock['asset_requirements'])
                    : [];
                $policySlices = ['layout.4_8_spacing', 'typography.refined_font_stack', 'image.integrated_not_pasted', 'responsive.no_horizontal_scroll'];
                $acceptanceRuleIds = ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic', 'color.readable_contrast'];
                $implementationSlices = $this->buildBlockImplementationSlices($blockType, $blockKey);
                $responsiveContract = $this->buildBlockResponsiveContract($blockType, $blockKey);

                $blocks[] = [
                    'block_id' => $blockId,
                    'page_id' => $pageId,
                    'page_type' => $pageType,
                    'section_key' => $blockKey,
                    'block_type' => $blockType,
                    'page_flow_role' => $pageFlowRole,
                    'visual_signature' => $visualSignature,
                    'design_tags' => $designTags,
                    'image_intent' => $imageIntent,
                    'block_contract' => $blockContract,
                    'asset_requirements' => $assetRequirements,
                    'content_keys' => $contentKeys,
                    'visual' => $this->buildBlockVisualImplementationContract($rawBlock, $blockType, $blockKey),
                    'sort_order' => 1000 + ((int)$pageIndex * 100) + ((int)$blockIndex * 10),
                ];
                $pageBlockIds[] = $blockId;
            }

            $pages[] = [
                'page_id' => $pageId,
                'page_type' => $pageType,
                'title_key' => $pageTitleKey,
                'description_key' => $pageDescriptionKey,
                'page_goal' => (string)($page['page_goal'] ?? $page['goal'] ?? ''),
                'content_focus' => (string)($page['content_focus'] ?? ''),
                'conversion_role' => (string)($page['conversion_role'] ?? ''),
                'theme_alignment_summary' => (string)($page['theme_alignment_summary'] ?? ''),
                'page_design_plan' => $this->stripPlanJsonExplanatoryFields(
                    \is_array($page['page_design_plan'] ?? null) ? $page['page_design_plan'] : []
                ),
                'block_ids' => $pageBlockIds,
                'sort_order' => 100 + ((int)$pageIndex * 10),
            ];
        }

        return [$pages, $blocks, $contentItems];
    }

    /**
     * @param array<string,mixed> $sourcePlan
     * @return array<string,mixed>
     */
    private function resolveTaskThemeRuntimeContext(array $sourcePlan): array
    {
        foreach ([
            $sourcePlan['theme_context_snapshot'] ?? null,
            $sourcePlan['theme_design'] ?? null,
            $sourcePlan['site_design_system'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $this->stripPlanJsonExplanatoryFields($candidate);
            }
        }

        return [
            'source' => 'plan_json_minimal_theme_context',
            'theme_rule' => 'use confirmed site design tokens, readable contrast, responsive rhythm, and consistent shared navigation styling',
        ];
    }

    /**
     * @param array<string,mixed> $sourcePlan
     * @return array<string,mixed>
     */
    private function resolveTaskSharedPromptRuntimeContext(
        array $sourcePlan,
        string $siteName,
        string $primaryGoal,
        string $locale
    ): array {
        foreach ([
            $sourcePlan['shared_prompt_context'] ?? null,
            $sourcePlan['shared_plan'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $this->stripPlanJsonExplanatoryFields($candidate);
            }
        }

        return [
            'source' => 'plan_json_minimal_shared_context',
            'site_name' => $siteName,
            'primary_goal' => $primaryGoal,
            'locale' => $locale,
            'header_role' => 'consistent navigation, brand identity, and primary action',
            'footer_role' => 'support links, trust cues, policy access, and secondary conversion path',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSharedTaskRuntimeContext(
        string $component,
        array $themeContext,
        array $sharedPromptContext,
        array $siteContext
    ): array
    {
        $contentLocale = $this->firstNonEmpty([
            $siteContext['content_locale'] ?? null,
            $siteContext['locale'] ?? null,
        ]);
        $languageContract = \is_array($siteContext['language_contract'] ?? null)
            ? $siteContext['language_contract']
            : $this->buildLanguageRuntimeContract($contentLocale);

        return [
            'target' => [
                'component' => $component,
                'region' => $component,
                'content_locale' => $contentLocale,
            ],
            'content_locale' => $contentLocale,
            'language_contract' => $languageContract,
            'theme_context_snapshot' => $themeContext,
            'shared_prompt_context' => $sharedPromptContext,
            'site_context' => $siteContext,
            'allowed_contract_refs' => [
                'site_brief',
                'pages',
                'content_manifest.items.site.name',
                'content_manifest.items.site.primary_goal',
            ],
            'generation_intent' => $component === 'header'
                ? 'Generate a concise, navigable shared header that reflects the confirmed site goal.'
                : 'Generate a complete shared footer with navigation, trust cues, and policy/support access.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSharedTaskOutputContract(string $component): array
    {
        return [
            'format' => 'pagebuilder_component_payload',
            'required_outputs' => ['html', 'css', 'render_data'],
            'component' => $component,
            'render_data' => [
                'root_class' => 'string',
                'navigation_items' => 'list',
                'cta' => 'object',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @param list<string> $contentKeys
     * @param array<string, mixed> $visualSignature
     * @param array<string, mixed> $imageIntent
     * @param array<string, mixed> $designTags
     * @param array<string, mixed> $blockContract
     * @param list<string> $implementationSlices
     * @param array<string, mixed> $responsiveContract
     * @return array<string, mixed>
     */
    private function buildBlockTaskRuntimeContext(
        array $page,
        string $pageId,
        string $pageType,
        string $blockId,
        string $blockKey,
        string $blockType,
        string $pageFlowRole,
        array $contentKeys,
        array $visualSignature,
        array $imageIntent,
        array $designTags,
        array $blockContract,
        array $implementationSlices,
        array $responsiveContract,
        array $themeContext,
        array $sharedPromptContext,
        array $siteContext
    ): array {
        $contentLocale = $this->firstNonEmpty([
            $siteContext['content_locale'] ?? null,
            $siteContext['locale'] ?? null,
        ]);
        $languageContract = \is_array($siteContext['language_contract'] ?? null)
            ? $siteContext['language_contract']
            : $this->buildLanguageRuntimeContract($contentLocale);

        return [
            'target' => [
                'page_id' => $pageId,
                'page_type' => $pageType,
                'block_id' => $blockId,
                'block_key' => $blockKey,
                'block_type' => $blockType,
                'page_flow_role' => $pageFlowRole,
                'content_locale' => $contentLocale,
            ],
            'content_locale' => $contentLocale,
            'language_contract' => $languageContract,
            'page_contract' => [
                'title_key' => (string)($page['title_key'] ?? ''),
                'description_key' => (string)($page['description_key'] ?? ''),
                'page_goal' => (string)($page['page_goal'] ?? $page['goal'] ?? ''),
                'content_focus' => (string)($page['content_focus'] ?? ''),
                'conversion_role' => (string)($page['conversion_role'] ?? ''),
                'content_locale' => $contentLocale,
            ],
            'theme_context_snapshot' => $themeContext,
            'shared_prompt_context' => $sharedPromptContext,
            'site_context' => $siteContext,
            'block_contract' => [
                'content_keys' => $contentKeys,
                'visual_signature' => $visualSignature,
                'image_intent' => $imageIntent,
                'design_tags' => $designTags,
                'contract_v2' => $blockContract,
                'morphology_id' => (string)($blockContract['morphology_id'] ?? ''),
                'media_strategy' => \is_array($blockContract['media_strategy'] ?? null) ? $blockContract['media_strategy'] : [],
                'acceptance_checks' => \is_array($blockContract['acceptance_checks'] ?? null) ? $blockContract['acceptance_checks'] : [],
                'implementation_slices' => $implementationSlices,
                'responsive_contract' => $responsiveContract,
            ],
            'allowed_contract_refs' => [
                'site_brief',
                'design_manifest',
                'content_manifest.items',
                'pages.' . $pageId,
                'blocks.' . $blockId,
            ],
        ];
    }

    /**
     * @param list<string> $contentKeys
     * @return array<string, mixed>
     */
    private function buildBlockTaskOutputContract(string $blockType, string $blockKey, array $contentKeys, array $blockContract = []): array
    {
        $contract = [
            'format' => 'pagebuilder_component_payload',
            'required_outputs' => ['html', 'css', 'render_data'],
            'block_type' => $blockType,
            'block_key' => $blockKey,
            'required_content_keys' => $contentKeys,
            'render_data' => [
                'root_class' => 'string',
                'headline' => 'string',
                'body' => 'string',
                'cta' => 'object',
                'media' => 'object',
            ],
        ];
        if ($blockContract !== []) {
            $contract['block_contract'] = [
                'morphology_id' => (string)($blockContract['morphology_id'] ?? ''),
                'media_strategy' => \is_array($blockContract['media_strategy'] ?? null) ? $blockContract['media_strategy'] : [],
                'acceptance_checks' => \is_array($blockContract['acceptance_checks'] ?? null) ? $blockContract['acceptance_checks'] : [],
                'responsive_contract' => \is_array($blockContract['responsive_contract'] ?? null) ? $blockContract['responsive_contract'] : [],
            ];
        }

        return $contract;
    }

    /**
     * @param list<string> $ruleIds
     * @return array<string, mixed>
     */
    private function planJsonTaskAcceptanceContract(array $ruleIds, string $targetLabel): array
    {
        return [
            'rule_ids' => $ruleIds,
            'checks' => [
                'visible_content_matches_confirmed_plan_for_' . $this->slugify($targetLabel),
                'no_placeholder_or_prompt_copy',
                'responsive_without_horizontal_scroll',
                'visual_hierarchy_and_cta_are_clear',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @return list<array<string, mixed>>
     */
    private function resolvePagesByType(array $scope, array $sourcePlan): array
    {
        $pageTypes = $this->resolvePageTypes($scope, $sourcePlan);
        $sources = [
            $sourcePlan['pages'] ?? null,
        ];
        foreach ($sources as $source) {
            $pages = $this->normalizePagesSource($source);
            if ($pages !== []) {
                $missing = $this->missingPageTypesFromPages($pages, $pageTypes);
                if ($missing !== []) {
                    throw new \RuntimeException(
                        'PlanJson contract failed: stage-one page plans missing selected page_types: ' . \implode(', ', $missing)
                    );
                }

                return $this->orderPagesByPageTypes($pages, $pageTypes);
            }
        }

        throw new \RuntimeException('PlanJson contract failed: stage-one pages are missing. Regenerate the plan.');
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param list<string> $expectedPageTypes
     * @return list<string>
     */
    private function missingPageTypesFromPages(array $pages, array $expectedPageTypes): array
    {
        if ($expectedPageTypes === []) {
            return [];
        }

        $actual = [];
        foreach ($pages as $page) {
            $pageType = \trim((string)($page['page_type'] ?? ''));
            if ($pageType !== '') {
                $actual[$pageType] = true;
            }
        }

        $missing = [];
        foreach ($expectedPageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && !isset($actual[$pageType])) {
                $missing[] = $pageType;
            }
        }

        return \array_values(\array_unique($missing));
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param list<string> $pageTypes
     * @return list<array<string, mixed>>
     */
    private function orderPagesByPageTypes(array $pages, array $pageTypes): array
    {
        if ($pages === [] || $pageTypes === []) {
            return $pages;
        }

        $rank = [];
        foreach (\array_values($pageTypes) as $index => $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && !isset($rank[$pageType])) {
                $rank[$pageType] = $index;
            }
        }

        \usort($pages, static function (array $a, array $b) use ($rank): int {
            $aType = \trim((string)($a['page_type'] ?? ''));
            $bType = \trim((string)($b['page_type'] ?? ''));
            $aRank = $rank[$aType] ?? 9999;
            $bRank = $rank[$bType] ?? 9999;
            if ($aRank === $bRank) {
                return 0;
            }

            return $aRank <=> $bRank;
        });

        return \array_values($pages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizePagesSource(mixed $source): array
    {
        if (!\is_array($source) || $source === []) {
            return [];
        }

        $pages = [];
        foreach ($source as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType === '') {
                $pageType = 'page_' . ((int)\count($pages) + 1);
            }
            $page['page_type'] = $pageType;
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @return list<string>
     */
    private function resolvePageTypes(array $scope, array $sourcePlan): array
    {
        $candidates = [
            $scope['page_types'] ?? null,
            $sourcePlan['page_types'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $types = $this->stringList($candidate);
            if ($types !== []) {
                return $types;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function collectPlanJsonPageBlocks(array $page): array
    {
        $reservedKeys = [
            'page_id' => true,
            'page_type' => true,
            'title' => true,
            'page_title' => true,
            'description' => true,
            'page_goal' => true,
            'goal' => true,
            'content_focus' => true,
            'conversion_role' => true,
            'theme_alignment_summary' => true,
            'page_design_plan' => true,
            'primary_keywords' => true,
            'secondary_keywords' => true,
            'seo' => true,
            'status' => true,
            'sort_order' => true,
            'blocks' => true,
            'block_previews' => true,
            'sections' => true,
            'components' => true,
        ];
        $Blocks = [];
        foreach ($page as $key => $value) {
            if (isset($reservedKeys[(string)$key]) || !\is_array($value)) {
                continue;
            }
            $value['block_key'] = \trim((string)($value['block_key'] ?? $key));
            $Blocks[] = $value;
        }

        return $Blocks;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveBlockKey(array $block, int $index): string
    {
        $key = $this->firstNonEmpty([
            $block['block_key'] ?? null,
            $block['source_block_key'] ?? null,
            $block['key'] ?? null,
            $block['id'] ?? null,
        ]);
        if ($key === '') {
            throw new \RuntimeException('PlanJson contract failed: stage-one block at index ' . ((int)$index + 1) . ' is missing block_key.');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveBlockType(array $block, int $index, string $blockKey): string
    {
        $type = \strtolower($this->firstNonEmpty([
            $block['block_type'] ?? null,
            $block['type'] ?? null,
            $block['template'] ?? null,
            $blockKey,
        ]));
        $type = \preg_replace('/[^a-z0-9_-]+/', '_', $type) ?? $type;
        $type = \trim($type, '_-');

        if ($type === '') {
            throw new \RuntimeException('PlanJson contract failed: stage-one block ' . ((int)$index + 1) . ' is missing block_type.');
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockTitle(array $block, string $blockKey, string $locale): string
    {
        $fieldTitle = $this->extractFieldPlanText($block, [
            'title',
            'heading',
            'headline',
            'main_heading',
            'section_title',
            'form_heading',
            'feature_headline',
            'methods_heading',
            'faq_title',
            'card_title',
            'list_title',
            'hero_title',
            'banner_title',
        ]);
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $execution = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $featurePoints = \is_array($execution['feature_points'] ?? null) ? $execution['feature_points'] : [];

        $title = $this->firstSafeLocalizedVisibleCopy([
            $fieldTitle,
            $realtime['headline'] ?? null,
            $block['title'] ?? null,
            $block['name'] ?? null,
            $block['label'] ?? null,
            $this->shortTitleFromCopy((string)($execution['core_copy'] ?? '')),
            $this->extractFirstFieldPlanSample($block, ['cta', 'button', 'action', 'image', 'media', 'icon', 'logo']),
            $featurePoints[0] ?? null,
            $this->shortTitleFromCopy((string)($block['content'] ?? '')),
        ], $locale);
        if ($title === '') {
            $title = $this->humanizeIdentifier($blockKey);
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockCopy(array $block, string $blockKey, string $locale): string
    {
        $fieldCopy = $this->extractFieldPlanText($block, ['description', 'body', 'copy', 'subtitle', 'supporting_copy', 'intro', 'paragraph']);
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $supporting = \is_array($realtime['supporting_copy'] ?? null) ? \implode(' ', \array_map('strval', $realtime['supporting_copy'])) : '';
        $execution = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $featurePoints = \is_array($execution['feature_points'] ?? null) ? \implode(' ', \array_map('strval', $execution['feature_points'])) : '';

        $copy = $this->firstSafeLocalizedVisibleCopy([
            $execution['core_copy'] ?? null,
            $fieldCopy,
            $supporting,
            $featurePoints,
            $block['content'] ?? null,
        ], $locale);
        if ($copy === '') {
            $copy = $this->localizedDefaultCopy($locale);
        }

        return $copy;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockCta(
        array $block,
        string $locale
    ): string
    {
        $fieldCta = $this->extractFieldPlanText($block, [
            'cta',
            'cta_label',
            'button',
            'button_text',
            'action',
            'action_label',
            'form_label',
            'submit_label',
        ]);
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $ctas = \is_array($realtime['ctas'] ?? null) ? $realtime['ctas'] : [];
        $candidates = [$fieldCta];
        foreach ($ctas as $cta) {
            if (!\is_array($cta)) {
                continue;
            }
            $text = $this->firstNonEmpty([$cta['text'] ?? null, $cta['label'] ?? null]);
            if ($text !== '') {
                $candidates[] = $text;
            }
        }

        return $this->firstSafeLocalizedVisibleCopy($candidates, $locale);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $fieldNames
     */
    private function extractFieldPlanText(array $block, array $fieldNames): string
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
            $text = $this->cleanVisibleCopy((string)($field['sample'] ?? $field['value'] ?? $field['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $skipNeedles
     */
    private function extractFirstFieldPlanSample(array $block, array $skipNeedles = []): string
    {
        foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $name = \strtolower(\trim((string)($field['field'] ?? $field['name'] ?? '')));
            $skip = false;
            foreach ($skipNeedles as $needle) {
                if ($needle !== '' && \str_contains($name, \strtolower($needle))) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)($field['sample'] ?? $field['value'] ?? $field['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function shortTitleFromCopy(string $copy): string
    {
        $copy = $this->cleanVisibleCopy($copy);
        if ($copy === '') {
            return '';
        }
        $parts = \preg_split('/[闁靛棗鍋婄槐鎺楁晬閻曞倻骞?.!?\n\r]+/u', $copy) ?: [];
        $title = \trim((string)($parts[0] ?? $copy));
        if ($title === '') {
            return '';
        }
        if (\function_exists('mb_strlen') && \mb_strlen($title) > 34) {
            return \trim((string)\mb_substr($title, 0, 34));
        }

        return \strlen($title) > 90 ? \substr($title, 0, 90) : $title;
    }

    private function localizedPageTitleFallback(string $pageType, string $siteName, string $locale): string
    {
        if (!$this->isCjkLocale($locale)) {
            return $this->humanizeIdentifier($pageType) ?: $siteName;
        }

        $pageType = \strtolower($pageType);
        if (\str_contains($pageType, 'home')) {
            return 'Home';
        }
        if (\str_contains($pageType, 'about')) {
            return 'About';
        }
        if (\str_contains($pageType, 'contact')) {
            return 'Contact';
        }
        if (\str_contains($pageType, 'blog') || \str_contains($pageType, 'article') || \str_contains($pageType, 'resource')) {
            return 'Resources';
        }

        return $siteName !== '' ? $siteName : 'Page';
    }

    private function localizedDefaultCopy(string $locale): string
    {
        return $this->isCjkLocale($locale)
            ? 'Present the core message clearly and guide visitors to the next action.'
            : 'Present the core message clearly and guide visitors to the next action.';
    }

    private function humanizeIdentifier(string $value): string
    {
        $value = \trim(\preg_replace('/[_-]+/', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        return \ucwords(\strtolower($value));
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
            if ($normalized !== '' && !isset($allowed[$normalized])) {
                $words[] = $normalized;
                if (\preg_match('/^[A-Z][A-Za-z0-9\'-]*$/', $rawWord) !== 1) {
                    $properNounOnly = false;
                }
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

    /**
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    private function extractExistingContentItems(array $scope): array
    {
        $contentManifest = \is_array($scope['content_manifest'] ?? null) ? $scope['content_manifest'] : [];
        $items = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $result = [];
        foreach ($items as $key => $value) {
            $key = \trim((string)$key);
            if ($key === '') {
                continue;
            }
            $text = $this->extractScalarText($value);
            if ($text !== '') {
                $result[$key] = $text;
            }
        }

        return $result;
    }

    /**
     * Existing generated content can be reused only for keys that still exist in the
     * freshly rebuilt contract graph. This prevents stale page/block content from
     * overwriting a new stage-one blueprint after page types changed.
     *
     * @param array<string, string> $existingItems
     * @param array<string, string> $freshItems
     * @return array<string, string>
     */
    private function filterReusableContentItems(array $existingItems, array $freshItems): array
    {
        if ($existingItems === [] || $freshItems === []) {
            return [];
        }

        $result = [];
        foreach ($existingItems as $key => $value) {
            if (!\array_key_exists($key, $freshItems)) {
                continue;
            }
            if ($this->containsDisallowedGeneratedEnglish($value)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function containsDisallowedGeneratedEnglish(string $value): bool
    {
        return (bool)\preg_match(
            '/\b(Start\s+with|Learn\s+more|Explore\s+more|Contact\s+us|Download\s+now)\b|(?:\x{7ACB}\x{5373}\x{54A8}\x{8BE2}|\x{8054}\x{7CFB}\x{6211}\x{4EEC}|\x{7ACB}\x{5373}\x{4E0B}\x{8F7D})/iu',
            $value
        );
    }

    private function extractScalarText(mixed $value): string
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return $this->cleanVisibleCopy((string)$value);
        }
        if (!\is_array($value)) {
            return '';
        }
        foreach (['text', 'value', 'copy', 'content'] as $field) {
            if (\array_key_exists($field, $value)) {
                return $this->extractScalarText($value[$field]);
            }
        }

        return '';
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function sameStringSet(array $a, array $b): bool
    {
        $normalize = static function (array $values): array {
            $out = [];
            foreach ($values as $value) {
                $text = \trim((string)$value);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
            $out = \array_values(\array_unique($out));
            \sort($out);

            return $out;
        };

        return $normalize($a) === $normalize($b);
    }

    private function cleanVisibleCopy(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $patterns = [
            '/^(Visitors\s+see|Visitor\s+sees|Visitors\s+can|Visitors\s+will|Provide|Show|Display|List|Present)\s+/iu' => '',
            '/^(The|This|A)\s+(visitor|customer|user)\s+/iu' => '',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = \preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return \trim($value);
    }

    private function unicodeText(string $jsonEscaped): string
    {
        $decoded = \json_decode('"' . $jsonEscaped . '"');

        return \is_string($decoded) ? $decoded : $jsonEscaped;
    }

    private function compactSourceText(mixed $value, int $maxLength = 1200): string
    {
        if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
            return '';
        }

        $text = \trim((string)$value);
        if ($text === '') {
            return '';
        }
        $text = \preg_replace('/\s+/u', ' ', $text) ?? $text;
        if ($maxLength > 0 && \function_exists('mb_strlen') && \mb_strlen($text) > $maxLength) {
            return \mb_substr($text, 0, $maxLength);
        }
        if ($maxLength > 0 && !\function_exists('mb_strlen') && \strlen($text) > $maxLength) {
            return \substr($text, 0, $maxLength);
        }

        return $text;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;
        $value = \trim($value, '_');

        return $value !== '' ? $value : 'item';
    }

    private function containsPositiveIntent(string $haystack, string $needle): bool
    {
        $haystack = \mb_strtolower(\trim($haystack));
        $needle = \mb_strtolower(\trim($needle));
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return \str_contains($haystack, $needle);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     */
    private function sourceSignature(array $scope, array $sourcePlan): string
    {
        return \sha1((string)\json_encode([
            'source_plan' => $sourcePlan,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function contractSignature(array $contract): string
    {
        return \sha1((string)\json_encode($contract, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function buildCurrentStageOneContract(array $scope, array $planJson): array
    {
        $pageTypes = \array_values(\array_keys(\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : []));
        if ($pageTypes === []) {
            $pageTypes = $this->resolveStageOnePageTypes($scope);
        }
        $locale = (string)($planJson['content_locale'] ?? $this->resolveStageOneLocale($scope));

        return $this->stageOneContractService->build($scope, $pageTypes, $locale, $locale, 'stage1');
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function renderPlanJsonMarkdown(array $planJson): string
    {
        $siteName = (string)($planJson['site_strategy']['site_display_name'] ?? 'AI Site');
        $pageCount = \is_array($planJson['pages'] ?? null) ? \count($planJson['pages']) : 0;

        return '# ' . $siteName . "\n\nStage-1 plan_json generated with " . $pageCount . " page(s).";
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function buildDerivedScopePatchFromPlanJson(array $planJson): array
    {
        return [
            'theme_context_snapshot' => \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [],
            'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
            'shared_prompt_context' => [
                'site_display_name' => (string)($planJson['site_strategy']['site_display_name'] ?? ''),
                'primary_cta' => (string)($planJson['requirement_expansion']['primary_cta'] ?? ''),
                'content_locale' => (string)($planJson['content_locale'] ?? ''),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveStageOnePageTypes(array $scope): array
    {
        $pageTypes = $this->stringList($scope['page_types'] ?? []);
        if ($pageTypes !== []) {
            return $pageTypes;
        }
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (\is_array($planJson['pages'] ?? null) && $planJson['pages'] !== []) {
            return \array_values(\array_map('strval', \array_keys($planJson['pages'])));
        }

        return [Page::TYPE_HOME];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function resolveStageOneLocale(array $scope, array $websiteProfile = []): string
    {
        foreach ([
            $scope['ai_content_locale'] ?? null,
            $scope['selected_content_locale'] ?? null,
            $scope['selected_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_language'] ?? null,
            $scope['website_profile']['default_language'] ?? null,
            $websiteProfile['locale'] ?? null,
        ] as $candidate) {
            $value = \trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'en_US';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildStageOneLanguagePromptContext(array $scope, array $websiteProfile, string $locale): array
    {
        $siteDefaultLanguage = $this->firstNonEmpty([
            $scope['ai_content_locale'] ?? null,
            $scope['selected_content_locale'] ?? null,
            $scope['selected_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $websiteProfile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['content_locale'] ?? null,
            $websiteProfile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $scope['default_language'] ?? null,
            $websiteProfile['default_language'] ?? null,
            $scope['website_profile']['default_language'] ?? null,
            $locale,
        ]);

        $languageContract = $this->buildLanguageRuntimeContract($siteDefaultLanguage !== '' ? $siteDefaultLanguage : $locale);

        return [
            'site_default_language' => $siteDefaultLanguage,
            'content_locale' => $locale,
            'language_contract' => $languageContract,
            'locale_context' => \is_array($languageContract['locale_profile'] ?? null) ? $languageContract['locale_profile'] : [],
            'language_rule' => 'All planned visitor-facing copy, navigation labels, CTA labels, SEO text, alt/title/aria/placeholder text, and field_plan.sample values must use site_default_language/content_locale unless the text is a proper noun.',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function stageOneBriefText(array $scope, array $websiteProfile): string
    {
        return $this->firstNonEmpty([
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $scope['instruction'] ?? null,
            $websiteProfile['brief_description'] ?? null,
            $websiteProfile['site_tagline'] ?? null,
            $scope['site_tagline'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildRequirementExpansion(array $scope, array $websiteProfile, array $pageTypes): array
    {
        $locale = $this->resolveStageOneLocale($scope, $websiteProfile);
        $languageContext = $this->buildStageOneLanguagePromptContext($scope, $websiteProfile, $locale);
        $brief = $this->stageOneBriefText($scope, $websiteProfile);
        $siteTitle = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            'AI Site',
        ]);
        $prompt = $this->stageOnePromptAssembler->assemble([
            'system_task' => [
                'Stage-1 REQUIREMENT EXPANSION planner: expand the operator brief into a concrete website planning contract.',
                'Return JSON only as {"requirement_expansion": {...}}. Do not include markdown fences.',
                'Do not output pages or block plans in this step.',
            ],
            'user_inputs' => [
                'site_title' => $siteTitle,
                'brief' => $brief,
                'content_locale' => $locale,
                'site_default_language' => (string)($languageContext['site_default_language'] ?? $locale),
                'language_rule' => (string)($languageContext['language_rule'] ?? ''),
                'page_types' => \array_values($pageTypes),
            ],
            'upstream_artifacts' => [
                'current_site_language_context' => $languageContext,
            ],
            'output_schema' => [
                'requirement_expansion.original_brief string',
                'requirement_expansion.expanded_brief string',
                'requirement_expansion.planning_summary string',
                'requirement_expansion.site_goal string',
                'requirement_expansion.target_users list of strings',
                'requirement_expansion.business_context string',
                'requirement_expansion.content_direction string',
                'requirement_expansion.conversion_strategy string',
                'requirement_expansion.primary_cta string',
                'requirement_expansion.page_strategy list keyed by page_type, intent, content_focus, conversion_role',
                'requirement_expansion.technical_direction list of strings',
            ],
            'self_check' => [
                'Every page_strategy item must use one of the selected page_types.',
                'Use concrete visitor, content, and conversion language. Avoid instructions like write the title.',
                'All visitor-facing planning text must follow current_site_language_context.site_default_language/content_locale.',
            ],
        ]);

        $payload = $this->decodeAiJsonPayload($this->callStageOneAiStream($prompt, null, $locale));
        $expansion = \is_array($payload['requirement_expansion'] ?? null) ? $payload['requirement_expansion'] : $payload;

        return $this->normalizeRequirementExpansion($expansion, $scope, $websiteProfile, $pageTypes);
    }

    /**
     * @param array<string, mixed> $expansion
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function normalizeRequirementExpansion(array $expansion, array $scope, array $websiteProfile, array $pageTypes): array
    {
        $brief = $this->stageOneBriefText($scope, $websiteProfile);
        if ($brief === '') {
            $brief = 'Create a clear, credible website with useful content and a direct next step.';
        }
        $siteTitle = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            'AI Site',
        ]);
        $expandedBrief = $this->firstNonEmpty([
            $expansion['expanded_brief'] ?? null,
            $expansion['summary'] ?? null,
            $brief,
        ]);

        $pageStrategy = [];
        $rawPageStrategy = \is_array($expansion['page_strategy'] ?? null) ? $expansion['page_strategy'] : [];
        foreach ($rawPageStrategy as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $pageType = \trim((string)($item['page_type'] ?? ''));
            if ($pageType === '' || !\in_array($pageType, $pageTypes, true)) {
                continue;
            }
            $pageStrategy[] = [
                'page_type' => $pageType,
                'intent' => $this->firstNonEmpty([$item['intent'] ?? null, 'Clarify the role of ' . $pageType . ' in the visitor journey.']),
                'content_focus' => $this->firstNonEmpty([$item['content_focus'] ?? null, $expandedBrief]),
                'conversion_role' => $this->firstNonEmpty([$item['conversion_role'] ?? null, 'Support the primary website goal.']),
            ];
        }
        foreach ($pageTypes as $pageType) {
            $exists = false;
            foreach ($pageStrategy as $item) {
                if (($item['page_type'] ?? '') === $pageType) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $pageStrategy[] = [
                    'page_type' => $pageType,
                    'intent' => 'Turn the brief into a useful ' . $pageType . ' experience.',
                    'content_focus' => $expandedBrief,
                    'conversion_role' => $pageType === Page::TYPE_HOME ? 'Primary entry and conversion page.' : 'Supporting trust and detail page.',
                ];
            }
        }

        $targetUsers = \is_array($expansion['target_users'] ?? null) ? \array_values($expansion['target_users']) : [];
        $technicalDirection = \is_array($expansion['technical_direction'] ?? null) ? \array_values($expansion['technical_direction']) : [];

        return [
            'original_brief' => $this->firstNonEmpty([$expansion['original_brief'] ?? null, $brief]),
            'expanded_brief' => $expandedBrief,
            'planning_summary' => $this->firstNonEmpty([$expansion['planning_summary'] ?? null, $siteTitle . ' needs a focused page plan based on the supplied brief.']),
            'site_goal' => $this->firstNonEmpty([$expansion['site_goal'] ?? null, $expandedBrief]),
            'target_users' => $targetUsers !== [] ? $targetUsers : ['Visitors matched to the supplied brief'],
            'business_context' => $this->firstNonEmpty([$expansion['business_context'] ?? null, $siteTitle . ' website planning context.']),
            'content_direction' => $this->firstNonEmpty([$expansion['content_direction'] ?? null, $expandedBrief]),
            'conversion_strategy' => $this->firstNonEmpty([$expansion['conversion_strategy'] ?? null, 'Use clear proof, useful detail, and a direct call to action.']),
            'primary_cta' => $this->firstNonEmpty([$expansion['primary_cta'] ?? null, 'Get started']),
            'page_strategy' => $pageStrategy,
            'technical_direction' => $technicalDirection !== [] ? $technicalDirection : ['Responsive layout', 'Accessible semantic sections'],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $requirementExpansion
     * @param array<string, mixed> $contract
     */
    private function buildStageOneThemePrompt(
        array $scope,
        array $websiteProfile,
        array $requirementExpansion,
        array $contract,
        string $locale
    ): string {
        $languageContext = $this->buildStageOneLanguagePromptContext($scope, $websiteProfile, $locale);

        return $this->stageOnePromptAssembler->assemble([
            'system_task' => [
                'THEME planner: return one JSON object for shared stage-one plan_json root theme, navigation, footer, shared_components, and seo_strategy.',
                'Confirmed requirement expansion from step 1 must be consumed, not rewritten.',
                'Return JSON only. Do not include markdown fences.',
            ],
            'user_inputs' => [
                'site_title' => (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''),
                'brief' => $this->stageOneBriefText($scope, $websiteProfile),
                'content_locale' => $locale,
                'site_default_language' => (string)($languageContext['site_default_language'] ?? $locale),
                'language_rule' => (string)($languageContext['language_rule'] ?? ''),
            ],
            'upstream_artifacts' => [
                'Confirmed requirement expansion from step 1' => $requirementExpansion,
                'current_site_language_context' => $languageContext,
                'page_route_contract' => $contract['page_route_contract'] ?? [],
                'theme_required_fields' => $contract['theme_required_fields'] ?? [],
            ],
            'output_schema' => [
                'Return keys: i18n, site_strategy, theme_style, palette, theme, theme_design, navigation_plan, footer_plan, shared_components, seo_strategy.',
                'Do not return only theme_design, only palette, or only navigation/footer. The top-level JSON object must include every root key listed above.',
                'Complete root theme artifact skeleton: ' . $this->json($this->stageOneThemeRootSkeleton()),
                'theme.logo_generation is mandatory. It must describe the website logo generation plan at plan_json.theme.logo_generation and include exactly four options keyed logo_option_1 through logo_option_4, each with asset_slot_id plan:theme:logo_generation:option_N.',
                'Do not output legacy logo generation locations outside plan_json.theme.logo_generation.',
                'theme_design must include every required field from theme_required_fields.theme_design, especially theme_purpose, style_signature, art_direction, color_scheme, typography_spacing_radius, visual_keywords, tone_of_voice, cta_tone, forbidden_styles, and selection_reason.',
                'theme_design.typography_spacing_radius must include font_family, heading_scale, body_scale, spacing_scale, and radius_scale.',
                'Complete theme_design skeleton: ' . $this->json($this->stageOneThemeDesignSkeleton()),
            ],
            'self_check' => [
                'Before returning JSON, verify the root object has i18n, site_strategy, theme_style, palette, theme, theme_design, navigation_plan, footer_plan, shared_components, and seo_strategy.',
                'Before returning JSON, verify theme.logo_generation.stage is logo_generation, output_path is plan_json.theme.logo_generation, and options contains exactly four selectable logo option records.',
                'Header/footer href values must be exact internal route paths from page_route_contract, no anchors and no query strings.',
                'Before returning JSON, verify theme_design.typography_spacing_radius and theme_design.forbidden_styles are present and non-empty.',
                'Before returning JSON, verify every visitor-facing theme, navigation, footer, shared component, SEO, CTA, alt/title/aria/placeholder text uses current_site_language_context.site_default_language/content_locale.',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $requirementExpansion
     * @param array<string, mixed> $contract
     */
    private function buildStageOnePagePrompt(
        string $pageType,
        array $scope,
        array $websiteProfile,
        array $requirementExpansion,
        array $contract,
        string $locale,
        int $attemptNo,
        array $previousIssues = []
    ): string {
        $pageContract = $this->stageOneContractService->pageContract($contract, $pageType);
        $exactBlockKeys = $this->exactStageOnePageBlockKeys($pageContract);
        $languageContext = $this->buildStageOneLanguagePromptContext($scope, $websiteProfile, $locale);

        return $this->stageOnePromptAssembler->assemble([
            'system_task' => [
                'Stage-1 PAGE planner: return one complete plan_json page object.',
                'Page type: ' . $pageType,
                'Attempt ' . $attemptNo . ' of ' . self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS . '.',
                'Return JSON only as {"page": {...}}.',
                'The value of "page" is plan_json.pages.' . $pageType . ' itself, not a single block object and not a wrapper containing ' . $pageType . '.',
            ],
            'user_inputs' => [
                'site_title' => (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''),
                'brief' => $this->stageOneBriefText($scope, $websiteProfile),
                'content_locale' => $locale,
                'site_default_language' => (string)($languageContext['site_default_language'] ?? $locale),
                'language_rule' => (string)($languageContext['language_rule'] ?? ''),
            ],
            'upstream_artifacts' => [
                'Confirmed requirement expansion from step 1' => $requirementExpansion,
                'current_site_language_context' => $languageContext,
                'page_contract' => $pageContract,
                'previous_validation_issues' => $previousIssues,
            ],
            'contract_lines' => [
                'Output path is plan_json.pages.' . $pageType . '.{block_key}.',
                'Exact dynamic block keys required for this page: ' . $this->json($exactBlockKeys) . '.',
                'Return all exact dynamic block keys directly inside the "page" object, each exactly once. Do not omit optional keys needed to hit target_blocks.',
                'Complete page return skeleton: ' . $this->json($this->stageOnePageReturnSkeleton($exactBlockKeys)) . '.',
                'Forbidden wrappers: do not output plan_json, pages, page, ' . $pageType . ', blocks, sections, components, or markdown around the returned page object.',
                'Forbidden single-block artifact: the top level of "page" must not contain block_key, page_flow_role, content, design_tags, visual_signature, image_intent, field_plan, execution_script, or execution_core_copy. Those keys belong inside each exact block object only.',
                'Every required_block_key must appear exactly once as a direct dynamic key under the page.',
                'Each block must include block_key, page_flow_role, content, design_tags, visual_signature, image_intent, field_plan, and execution_script.',
                'Field plan hard rule: every block field_plan must be an array of exactly 3 rows. Row fields must be concrete machine keys such as headline, supporting_copy, cta_label, proof_detail, image_brief, or context_detail.',
                'Field plan copy rule: field_plan.sample must be final visitor-facing copy or a concrete asset brief. Never write instruction text such as write, rewrite, describe, use this field, explain, include, highlight, or mention.',
                'Generated image hard rule: when image_intent.needs_image=true or an asset_requirements row has required=true, image_intent.image_subject and asset_requirements[].subject must be a concrete image-generation brief: real scene/product/editorial/interface subject, supporting props, crop/composition, lighting/atmosphere, and how it supports this block. Do not describe CSS-only motifs, CSS icons, decorative shapes, gradients, placeholders, fake screenshots, or no-image fallbacks as generated images. Never output no-image text in any language for a required image slot, including examples such as "no generated image", "لا توجد صورة مولدة", "没有生成图片", "无需图片", "sin imagen", or "sans image".',
                'CSS-only visual rule: if a block should use only CSS decoration, set image_intent.needs_image=false, image_role=css_motif, reuse_policy=no_generated_image, and do not create required=true asset_requirements for it.',
                'Image-copy separation: image prompts may mention visual subjects and props, but must not contain visitor CTA/body copy, prompt instructions, planning reasons, schema keys, or text that should appear baked into the image.',
            ],
            'self_check' => [
                'Output exactly ' . (string)\count($exactBlockKeys) . ' direct dynamic block keys under plan_json.pages.' . $pageType . ': ' . \implode(', ', $exactBlockKeys) . '.',
                'Keep every generated field inside plan_json.pages.' . $pageType . '.{block_key}.',
                'If the draft has top-level visual_signature, image_intent, field_plan, execution_script, or execution_core_copy, discard it and rebuild the full page object with direct block keys.',
                'Before returning JSON, verify every field_plan.sample, content, title, CTA, SEO, alt/title/aria/placeholder candidate uses current_site_language_context.site_default_language/content_locale.',
                'Before returning JSON, verify every required generated-image slot has a concrete non-CSS image subject and every CSS-only motif has needs_image=false with no required image slot.',
            ],
        ]);
    }

    private function callStageOneAiStream(string $prompt, ?callable $onChunk, string $locale): string
    {
        $buffer = '';
        $callback = function (string $chunk) use (&$buffer, $onChunk): void {
            $buffer .= $chunk;
            if ($onChunk !== null) {
                $onChunk($chunk);
            }
        };

        if ($this->aiService instanceof AiService) {
            $this->aiService->generateStream($prompt, $callback, null, 'pagebuilder_plan_generation', $locale, []);
        } else {
            w_query('ai', 'generateStream', [
                'prompt' => $prompt,
                'on_chunk' => $callback,
                'model_code' => null,
                'scenario_code' => 'pagebuilder_plan_generation',
                'locale' => $locale,
                'params' => [],
            ]);
        }
        if (\trim($buffer) === '') {
            throw new \RuntimeException('AI stream generation completed without content.');
        }

        return $buffer;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAiJsonPayload(string $raw): array
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return [];
        }
        if (\str_starts_with($raw, '```')) {
            $raw = (string)\preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = (string)\preg_replace('/\s*```$/', '', $raw);
            $raw = \trim($raw);
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            $start = \strpos($raw, '{');
            $end = \strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = \json_decode(\substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Stage-one AI response is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $requirementExpansion
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function normalizeThemePayload(
        array $payload,
        array $scope,
        array $websiteProfile,
        array $requirementExpansion,
        array $contract,
        string $locale
    ): array {
        $base = $this->buildMinimalStageOneRoot($scope, $websiteProfile, $requirementExpansion, $locale);
        foreach ([
            'i18n',
            'site_strategy',
            'theme_style',
            'palette',
            'theme',
            'theme_design',
            'navigation_plan',
            'footer_plan',
            'seo_strategy',
        ] as $sectionKey) {
            if (!\is_array($payload[$sectionKey] ?? null) || $payload[$sectionKey] === []) {
                unset($payload[$sectionKey]);
            }
        }
        if (!\is_array($payload['shared_components'] ?? null)) {
            unset($payload['shared_components']);
        }
        $payload = \array_replace_recursive($base, $payload);
        $payload['theme'] = \is_array($payload['theme'] ?? null) ? $payload['theme'] : [];
        $payload['theme']['logo_generation'] = $this->normalizeStageOneLogoGenerationPlan(
            $payload['theme']['logo_generation'] ?? [],
            $payload
        );
        $payload['navigation_plan']['header_items'] = $this->normalizeStageOneLinks(
            $payload['navigation_plan']['header_items'] ?? [],
            $contract,
            'navigation_plan.header_items'
        );
        $payload['footer_plan']['featured'] = $this->normalizeStageOneLinks(
            $payload['footer_plan']['featured'] ?? [],
            $contract,
            'footer_plan.featured'
        );
        $payload['footer_plan']['policies'] = $this->normalizeStageOneLinks(
            $payload['footer_plan']['policies'] ?? [],
            $contract,
            'footer_plan.policies'
        );
        unset($payload['pages']);

        return $payload;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $requirementExpansion
     * @return array<string, mixed>
     */
    private function buildMinimalStageOneRoot(array $scope, array $websiteProfile, array $requirementExpansion, string $locale): array
    {
        $siteName = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            'AI Site',
        ]);
        $primaryGoal = $this->firstNonEmpty([
            $requirementExpansion['site_goal'] ?? null,
            $requirementExpansion['expanded_brief'] ?? null,
            $this->stageOneBriefText($scope, $websiteProfile),
            'Create a clear, credible website with a direct next step.',
        ]);
        $primaryCta = $this->firstNonEmpty([
            $requirementExpansion['primary_cta'] ?? null,
            'Get started',
        ]);
        $palette = [
            'primary' => '#1D4ED8',
            'secondary' => '#0F766E',
            'accent' => '#F59E0B',
            'background' => '#F8FAFC',
            'body' => '#111827',
            'button' => '#1D4ED8',
        ];

        return [
            'confirmed' => 0,
            'content_locale' => $locale,
            'site_name' => $siteName,
            'requirement_expansion' => $requirementExpansion,
            'site_strategy' => [
                'site_display_name' => $siteName,
                'primary_goal' => $primaryGoal,
                'core_goal' => $primaryGoal,
                'audience' => $this->firstNonEmpty([$requirementExpansion['target_users'][0] ?? null, 'Visitors matched to the supplied brief']),
                'content_strategy' => $this->firstNonEmpty([$requirementExpansion['content_direction'] ?? null, $primaryGoal]),
                'conversion_path' => $this->firstNonEmpty([$requirementExpansion['conversion_strategy'] ?? null, 'Proof, useful detail, and a direct call to action.']),
                'primary_cta' => $primaryCta,
            ],
            'theme_style' => [
                'style_name' => $siteName . ' generated theme',
                'tone' => 'Clear, trustworthy, and conversion-aware.',
                'layout_direction' => 'Responsive sections with strong hierarchy and concise content.',
            ],
            'palette' => $palette,
            'theme' => [
                'logo_generation' => $this->buildStageOneLogoGenerationPlan($siteName, $primaryGoal, $palette, $locale),
            ],
            'theme_design' => [
                'theme_purpose' => $primaryGoal,
                'style_signature' => 'Purposeful editorial landing layout with practical proof and direct action.',
                'art_direction' => 'Clean sections, strong content hierarchy, and restrained visual emphasis.',
                'color_scheme' => $palette,
                'typography_spacing_radius' => [
                    'font_family' => 'Inter, system-ui, sans-serif',
                    'heading_scale' => 'Clear page headings with compact hierarchy.',
                    'body_scale' => 'Readable body text with concise paragraphs.',
                    'spacing_scale' => '8px base rhythm with generous section spacing.',
                    'radius_scale' => '6px cards and 4px controls.',
                ],
                'visual_keywords' => ['clear hierarchy', 'trust proof', 'direct CTA'],
                'tone_of_voice' => 'Specific, useful, and confident.',
                'cta_tone' => 'Direct and low-friction.',
                'forbidden_styles' => ['generic placeholder copy', 'invented routes', 'oversized decoration'],
                'selection_reason' => 'Base theme context generated from the requirement expansion.',
            ],
            'navigation_plan' => [
                'header_items' => [],
            ],
            'footer_plan' => [
                'featured' => [],
                'policies' => [],
            ],
            'shared_components' => [],
            'seo_strategy' => [
                'site_title' => $siteName,
                'meta_description' => $primaryGoal,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $palette
     * @return array<string, mixed>
     */
    private function buildStageOneLogoGenerationPlan(string $siteName, string $primaryGoal, array $palette = [], string $locale = ''): array
    {
        $primaryColor = \trim((string)($palette['primary'] ?? ''));
        $accentColor = \trim((string)($palette['accent'] ?? ''));
        $paletteText = \trim(\implode(', ', \array_filter([$primaryColor, $accentColor], static fn(string $value): bool => $value !== '')));
        if ($paletteText === '') {
            $paletteText = 'the generated theme palette';
        }
        $languageText = $this->buildLogoTextLanguagePrompt($locale);
        $promptBrief = 'Generate four transparent PNG symbol-only website logo icons from the approved business requirement.'
            . ' Do not render the site name, brand name, initials, monograms, user requirement text, slogans, labels, or any readable/pseudo text.'
            . ' Match ' . $paletteText . ' and support: ' . $primaryGoal
            . '. ' . $languageText
            . ' Each option must use a different symbol-only composition, glyph concept, and mark silhouette.';

        return [
            'stage' => 'logo_generation',
            'status' => 'planned',
            'asset_slot_id' => 'plan:theme:logo_generation',
            'slot_type' => 'logo_options',
            'kind' => 'logo_generation_options',
            'scope' => 'plan_theme',
            'output_path' => 'plan_json.theme.logo_generation',
            'option_count' => 4,
            'selected_option_id' => '',
            'selected_asset_slot_id' => '',
            'selected_url' => '',
            'prompt_brief' => $promptBrief,
            'style_direction' => 'Prepare four distinct symbol-only logo icon directions; each option must stay simple, recognizable, transparent, and legible at header size. Do not reuse the same icon, pictorial motif, layout, or silhouette across options. Do not plan wordmarks, initials, monograms, or text treatments.',
            'reuse_policy' => 'Generate four logo options from this theme plan; selected option and generated image URLs must live only in plan_json.theme.logo_generation.options.',
            'options' => $this->buildStageOneLogoGenerationOptions($siteName, $primaryGoal, $paletteText, $languageText),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStageOneLogoGenerationOptions(string $siteName, string $primaryGoal, string $paletteText, string $languageText): array
    {
        $directions = [
            'Clean abstract glyph based on the business subject; no letters, initials, words, or typography.',
            'Standalone emblem built from a concrete industry motif; no wordmark, initials, monogram, or readable text.',
            'Geometric pictorial mark using simple shapes from the approved brief; no monogram or letterform.',
            'Minimal line-mark with a distinctive subject silhouette; no text strip, slogan, or pseudo lettering.',
        ];
        $options = [];
        foreach ($directions as $index => $direction) {
            $number = $index + 1;
            $options[] = [
                'option_id' => 'logo_option_' . $number,
                'label' => 'Logo ' . $number,
                'asset_slot_id' => 'plan:theme:logo_generation:option_' . $number,
                'slot_type' => 'logo_icon',
                'kind' => 'logo_option',
                'status' => 'planned',
                'url' => '',
                'final_url' => '',
                'prompt_brief' => 'Generate logo option ' . $number
                    . ' as a transparent PNG symbol-only icon. Derive the glyph from the approved business requirement, not from the site name.'
                    . ' Match ' . $paletteText . '; support: ' . $primaryGoal
                    . '. ' . $languageText
                    . ' Direction: ' . $direction
                    . ' This option must be visually distinct from the other three options. Do not render brand names, initials, monograms, user requirement text, labels, or any readable/pseudo text.',
                'style_direction' => $direction,
            ];
        }

        return $options;
    }

    /**
     * @param mixed $candidate
     * @param array<string, mixed> $planRoot
     * @return array<string, mixed>
     */
    private function normalizeStageOneLogoGenerationPlan(mixed $candidate, array $planRoot): array
    {
        $siteName = $this->firstNonEmpty([
            $planRoot['site_strategy']['site_display_name'] ?? null,
            $planRoot['site_name'] ?? null,
            'AI Site',
        ]);
        $primaryGoal = $this->firstNonEmpty([
            $planRoot['site_strategy']['primary_goal'] ?? null,
            $planRoot['requirement_expansion']['site_goal'] ?? null,
            $planRoot['theme_design']['theme_purpose'] ?? null,
            'Create a clear website identity.',
        ]);
        $locale = $this->firstNonEmpty([
            $planRoot['content_locale'] ?? null,
            $planRoot['i18n']['content_locale'] ?? null,
            $planRoot['i18n']['primary_locale'] ?? null,
            $planRoot['i18n']['locale'] ?? null,
            $planRoot['plan_locale'] ?? null,
        ]);
        $base = $this->buildStageOneLogoGenerationPlan(
            $siteName,
            $primaryGoal,
            \is_array($planRoot['palette'] ?? null) ? $planRoot['palette'] : [],
            $locale
        );
        $incoming = \is_array($candidate) ? $candidate : [];
        $normalized = $base;
        foreach (['status', 'scope', 'prompt_brief', 'style_direction', 'reuse_policy'] as $field) {
            $text = \trim((string)($incoming[$field] ?? ''));
            if ($text !== '') {
                $normalized[$field] = $text;
            }
        }
        $normalized['options'] = $this->normalizeStageOneLogoGenerationOptions(
            $incoming['options'] ?? [],
            \is_array($base['options'] ?? null) ? $base['options'] : []
        );
        $optionIds = [];
        foreach ($normalized['options'] as $option) {
            $optionId = \trim((string)($option['option_id'] ?? ''));
            if ($optionId !== '') {
                $optionIds[$optionId] = true;
            }
        }
        $selectedOptionId = \trim((string)($incoming['selected_option_id'] ?? ''));
        if ($selectedOptionId !== '' && !isset($optionIds[$selectedOptionId])) {
            $selectedOptionId = '';
        }
        $selectedAssetSlotId = \trim((string)($incoming['selected_asset_slot_id'] ?? ''));
        $selectedUrl = $this->firstNonEmpty([
            $incoming['selected_url'] ?? null,
            $incoming['selected_final_url'] ?? null,
            $incoming['final_url'] ?? null,
            $incoming['url'] ?? null,
        ]);
        foreach ($normalized['options'] as $option) {
            if ($selectedOptionId !== '' && (string)($option['option_id'] ?? '') === $selectedOptionId) {
                $selectedAssetSlotId = $this->firstNonEmpty([$selectedAssetSlotId, $option['asset_slot_id'] ?? null]);
                $selectedUrl = $this->firstNonEmpty([$selectedUrl, $option['final_url'] ?? null, $option['url'] ?? null]);
                break;
            }
            if ($selectedOptionId === '' && $selectedUrl !== '') {
                $optionUrl = $this->firstNonEmpty([$option['final_url'] ?? null, $option['url'] ?? null]);
                if ($optionUrl === $selectedUrl) {
                    $selectedOptionId = (string)($option['option_id'] ?? '');
                    $selectedAssetSlotId = $this->firstNonEmpty([$selectedAssetSlotId, $option['asset_slot_id'] ?? null]);
                    break;
                }
            }
        }
        $normalized['stage'] = 'logo_generation';
        $normalized['asset_slot_id'] = 'plan:theme:logo_generation';
        $normalized['slot_type'] = 'logo_options';
        $normalized['kind'] = 'logo_generation_options';
        $normalized['output_path'] = 'plan_json.theme.logo_generation';
        $normalized['option_count'] = 4;
        $normalized['selected_option_id'] = $selectedOptionId;
        $normalized['selected_asset_slot_id'] = $selectedOptionId !== '' ? $selectedAssetSlotId : '';
        $normalized['selected_url'] = $selectedOptionId !== '' ? $selectedUrl : '';

        return $normalized;
    }

    /**
     * @param mixed $candidate
     * @param list<array<string, mixed>> $baseOptions
     * @return list<array<string, mixed>>
     */
    private function normalizeStageOneLogoGenerationOptions(mixed $candidate, array $baseOptions): array
    {
        $incomingByKey = [];
        if (\is_array($candidate)) {
            foreach ($candidate as $key => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $optionId = \trim((string)($row['option_id'] ?? $row['id'] ?? ''));
                $slotId = \trim((string)($row['asset_slot_id'] ?? $row['slot_id'] ?? ''));
                if ($optionId === '' && \is_string($key)) {
                    $optionId = \trim($key);
                }
                if ($optionId === '' && \is_int($key)) {
                    $optionId = 'logo_option_' . ($key + 1);
                }
                if ($optionId === '' && $slotId !== '' && \preg_match('/option[_:-]?([1-4])/', $slotId, $matches)) {
                    $optionId = 'logo_option_' . $matches[1];
                }
                if ($optionId !== '') {
                    $incomingByKey[$optionId] = $row;
                }
                if ($slotId !== '') {
                    $incomingByKey[$slotId] = $row;
                }
            }
        }

        $options = [];
        for ($index = 0; $index < 4; $index++) {
            $number = $index + 1;
            $base = \is_array($baseOptions[$index] ?? null) ? $baseOptions[$index] : [];
            $optionId = 'logo_option_' . $number;
            $slotId = 'plan:theme:logo_generation:option_' . $number;
            $incoming = \is_array($incomingByKey[$optionId] ?? null)
                ? $incomingByKey[$optionId]
                : (\is_array($incomingByKey[$slotId] ?? null) ? $incomingByKey[$slotId] : []);
            $option = \array_replace($base, [
                'option_id' => $optionId,
                'label' => 'Logo ' . $number,
                'asset_slot_id' => $slotId,
                'slot_type' => 'logo_icon',
                'kind' => 'logo_option',
                'status' => 'planned',
                'url' => '',
                'final_url' => '',
            ]);
            foreach (['label', 'status', 'prompt_brief', 'style_direction', 'revised_prompt', 'error', 'updated_at'] as $field) {
                $text = \trim((string)($incoming[$field] ?? ''));
                if ($text !== '') {
                    $option[$field] = $text;
                }
            }
            $finalUrl = $this->firstNonEmpty([
                $incoming['final_url'] ?? null,
                $incoming['url'] ?? null,
                $incoming['asset_url'] ?? null,
                $base['final_url'] ?? null,
                $base['url'] ?? null,
            ]);
            if ($finalUrl !== '') {
                $option['final_url'] = $finalUrl;
                $option['url'] = $finalUrl;
                $status = \strtolower(\trim((string)($option['status'] ?? '')));
                if ($status === '' || \in_array($status, ['planned', 'pending'], true)) {
                    $option['status'] = 'generated';
                }
            }
            $options[] = $option;
        }

        return $options;
    }

    /**
     * @param mixed $links
     * @param array<string, mixed> $contract
     * @return list<array{label:string,href:string}>
     */
    private function normalizeStageOneLinks(mixed $links, array $contract, string $requirementPath): array
    {
        $routeContract = \is_array($contract['page_route_contract'] ?? null) ? $contract['page_route_contract'] : [];
        $allowed = $this->stageOneAllowedPathsForRequirement($routeContract, $requirementPath);
        $allowedMap = \array_fill_keys($allowed, true);
        $normalized = [];
        foreach (\is_array($links) ? $links : [] as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $label = \trim((string)($link['label'] ?? ''));
            $href = $this->normalizeStageOneHref((string)($link['href'] ?? ''));
            if ($label === '' || $href === '' || ($allowedMap !== [] && !isset($allowedMap[$href]))) {
                continue;
            }
            $normalized[] = ['label' => $label, 'href' => $href];
        }
        if ($normalized !== []) {
            return $normalized;
        }
        foreach ($allowed !== [] ? $allowed : ['/'] as $path) {
            $normalized[] = ['label' => $path === '/' ? 'Home' : $this->humanizeIdentifier(\trim($path, '/')), 'href' => $path];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $routeContract
     * @return list<string>
     */
    private function stageOneAllowedPathsForRequirement(array $routeContract, string $requirementPath): array
    {
        $linkGroups = \is_array($routeContract['link_groups'][$requirementPath] ?? null) ? $routeContract['link_groups'][$requirementPath] : [];
        $paths = [];
        foreach (\is_array($linkGroups['allowed_paths'] ?? null) ? $linkGroups['allowed_paths'] : [] as $path) {
            $path = $this->normalizeStageOneHref((string)$path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        if ($paths !== []) {
            return \array_values(\array_unique($paths));
        }
        $routes = \is_array($routeContract['routes_by_type'] ?? null) ? $routeContract['routes_by_type'] : [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $path = $this->normalizeStageOneHref((string)($route['path'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return \array_values(\array_unique($paths !== [] ? $paths : ['/']));
    }

    private function normalizeStageOneHref(string $href): string
    {
        $href = \trim($href);
        if ($href === '' || $href === '#') {
            return '';
        }
        if (\preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1 || \str_starts_with($href, '//')) {
            return '';
        }
        $href = \preg_replace('/[?#].*$/', '', $href) ?? $href;
        $href = '/' . \trim($href, '/');
        return $href === '//' ? '/' : $href;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $requirementExpansion
     * @param array<string, mixed> $contract
     * @return array{success:bool,page:array<string,mixed>,attempt_no:int,message?:string,validation_issues?:list<array<string,mixed>>}
     */
    private function generateStageOnePageByAiWithAttemptLimit(
        string $pageType,
        array $scope,
        array $websiteProfile,
        array $requirementExpansion,
        array $contract,
        string $locale,
        ?callable $onChunk,
        ?callable $onProgress
    ): array {
        $previousIssues = [];
        $lastMessage = '';
        for ($attemptNo = 1; $attemptNo <= self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS; $attemptNo++) {
            try {
                $prompt = $this->buildStageOnePagePrompt($pageType, $scope, $websiteProfile, $requirementExpansion, $contract, $locale, $attemptNo, $previousIssues);
                $payload = $this->decodeAiJsonPayload($this->callStageOneAiStream($prompt, $onChunk, $locale));
                $page = \is_array($payload['page'] ?? null)
                    ? $payload['page']
                    : (\is_array($payload['pages'][$pageType] ?? null) ? $payload['pages'][$pageType] : $payload);
                $page = $this->normalizeGeneratedStageOnePage($pageType, $page, $contract, $scope, $locale);
                $page = $this->repairAiStageOnePlanJsonBeforeValidation(
                    ['pages' => [$pageType => $page]],
                    [$pageType],
                    $locale,
                    $this->stageOneBriefText($scope, $websiteProfile)
                )['pages'][$pageType] ?? $page;
                $report = $this->stageOneContractValidator->validatePlanJsonPage($pageType, $page, $contract, [
                    'validation_page_types' => [$pageType],
                    'strict_retry_count' => $attemptNo - 1,
                ]);
                if (!empty($report['passed'])) {
                    return [
                        'success' => true,
                        'page' => $page,
                        'attempt_no' => $attemptNo,
                    ];
                }
                $previousIssues = \is_array($report['issues'] ?? null) ? $report['issues'] : [];
                $lastMessage = $this->summarizeValidationIssues($previousIssues);
            } catch (\Throwable $throwable) {
                $lastMessage = $throwable->getMessage();
                $previousIssues = [[
                    'code' => 'ai_page_generation_exception',
                    'message' => $lastMessage,
                    'page_type' => $pageType,
                    'severity' => 'high',
                ]];
            }

            if ($attemptNo < self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS && $onProgress !== null) {
                $onProgress([
                    'message' => 'Plan JSON page contract failed; retrying strict recovery for page: ' . $pageType . ' issues=' . $lastMessage,
                    'progress_kind' => 'stage1_page_retry',
                    'stage1_phase' => 'page_retry',
                    'page_type' => $pageType,
                    'attempt_no' => $attemptNo,
                    'max_attempts' => self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS,
                    'progress_percent' => 45,
                ]);
            }
        }

        return [
            'success' => false,
            'page' => $this->buildFailedStageOnePage($pageType, $lastMessage, self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS, $contract),
            'attempt_no' => self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS,
            'message' => $lastMessage !== '' ? $lastMessage : 'Stage-one page generation failed after automatic attempts.',
            'validation_issues' => $previousIssues,
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizeGeneratedStageOnePage(string $pageType, array $page, array $contract, array $scope, string $locale): array
    {
        unset($contract, $scope, $locale);
        $page = \array_replace($page, [
            'page_type' => $pageType,
            'status' => AiSitePlanJsonStateService::STATUS_PENDING,
        ]);
        foreach ($page as $key => $value) {
            if (!$this->isStageOneDynamicBlockKey((string)$key) || !\is_array($value)) {
                continue;
            }
            $block = $value;
            $block['block_key'] = \trim((string)($block['block_key'] ?? $key));
            $block['status'] = AiSitePlanJsonStateService::STATUS_PENDING;
            $page[$key] = $block;
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $pageContract
     * @return list<string>
     */
    private function exactStageOnePageBlockKeys(array $pageContract): array
    {
        $required = \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [];
        $optional = \is_array($pageContract['recommended_optional_block_keys'] ?? null) ? $pageContract['recommended_optional_block_keys'] : [];
        $target = (int)($pageContract['target_blocks'] ?? 0);
        if ($target <= 0) {
            $target = (int)($pageContract['min_blocks'] ?? 0);
        }

        $keys = [];
        foreach (\array_merge($required, $optional) as $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey === '' || \in_array($blockKey, $keys, true)) {
                continue;
            }
            $keys[] = $blockKey;
            if ($target > 0 && \count($keys) >= $target) {
                break;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function stageOneThemeDesignSkeleton(): array
    {
        return [
            'theme_purpose' => 'Visitor goal and business goal for the site.',
            'style_signature' => 'Distinct layout and visual identity for this brief.',
            'art_direction' => 'Concrete image, surface, and composition direction.',
            'color_scheme' => [
                'primary' => '#1D4ED8',
                'secondary' => '#0F766E',
                'accent' => '#F59E0B',
                'background' => '#F8FAFC',
                'body' => '#111827',
                'button' => '#1D4ED8',
            ],
            'typography_spacing_radius' => [
                'font_family' => 'Inter, system-ui, sans-serif',
                'heading_scale' => 'Clear page headings with compact hierarchy.',
                'body_scale' => 'Readable body text with concise paragraphs.',
                'spacing_scale' => '8px base rhythm with generous section spacing.',
                'radius_scale' => '6px cards and 4px controls.',
            ],
            'visual_keywords' => ['clear hierarchy', 'trust proof', 'direct CTA'],
            'tone_of_voice' => 'Specific, useful, and confident.',
            'cta_tone' => 'Direct and low-friction.',
            'forbidden_styles' => ['generic placeholder copy', 'invented routes', 'oversized decoration'],
            'selection_reason' => 'Theme choices are derived from the current brief and route contract.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stageOneThemeRootSkeleton(): array
    {
        return [
            'i18n' => [
                'content_locale' => 'en_US',
            ],
            'site_strategy' => [
                'site_display_name' => 'Current site name',
                'primary_goal' => 'Primary visitor outcome from the brief.',
                'audience' => 'Visitors matched to the current brief.',
            ],
            'theme_style' => [
                'style_name' => 'Current brief theme',
                'tone' => 'Specific tone for the current audience.',
                'layout_direction' => 'Concrete layout direction for the selected pages.',
            ],
            'palette' => [
                'primary' => '#1D4ED8',
                'secondary' => '#0F766E',
                'accent' => '#F59E0B',
                'background' => '#F8FAFC',
                'body' => '#111827',
                'button' => '#1D4ED8',
            ],
            'theme' => [
                'logo_generation' => $this->buildStageOneLogoGenerationPlan(
                    'Current site name',
                    'Primary visitor outcome from the brief.',
                    [
                        'primary' => '#1D4ED8',
                        'secondary' => '#0F766E',
                        'accent' => '#F59E0B',
                        'background' => '#F8FAFC',
                        'body' => '#111827',
                        'button' => '#1D4ED8',
                    ],
                    'en_US'
                ),
            ],
            'theme_design' => $this->stageOneThemeDesignSkeleton(),
            'navigation_plan' => [
                'header_items' => [],
            ],
            'footer_plan' => [
                'featured' => [],
                'policies' => [],
            ],
            'shared_components' => [],
            'seo_strategy' => [
                'primary_keywords' => [],
                'secondary_keywords' => [],
                'meta_title_pattern' => 'Current site title',
                'meta_description_pattern' => 'Current site description',
            ],
        ];
    }

    private function json(mixed $value): string
    {
        return (string)(\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]');
    }

    /**
     * @param list<string> $exactBlockKeys
     * @return array<string, mixed>
     */
    private function stageOnePageReturnSkeleton(array $exactBlockKeys): array
    {
        $skeleton = [
            'page_goal' => 'Visitor-facing goal for this page.',
            'theme_alignment_summary' => 'Theme fit summary for this page.',
            'page_design_plan' => [
                'section_flow' => $exactBlockKeys,
                'visual_rhythm' => 'Concrete rhythm across the exact block keys.',
            ],
        ];
        foreach ($exactBlockKeys as $blockKey) {
            $skeleton[$blockKey] = [
                'block_key' => $blockKey,
                'page_flow_role' => 'opening|proof|support|conversion|detail',
                'content' => 'Visitor-facing block copy.',
                'design_tags' => '{complete object}',
                'visual_signature' => '{complete object}',
                'image_intent' => '{complete object}',
                'field_plan' => [
                    [
                        'field' => 'headline',
                        'sample' => 'Trusted play starts here.',
                        'implementation_note' => 'Render as the block heading.',
                    ],
                    [
                        'field' => 'supporting_copy',
                        'sample' => 'Visitors get clear steps, secure cues, and a focused path to the next action.',
                        'implementation_note' => 'Render below the heading.',
                    ],
                    [
                        'field' => 'context_detail',
                        'sample' => 'Fast setup, transparent rules, and visible support details.',
                        'implementation_note' => 'Render as supporting detail.',
                    ],
                ],
                'execution_script' => '{complete object with core_copy}',
            ];
        }

        return $skeleton;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildFailedStageOnePage(string $pageType, string $message, int $attemptNo, array $contract): array
    {
        $pageContract = $this->stageOneContractService->pageContract($contract, $pageType);
        $blockKeys = \array_values(\array_filter(\array_map('strval', \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [])));
        if ($blockKeys === []) {
            $blockKeys = ['stage1_plan'];
        }
        $page = [
            'page_type' => $pageType,
            'status' => AiSitePlanJsonStateService::STATUS_FAILED,
            'error' => $message,
            'attempt_no' => $attemptNo,
            'page_goal' => 'Stage-one page generation failed.',
            'theme_alignment_summary' => 'Manual retry is required before this page can build.',
            'page_design_plan' => ['status' => 'failed'],
        ];
        foreach ($blockKeys as $blockKey) {
            $page[$blockKey] = [
                'block_key' => $blockKey,
                'status' => AiSitePlanJsonStateService::STATUS_FAILED,
                'error' => $message,
                'attempt_no' => $attemptNo,
                'content' => '',
            ];
        }

        return $page;
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return array<string,mixed>
     */
    private function buildRetryableStageOnePageFailure(string $pageType, string $message, array $issues, int $attemptNo): array
    {
        return [
            'operation' => 'plan',
            'item_key' => $pageType,
            'item_type' => 'page_fanout',
            'retry_scope' => 'stage1_page',
            'page_type' => $pageType,
            'failure_source' => 'gate_contract',
            'failure_class' => 'stage1_page_attempt_limit',
            'message' => $message,
            'validation_summary' => $this->summarizeValidationIssues($issues),
            'validation_issues' => $issues,
            'attempt_no' => $attemptNo,
            'max_attempts' => self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS,
            'failed_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $issues
     */
    private function summarizeValidationIssues(array $issues): string
    {
        $parts = [];
        foreach (\array_slice($issues, 0, 6) as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $path = \trim((string)($issue['path'] ?? $issue['field_path'] ?? ''));
            $code = \trim((string)($issue['code'] ?? $issue['reason_code'] ?? 'invalid'));
            $parts[] = ($path !== '' ? $path : 'plan_json') . '=' . ($code !== '' ? $code : 'invalid');
        }

        return \implode('; ', \array_values(\array_filter($parts)));
    }

    private function isStageOneDynamicBlockKey(string $key): bool
    {
        return $key !== '' && !isset([
            'page_id' => true,
            'page_type' => true,
            'type' => true,
            'name' => true,
            'title' => true,
            'label' => true,
            'page_label' => true,
            'page_title' => true,
            'page_goal' => true,
            'theme_alignment_summary' => true,
            'page_design_plan' => true,
            'status' => true,
            'error' => true,
            'attempt_no' => true,
            'primary_keywords' => true,
            'secondary_keywords' => true,
            'seo' => true,
            'route' => true,
            'route_path' => true,
            'slug' => true,
            'path' => true,
            'layout' => true,
            'style_code' => true,
            'style_settings' => true,
            'design_tokens' => true,
            'theme_css_ref' => true,
            'navigation' => true,
            'menus' => true,
            'links' => true,
            'settings' => true,
            'preview_url' => true,
            'preview_full_url' => true,
            'visual_preview_url' => true,
            'visual_edit_url' => true,
            'virtual_preview_url' => true,
            'virtual_edit_url' => true,
            'section_refinements' => true,
            'ai_description' => true,
            'updated_at' => true,
        ][$key]);
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function repairAiStageOnePlanJsonBeforeValidation(array $planJson, array $pageTypes, string $locale, string $brief): array
    {
        unset($brief);
        foreach ($pageTypes as $pageType) {
            if (!\is_array($planJson['pages'][$pageType] ?? null)) {
                continue;
            }
            $page = $planJson['pages'][$pageType];
            $label = (string)($page['page_label'] ?? Page::getPageTypes()[$pageType] ?? $this->humanizeIdentifier($pageType));
            if ($this->isWeakStageOnePageGoal((string)($page['page_goal'] ?? ''), $pageType)) {
                $page['page_goal'] = $this->resolvePageGoal($pageType, $label, $locale);
            }
            if (\trim((string)($page['theme_alignment_summary'] ?? '')) === '') {
                $page['theme_alignment_summary'] = $this->resolvePageWhy($pageType, $label, $locale);
            }
            if (!\is_array($page['page_design_plan'] ?? null) || $page['page_design_plan'] === []) {
                $page['page_design_plan'] = [
                    'intent' => (string)($page['page_goal'] ?? $this->resolvePageGoal($pageType, $label, $locale)),
                    'structure' => 'Use the required direct block keys under plan_json.pages.' . $pageType . ' as the execution order.',
                    'visual_direction' => (string)($page['theme_alignment_summary'] ?? $this->resolvePageWhy($pageType, $label, $locale)),
                    'content_direction' => 'Each block should contain concrete visitor-facing copy, proof, and a clear next step when relevant.',
                ];
            }
            $planJson['pages'][$pageType] = $page;
        }

        return $planJson;
    }

    private function isWeakStageOnePageGoal(string $goal, string $pageType): bool
    {
        $normalized = \mb_strtolower(\trim($goal));
        if ($normalized === '') {
            return true;
        }
        foreach ([
            'deliver clear and actionable page content for visitors',
            'provide clear and actionable page content',
            'should clearly serve page intent and lead users to next actions',
            'explain the page clearly',
        ] as $needle) {
            if ($needle !== '' && \str_contains($normalized, \mb_strtolower($needle))) {
                return true;
            }
        }

        return $pageType === Page::TYPE_REFUND_POLICY && \mb_strlen($normalized) < 30;
    }

    private function resolvePageGoal(string $pageType, string $pageLabel, string $locale): string
    {
        if ($pageType === Page::TYPE_REFUND_POLICY) {
            return $this->isCjkLocale($locale)
                ? 'Explain refund eligibility, timing, and request steps so customers can act with confidence.'
                : 'Explain refund eligibility, timing, and request steps so customers can act with confidence.';
        }
        if ($this->isCjkLocale($locale) && ($pageType === Page::TYPE_HOME || $pageType === 'home_page')) {
            return 'Explain the home page value clearly and guide visitors to the primary action.';
        }

        return $pageLabel . ' explains the page value clearly and guides visitors to the next action.';
    }

    private function resolvePageWhy(string $pageType, string $pageLabel, string $locale): string
    {
        if ($this->isCjkLocale($locale) && ($pageType === Page::TYPE_HOME || $pageType === 'home_page')) {
            return 'The home page anchors the shared value story, navigation, and primary conversion path.';
        }

        return $pageLabel . ' aligns the shared theme with the page role and visitor decision path.';
    }

    private function isEmptyAiStreamCompletionFailure(\Throwable $throwable): bool
    {
        for ($current = $throwable; $current instanceof \Throwable; $current = $current->getPrevious()) {
            $message = \mb_strtolower($current->getMessage());
            if (\str_contains($message, 'without content')
                || \str_contains($message, 'without any content')
                || \str_contains($message, 'completed without content')
                || \str_contains($message, 'returned no content')
                || \str_contains($message, 'empty ai stream completion')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $siteStrategy
     * @param array<string,mixed> $sharedPromptContext
     * @param array<string,mixed> $brandPositioning
     */
    private function resolveStageOneContractSiteDisplayName(
        array $scope,
        array $siteStrategy,
        array $sharedPromptContext,
        array $brandPositioning,
        string $contentLocale = ''
    ): string {
        $manifestItems = \is_array($scope['content_manifest']['items'] ?? null) ? $scope['content_manifest']['items'] : [];
        $name = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $siteStrategy['site_display_name'] ?? null,
            $sharedPromptContext['site_title'] ?? null,
            $brandPositioning['site_name'] ?? null,
            $manifestItems['site.name'] ?? null,
            $scope['store_name'] ?? null,
        ]);
        if ($name !== '') {
            return $name;
        }

        return 'AI Site';
    }

    /**
     * About/contact/blog media rhythm rule: media richness prompts are guidance only,
     * not validation gates. Policy media rule: image count, image subject, and
     * generated copy are not validation gates.
     *
     * @return array<string,mixed>
     */
    private function buildSeoStrategy(): array
    {
        return [
            'core_intent' => 'official website',
            'primary_keywords' => ['official website', 'brand guide'],
            'keyword_page_map' => [
                ['keyword' => 'official website', 'page_type' => Page::TYPE_HOME],
            ],
            'content_strategy' => 'Meta titles should carry the primary keyword while page copy stays visitor-facing.',
            'internal_linking' => 'Use concise English slugs and exact route paths for shared navigation.',
            'url_structure' => 'flat',
        ];
    }

    private function isChineseLocale(string $locale): bool
    {
        return $this->isCjkLocale($locale);
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $fanoutProgress
     * @param callable|null $onProgress
     */
    private function emitStageOnePageFanoutProgress(array $pageTypes, array $fanoutProgress, ?callable $onProgress = null): void
    {
        $summary = $this->summarizeStageOnePageFanoutProgress($fanoutProgress);
        $summary['message'] = 'Stage 1 page fanout: total ' . \count($pageTypes);
        $summary['concurrency'] = \max(0, (int)($fanoutProgress['concurrency'] ?? 0));
        if ($onProgress !== null) {
            $onProgress($summary);
        }
    }

    /**
     * @param array<string, mixed> $fanoutProgress
     * @return array<string, mixed>
     */
    private function summarizeStageOnePageFanoutProgress(array $fanoutProgress): array
    {
        $groups = \is_array($fanoutProgress['groups'] ?? null) ? $fanoutProgress['groups'] : [];
        foreach (['running', 'pending', 'done', 'failed'] as $key) {
            $groups[$key] = \is_array($groups[$key] ?? null) ? $groups[$key] : [];
        }

        return [
            'concurrency' => \max(0, (int)($fanoutProgress['concurrency'] ?? 0)),
            'remaining_count' => \count($groups['running']) + \count($groups['pending']),
            'details' => $groups,
        ];
    }

    private function resolveStageOnePageFanoutConcurrency(int $pageCount): int
    {
        $configured = (int)Env::get('pagebuilder.ai_site.max_http_concurrency', 5);
        return \max(1, \min(\max(1, $pageCount), $configured));
    }

    private function resolveStageOneBlockSegmentConcurrency(int $segmentCount): int
    {
        return \max(1, \min(\max(1, $segmentCount), $this->resolveStageOnePageFanoutConcurrency($segmentCount)));
    }

    private function generateStageOneBlockSegmentByAi(array $segment): array
    {
        return $segment;
    }

    private function runStageOnePageFanoutTasks(array $tasks, array $segmentTasks, array $pageTypes, ?callable $onSettled = null): array
    {
        $concurrency = $this->resolveStageOnePageFanoutConcurrency(\count($pageTypes));
        $segmentConcurrency = $this->resolveStageOneBlockSegmentConcurrency(\count($segmentTasks));
        if ($this->supportsCooperativeConcurrency($segmentConcurrency) && FiberTaskRunner::currentPump() === null) {
            $this->runCooperativeSessionTasksSettled($segmentTasks, ['concurrency' => $segmentConcurrency]);
        }

        return $this->runCooperativeSessionTasksSettled($tasks, [
            'concurrency' => $concurrency,
            'on_settled' => $onSettled,
        ]);
    }

    private function supportsCooperativeConcurrency(int $concurrency): bool
    {
        return $concurrency > 1;
    }

    private function runCooperativeSessionTasksSettled(array $tasks, array $options = []): array
    {
        if ($tasks === []) {
            return [];
        }

        $concurrency = \max(1, (int)($options['concurrency'] ?? 1));
        $onSettled = \is_callable($options['on_settled'] ?? null) ? $options['on_settled'] : null;
        if ($concurrency > 1 && \class_exists(\Fiber::class)) {
            $runner = new FiberTaskRunner(defaultConcurrency: $concurrency);
            $results = [];
            foreach ($runner->runEvents($this->wrapStageOneFanoutTasks($tasks), $concurrency) as $taskKey => $event) {
                if (($event['status'] ?? '') === 'fulfilled') {
                    $results[$taskKey] = \is_array($event['result'] ?? null) ? $event['result'] : [];
                    if ($onSettled !== null) {
                        $onSettled($taskKey, $results[$taskKey]);
                    }
                    continue;
                }
                $error = ($event['error'] ?? null) instanceof \Throwable
                    ? $event['error']
                    : new \RuntimeException('Stage-one fanout task failed without an exception payload.');
                $results[$taskKey] = $this->buildRejectedStageOneFanoutResult($error);
                if ($onSettled !== null) {
                    $onSettled($taskKey, $results[$taskKey]);
                }
            }

            return $results;
        }

        $results = [];
        foreach ($tasks as $taskKey => $task) {
            try {
                $results[$taskKey] = \is_callable($task) ? $task() : $task;
            } catch (\Throwable $throwable) {
                $results[$taskKey] = $this->buildRejectedStageOneFanoutResult($throwable);
            }
            if ($onSettled !== null) {
                $onSettled($taskKey, \is_array($results[$taskKey] ?? null) ? $results[$taskKey] : []);
            }
        }

        return $results;
    }

    /**
     * @param array<string|int, mixed> $tasks
     * @return array<string|int, callable(string|int): mixed>
     */
    private function wrapStageOneFanoutTasks(array $tasks): array
    {
        $wrapped = [];
        foreach ($tasks as $taskKey => $task) {
            $wrapped[$taskKey] = static function () use ($task): mixed {
                if (!\is_callable($task)) {
                    return $task;
                }

                return $task();
            };
        }

        return $wrapped;
    }

    /**
     * @return array{success:bool,page:array<string,mixed>,attempt_no:int,message:string,validation_issues:list<array<string,mixed>>}
     */
    private function buildRejectedStageOneFanoutResult(\Throwable $throwable): array
    {
        $message = \trim($throwable->getMessage());
        if ($message === '') {
            $message = 'Stage-one fanout task failed.';
        }

        return [
            'success' => false,
            'page' => [],
            'attempt_no' => self::STAGE_ONE_PAGE_MAX_AI_ATTEMPTS,
            'message' => $message,
            'validation_issues' => [[
                'code' => 'stage1_fanout_task_exception',
                'message' => $message,
                'severity' => 'high',
            ]],
        ];
    }


    private function policyRegistry(): AiSiteDesignPolicyRegistry
    {
        return $this->policyRegistry ?? new AiSiteDesignPolicyRegistry();
    }

    private function validator(): PlanJsonContractValidator
    {
        return $this->validator ?? new PlanJsonContractValidator();
    }

    private function projectionService(): AiSitePlanJsonProjectionService
    {
        return $this->projectionService ?? new AiSitePlanJsonProjectionService();
    }
}
