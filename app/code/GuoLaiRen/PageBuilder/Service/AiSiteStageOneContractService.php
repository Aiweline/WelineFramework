<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSiteStageOneContractService
{
    public const CONTRACT_VERSION = 'stage1_contract_v4';
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
    public const RECOMMENDED_DESIGN_TAG_KEYS = [
        'color_layering',
    ];

    /** @var list<string> */
    public const VISUAL_SIGNATURE_KEYS = [
        'composition_pattern',
        'spatial_rhythm',
        'media_strategy',
        'surface_treatment',
        'interaction_pattern',
    ];

    /** @var list<string> */
    public const IMAGE_INTENT_KEYS = [
        'needs_image',
        'image_role',
        'image_subject',
        'placement',
        'visual_atmosphere',
        'image_treatment',
        'reuse_policy',
        'css_motif',
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

    public function __construct(
        private readonly ?AiSitePageRouteContractService $pageRouteContractService = null
    ) {
    }

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
        $pageRouteContract = $this->getPageRouteContractService()->build($pageTypes, $scope, $contentLocale);
        $pageContracts = [];
        foreach ($pageTypes as $pageType) {
            $budget = $this->resolveBlockBudget($pageType, $scope);
            $isPolicyPage = \in_array($pageType, [
                'privacy_policy',
                'terms_of_service',
                'refund_policy',
                'shipping_policy',
                'cookie_policy',
            ], true);
            $firstRequiredBlockKey = \trim((string)($budget['required'][0] ?? ''));
            $preferredGeneratedImageBlockKey = $isPolicyPage ? '' : $this->resolvePreferredGeneratedImageBlockKey($pageType, $budget);
            $pageContracts[$pageType] = [
                'page_type' => $pageType,
                'min_blocks' => $budget['min'],
                'max_blocks' => $budget['max'],
                'target_blocks' => $budget['target'],
                'required_block_keys' => $budget['required'],
                'recommended_optional_block_keys' => $budget['optional'],
                'field_plan_count' => self::FIELD_PLAN_COUNT,
                'required_design_tag_keys' => self::DESIGN_TAG_KEYS,
                'recommended_design_tag_keys' => self::RECOMMENDED_DESIGN_TAG_KEYS,
                'forbidden_block_keys' => self::GENERIC_BLOCK_KEYS,
                'requires_page_goal' => true,
                'requires_theme_alignment_summary' => true,
                'requires_page_design_plan' => true,
                'requires_execution_core_copy' => true,
                'requires_visual_signature' => true,
                'visual_signature_keys' => self::VISUAL_SIGNATURE_KEYS,
                'visual_signature_uniqueness_scope' => !$isPolicyPage ? 'same_page_adjacent_blocks' : 'same_page_adjacent_blocks_soft',
                'visual_signature_duplicate_severity' => !$isPolicyPage ? 'high' : 'medium',
                'forbid_repeated_composition_patterns_within_page' => !$isPolicyPage,
                'composition_overuse_severity' => 'medium',
                'requires_image_intent' => true,
                'image_intent_keys' => self::IMAGE_INTENT_KEYS,
                'first_block_requires_generated_image' => $preferredGeneratedImageBlockKey !== ''
                    && $firstRequiredBlockKey !== ''
                    && $preferredGeneratedImageBlockKey === $firstRequiredBlockKey,
                'first_generated_image_block_key' => $preferredGeneratedImageBlockKey,
                'block_count_handoff_required' => true,
            ];
        }

        $contract = [
            'contract_version' => self::CONTRACT_VERSION,
            'version' => 3,
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
            'page_route_contract' => $pageRouteContract,
            'navigation_address_rules' => [
                'header_and_footer_href_must_use_page_route_contract' => true,
                'forbid_invented_internal_paths' => true,
                'home_page_path' => '/',
                'allowed_internal_paths' => $pageRouteContract['allowed_internal_paths'] ?? [],
            ],
            'copy_rules' => [
                'visible_copy_must_use_content_locale' => true,
                'visitor_copy_only' => true,
                'forbid_prompt_like_copy' => true,
                'forbid_schema_filler_values' => true,
                'must_reuse_brief_nouns' => true,
                'same_page_blocks_must_not_reuse_same_opening_message' => true,
                'same_page_blocks_must_not_reuse_same_core_copy' => true,
            ],
            'field_plan_rules' => [
                'rows_per_block' => self::FIELD_PLAN_COUNT,
                'required_row_slots' => [
                    ['index' => 0, 'field' => 'headline', 'sample_kind' => 'visitor_visible_heading'],
                    ['index' => 1, 'field' => 'supporting_copy', 'sample_kind' => 'visitor_visible_sentence'],
                    ['index' => 2, 'field' => 'context_detail', 'sample_kind' => 'cta_label_or_proof_or_asset_brief'],
                ],
                'field_key_examples' => [
                    'headline',
                    'supporting_copy',
                    'cta_label',
                    'proof_detail',
                    'image_brief',
                    'form_label',
                    'policy_summary',
                ],
                'field_key_format' => 'short_snake_case_semantic_key',
                'forbid_empty_field_key' => true,
                'sample_acceptance_examples' => [
                    'headline' => 'Neon card tables that make play, rules, and rewards clear',
                    'supporting_copy' => 'Show room choices, game cues, player proof, and fast support before visitors join.',
                    'context_detail' => 'Popular rooms, bonus events, player trust, and quick support',
                ],
                'sample_rejection_examples' => [
                    'Write a title around the main value',
                    'Describe this block clearly',
                    'Highlight brand advantages',
                    'Describe the main benefit',
                ],
            ],
            'image_planning_rules' => [
                'cache_grain' => 'session:block:planning_signature',
                'reuse_when_stage1_contract_hash_and_block_intent_match' => true,
                'regenerate_when_contract_hash_or_block_image_intent_changes' => true,
                'forbid_external_symbolic_urls' => true,
                'each_block_must_declare_image_intent' => true,
                'needs_image_must_be_json_boolean_true_or_false' => true,
                'needs_image_true_requires_role_subject_placement' => true,
                'needs_image_false_requires_css_motif_and_treatment' => true,
                'opening_or_media_asset_blocks_default_to_needs_image' => true,
                'non_policy_pages_require_at_least_one_generated_image_intent' => true,
                'non_policy_first_block_requires_generated_image_intent' => false,
                'planned_image_without_verified_asset_must_fail_no_filler_media' => true,
                'filler_image_assets_forbidden' => true,
            ],
            'visual_quality_rules' => [
                'non_generic_visual_direction' => true,
                'forbid_default_blue_saas_or_purple_white_bias' => true,
                'requires_layered_backgrounds' => true,
                'requires_deliberate_typography' => true,
                'requires_mobile_composition_plan' => true,
                'requires_reduced_motion_safe_effects' => true,
            ],
            'visual_diversity_rules' => [
                'each_block_must_have_visual_signature' => true,
                'adjacent_blocks_must_not_share_same_composition_surface_media' => true,
                'composition_pattern_must_match_block_role' => true,
                'forbid_reusing_hero_layout_for_non_hero_blocks' => true,
                'stage3_must_consume_visual_signature' => true,
            ],
            'build_handoff_rules' => [
                'stage1_page_block_count_is_build_plan_truth' => true,
                'stage1_block_key_order_is_build_order' => true,
                'one_page_section_execution_per_stage1_block' => true,
                'completed_block_must_match_block_identity' => true,
                'duplicated_html_or_title_between_page_blocks_is_invalid' => true,
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

        $isCurrentVersion = (string)($contract['contract_version'] ?? '') === self::CONTRACT_VERSION;
        $contractForMerge = $isCurrentVersion ? $contract : [];
        $targetPageTypes = $this->normalizePageTypes($pageTypes);
        if ($targetPageTypes === []) {
            $targetPageTypes = $this->normalizePageTypes(\is_array($contractForMerge['page_types'] ?? null) ? $contractForMerge['page_types'] : $base['page_types']);
        }
        $sourcePageContracts = \array_replace(
            \is_array($base['page_contracts'] ?? null) ? $base['page_contracts'] : [],
            \is_array($contractForMerge['page_contracts'] ?? null) ? $contractForMerge['page_contracts'] : []
        );
        $pageContracts = [];
        foreach ($targetPageTypes as $pageType) {
            $basePageContract = \is_array($base['page_contracts'][$pageType] ?? null) ? $base['page_contracts'][$pageType] : [];
            if (\is_array($sourcePageContracts[$pageType] ?? null)) {
                $basePreferredImageKey = \trim((string)($basePageContract['first_generated_image_block_key'] ?? ''));
                $sourcePreferredImageKey = \trim((string)($sourcePageContracts[$pageType]['first_generated_image_block_key'] ?? ''));
                $preferredGeneratedImageBlockKey = $sourcePreferredImageKey !== '' ? $sourcePreferredImageKey : $basePreferredImageKey;
                $firstRequiredBlockKey = \trim((string)($basePageContract['required_block_keys'][0] ?? ''));
                $pageContracts[$pageType] = \array_replace($basePageContract, $sourcePageContracts[$pageType], [
                    'requires_visual_signature' => true,
                    'visual_signature_keys' => self::VISUAL_SIGNATURE_KEYS,
                    'visual_signature_uniqueness_scope' => 'same_page_adjacent_blocks',
                    'forbid_repeated_composition_patterns_within_page' => true,
                    'requires_image_intent' => true,
                    'image_intent_keys' => self::IMAGE_INTENT_KEYS,
                    'first_block_requires_generated_image' => $preferredGeneratedImageBlockKey !== ''
                        && $firstRequiredBlockKey !== ''
                        && $preferredGeneratedImageBlockKey === $firstRequiredBlockKey,
                    'first_generated_image_block_key' => $preferredGeneratedImageBlockKey,
                    'block_count_handoff_required' => true,
                ]);
            }
        }

        $normalized = \array_replace($base, $contractForMerge, [
            'contract_version' => self::CONTRACT_VERSION,
            'page_types' => $targetPageTypes,
            'page_contracts' => $pageContracts,
            'theme_required_sections' => \is_array($contractForMerge['theme_required_sections'] ?? null) ? $contractForMerge['theme_required_sections'] : $base['theme_required_sections'],
            'theme_required_fields' => \is_array($contractForMerge['theme_required_fields'] ?? null) ? $contractForMerge['theme_required_fields'] : $base['theme_required_fields'],
            'shared_link_requirements' => \is_array($contractForMerge['shared_link_requirements'] ?? null) ? $contractForMerge['shared_link_requirements'] : $base['shared_link_requirements'],
            'page_route_contract' => $this->getPageRouteContractService()->normalize(
                \is_array($contractForMerge['page_route_contract'] ?? null) ? $contractForMerge['page_route_contract'] : [],
                $targetPageTypes,
                $scope,
                $contentLocale
            ),
            'navigation_address_rules' => \is_array($contractForMerge['navigation_address_rules'] ?? null) ? $contractForMerge['navigation_address_rules'] : $base['navigation_address_rules'],
            'copy_rules' => \is_array($contractForMerge['copy_rules'] ?? null) ? $contractForMerge['copy_rules'] : $base['copy_rules'],
            'field_plan_rules' => \is_array($contractForMerge['field_plan_rules'] ?? null) ? $contractForMerge['field_plan_rules'] : $base['field_plan_rules'],
            'image_planning_rules' => \is_array($contractForMerge['image_planning_rules'] ?? null) ? $contractForMerge['image_planning_rules'] : $base['image_planning_rules'],
            'visual_quality_rules' => \is_array($contractForMerge['visual_quality_rules'] ?? null) ? $contractForMerge['visual_quality_rules'] : $base['visual_quality_rules'],
            'visual_diversity_rules' => \is_array($contractForMerge['visual_diversity_rules'] ?? null) ? $contractForMerge['visual_diversity_rules'] : $base['visual_diversity_rules'],
            'build_handoff_rules' => \is_array($contractForMerge['build_handoff_rules'] ?? null) ? $contractForMerge['build_handoff_rules'] : $base['build_handoff_rules'],
            'retry_policy' => \is_array($contractForMerge['retry_policy'] ?? null) ? $contractForMerge['retry_policy'] : $base['retry_policy'],
        ]);
        $normalized['navigation_address_rules'] = \is_array($normalized['navigation_address_rules'] ?? null) ? $normalized['navigation_address_rules'] : [];
        $normalized['navigation_address_rules']['allowed_internal_paths'] = \is_array($normalized['page_route_contract']['allowed_internal_paths'] ?? null)
            ? $normalized['page_route_contract']['allowed_internal_paths']
            : [];
        $normalized['image_planning_rules'] = \is_array($normalized['image_planning_rules'] ?? null) ? $normalized['image_planning_rules'] : [];
        $normalized['image_planning_rules']['non_policy_first_block_requires_generated_image_intent'] = false;
        $normalized['contract_hash'] = $this->hashStablePayload($normalized);

        return $normalized;
    }

    /**
     * @param array{min:int,max:int,target:int,required:list<string>,optional:list<string>} $budget
     */
    private function resolvePreferredGeneratedImageBlockKey(string $pageType, array $budget): string
    {
        $required = \array_values(\array_filter(\array_map('strval', $budget['required'] ?? []), static fn(string $value): bool => \trim($value) !== ''));
        $optional = \array_values(\array_filter(\array_map('strval', $budget['optional'] ?? []), static fn(string $value): bool => \trim($value) !== ''));
        $available = \array_fill_keys(\array_merge($required, $optional), true);
        $preferredByPage = [
            Page::TYPE_HOME => 'hero',
            'home_page' => 'hero',
            Page::TYPE_ABOUT => 'origin_story',
            'about_page' => 'origin_story',
            Page::TYPE_CONTACT => 'contact_methods',
            'contact_page' => 'contact_methods',
            Page::TYPE_BLOG => 'article_hero',
            'blog_post' => 'article_hero',
            Page::TYPE_BLOG_CATEGORY => 'category_hero',
            'blog_category' => 'category_hero',
        ];
        $preferred = \trim((string)($preferredByPage[$pageType] ?? ''));
        if ($preferred !== '' && isset($available[$preferred])) {
            return $preferred;
        }

        return \trim((string)($required[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{min:int, max:int, target:int, required:list<string>, optional:list<string>}
     */
    public function resolveBlockBudget(string $pageType, array $scope): array
    {
        $required = [];
        $optional = [];
        $styleCode = \mb_strtolower(\trim((string)(
            $scope['design_direction_code']
            ?? $scope['design_direction_snapshot']['code']
            ?? $scope['design_direction']['code']
            ?? ''
        )));
        $isCardGameStyle = $styleCode === 'india-card-game-apk-dark-neon'
            && $this->scopeHasPositiveCardGameIntent($scope);
        if ($pageType === Page::TYPE_HOME || $pageType === 'home_page') {
            $sourceRequired = \is_array($scope['source_truth_contract']['required_home_blocks'] ?? null)
                ? \array_values(\array_filter(\array_map('strval', $scope['source_truth_contract']['required_home_blocks'])))
                : [];
            if ($isCardGameStyle) {
                $hasDownloadIntent = $this->scopeHasPositiveDownloadIntent($scope)
                    || \in_array('hero_download', $sourceRequired, true)
                    || \in_array('final_download_cta', $sourceRequired, true);
                $baseRequired = [
                    $hasDownloadIntent ? 'hero_download' : 'hero',
                    'game_showcase_or_features',
                    'trust_security',
                    'player_reviews',
                    'faq_or_rules',
                    $hasDownloadIntent ? 'final_download_cta' : 'final_cta',
                ];
                $required = \array_values(\array_unique(\array_merge($baseRequired, $sourceRequired)));
                if (!$hasDownloadIntent) {
                    $required = \array_values(\array_filter(
                        $required,
                        static fn(string $blockKey): bool => !\in_array($blockKey, ['hero_download', 'final_download_cta'], true)
                    ));
                }
                $optional = $hasDownloadIntent
                    ? ['bonus_steps', 'install_steps', 'route_chips', 'benefit_cards', 'responsible_play']
                    : ['bonus_steps', 'route_chips', 'benefit_cards', 'responsible_play'];
            } else {
                $required = \array_values(\array_unique(\array_merge(['hero', 'final_cta'], $sourceRequired)));
                $optional = ['brand_promise', 'featured_offers', 'trust_proof', 'resource_preview', 'experience_highlights'];
            }
        } elseif ($pageType === Page::TYPE_ABOUT) {
            $required = ['origin_story', 'mission_values', 'trust_proof', 'about_cta'];
            $optional = ['team_principles', 'quality_process', 'community_signal'];
        } elseif ($pageType === Page::TYPE_CONTACT) {
            $required = ['contact_methods', 'support_form_guidance', 'support_faq', 'contact_cta'];
            $optional = ['service_area', 'response_expectations', 'map_guidance'];
        } elseif ($pageType === Page::TYPE_PRIVACY_POLICY) {
            $required = ['privacy_overview', 'data_use', 'user_rights', 'privacy_contact'];
            $optional = ['data_security', 'third_party_services'];
        } elseif ($pageType === Page::TYPE_TERMS_OF_SERVICE) {
            $required = ['terms_overview', 'service_rules', 'customer_responsibilities', 'terms_contact'];
            $optional = ['account_terms', 'limitations'];
        } elseif ($pageType === Page::TYPE_REFUND_POLICY) {
            $required = ['refund_overview', 'eligibility_rules', 'refund_steps', 'refund_contact'];
            $optional = ['refund_timing', 'exception_notes'];
        } elseif ($pageType === Page::TYPE_SHIPPING_POLICY) {
            $required = ['fulfillment_overview', 'delivery_options', 'pickup_timing', 'shipping_contact'];
            $optional = ['tracking_updates', 'delivery_exceptions'];
        } elseif ($pageType === Page::TYPE_COOKIE_POLICY) {
            $required = ['cookie_overview', 'cookie_types', 'preference_controls', 'cookie_contact'];
            $optional = ['analytics_notice', 'consent_updates'];
        } elseif ($pageType === Page::TYPE_BLOG) {
            $required = ['article_hero', 'article_body', 'related_resources', 'article_cta'];
            $optional = ['author_note', 'topic_summary'];
        } elseif ($pageType === Page::TYPE_BLOG_CATEGORY) {
            $required = ['category_hero', 'topic_filters', 'article_collection', 'category_cta'];
            $optional = ['editorial_promise', 'featured_series'];
        } elseif ($pageType === Page::TYPE_BLOG_LIST) {
            $required = ['resource_hero', 'article_grid', 'learning_path', 'newsletter_cta'];
            $optional = ['featured_article', 'topic_filters'];
        } elseif ($pageType === Page::TYPE_CUSTOM) {
            $required = ['page_intro', 'primary_story', 'proof_or_details', 'page_cta'];
            $optional = ['feature_highlights', 'supporting_gallery', 'faq_band'];
        }

        $isHome = $pageType === Page::TYPE_HOME || $pageType === 'home_page';
        $isCardGameHome = $isHome && $isCardGameStyle;
        $min = \max($isHome ? 5 : 3, \count($required));
        $max = $isHome ? \max($min, $isCardGameHome ? 8 : 7) : \max($min, 5);
        $target = $isHome ? \min(\max($isCardGameHome ? 7 : 6, $min), $max) : \min(\max(\count($required), $min), $max);
        $optional = \array_values(\array_filter(\array_unique(\array_map('strval', $optional)), static fn(string $value): bool => \trim($value) !== ''));

        return [
            'min' => $min,
            'max' => $max,
            'target' => $target,
            'required' => \array_values($required),
            'optional' => $optional,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopeHasPositiveCardGameIntent(array $scope): bool
    {
        $brief = \implode("\n", \array_filter(\array_map('strval', [
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $scope['instruction'] ?? null,
            $scope['source_instruction'] ?? null,
        ]), static fn(string $value): bool => \trim($value) !== ''));
        if ($this->containsAnyPositiveIntent($brief, [
            'APK',
            'download',
            'app',
            '安装',
            '下载',
            '推广',
            '游戏',
            'game',
            '棋牌',
            'card game',
            'Teen Patti',
            'rummy',
            'casino',
        ])) {
            return true;
        }
        if ($this->sourceTruthForbidsCardGameDirection($scope)) {
            return false;
        }

        return \trim($brief) === '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopeHasPositiveDownloadIntent(array $scope): bool
    {
        $brief = \implode("\n", \array_filter(\array_map('strval', [
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $scope['instruction'] ?? null,
            $scope['source_instruction'] ?? null,
        ]), static fn(string $value): bool => \trim($value) !== ''));

        return $this->containsAnyPositiveIntent($brief, [
            'APK',
            'download',
            'app',
            '瀹夎',
            '涓嬭浇',
            '鎺ㄥ箍',
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function sourceTruthForbidsCardGameDirection(array $scope): bool
    {
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $forbidden = \is_array($sourceTruth['must_not_do'] ?? null) ? $sourceTruth['must_not_do'] : [];
        $text = \implode("\n", \array_map('strval', $forbidden));
        if (\trim($text) === '') {
            return false;
        }

        return \preg_match('/(?:APK|\bdownload\b|\bapp\b|casino|gambling|gaming|\bcard\b|card game|Teen\s*Patti|rummy|棋牌|游戏|下载|安装)/iu', $text) === 1;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAnyPositiveIntent(string $haystack, array $needles): bool
    {
        if (\trim($haystack) === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if ($this->containsPositiveIntent($haystack, (string)$needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsPositiveIntent(string $haystack, string $needle): bool
    {
        $needle = \trim($needle);
        if (\trim($haystack) === '' || $needle === '') {
            return false;
        }

        $quoted = \preg_quote($needle, '/');
        $pattern = \preg_match('/^[a-z0-9]+$/i', $needle) === 1
            ? '/(?<![a-z0-9])' . $quoted . '(?![a-z0-9])/iu'
            : '/' . $quoted . '/iu';
        if (\preg_match_all($pattern, $haystack, $matches, \PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($matches[0] as $match) {
            $position = (int)($match[1] ?? 0);
            if (!$this->isNegatedTermOccurrence($haystack, $position)) {
                return true;
            }
        }

        return false;
    }

    private function isNegatedTermOccurrence(string $haystack, int $bytePosition): bool
    {
        $start = \max(0, $bytePosition - 140);
        $prefix = \substr($haystack, $start, $bytePosition - $start);
        $prefix = (string)\preg_replace('/^.*[.;!?。！？\r\n]/u', '', $prefix);

        return \preg_match(
            '/(?:\b(?:avoid|exclude|excluding|without|no|not|never|forbid|forbidden|do\s+not|don\'t)\b|禁止|避免|不要|不得|排除|不是|非|勿|请勿)[^.;!?。！？\r\n]{0,140}$/iu',
            $prefix
        ) === 1;
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
                'target_blocks' => 4,
                'required_block_keys' => [],
                'recommended_optional_block_keys' => [],
                'field_plan_count' => self::FIELD_PLAN_COUNT,
                'required_design_tag_keys' => self::DESIGN_TAG_KEYS,
                'forbidden_block_keys' => self::GENERIC_BLOCK_KEYS,
                'requires_visual_signature' => true,
                'visual_signature_keys' => self::VISUAL_SIGNATURE_KEYS,
                'visual_signature_uniqueness_scope' => 'same_page_adjacent_blocks',
                'visual_signature_duplicate_severity' => 'high',
                'forbid_repeated_composition_patterns_within_page' => true,
                'composition_overuse_severity' => 'medium',
                'requires_image_intent' => true,
                'image_intent_keys' => self::IMAGE_INTENT_KEYS,
            ];
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function stableHash(array $contract): string
    {
        return $this->hashStablePayload($contract);
    }

    private function getPageRouteContractService(): AiSitePageRouteContractService
    {
        return $this->pageRouteContractService ?? new AiSitePageRouteContractService();
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
            'page_route_contract' => \is_array($contract['page_route_contract'] ?? null) ? $contract['page_route_contract'] : [],
            'navigation_address_rules' => \is_array($contract['navigation_address_rules'] ?? null) ? $contract['navigation_address_rules'] : [],
            'copy_rules' => \is_array($contract['copy_rules'] ?? null) ? $contract['copy_rules'] : [],
            'field_plan_rules' => \is_array($contract['field_plan_rules'] ?? null) ? $contract['field_plan_rules'] : [],
            'image_planning_rules' => \is_array($contract['image_planning_rules'] ?? null) ? $contract['image_planning_rules'] : [],
            'visual_quality_rules' => \is_array($contract['visual_quality_rules'] ?? null) ? $contract['visual_quality_rules'] : [],
            'visual_diversity_rules' => \is_array($contract['visual_diversity_rules'] ?? null) ? $contract['visual_diversity_rules'] : [],
            'build_handoff_rules' => \is_array($contract['build_handoff_rules'] ?? null) ? $contract['build_handoff_rules'] : [],
            'source_truth_contract_hash' => (string)($contract['source_truth_contract_hash'] ?? ''),
            'asset_manifest_hash' => (string)($contract['asset_manifest_hash'] ?? ''),
        ];

        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
