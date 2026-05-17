<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSiteStageOneContractService
{
    public const CONTRACT_VERSION = 'stage1_contract_v1';
    public const FIELD_PLAN_COUNT = 3;

    /** @var list<string> */
    public const DESIGN_TAG_KEYS = [
        'visual',
        'motion',
        'interaction',
        'texture',
        'responsive',
        'color_layering',
        'implementation_note',
    ];

    /** @var list<string> */
    public const GENERIC_BLOCK_KEYS = [
        'details',
        'content',
        'section',
        'info',
        'block',
        'item',
    ];

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    public function build(
        array $scope,
        array $pageTypes,
        string $planLocale = '',
        string $contentLocale = '',
        string $step = 'stage1'
    ): array {
        $pageTypes = $this->normalizePageTypes($pageTypes);
        $pageContracts = [];
        foreach ($pageTypes as $pageType) {
            $budget = $this->resolveBlockBudget($pageType, $scope);
            $pageContracts[$pageType] = [
                'page_type' => $pageType,
                'min_blocks' => $budget['min'],
                'max_blocks' => $budget['max'],
                'required_block_keys' => $budget['required'],
                'field_plan_count' => self::FIELD_PLAN_COUNT,
                'required_design_tag_keys' => self::DESIGN_TAG_KEYS,
                'forbidden_block_keys' => self::GENERIC_BLOCK_KEYS,
                'requires_page_goal' => true,
                'requires_theme_alignment_summary' => true,
                'requires_page_design_plan' => true,
                'requires_execution_core_copy' => true,
            ];
        }

        $contract = [
            'contract_version' => self::CONTRACT_VERSION,
            'version' => 2,
            'stage' => 'stage1',
            'step' => $step,
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'page_types' => $pageTypes,
            'page_contracts' => $pageContracts,
            'theme_required_sections' => [
                'site_strategy',
                'theme_style',
                'palette',
                'theme_design',
                'navigation_plan',
                'footer_plan',
                'seo_strategy',
            ],
            'theme_required_fields' => [
                'theme_design' => [
                    'theme_purpose',
                    'style_signature',
                    'art_direction',
                    'color_scheme',
                    'typography_spacing_radius',
                    'visual_keywords',
                    'tone_of_voice',
                    'cta_tone',
                    'forbidden_styles',
                    'selection_reason',
                ],
                'theme_design.color_scheme' => [
                    'primary',
                    'secondary',
                    'accent',
                    'background',
                    'body',
                    'button',
                ],
                'theme_design.typography_spacing_radius' => [
                    'font_family',
                    'heading_scale',
                    'body_scale',
                    'spacing_scale',
                    'radius_scale',
                ],
            ],
            'shared_link_requirements' => [
                'navigation_plan.header_items',
                'footer_plan.featured',
                'footer_plan.policies',
            ],
            'copy_rules' => [
                'visible_copy_must_use_content_locale' => true,
                'visitor_copy_only' => true,
                'forbid_prompt_like_copy' => true,
                'forbid_schema_placeholders' => true,
                'must_reuse_brief_nouns' => true,
            ],
            'image_planning_rules' => [
                'cache_grain' => 'session:block:planning_signature',
                'reuse_when_stage1_contract_hash_and_block_intent_match' => true,
                'regenerate_when_contract_hash_or_block_image_intent_changes' => true,
                'forbid_external_symbolic_urls' => true,
            ],
            'visual_quality_rules' => [
                'non_generic_visual_direction' => true,
                'forbid_default_blue_saas_or_purple_white_bias' => true,
                'requires_layered_backgrounds' => true,
                'requires_deliberate_typography' => true,
                'requires_mobile_composition_plan' => true,
                'requires_reduced_motion_safe_effects' => true,
            ],
            'retry_policy' => [
                'product_flow_allows_ai_recovery' => true,
                'validation_first_pass_requires_no_recovery' => true,
                'local_content_fallback_forbidden' => true,
                'structural_normalization_allowed' => true,
            ],
            'source_truth_contract_hash' => (string)($scope['source_truth_contract_hash'] ?? ''),
            'asset_manifest_hash' => (string)($scope['asset_manifest_hash'] ?? ''),
        ];
        $contract['contract_hash'] = $this->hashStablePayload($contract);

        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    public function normalize(
        array $contract,
        array $scope,
        array $pageTypes,
        string $planLocale = '',
        string $contentLocale = '',
        string $step = 'stage1'
    ): array {
        $base = $this->build($scope, $pageTypes, $planLocale, $contentLocale, $step);
        if ($contract === []) {
            return $base;
        }

        $targetPageTypes = $this->normalizePageTypes($pageTypes);
        if ($targetPageTypes === []) {
            $targetPageTypes = $this->normalizePageTypes(\is_array($contract['page_types'] ?? null) ? $contract['page_types'] : $base['page_types']);
        }
        $sourcePageContracts = \array_replace(
            \is_array($base['page_contracts'] ?? null) ? $base['page_contracts'] : [],
            \is_array($contract['page_contracts'] ?? null) ? $contract['page_contracts'] : []
        );
        $pageContracts = [];
        foreach ($targetPageTypes as $pageType) {
            if (\is_array($sourcePageContracts[$pageType] ?? null)) {
                $pageContracts[$pageType] = $sourcePageContracts[$pageType];
            }
        }

        $normalized = \array_replace($base, $contract, [
            'contract_version' => (string)($contract['contract_version'] ?? $base['contract_version']),
            'page_types' => $targetPageTypes,
            'page_contracts' => $pageContracts,
            'theme_required_sections' => \is_array($contract['theme_required_sections'] ?? null) ? $contract['theme_required_sections'] : $base['theme_required_sections'],
            'theme_required_fields' => \is_array($contract['theme_required_fields'] ?? null) ? $contract['theme_required_fields'] : $base['theme_required_fields'],
            'shared_link_requirements' => \is_array($contract['shared_link_requirements'] ?? null) ? $contract['shared_link_requirements'] : $base['shared_link_requirements'],
            'copy_rules' => \is_array($contract['copy_rules'] ?? null) ? $contract['copy_rules'] : $base['copy_rules'],
            'image_planning_rules' => \is_array($contract['image_planning_rules'] ?? null) ? $contract['image_planning_rules'] : $base['image_planning_rules'],
            'visual_quality_rules' => \is_array($contract['visual_quality_rules'] ?? null) ? $contract['visual_quality_rules'] : $base['visual_quality_rules'],
            'retry_policy' => \is_array($contract['retry_policy'] ?? null) ? $contract['retry_policy'] : $base['retry_policy'],
        ]);
        $normalized['contract_hash'] = $this->hashStablePayload($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{min:int, max:int, required:list<string>}
     */
    public function resolveBlockBudget(string $pageType, array $scope): array
    {
        $required = [];
        if ($pageType === Page::TYPE_HOME || $pageType === 'home_page') {
            $sourceRequired = \is_array($scope['source_truth_contract']['required_home_blocks'] ?? null)
                ? \array_values(\array_filter(\array_map('strval', $scope['source_truth_contract']['required_home_blocks'])))
                : [];
            $required = \array_values(\array_unique(\array_merge(['hero', 'final_cta'], $sourceRequired)));
        } elseif ($pageType === Page::TYPE_ABOUT) {
            $required = ['origin_story', 'mission_values', 'trust_proof', 'about_cta'];
        } elseif ($pageType === Page::TYPE_CONTACT) {
            $required = ['contact_methods', 'support_form_guidance', 'support_faq', 'contact_cta'];
        } elseif ($pageType === Page::TYPE_PRIVACY_POLICY) {
            $required = ['privacy_overview', 'data_use', 'user_rights', 'privacy_contact'];
        } elseif ($pageType === Page::TYPE_TERMS_OF_SERVICE) {
            $required = ['terms_overview', 'service_rules', 'customer_responsibilities', 'terms_contact'];
        } elseif ($pageType === Page::TYPE_REFUND_POLICY) {
            $required = ['refund_overview', 'eligibility_rules', 'refund_steps', 'refund_contact'];
        } elseif ($pageType === Page::TYPE_SHIPPING_POLICY) {
            $required = ['fulfillment_overview', 'delivery_options', 'pickup_timing', 'shipping_contact'];
        } elseif ($pageType === Page::TYPE_COOKIE_POLICY) {
            $required = ['cookie_overview', 'cookie_types', 'preference_controls', 'cookie_contact'];
        } elseif ($pageType === Page::TYPE_BLOG) {
            $required = ['article_hero', 'article_body', 'related_resources', 'article_cta'];
        } elseif ($pageType === Page::TYPE_BLOG_CATEGORY) {
            $required = ['category_hero', 'topic_filters', 'article_collection', 'category_cta'];
        } elseif ($pageType === Page::TYPE_BLOG_LIST) {
            $required = ['resource_hero', 'article_grid', 'learning_path', 'newsletter_cta'];
        }

        $min = \max(($pageType === Page::TYPE_HOME || $pageType === 'home_page') ? 4 : 3, \count($required));
        $max = ($pageType === Page::TYPE_HOME || $pageType === 'home_page') ? \max($min, \count($required) + 2) : \max($min, 5);

        return [
            'min' => $min,
            'max' => $max,
            'required' => \array_values($required),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function pageContract(array $contract, string $pageType): array
    {
        return \is_array($contract['page_contracts'][$pageType] ?? null)
            ? $contract['page_contracts'][$pageType]
            : [
                'page_type' => $pageType,
                'min_blocks' => 3,
                'max_blocks' => 5,
                'required_block_keys' => [],
                'field_plan_count' => self::FIELD_PLAN_COUNT,
                'required_design_tag_keys' => self::DESIGN_TAG_KEYS,
                'forbidden_block_keys' => self::GENERIC_BLOCK_KEYS,
            ];
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function stableHash(array $contract): string
    {
        return $this->hashStablePayload($contract);
    }

    /**
     * @param array<int|string, mixed> $pageTypes
     * @return list<string>
     */
    private function normalizePageTypes(array $pageTypes): array
    {
        $normalized = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && !\in_array($pageType, $normalized, true)) {
                $normalized[] = $pageType;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function hashStablePayload(array $contract): string
    {
        $payload = [
            'contract_version' => (string)($contract['contract_version'] ?? self::CONTRACT_VERSION),
            'page_types' => \array_values(\is_array($contract['page_types'] ?? null) ? $contract['page_types'] : []),
            'page_contracts' => \is_array($contract['page_contracts'] ?? null) ? $contract['page_contracts'] : [],
            'theme_required_sections' => \is_array($contract['theme_required_sections'] ?? null) ? $contract['theme_required_sections'] : [],
            'theme_required_fields' => \is_array($contract['theme_required_fields'] ?? null) ? $contract['theme_required_fields'] : [],
            'shared_link_requirements' => \is_array($contract['shared_link_requirements'] ?? null) ? $contract['shared_link_requirements'] : [],
            'copy_rules' => \is_array($contract['copy_rules'] ?? null) ? $contract['copy_rules'] : [],
            'image_planning_rules' => \is_array($contract['image_planning_rules'] ?? null) ? $contract['image_planning_rules'] : [],
            'visual_quality_rules' => \is_array($contract['visual_quality_rules'] ?? null) ? $contract['visual_quality_rules'] : [],
            'source_truth_contract_hash' => (string)($contract['source_truth_contract_hash'] ?? ''),
            'asset_manifest_hash' => (string)($contract['asset_manifest_hash'] ?? ''),
        ];

        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
