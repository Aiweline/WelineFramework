<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractSchema;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;

final class AiSiteBuildPlanService
{
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
        $planStructured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null) && $scope['execution_blueprint_draft'] !== []
            ? $scope['execution_blueprint_draft']
            : (\is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : []);
        $sourcePlan = $planJson !== [] ? $planJson : ($planStructured !== [] ? $planStructured : $executionBlueprint);
        $sourceSignature = $this->sourceSignature($scope, $sourcePlan, $executionBlueprint);
        $expectedPageTypes = $this->resolvePageTypes($scope, $sourcePlan, $executionBlueprint);
        $existing = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if (
            $this->looksLikeBuildPlanV2($existing)
            && $this->existingContractMatchesCurrentSource($existing, $sourceSignature, $expectedPageTypes)
        ) {
            return $this->normalizeExistingContract($existing, $scope, $websiteProfile);
        }

        $profile = \array_replace(
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $websiteProfile
        );
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $siteName = $this->firstNonEmpty([
            $scope['site_title'] ?? null,
            $siteStrategy['site_display_name'] ?? null,
            $profile['site_title'] ?? null,
            $profile['site_name'] ?? null,
            $scope['store_name'] ?? null,
            'AI Site',
        ]);
        $primaryGoal = $this->resolveBuildPlanPrimaryGoal($scope, $profile);
        $locale = $this->firstNonEmpty([
            $scope['plan_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $profile['default_locale'] ?? null,
            'zh_Hans_CN',
        ]);
        $contractId = 'build_plan_v2_' . \substr($sourceSignature, 0, 16);
        $sourceContracts = $this->buildSourceContractRefs($scope, $sourceSignature);
        $sourceOfTruth = $this->buildBuildPlanSourceOfTruth(
            [],
            $scope,
            $sourcePlan,
            $executionBlueprint,
            $sourceSignature,
            $siteName,
            $primaryGoal,
            $expectedPageTypes
        );

        [$pages, $blocks, $tasks, $buildOrder, $contentItems] = $this->buildPageBlockTaskGraph(
            $scope,
            $sourcePlan,
            $executionBlueprint,
            $siteName,
            $primaryGoal,
            $locale
        );

        $contentItems = \array_replace(
            [
                'site.name' => $siteName,
                'site.primary_goal' => $primaryGoal,
            ],
            $contentItems
        );

        return [
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
            'design_manifest' => $this->buildBuildPlanDesignManifest($policy, $sourcePlan, $executionBlueprint),
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
            'tasks' => $tasks,
            'build_order' => $buildOrder,
            'source_contracts' => $sourceContracts,
            'permission_matrix' => [
                'read' => ['policy_ref', 'policy_projection', 'design_manifest', 'content_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
                'create' => ['task_results', 'qa_report', 'repair_patch'],
                'patch' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
                'forbidden' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
                'read_only' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
            ],
            'frozen_fields' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
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
            && \is_array($contract['tasks'] ?? null)
            && \is_array($contract['pages'] ?? null)
            && \is_array($contract['blocks'] ?? null);
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
        $planStructured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null) && $scope['execution_blueprint_draft'] !== []
            ? $scope['execution_blueprint_draft']
            : (\is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : []);
        $sourcePlan = $planJson !== [] ? $planJson : ($planStructured !== [] ? $planStructured : $executionBlueprint);
        $sourceSignature = $this->sourceSignature($scope, $sourcePlan, $executionBlueprint);
        $pageTypes = $this->resolvePageTypes($scope, $sourcePlan, $executionBlueprint);
        $profile = \array_replace(
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $websiteProfile
        );
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
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
            $executionBlueprint,
            $sourceSignature,
            $siteName,
            $primaryGoal,
            $pageTypes
        );
        $contract['design_manifest'] = $this->stripBuildPlanExplanatoryFields(
            \array_replace_recursive(
                $this->buildBuildPlanDesignManifest($policy, $sourcePlan, $executionBlueprint),
                \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : []
            )
        );
        $locale = $this->firstNonEmpty([$scope['plan_locale'] ?? null, $scope['default_locale'] ?? null, $profile['default_locale'] ?? null, 'zh_Hans_CN']);
        [, , , , $freshContentItems] = $this->buildPageBlockTaskGraph(
            $scope,
            $sourcePlan,
            $executionBlueprint,
            $siteName,
            $primaryGoal,
            $locale
        );
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
     * @param array<string, mixed> $executionBlueprint
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildBuildPlanSourceOfTruth(
        array $existing,
        array $scope,
        array $sourcePlan,
        array $executionBlueprint,
        string $sourceSignature,
        string $siteName,
        string $primaryGoal,
        array $pageTypes
    ): array {
        $source = $existing;
        $source['stage_one_plan_signature'] = (string)($executionBlueprint['signature'] ?? $scope['execution_blueprint_confirmed_signature'] ?? $sourceSignature);
        $source['design_policy_id'] = AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID;

        $existingRequirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $source['user_requirements'] = \array_replace(
            $existingRequirements,
            $this->buildBuildPlanUserRequirements($sourcePlan, $executionBlueprint, $siteName, $primaryGoal, $pageTypes)
        );

        return $source;
    }

    /**
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildBuildPlanUserRequirements(
        array $sourcePlan,
        array $executionBlueprint,
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

        $blueprintPageTypes = $this->stringList($executionBlueprint['page_types'] ?? []);
        if ($blueprintPageTypes !== []) {
            $requirements['execution_blueprint_page_types'] = $blueprintPageTypes;
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
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, mixed>
     */
    private function buildBuildPlanDesignManifest(array $policy, array $sourcePlan, array $executionBlueprint): array
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

        $themeContext = \is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : [];
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
            $scope['plan_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $profile['default_locale'] ?? null,
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
     * @param list<mixed> $values
     */
    private function firstSafeVisibleCopy(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)$value);
            if ($text === '' || $this->looksLikeInternalControlCopy($text)) {
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
            if ($text === '' || $this->looksLikeInternalControlCopy($text)) {
                continue;
            }
            if ($this->looksLikeVisibleLocaleLeak($text, $locale)) {
                continue;
            }

            return $text;
        }

        return '';
    }

    private function looksLikeInternalControlCopy(string $value): bool
    {
        if (\trim($value) === '') {
            return true;
        }

        return \preg_match(
            '/(?:合同字段|提示词|JSON|可见页面|不要出现|禁止|不得|不能|必须使用|除了?.+以外|language|locale|prompt|contract|field|visible copy|do not|must not|forbidden)/iu',
            $value
        ) === 1;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildBlockVisualImplementationContract(array $block, string $blockType, string $blockKey): array
    {
        return [
            'image_integration' => 'Integrate imagery as part of the section composition, with responsive crop and readable overlays when needed.',
            'responsive_layout_contract' => $this->buildBlockResponsiveContract($blockType, $blockKey),
            'implementation_slices' => $this->buildBlockImplementationSlices($blockType, $blockKey),
            'composition_guards' => [
                'no_horizontal_scroll',
                'no_fixed_width_wider_than_container',
                'no_absolute_panel_outside_root',
                'no_media_or_decor_layer_covering_text_or_form',
                'all_grid_flex_children_min_width_zero',
            ],
            'source_design_tags' => \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlockResponsiveContract(string $blockType, string $blockKey): array
    {
        $identity = \strtolower($blockType . ' ' . $blockKey);
        $hasFormOrSupport = \preg_match('/contact|form|support|faq|lead|query|consult/i', $identity) === 1;
        $isHeroOrCta = \preg_match('/hero|banner|cta|download|conversion|final/i', $identity) === 1;

        return [
            'breakpoints' => [
                'desktop' => '>=1024px: multi-column is allowed only inside a centered max-width container; every column uses minmax(0, 1fr) or flex-basis with min-width:0.',
                'tablet' => '<=900px: media, copy, and action/form panels must stack or become a safe two-row layout; no side panel may remain absolutely offset outside the grid.',
                'mobile' => '<=420px: single column; width:100%; max-width:100%; images height auto or fixed-ratio cover; CTA/form controls fill available width without overflow.',
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
                'form inputs, buttons, and textareas must use width:100%; max-width:100%; box-sizing:border-box',
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
        $identity = \strtolower($blockType . ' ' . $blockKey);
        $slices = [
            'copy: headline, supporting copy, proof/detail row, CTA labels',
            'layout: root shell, safe inner container, responsive grid/flex structure',
            'visual: background system, surface treatment, media frame, decorative layers',
            'interaction: hover/focus states and reduced-motion-safe animation',
            'responsive: desktop/tablet/mobile layout with no overflow',
        ];
        if (\preg_match('/contact|form|support|lead|query|consult/i', $identity) === 1) {
            $slices[] = 'form: labels, inputs, textarea, submit CTA, support contact details, stacked mobile state';
        }
        if (\preg_match('/hero|banner|download|cta|conversion|final/i', $identity) === 1) {
            $slices[] = 'conversion: primary action cluster, trust cue, readable image overlay, mobile first-screen stacking';
        }

        return \array_values(\array_unique($slices));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>,3:list<string>,4:array<string,string>}
     */
    private function buildPageBlockTaskGraph(
        array $scope,
        array $sourcePlan,
        array $executionBlueprint,
        string $siteName,
        string $primaryGoal,
        string $locale
    ): array {
        $pagesByType = $this->resolvePagesByType($scope, $sourcePlan, $executionBlueprint);
        $pages = [];
        $blocks = [];
        $tasks = [];
        $buildOrder = [];
        $contentItems = [];
        $primaryCta = $this->resolveBuildPlanPrimaryCta($sourcePlan, $executionBlueprint, $locale);

        $sharedTasks = [
            [
                'task_id' => 'shared:header',
                'task_kind' => 'block_build',
                'executor' => 'AiSiteBuildQueue',
                'input_scope' => ['region' => 'header', 'component' => 'header'],
                'policy_slices' => ['layout.grid_alignment', 'typography.refined_font_stack', 'color.readable_contrast'],
                'context_budget' => ['max_tokens' => 1200],
                'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic'],
                'depends_on' => [],
            ],
            [
                'task_id' => 'shared:footer',
                'task_kind' => 'block_build',
                'executor' => 'AiSiteBuildQueue',
                'input_scope' => ['region' => 'footer', 'component' => 'footer'],
                'policy_slices' => ['layout.grid_alignment', 'typography.body_16_18', 'color.readable_contrast'],
                'context_budget' => ['max_tokens' => 1200],
                'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic'],
                'depends_on' => [],
            ],
        ];
        foreach ($sharedTasks as $task) {
            $tasks[] = $task;
            $buildOrder[] = (string)$task['task_id'];
        }

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
                $pageBlocks = [['block_key' => 'hero', 'title' => $pageTitle, 'goal' => $pageDescription, 'block_type' => 'hero']];
            }
            if ($pageType === Page::TYPE_HOME) {
                $pageBlocks = $this->ensureHomeConversionBlocks($pageBlocks, $siteName, $pageTitle, $pageDescription);
            }

            foreach ($pageBlocks as $blockIndex => $rawBlock) {
                if (!\is_array($rawBlock)) {
                    continue;
                }
                $blockKey = $this->resolveBlockKey($rawBlock, $blockIndex);
                $blockId = $pageId . '.' . $this->slugify($blockKey);
                $taskId = 'page:' . $pageType . ':' . $this->slugify($blockKey);
                $titleKey = 'block.' . $blockId . '.title';
                $copyKey = 'block.' . $blockId . '.copy';
                $ctaKey = 'block.' . $blockId . '.cta';
                $blockTitle = $this->extractBlockTitle($rawBlock, $blockKey, $pageTitle, $locale);
                $blockCopy = $this->extractBlockCopy($rawBlock, $pageDescription, $locale);
                $blockCta = $this->extractBlockCta($rawBlock, $siteName, $locale, $primaryCta, $blockKey, $pageType);
                $contentItems[$titleKey] = $blockTitle;
                $contentItems[$copyKey] = $blockCopy;
                $contentItems[$ctaKey] = $blockCta;
                $blockType = $this->resolveBlockType($rawBlock, $blockIndex);

                $blocks[] = [
                    'block_id' => $blockId,
                    'page_id' => $pageId,
                    'block_type' => $blockType,
                    'content_keys' => [$titleKey, $copyKey, $ctaKey],
                    'task_ids' => [$taskId],
                    'visual' => $this->buildBlockVisualImplementationContract($rawBlock, $blockType, $blockKey),
                    'sort_order' => 1000 + ((int)$pageIndex * 100) + ((int)$blockIndex * 10),
                ];
                $pageBlockIds[] = $blockId;
                $tasks[] = [
                    'task_id' => $taskId,
                    'task_kind' => 'block_build',
                    'executor' => 'AiSiteBuildQueue',
                    'input_scope' => [
                        'page_id' => $pageId,
                        'page_type' => $pageType,
                        'block_id' => $blockId,
                        'block_type' => $blockType,
                        'section_key' => $blockKey,
                    ],
                    'policy_slices' => ['layout.4_8_spacing', 'typography.refined_font_stack', 'image.integrated_not_pasted', 'responsive.no_horizontal_scroll'],
                    'context_budget' => ['max_tokens' => 1800],
                    'implementation_slices' => $this->buildBlockImplementationSlices($blockType, $blockKey),
                    'responsive_contract' => $this->buildBlockResponsiveContract($blockType, $blockKey),
                    'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic', 'color.readable_contrast'],
                    'depends_on' => ['shared:header', 'shared:footer'],
                ];
                $buildOrder[] = $taskId;
            }

            $pages[] = [
                'page_id' => $pageId,
                'page_type' => $pageType,
                'title_key' => $pageTitleKey,
                'description_key' => $pageDescriptionKey,
                'blocks' => $pageBlockIds,
                'sort_order' => 100 + ((int)$pageIndex * 10),
            ];
        }

        return [$pages, $blocks, $tasks, $buildOrder, $contentItems];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     * @return list<array<string, mixed>>
     */
    private function resolvePagesByType(array $scope, array $sourcePlan, array $executionBlueprint): array
    {
        $sources = [
            $executionBlueprint['pages'] ?? null,
            $executionBlueprint['page_plans'] ?? null,
            $sourcePlan['pages'] ?? null,
            $sourcePlan['page_plans'] ?? null,
        ];
        foreach ($sources as $source) {
            $pages = $this->normalizePagesSource($source);
            if ($pages !== []) {
                return $this->orderPagesByPageTypes($pages, $this->resolvePageTypes($scope, $sourcePlan, $executionBlueprint));
            }
        }

        $pageTypes = $this->resolvePageTypes($scope, $sourcePlan, $executionBlueprint);
        $pages = [];
        foreach ($pageTypes as $pageType) {
            $pageTitle = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
            $pages[] = [
                'page_type' => $pageType,
                'title' => $pageTitle,
                'page_goal' => match ($pageType) {
                    Page::TYPE_HOME => 'Capture core intent, explain value, and surface primary conversion actions.',
                    Page::TYPE_ABOUT => 'Build trust by explaining brand background and delivery capability.',
                    Page::TYPE_CONTACT => 'Reduce friction and collect qualified leads quickly.',
                    Page::TYPE_REFUND_POLICY => 'Explain refund eligibility, timing, and request steps so customers can act with confidence.',
                    Page::TYPE_PRIVACY_POLICY => 'Explain what data is collected, how it is used, and what control visitors keep.',
                    Page::TYPE_TERMS_OF_SERVICE => 'Clarify usage rules, responsibilities, and account expectations before purchase or signup.',
                    Page::TYPE_SHIPPING_POLICY => 'Set delivery timing, shipping regions, and exception handling expectations clearly.',
                    Page::TYPE_COOKIE_POLICY => 'Explain what cookies are used, why they exist, and how visitors can manage consent.',
                    default => $pageTitle . ' gives visitors specific context, useful proof, and a clear route to continue.',
                },
                'blocks' => [['block_key' => 'hero', 'block_type' => 'hero']],
            ];
        }

        return $pages;
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
     * @param array<string, mixed> $executionBlueprint
     * @return list<string>
     */
    private function resolvePageTypes(array $scope, array $sourcePlan, array $executionBlueprint): array
    {
        $candidates = [
            $scope['page_types'] ?? null,
            $executionBlueprint['page_types'] ?? null,
            $sourcePlan['page_types'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $types = $this->stringList($candidate);
            if ($types !== []) {
                return $types;
            }
        }

        return ['home_page', 'about_page'];
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
            $block['type'] ?? null,
            'block_' . ((int)$index + 1),
        ]);

        return $key !== '' ? $key : 'block_' . ((int)$index + 1);
    }

    /**
     * @param list<array<string, mixed>> $pageBlocks
     * @return list<array<string, mixed>>
     */
    private function ensureHomeConversionBlocks(array $pageBlocks, string $siteName, string $pageTitle, string $pageDescription): array
    {
        // Strong-contract build must preserve the Stage-1 block tree exactly.
        // Missing conversion sections should be fixed in Stage-1 planning, not injected here.
        return \array_values($pageBlocks);
    }

    /**
     * @param list<array<string, mixed>> $pageBlocks
     */
    private function pageBlocksContainKind(array $pageBlocks, string $kind): bool
    {
        $kind = \strtolower(\trim($kind));
        foreach ($pageBlocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \strtolower($this->slugify($this->resolveBlockKey($block, (int)$index)));
            $blockType = \strtolower($this->slugify($this->resolveBlockType($block, (int)$index)));
            if ($kind === 'hero' && ($blockType === 'hero' || \str_contains($blockKey, 'hero'))) {
                return true;
            }
            if ($kind === 'final_cta' && (\str_contains($blockKey, 'final_cta') || \str_contains($blockKey, 'cta') || $blockType === 'cta')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveBlockType(array $block, int $index): string
    {
        $type = \strtolower($this->firstNonEmpty([
            $block['block_type'] ?? null,
            $block['type'] ?? null,
            $block['template'] ?? null,
            $index === 0 ? 'hero' : 'section',
        ]));
        $type = \preg_replace('/[^a-z0-9_-]+/', '_', $type) ?? $type;
        $type = \trim($type, '_-');

        return $type !== '' ? $type : ($index === 0 ? 'hero' : 'section');
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockTitle(array $block, string $fallbackKey, string $pageTitle, string $locale): string
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

        return $this->firstSafeLocalizedVisibleCopy([
            $fieldTitle,
            $realtime['headline'] ?? null,
            $block['title'] ?? null,
            $block['name'] ?? null,
            $block['label'] ?? null,
            $this->shortTitleFromCopy((string)($execution['core_copy'] ?? '')),
            $this->extractFirstFieldPlanSample($block, ['cta', 'button', 'action', 'image', 'media', 'icon', 'logo']),
            $featurePoints[0] ?? null,
            $this->shortTitleFromCopy((string)($block['content'] ?? '')),
            $this->localizedBlockTitleFallback($fallbackKey, $pageTitle, $locale),
        ], $locale);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockCopy(array $block, string $fallback, string $locale): string
    {
        $fieldCopy = $this->extractFieldPlanText($block, ['description', 'body', 'copy', 'subtitle']);
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $supporting = \is_array($realtime['supporting_copy'] ?? null) ? \implode(' ', \array_map('strval', $realtime['supporting_copy'])) : '';

        return $this->firstSafeLocalizedVisibleCopy([
            $block['execution_script']['core_copy'] ?? null,
            $fieldCopy,
            $supporting,
            $fallback,
            $this->localizedDefaultCopy($locale),
        ], $locale);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockCta(
        array $block,
        string $siteName,
        string $locale,
        string $primaryCta,
        string $blockKey,
        string $pageType
    ): string
    {
        $fieldCta = $this->extractFieldPlanText($block, ['cta', 'button', 'action']);
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

        $candidates[] = $this->isCjkLocale($locale) ? '立即咨询' : 'Start now';

        \array_pop($candidates);
        if ($primaryCta !== '') {
            $candidates = \array_values(\array_filter(
                $candidates,
                fn(string $label): bool => !$this->isGenericBuildPlanConsultCta($label)
            ));
            \array_unshift($candidates, $this->selectBuildPlanCtaForBlock($primaryCta, $blockKey, $pageType));
        }
        $candidates[] = $primaryCta !== ''
            ? $this->selectBuildPlanCtaForBlock($primaryCta, $blockKey, $pageType)
            : ($this->isCjkLocale($locale) ? $this->unicodeText('\u4e86\u89e3\u8be6\u60c5') : 'Learn more');

        return $this->firstSafeLocalizedVisibleCopy($candidates, $locale);
    }

    /**
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     */
    private function resolveBuildPlanPrimaryCta(array $sourcePlan, array $executionBlueprint, string $locale): string
    {
        $requirementExpansion = \is_array($sourcePlan['requirement_expansion'] ?? null) ? $sourcePlan['requirement_expansion'] : [];
        $siteStrategy = \is_array($sourcePlan['site_strategy'] ?? null) ? $sourcePlan['site_strategy'] : [];
        $sharedContext = \is_array($executionBlueprint['shared_prompt_context'] ?? null) ? $executionBlueprint['shared_prompt_context'] : [];
        $sharedRequirementExpansion = \is_array($sharedContext['requirement_expansion'] ?? null) ? $sharedContext['requirement_expansion'] : [];
        $sharedSiteStrategy = \is_array($sharedContext['site_strategy'] ?? null) ? $sharedContext['site_strategy'] : [];
        $ctaStrategy = \is_array($sharedContext['shared_cta_strategy'] ?? null)
            ? $sharedContext['shared_cta_strategy']
            : (\is_array($sharedContext['cta_strategy'] ?? null) ? $sharedContext['cta_strategy'] : []);

        return $this->firstSafeLocalizedVisibleCopy([
            $requirementExpansion['primary_cta'] ?? null,
            $sharedRequirementExpansion['primary_cta'] ?? null,
            $siteStrategy['primary_cta'] ?? null,
            $sharedSiteStrategy['primary_cta'] ?? null,
            $ctaStrategy['primary_action'] ?? null,
            $ctaStrategy['primary_cta'] ?? null,
            $sharedContext['primary_cta'] ?? null,
        ], $locale);
    }

    private function selectBuildPlanCtaForBlock(string $primaryCta, string $blockKey, string $pageType): string
    {
        $parts = \preg_split('/\s*(?:\/|\||,|\x{FF0C}|\x{3001})\s*/u', $primaryCta, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $labels = [];
        foreach ($parts as $part) {
            $label = $this->cleanVisibleCopy((string)$part);
            if ($label !== '' && !\in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        if ($labels === []) {
            return $primaryCta;
        }

        $identity = \strtolower($blockKey . ' ' . $pageType);
        $preferOrder = \preg_match('/order|shop|store|menu|product|catalog|buy|purchase|\x{8BA2}\x{8D2D}|\x{8D2D}\x{4E70}|\x{83DC}\x{5355}|\x{4EA7}\x{54C1}/u', $identity) === 1;
        $preferReservation = \preg_match('/reserve|reservation|booking|appointment|visit|experience|\x{9884}\x{7EA6}|\x{4F53}\x{9A8C}|\x{5230}\x{5E97}/u', $identity) === 1;
        foreach ($labels as $label) {
            if ($preferOrder && \preg_match('/order|buy|shop|purchase|\x{8BA2}\x{8D2D}|\x{8D2D}\x{4E70}/iu', $label) === 1) {
                return $label;
            }
            if ($preferReservation && \preg_match('/reserve|book|appointment|experience|\x{9884}\x{7EA6}|\x{4F53}\x{9A8C}/iu', $label) === 1) {
                return $label;
            }
        }

        return $labels[0];
    }

    private function isGenericBuildPlanConsultCta(string $label): bool
    {
        $label = $this->cleanVisibleCopy($label);
        if ($label === '') {
            return false;
        }

        return \preg_match(
            '/^(?:start\s+now|learn\s+more|contact\s+us|download\s+now|\x{7ACB}\x{5373}\x{54A8}\x{8BE2}|\x{8054}\x{7CFB}\x{6211}\x{4EEC}|\x{54A8}\x{8BE2}|\x{7ACB}\x{5373}\x{4E0B}\x{8F7D}|\x{4E86}\x{89E3}\x{8BE6}\x{60C5})$/iu',
            $label
        ) === 1;
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

    private function localizedBlockTitleFallback(string $blockKey, string $pageTitle, string $locale): string
    {
        if (!$this->isCjkLocale($locale)) {
            return $this->humanizeIdentifier($blockKey) ?: $pageTitle;
        }

        $identity = \strtolower($blockKey);
        $rules = [
            '安全下载入口' => ['hero', 'download', 'apk', 'banner'],
            '玩法亮点' => ['game', 'feature', 'showcase', 'highlight'],
            '奖励活动' => ['reward', 'promotion', 'bonus', 'offer'],
            '安全与信任' => ['trust', 'security', 'safe', 'proof'],
            '品牌故事' => ['origin', 'story', 'about', 'mission', 'value'],
            '咨询表单' => ['form', 'consult', 'query', 'lead'],
            '联系渠道' => ['contact', 'method', 'support'],
            '常见问题' => ['faq', 'question'],
            '精选内容' => ['article', 'blog', 'resource', 'grid', 'list'],
            '立即行动' => ['cta', 'final', 'conversion'],
        ];
        foreach ($rules as $title => $needles) {
            foreach ($needles as $needle) {
                if (\str_contains($identity, $needle)) {
                    return $title;
                }
            }
        }

        return $pageTitle !== '' ? $pageTitle : '页面内容';
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

        return $this->hasMeaningfulCjkCopy($value);
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
        foreach ($matches[0] ?? [] as $word) {
            $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
            if ($normalized !== '' && !isset($allowed[$normalized])) {
                $words[] = $normalized;
            }
        }
        if ($words === []) {
            return false;
        }

        $letterCount = 0;
        foreach ($words as $word) {
            $letterCount += \strlen($word);
        }

        return \count($words) >= 3 && $letterCount >= 16;
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
     * @param array<string, mixed> $executionBlueprint
     */
    private function sourceSignature(array $scope, array $sourcePlan, array $executionBlueprint): string
    {
        return \sha1((string)\json_encode([
            'plan_generated_source_signature' => (string)($scope['plan_generated_source_signature'] ?? ''),
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
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
