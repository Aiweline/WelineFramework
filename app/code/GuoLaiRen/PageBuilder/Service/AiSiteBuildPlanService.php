<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContentManifestLinter;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractSchema;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;

final class AiSiteBuildPlanService
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

    public function __construct(
        private readonly ?AiSiteDesignPolicyRegistry $policyRegistry = null,
        private readonly ?BuildPlanContractValidator $validator = null,
        private readonly ?AiSiteBuildPlanProjectionService $projectionService = null
    ) {
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
        $existing = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];

        $sourcePlan = $this->selectStageOneSourcePlan($planJson);
        if ($sourcePlan === []) {
            throw new \RuntimeException('BuildPlan contract failed: stage-one plan JSON is missing. Regenerate the plan instead of using legacy execution blueprint fallback.');
        }
        $sourceSignature = $this->sourceSignature($scope, $sourcePlan);
        $expectedPageTypes = $this->resolvePageTypes($scope, $sourcePlan);
        if (
            $this->looksLikeBuildPlanV2($existing)
            && $this->existingContractMatchesCurrentSource($existing, $sourceSignature, $expectedPageTypes)
        ) {
            $existingMeta = \is_array($existing['contract_meta'] ?? null) ? $existing['contract_meta'] : [];
            if (\strtolower(\trim((string)($existingMeta['status'] ?? ''))) === 'confirmed') {
                return $existing;
            }
            return $this->normalizeExistingContract($existing, $scope, $websiteProfile);
        }

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
        $primaryGoal = $this->resolveBuildPlanPrimaryGoal($scope, $profile);
        $locale = $this->resolveBuildPlanContentLocale($scope, $profile, $sourcePlan);
        $contractId = 'build_plan_v2_' . \substr($sourceSignature, 0, 16);
        $sourceContracts = $this->buildSourceContractRefs($scope, $sourceSignature);
        $sourceOfTruth = $this->buildBuildPlanSourceOfTruth(
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
                'version' => BuildPlanContractSchema::VERSION,
                'type' => 'build_plan_v2',
                'stage' => ContractType::STAGE_STAGE1,
                'status' => 'draft',
                'creator' => 'AiSitePlanQueue',
                'adapter_type' => 'build_plan_contract_v2_2',
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
            'design_manifest' => $this->buildBuildPlanDesignManifest($policy, $sourcePlan),
            'i18n' => [
                'primary_locale' => $locale,
                'required_locales' => [$locale],
            ],
            'content_manifest' => [
                'primary_locale' => $locale,
                'items' => $contentItems,
            ],
            'pages' => $pages,
            'blocks' => $blocks,
            'source_contracts' => $sourceContracts,
            'permission_matrix' => [
                'read' => ['policy_ref', 'policy_projection', 'design_manifest', 'content_manifest', 'pages', 'blocks'],
                'create' => ['task_results', 'qa_report', 'repair_patch'],
                'patch' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
                'forbidden' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks'],
                'read_only' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks'],
            ],
            'frozen_fields' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks'],
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

        AiSiteWorkflowTrace::log('build_plan_v2_contract_built', [
            'contract_id' => $contractId,
            'page_count' => \count($pages),
            'block_count' => \count($blocks),
            'locale' => $locale,
            'site_name' => $siteName,
            'primary_goal' => $primaryGoal,
        ]);
        if (AiSiteWorkflowTrace::verbose()) {
            AiSiteWorkflowTrace::json('build_plan_v2_contract_detail', $contract, [
                'contract_id' => $contractId,
            ]);
        }

        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function confirm(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $confirmedAt = \date('Y-m-d H:i:s');
        $meta['version'] = (string)($meta['version'] ?? BuildPlanContractSchema::VERSION);
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
     * @param array<string, mixed> $contract
     */
    private function looksLikeBuildPlanV2(array $contract): bool
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        return (string)($meta['version'] ?? '') === BuildPlanContractSchema::VERSION
            && \is_array($contract['pages'] ?? null)
            && \is_array($contract['blocks'] ?? null);
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
        foreach (['page_plans', 'pages'] as $key) {
            if ($this->normalizePagesSource($sourcePlan[$key] ?? null) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function normalizeExistingContract(array $contract, array $scope, array $websiteProfile): array
    {
        $policy = $this->policyRegistry()->get();
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $sourcePlan = $this->selectStageOneSourcePlan($planJson);
        if ($sourcePlan === []) {
            throw new \RuntimeException('BuildPlan contract failed: stage-one plan JSON is missing. Regenerate the plan instead of using legacy execution blueprint fallback.');
        }
        $sourceSignature = $this->sourceSignature($scope, $sourcePlan);
        $pageTypes = $this->resolvePageTypes($scope, $sourcePlan);
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
        $primaryGoal = $this->resolveBuildPlanPrimaryGoal($scope, $profile);

        if (!\is_array($contract['site_brief'] ?? null)) {
            $contract['site_brief'] = [
                'site_name' => $this->firstNonEmpty([$websiteProfile['site_title'] ?? null, $scope['site_title'] ?? null, 'AI Site']),
                'primary_goal' => $this->firstNonEmpty([$websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, 'Present the business clearly.']),
            ];
        }
        $contract['source_of_truth'] = $this->buildBuildPlanSourceOfTruth(
            \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [],
            $scope,
            $sourcePlan,
            $sourceSignature,
            $siteName,
            $primaryGoal,
            $pageTypes
        );
        $contract['design_manifest'] = $this->stripBuildPlanExplanatoryFields(
            \array_replace_recursive(
                $this->buildBuildPlanDesignManifest($policy, $sourcePlan),
                \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : []
            )
        );
        $locale = $this->resolveBuildPlanContentLocale($scope, $profile, $sourcePlan);
        [$freshPages, $freshBlocks, $freshContentItems] = $this->buildPageBlockGraph(
            $scope,
            $sourcePlan,
            $siteName,
            $primaryGoal,
            $locale
        );
        $contract['pages'] = $freshPages;
        $contract['blocks'] = $freshBlocks;
        $contract['content_manifest'] = [
            'primary_locale' => $locale,
            'items' => \array_replace(
                [
                    'site.name' => $siteName,
                    'site.primary_goal' => $primaryGoal,
                ],
                $freshContentItems
            ),
        ];
        if (!\is_array($contract['source_contracts'] ?? null) || $contract['source_contracts'] === []) {
            $contract['source_contracts'] = $this->buildSourceContractRefs($scope, $sourceSignature);
        }
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $meta['type'] = (string)($meta['type'] ?? 'build_plan_v2');
        $meta['stage'] = (string)($meta['stage'] ?? ContractType::STAGE_STAGE1);
        $meta['creator'] = (string)($meta['creator'] ?? 'AiSitePlanQueue');
        $meta['adapter_type'] = (string)($meta['adapter_type'] ?? 'build_plan_contract_v2_2');
        $contract['contract_meta'] = $meta;

        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<string> $expectedPageTypes
     */
    private function existingContractMatchesCurrentSource(array $contract, string $sourceSignature, array $expectedPageTypes): bool
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        if (\trim((string)($meta['source_signature'] ?? '')) !== $sourceSignature) {
            return false;
        }

        if ($expectedPageTypes === []) {
            return true;
        }

        return $this->sameStringSet($this->contractPageTypes($contract), $expectedPageTypes);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $sourcePlan
     */
    private function resolveBuildPlanContentLocale(
        array $scope,
        array $profile,
        array $sourcePlan = []
    ): string {
        return $this->firstNonEmpty([
            $scope['ai_content_locale'] ?? null,
            $sourcePlan['i18n']['content_locale'] ?? null,
            $sourcePlan['i18n']['primary_locale'] ?? null,
            $sourcePlan['i18n']['locale'] ?? null,
            $scope['content_locale'] ?? null,
            $scope['plan_generated_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $sourcePlan['plan_locale'] ?? null,
            $sourcePlan['content_locale'] ?? null,
            $profile['content_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $profile['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
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
        return [
            'source_of_truth_locale' => $locale,
            'visible_copy_rule' => 'All visitor-facing copy for headings, body, buttons, navigation, footer, form labels, alt/title/aria/placeholder text must use source_of_truth_locale.',
            'plan_text_rule' => 'Stage-one and BuildPlan text is intent only; translate or rewrite it before rendering visible copy.',
            'proper_noun_rule' => 'Brand names, product names, domain names, URLs, acronyms, model names, and user-provided proper nouns may retain original spelling when natural.',
            'failure_mode' => 'Visible copy in a different main language is a build contract violation.',
        ];
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
        $contracts = \is_array($scope['plan_workbench']['contracts'] ?? null) ? $scope['plan_workbench']['contracts'] : [];
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];

        foreach ([
            ContractType::TYPE_SOURCE_TRUTH => $sourceTruth,
            ContractType::TYPE_SITE_BRIEF => \is_array($contracts[ContractType::TYPE_SITE_BRIEF] ?? null) ? $contracts[ContractType::TYPE_SITE_BRIEF] : [],
            ContractType::TYPE_DESIGN_MANIFEST => \is_array($contracts[ContractType::TYPE_DESIGN_MANIFEST] ?? null) ? $contracts[ContractType::TYPE_DESIGN_MANIFEST] : [],
            ContractType::TYPE_PAGE_CONTRACT => \is_array($contracts[ContractType::TYPE_PAGE_CONTRACT] ?? null) ? $contracts[ContractType::TYPE_PAGE_CONTRACT] : [],
            ContractType::TYPE_BLOCK_PLAN => \is_array($contracts[ContractType::TYPE_BLOCK_PLAN] ?? null) ? $contracts[ContractType::TYPE_BLOCK_PLAN] : [],
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
    private function buildBuildPlanSourceOfTruth(
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
            $this->buildBuildPlanUserRequirements($sourcePlan, $siteName, $primaryGoal, $pageTypes)
        );

        return $source;
    }

    /**
     * @param array<string, mixed> $sourcePlan
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildBuildPlanUserRequirements(
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
            'page_type_contract' => '页面代码：' . \implode(', ', \array_values($pageTypes)),
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
    private function buildBuildPlanDesignManifest(array $policy, array $sourcePlan): array
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
                $manifest[$key] = $this->stripBuildPlanExplanatoryFields($value);
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
            $manifest['visual_contract'] = $this->stripBuildPlanExplanatoryFields($visualContract);
        }

        foreach ([
            $sourcePlan['site_design_system'] ?? null,
            $sourcePlan['shared_plan']['site_design_system'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                $manifest['site_design_system'] = $this->stripBuildPlanExplanatoryFields($candidate);
                break;
            }
        }

        foreach ([
            $sourcePlan['asset_distribution_policy'] ?? null,
            $sourcePlan['shared_plan']['asset_distribution_policy'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                $manifest['asset_distribution_policy'] = $this->stripBuildPlanExplanatoryFields($candidate);
                break;
            }
        }

        $themeContext = \is_array($sourcePlan['theme_context_snapshot'] ?? null) ? $sourcePlan['theme_context_snapshot'] : [];
        if ($themeContext !== []) {
            $manifest['theme_context_snapshot'] = $this->stripBuildPlanExplanatoryFields($themeContext);
        }

        return $manifest;
    }

    private function stripBuildPlanExplanatoryFields(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if ($this->isBuildPlanExplanatoryField((string)$key)) {
                continue;
            }
            $result[$key] = $this->stripBuildPlanExplanatoryFields($item);
        }

        return $result;
    }

    private function isBuildPlanExplanatoryField(string $key): bool
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
     * BuildPlan content_manifest is visitor copy. Do not seed it from the raw
     * brief because the brief can include prompt controls such as language bans
     * or JSON/contract leakage constraints.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $profile
     */
    private function resolveBuildPlanPrimaryGoal(array $scope, array $profile): string
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
                ? '清晰介绍核心价值，并引导用户完成咨询或下载。'
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

        return \implode('，', \array_values(\array_unique($facts)));
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
            if ($text === '' || $this->looksLikeUnusableBuildPlanVisibleCopy($text)) {
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
            if ($text === '' || $this->looksLikeUnusableBuildPlanVisibleCopy($text)) {
                continue;
            }
            if ($this->looksLikeVisibleLocaleLeak($text, $locale)) {
                continue;
            }

            return $text;
        }

        return '';
    }

    private function looksLikeUnusableBuildPlanVisibleCopy(string $value): bool
    {
        return $this->looksLikeInternalControlCopy($value)
            || BuildPlanContentManifestLinter::isPlanningOrImplementationCopy($value);
    }

    private function looksLikeInternalControlCopy(string $value): bool
    {
        if (\trim($value) === '') {
            return true;
        }

        return \preg_match(
            '/(?:合同字段|提示词|JSON|可见页面|不要出现|禁止(?:输出|生成|使用|出现)|不得(?:输出|生成|使用|出现)|不能(?:输出|生成|使用|出现)|必须使用|除了?.+以外|language|locale|prompt|contract|field|visible copy|do not|must not|forbidden)/iu',
            $value
        ) === 1;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildBlockVisualImplementationContract(array $block, string $blockType, string $blockKey): array
    {
        $visualSignature = $this->normalizeBlockVisualSignatureForBuildPlan($block['visual_signature'] ?? []);
        $imageIntent = $this->normalizeBlockImageIntentForBuildPlan($block['image_intent'] ?? []);
        $blockContract = $this->normalizeBlockContractForBuildPlan($block['block_contract'] ?? []);

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
            'source_design_tags' => $this->stripBuildPlanExplanatoryFields(
                \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : []
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlockVisualSignatureForBuildPlan(mixed $value): array
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
    private function normalizeBlockImageIntentForBuildPlan(mixed $value): array
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

        return $intent;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlockContractForBuildPlan(mixed $value): array
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
                $contract[$key] = $this->stripBuildPlanExplanatoryFields($value[$key]);
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
            $pageBlocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($pageBlocks === []) {
                throw new \RuntimeException('BuildPlan contract failed: page ' . $pageType . ' has no stage-one blocks. Regenerate the plan instead of injecting fallback blocks.');
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
                $visualSignature = $this->normalizeBlockVisualSignatureForBuildPlan($rawBlock['visual_signature'] ?? []);
                $designTags = $this->stripBuildPlanExplanatoryFields(
                    \is_array($rawBlock['design_tags'] ?? null) ? $rawBlock['design_tags'] : []
                );
                $imageIntent = $this->normalizeBlockImageIntentForBuildPlan($rawBlock['image_intent'] ?? []);
                $blockContract = $this->normalizeBlockContractForBuildPlan($rawBlock['block_contract'] ?? []);
                $assetRequirements = \is_array($rawBlock['asset_requirements'] ?? null)
                    ? $this->stripBuildPlanExplanatoryFields($rawBlock['asset_requirements'])
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
                'page_design_plan' => $this->stripBuildPlanExplanatoryFields(
                    \is_array($page['page_design_plan'] ?? null) ? $page['page_design_plan'] : []
                ),
                'blocks' => $pageBlockIds,
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
                return $this->stripBuildPlanExplanatoryFields($candidate);
            }
        }

        return [
            'source' => 'build_plan_v2_minimal_theme_context',
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
                return $this->stripBuildPlanExplanatoryFields($candidate);
            }
        }

        return [
            'source' => 'build_plan_v2_minimal_shared_context',
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
    private function buildTaskAcceptanceContract(array $ruleIds, string $targetLabel): array
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
            $sourcePlan['page_plans'] ?? null,
            $sourcePlan['pages'] ?? null,
        ];
        foreach ($sources as $source) {
            $pages = $this->normalizePagesSource($source);
            if ($pages !== []) {
                $missing = $this->missingPageTypesFromPages($pages, $pageTypes);
                if ($missing !== []) {
                    throw new \RuntimeException(
                        'BuildPlan contract failed: stage-one page plans missing selected page_types: ' . \implode(', ', $missing)
                    );
                }

                return $this->orderPagesByPageTypes($pages, $pageTypes);
            }
        }

        throw new \RuntimeException('BuildPlan contract failed: stage-one page plans are missing. Regenerate the plan instead of injecting fallback pages.');
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
            throw new \RuntimeException('BuildPlan contract failed: stage-one block at index ' . ((int)$index + 1) . ' is missing block_key.');
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
            throw new \RuntimeException('BuildPlan contract failed: stage-one block ' . ((int)$index + 1) . ' is missing block_type.');
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
        $parts = \preg_split('/[。！？；;.!?\n\r]+/u', $copy) ?: [];
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
            return '首页';
        }
        if (\str_contains($pageType, 'about')) {
            return '关于我们';
        }
        if (\str_contains($pageType, 'contact')) {
            return '联系我们';
        }
        if (\str_contains($pageType, 'blog') || \str_contains($pageType, 'article') || \str_contains($pageType, 'resource')) {
            return '内容资讯';
        }

        return $siteName !== '' ? $siteName : '页面';
    }

    private function localizedDefaultCopy(string $locale): string
    {
        return $this->isCjkLocale($locale)
            ? '清晰呈现核心信息，并引导用户完成下一步行动。'
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

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     */
    private function sourceSignature(array $scope, array $sourcePlan): string
    {
        return \sha1((string)\json_encode([
            'plan_generated_source_signature' => (string)($scope['plan_generated_source_signature'] ?? ''),
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

    private function policyRegistry(): AiSiteDesignPolicyRegistry
    {
        return $this->policyRegistry ?? new AiSiteDesignPolicyRegistry();
    }

    private function validator(): BuildPlanContractValidator
    {
        return $this->validator ?? new BuildPlanContractValidator();
    }

    private function projectionService(): AiSiteBuildPlanProjectionService
    {
        return $this->projectionService ?? new AiSiteBuildPlanProjectionService();
    }
}
