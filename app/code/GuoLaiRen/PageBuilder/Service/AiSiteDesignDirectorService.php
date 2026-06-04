<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteDesignDirectorService
{
    private const VERSION = 1;

    /**
     * @var list<string>
     */
    private const PLACEHOLDER_VALUES = [
        '',
        'string',
        'none',
        'n/a',
        'na',
        'null',
        'same as above',
        'placeholder',
        'todo',
        'tbd',
        'lorem ipsum',
    ];

    public function __construct(
        private readonly ?AiSiteBlockMorphologyRegistry $morphologyRegistry = null,
        private readonly ?AiSiteDesignPolicyRegistry $policyRegistry = null
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $themePlan
     * @param array<string, mixed> $planJsonPages
     * @param array<string, mixed> $referenceImageInsights
     * @return array<string, mixed>
     */
    public function materialize(
        array $scope,
        array $websiteProfile = [],
        array $themePlan = [],
        array $planJsonPages = [],
        array $referenceImageInsights = []
    ): array {
        $policy = $this->policyRegistry()->get();
        $briefText = $this->briefText($scope, $websiteProfile, $themePlan);
        $isNeonCardDefault = $this->isNeonCardBrief($briefText, $themePlan, $websiteProfile);
        $visualDensity = $this->resolveVisualDensity($briefText, $referenceImageInsights);
        $assetDensity = $visualDensity === 'minimal' ? 'low' : ($isNeonCardDefault ? 'high' : 'medium');
        $styleDensity = $visualDensity === 'minimal' ? 'airy' : ($isNeonCardDefault ? 'immersive' : 'balanced');
        $pageTypes = $this->resolvePageTypes($scope, $planJsonPages);
        $morphologyPool = $this->resolveMorphologyPool($pageTypes, $planJsonPages, $visualDensity);
        $colorRoles = $this->resolveColorRoles($scope, $themePlan, $referenceImageInsights, $websiteProfile);
        $typography = $this->resolveTypographyTokens($scope, $themePlan, $policy, $websiteProfile, $briefText);
        $marketPosition = $this->firstMeaningfulString([
            $websiteProfile['brand_positioning'] ?? null,
            $themePlan['theme_purpose'] ?? null,
            $themePlan['style_signature'] ?? null,
        ], $isNeonCardDefault ? 'premium neon card-game entertainment' : 'premium and practical');
        $trustAngle = $this->firstMeaningfulString([
            $websiteProfile['trust_angle'] ?? null,
            $scope['trust_angle'] ?? null,
            $themePlan['trust_angle'] ?? null,
            $websiteProfile['value_proposition'] ?? null,
        ], $isNeonCardDefault
            ? 'fair-play cues, responsible entertainment notes, visible player proof, and fast support access'
            : 'credible proof, transparent details, and a direct path to action');
        $styleMood = $this->resolveStyleMood($briefText, $themePlan, $websiteProfile);
        $geometry = $this->resolveGeometry($briefText, $themePlan);
        $imagery = $visualDensity === 'minimal'
            ? 'interface'
            : ($isNeonCardDefault ? 'cinematic neon card-room scenes and block-specific editorial gaming imagery' : 'photo');

        $system = [
            'version' => self::VERSION,
            'policy_ref' => $this->policyRegistry()->policyRef(),
            'brand_positioning' => [
                'site_name' => $this->firstMeaningfulString([
                    $scope['site_title'] ?? null,
                    $websiteProfile['site_title'] ?? null,
                    $scope['store_name'] ?? null,
                ], 'Website experience'),
                'audience' => $this->firstMeaningfulString([
                    $websiteProfile['audience'] ?? null,
                    $scope['target_audience'] ?? null,
                    $scope['audience'] ?? null,
                ], 'qualified visitors'),
                'promise' => $this->firstMeaningfulString([
                    $websiteProfile['value_proposition'] ?? null,
                    $scope['value_proposition'] ?? null,
                    $scope['brief_description'] ?? null,
                    $scope['user_prompt'] ?? null,
                ], 'clear value, credible proof, and a direct next action'),
                'market_position' => $marketPosition,
                'market_level' => $this->resolveMarketLevel($marketPosition, $briefText),
                'trust_angle' => $trustAngle,
                'conversion_goal' => $this->firstMeaningfulString([
                    $websiteProfile['primary_conversion'] ?? null,
                    $scope['primary_conversion'] ?? null,
                    $scope['goal'] ?? null,
                ], 'move visitors from understanding to action'),
            ],
            'style_axis' => [
                'tone' => $this->firstMeaningfulString([
                    $themePlan['visual_tone'] ?? null,
                    $themePlan['tone'] ?? null,
                    $themePlan['style_signature'] ?? null,
                    $websiteProfile['style_tone'] ?? null,
                ], 'refined, clear, and conversion-oriented'),
                'density' => $styleDensity,
                'mood' => $styleMood,
                'geometry' => $geometry,
                'imagery' => $imagery,
                'visual_depth' => $visualDensity === 'minimal'
                    ? 'quiet layers, crisp dividers, and restrained media'
                    : 'integrated media, layered surfaces, and strong section rhythm',
                'motion' => 'subtle transform and opacity only',
                'typography_voice' => $this->firstMeaningfulString([
                    $themePlan['typography_spacing_radius']['typography_voice'] ?? null,
                    $themePlan['typography']['voice'] ?? null,
                    $themePlan['font_family'] ?? null,
                ], 'readable editorial hierarchy'),
            ],
            'tokens' => [
                'container' => '1280px',
                'radius_scale' => $this->resolveScaleTokens($policy, 'radius', ['12px', '20px', '28px']),
                'spacing_scale' => $this->resolveScaleTokens($policy, 'spacing', ['clamp(48px, 7vw, 96px)', 'clamp(24px, 4vw, 56px)']),
                'type_scale' => [
                    'hero' => '72px desktop / 56px tablet / 40px mobile',
                    'section_title' => '44px desktop / 36px tablet / 28px mobile',
                    'body' => '16px desktop / 16px mobile',
                ],
                'color_roles' => $colorRoles,
                'typography' => $typography,
                'spacing' => $this->resolveTokenGroup($policy, 'spacing'),
                'radius' => $this->resolveTokenGroup($policy, 'radius'),
                'motion' => $this->resolveTokenGroup($policy, 'motion'),
            ],
            'layout_strategy' => [
                'page_rhythm' => 'opening, value, proof, details, support, conversion',
                'section_grid' => 'responsive 12-column desktop with single-column mobile stack',
                'hero_rule' => 'first viewport must show the site subject, primary value, and one action path',
                'block_diversity_rule' => 'avoid adjacent morphology repetition and avoid repeated title-text-button skeletons',
                'section_density_rule' => 'alternate dense information blocks with lighter proof, media, or support sections',
                'anti_monotony_rule' => 'no two adjacent content sections may share the same morphology, media placement, and title-copy-action skeleton',
                'shared_header_footer_rule' => 'header and footer must stay visually consistent across pages',
            ],
            'media_strategy' => [
                'density' => $visualDensity,
                'page_asset_density' => $assetDensity,
                'hero_image_rule' => $visualDensity === 'minimal'
                    ? 'hero can use structured CSS motif when the brief asks for low imagery'
                    : ($isNeonCardDefault
                        ? 'hero should use one immersive neon card-room or game-lobby scene with cards, mahjong tiles, chips, table glow, and a text-safe crop'
                        : 'hero should prefer one integrated subject image or product scene'),
                'non_hero_image_rule' => $visualDensity === 'minimal'
                    ? 'non-hero sections may use CSS motifs, icons, or data surfaces instead of real images'
                    : ($isNeonCardDefault
                        ? 'distribute block-specific real images into proof, game-feature, article, about, and support sections; do not reuse the same hero lobby subject for every block'
                        : 'distribute required real images beyond the hero into proof, detail, or support sections'),
                'image_treatment' => $visualDensity === 'minimal'
                    ? 'use CSS motifs, interface surfaces, and restrained framed media only when the contract requires it'
                    : ($isNeonCardDefault
                        ? 'use dark premium neon lighting, cyan/magenta/violet edge glow, gold highlights, green felt or glass card-room texture, responsive crop, readable scrims, and role-specific props'
                        : 'use responsive crop, editorial framing, contextual subject matter, and palette-compatible overlays'),
                'text_on_media_rule' => 'text may sit over media only with a contract-defined scrim or separate readable panel',
                'css_only_rule' => 'CSS-only sections must still declare a concrete motif and visible composition structure',
                'reference_image_usage' => $this->referenceUsageRule($referenceImageInsights),
            ],
            'morphology_pool' => $morphologyPool,
            'a11y_rules' => [
                'semantic headings and landmarks',
                'readable contrast for text and CTA states',
                'alt text for every required real image',
                'no horizontal overflow on mobile',
            ],
            'forbidden_patterns' => [
                'template_copy_leak',
                'title paragraph button only sections',
                'empty visual slabs',
                'uncontrolled palette changes',
                'raw prompt or rationale text',
            ],
            'trace' => [
                'page_types' => $pageTypes,
                'color_source' => $colorRoles === $this->semanticColorRoles() ? 'semantic_contract' : 'theme_or_reference',
                'media_density_reason' => $visualDensity === 'minimal' ? 'brief_requests_low_imagery' : 'standard_site_visual_rhythm',
            ],
        ];

        $validation = $this->validate($system);
        if (!($validation['valid'] ?? false)) {
            throw new \RuntimeException('AI site design system is invalid: ' . \implode('; ', $validation['errors'] ?? []));
        }

        return $system;
    }

    /**
     * @param array<string, mixed> $system
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $system): array
    {
        $errors = [];
        foreach (['version', 'brand_positioning', 'style_axis', 'tokens', 'layout_strategy', 'media_strategy', 'morphology_pool'] as $field) {
            if (!\array_key_exists($field, $system) || $system[$field] === [] || $this->isPlaceholder($system[$field])) {
                $errors[] = 'missing_or_placeholder:' . $field;
            }
        }

        $tokens = \is_array($system['tokens'] ?? null) ? $system['tokens'] : [];
        $colorRoles = \is_array($tokens['color_roles'] ?? null) ? $tokens['color_roles'] : [];
        foreach (['background', 'surface', 'accent', 'accent_soft', 'text'] as $role) {
            if (!$this->isMeaningfulString($colorRoles[$role] ?? null)) {
                $errors[] = 'missing_color_role:' . $role;
            }
        }
        foreach (['container', 'radius_scale', 'spacing_scale', 'type_scale'] as $tokenKey) {
            if (!\array_key_exists($tokenKey, $tokens) || $tokens[$tokenKey] === []) {
                $errors[] = 'missing_token:' . $tokenKey;
            }
        }
        foreach (['audience', 'market_level', 'trust_angle', 'conversion_goal'] as $key) {
            if (!$this->isMeaningfulString($system['brand_positioning'][$key] ?? null)) {
                $errors[] = 'missing_brand_positioning:' . $key;
            }
        }
        foreach (['density', 'mood', 'geometry', 'imagery'] as $key) {
            if (!$this->isMeaningfulString($system['style_axis'][$key] ?? null)) {
                $errors[] = 'missing_style_axis:' . $key;
            }
        }
        foreach (['page_asset_density', 'non_hero_image_rule', 'image_treatment', 'text_on_media_rule'] as $key) {
            if (!$this->isMeaningfulString($system['media_strategy'][$key] ?? null)) {
                $errors[] = 'missing_media_strategy:' . $key;
            }
        }

        $pool = \is_array($system['morphology_pool'] ?? null) ? \array_values($system['morphology_pool']) : [];
        if (\count($pool) < 6) {
            $errors[] = 'morphology_pool_too_small';
        }
        $registered = \array_fill_keys(\array_keys($this->morphologyRegistry()->all()), true);
        $hasRegistered = false;
        foreach ($pool as $id) {
            if (\is_string($id) && isset($registered[$id])) {
                $hasRegistered = true;
                break;
            }
        }
        if (!$hasRegistered) {
            $errors[] = 'morphology_pool_not_registered';
        }

        $placeholderPaths = [];
        $this->collectPlaceholderPaths($system, '', $placeholderPaths);
        foreach ($placeholderPaths as $path) {
            $errors[] = 'placeholder_value:' . $path;
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
        ];
    }

    private function morphologyRegistry(): AiSiteBlockMorphologyRegistry
    {
        return $this->morphologyRegistry ?? new AiSiteBlockMorphologyRegistry();
    }

    private function policyRegistry(): AiSiteDesignPolicyRegistry
    {
        return $this->policyRegistry ?? new AiSiteDesignPolicyRegistry();
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $themePlan
     */
    private function briefText(array $scope, array $websiteProfile, array $themePlan): string
    {
        $parts = [];
        foreach ([
            $scope['brief_description'] ?? null,
            $scope['user_prompt'] ?? null,
            $scope['goal'] ?? null,
            $scope['style_preference'] ?? null,
            $websiteProfile['summary'] ?? null,
            $websiteProfile['style_tone'] ?? null,
            $themePlan['style_signature'] ?? null,
            $themePlan['visual_tone'] ?? null,
        ] as $value) {
            if ($this->isMeaningfulString($value)) {
                $parts[] = (string)$value;
            }
        }

        return \strtolower(\implode(' ', $parts));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJsonPages
     * @return list<string>
     */
    private function resolvePageTypes(array $scope, array $planJsonPages): array
    {
        $types = [];
        foreach ([$scope['page_types'] ?? null, $scope['pages'] ?? null] as $source) {
            if (!\is_array($source)) {
                continue;
            }
            foreach ($source as $key => $value) {
                $type = \is_string($key) && !\is_numeric($key) ? $key : (string)($value['page_type'] ?? $value['type'] ?? $value);
                $type = $this->normalizeToken($type);
                if ($type !== '') {
                    $types[] = $type;
                }
            }
        }
        foreach ($planJsonPages as $key => $plan) {
            $type = \is_string($key) && !\is_numeric($key) ? $key : (string)($plan['page_type'] ?? $plan['type'] ?? '');
            $type = $this->normalizeToken($type);
            if ($type !== '') {
                $types[] = $type;
            }
        }
        if ($types === []) {
            $types[] = 'home_page';
        }

        return \array_values(\array_unique($types));
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $planJsonPages
     * @return list<string>
     */
    private function resolveMorphologyPool(array $pageTypes, array $planJsonPages, string $visualDensity): array
    {
        $roles = ['opening', 'details', 'proof', 'support', 'cta'];
        foreach ($planJsonPages as $plan) {
            foreach ($this->extractBlocks($plan) as $block) {
                $role = $this->normalizeToken((string)($block['page_flow_role'] ?? $block['role'] ?? ''));
                if ($role !== '') {
                    $roles[] = $role;
                }
            }
        }

        $pool = [];
        foreach ($pageTypes as $pageType) {
            foreach (\array_values(\array_unique($roles)) as $role) {
                $candidates = $this->morphologyRegistry()->selectCandidates($pageType, $role, [
                    'needs_image' => $visualDensity === 'rich' ? null : false,
                ]);
                foreach ($candidates as $candidate) {
                    $id = (string)($candidate['id'] ?? '');
                    if ($id !== '' && !\in_array($id, $pool, true)) {
                        $pool[] = $id;
                    }
                    if (\count($pool) >= 12) {
                        return $pool;
                    }
                }
            }
        }

        foreach (\array_keys($this->morphologyRegistry()->all()) as $id) {
            if (!\in_array($id, $pool, true)) {
                $pool[] = $id;
            }
            if (\count($pool) >= 12) {
                break;
            }
        }

        return $pool;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function extractBlocks(array $plan): array
    {
        $blocks = [];
        foreach ($plan as $key => $value) {
            if (!\is_string($key) || !\is_array($value) || !$this->looksLikeDynamicBlockNode($key, $value)) {
                continue;
            }

            $value['block_key'] = (string)($value['block_key'] ?? $key);
            $blocks[] = $value;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function looksLikeDynamicBlockNode(string $key, array $node): bool
    {
        if (\in_array($key, ['seo', 'page_design_plan', 'asset_distribution_policy', 'theme_context_snapshot'], true)) {
            return false;
        }

        foreach (['block_key', 'page_flow_role', 'block_contract', 'visual_signature', 'image_intent', 'field_plan', 'execution_script'] as $field) {
            if (\array_key_exists($field, $node)) {
                return true;
            }
        }

        return \array_key_exists('status', $node)
            && (\array_key_exists('html', $node) || \array_key_exists('fields', $node) || \array_key_exists('demo', $node));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $themePlan
     * @param array<string, mixed> $referenceImageInsights
     * @param array<string, mixed> $websiteProfile
     * @return array<string, string>
     */
    private function resolveColorRoles(array $scope, array $themePlan, array $referenceImageInsights, array $websiteProfile = []): array
    {
        $roles = [];
        foreach ([
            $themePlan['color_scheme'] ?? null,
            $themePlan['palette'] ?? null,
            $themePlan['theme_design']['color_scheme'] ?? null,
            $scope['theme_design']['color_scheme'] ?? null,
            $scope['palette'] ?? null,
            $scope['theme_context_snapshot']['palette'] ?? null,
            $referenceImageInsights['color_palette'] ?? null,
        ] as $source) {
            if (!\is_array($source)) {
                continue;
            }
            foreach ($source as $key => $value) {
                $hex = $this->normalizeHex($value);
                if ($hex === '') {
                    continue;
                }
                $role = \is_string($key) && !\is_numeric($key)
                    ? $this->normalizeToken($key)
                    : 'accent_' . ((string)(\count($roles) + 1));
                if ($role === 'background') {
                    $role = 'surface';
                }
                if (!isset($roles[$role])) {
                    $roles[$role] = $hex;
                }
            }
        }

        $aliases = [
            'primary' => ['primary', 'brand', 'accent_1'],
            'background' => ['background', 'base', 'surface', 'accent_2'],
            'surface' => ['surface', 'base', 'background', 'accent_2'],
            'text' => ['text', 'foreground', 'body', 'accent_3'],
            'accent' => ['accent', 'secondary', 'accent_4', 'primary'],
            'accent_soft' => ['accent_soft', 'accent_softened', 'secondary', 'accent_4', 'accent'],
        ];
        foreach ($aliases as $required => $candidates) {
            if (isset($roles[$required])) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (isset($roles[$candidate])) {
                    $roles[$required] = $roles[$candidate];
                    break;
                }
            }
        }

        if (!isset($roles['primary'], $roles['background'], $roles['surface'], $roles['text'], $roles['accent'], $roles['accent_soft'])
            && $this->isNeonCardBrief($this->briefText($scope, $websiteProfile, $themePlan), $themePlan, $websiteProfile)
        ) {
            return $this->neonCardColorRoles();
        }

        if (!isset($roles['primary'], $roles['background'], $roles['surface'], $roles['text'], $roles['accent'], $roles['accent_soft'])) {
            return $this->semanticColorRoles();
        }

        return \array_replace($this->semanticColorRoles(), $roles);
    }

    /**
     * @return array<string, string>
     */
    private function semanticColorRoles(): array
    {
        return [
            'primary' => 'brand.primary',
            'background' => 'surface.canvas',
            'surface' => 'surface.canvas',
            'surface_alt' => 'surface.elevated',
            'text' => 'content.primary',
            'muted_text' => 'content.muted',
            'accent' => 'brand.accent',
            'accent_soft' => 'brand.accent.soft',
            'divider' => 'surface.divider',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function neonCardColorRoles(): array
    {
        return [
            'primary' => '#7C3AED',
            'background' => '#0F0F23',
            'surface' => '#15152E',
            'surface_alt' => '#1E1B3F',
            'text' => '#E2E8F0',
            'muted_text' => '#A78BFA',
            'accent' => '#00F5FF',
            'accent_soft' => '#F43F5E',
            'warning' => '#F7C948',
            'success' => '#33F28B',
            'divider' => '#312E81',
            'shadow' => '#030712',
            'on_primary' => '#FFFFFF',
            'secondary' => '#A78BFA',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $themePlan
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function resolveTypographyTokens(
        array $scope,
        array $themePlan,
        array $policy,
        array $websiteProfile = [],
        string $briefText = ''
    ): array
    {
        $defaults = \is_array($policy['default_tokens']['typography'] ?? null) ? $policy['default_tokens']['typography'] : [];
        $isNeonCardDefault = $this->isNeonCardBrief($briefText !== '' ? $briefText : $this->briefText($scope, $websiteProfile, $themePlan), $themePlan, $websiteProfile);
        $font = $this->firstMeaningfulString([
            $themePlan['typography_spacing_radius']['font_family'] ?? null,
            $themePlan['typography']['font_family'] ?? null,
            $themePlan['font_family'] ?? null,
            $scope['theme_design']['typography_spacing_radius']['font_family'] ?? null,
        ], $isNeonCardDefault ? 'Russo One, Chakra Petch, Orbitron, Outfit, Work Sans, system-ui, sans-serif' : 'system readable stack');

        return \array_replace($defaults, [
            'font_family' => $font,
            'heading_voice' => $this->firstMeaningfulString([
                $themePlan['typography_spacing_radius']['heading_voice'] ?? null,
                $themePlan['typography']['heading_voice'] ?? null,
            ], $isNeonCardDefault ? 'angular neon gaming display with readable Chinese support' : 'clear section hierarchy'),
        ]);
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function resolveTokenGroup(array $policy, string $group): array
    {
        $tokens = $policy['default_tokens'][$group] ?? [];
        return \is_array($tokens) ? $tokens : [];
    }

    /**
     * @param array<string,mixed> $themePlan
     * @param array<string,mixed> $websiteProfile
     */
    private function isNeonCardBrief(string $briefText, array $themePlan = [], array $websiteProfile = []): bool
    {
        $raw = \mb_strtolower(\trim($briefText . ' ' . \json_encode([
            $themePlan['style_signature'] ?? null,
            $themePlan['visual_tone'] ?? null,
            $themePlan['visual_keywords'] ?? null,
            $themePlan['art_direction'] ?? null,
            $websiteProfile['style_tone'] ?? null,
            $websiteProfile['brief_description'] ?? null,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)), 'UTF-8');
        if ($raw === '') {
            return false;
        }
        if (\preg_match('/(?:avoid|without|no|forbid|ban|reject)[^.。；;]{0,80}(?:neon|casino|gaming|gambling|card\s*game|poker|mahjong|棋牌|霓虹|博彩|赌博)/iu', $raw) === 1
            || \preg_match('/(?:不要|避免|禁止|拒绝)[^。；;]{0,80}(?:霓虹|棋牌|博彩|赌博|娱乐场|赌场|扑克|麻将)/u', $raw) === 1
        ) {
            return false;
        }

        return \preg_match('/(?:neon|casino|card\s*game|poker|mahjong|rummy|teen\s*patti|game\s*lobby|gaming|棋牌|棋牌游戏|霓虹|牌桌|牌局|扑克|麻将|电玩城|线上娱乐|游戏房间|赛事房间)/iu', $raw) === 1;
    }

    private function resolveVisualDensity(string $briefText, array $referenceImageInsights): string
    {
        foreach (['no image', 'no images', 'without image', 'without images', 'minimal imagery', 'low imagery', 'text only'] as $needle) {
            if ($briefText !== '' && \str_contains($briefText, $needle)) {
                return 'minimal';
            }
        }
        $styleKeywords = $referenceImageInsights['style_keywords'] ?? [];
        if (\is_array($styleKeywords)) {
            foreach ($styleKeywords as $keyword) {
                $keyword = \strtolower((string)$keyword);
                if (\str_contains($keyword, 'minimal') || \str_contains($keyword, 'low imagery')) {
                    return 'minimal';
                }
            }
        }

        return 'rich';
    }

    /**
     * @param array<string,mixed> $themePlan
     * @param array<string,mixed> $websiteProfile
     */
    private function resolveStyleMood(string $briefText, array $themePlan, array $websiteProfile): string
    {
        $raw = \strtolower($this->firstMeaningfulString([
            $themePlan['mood'] ?? null,
            $themePlan['visual_mood'] ?? null,
            $themePlan['visual_tone'] ?? null,
            $websiteProfile['style_tone'] ?? null,
        ], $briefText));
        if ($this->isNeonCardBrief($briefText, $themePlan, $websiteProfile)) {
            return 'neon gaming';
        }
        if (\str_contains($raw, 'luxury') || \str_contains($raw, 'premium')) {
            return 'luxury';
        }
        if (\str_contains($raw, 'technical') || \str_contains($raw, 'clinical') || \str_contains($raw, 'saas')) {
            return 'technical';
        }
        if (\str_contains($raw, 'energetic') || \str_contains($raw, 'bold')) {
            return 'energetic';
        }
        if (\str_contains($raw, 'warm') || \str_contains($raw, 'family') || \str_contains($raw, 'restaurant')) {
            return 'warm';
        }
        if (\str_contains($raw, 'editorial') || \str_contains($raw, 'story')) {
            return 'editorial';
        }

        return 'calm';
    }

    /**
     * @param array<string,mixed> $themePlan
     */
    private function resolveGeometry(string $briefText, array $themePlan): string
    {
        $raw = \strtolower($this->firstMeaningfulString([
            $themePlan['geometry'] ?? null,
            $themePlan['shape_language'] ?? null,
            $themePlan['style_signature'] ?? null,
        ], $briefText));
        if ($this->isNeonCardBrief($briefText, $themePlan)) {
            return 'angular';
        }
        if (\str_contains($raw, 'sharp') || \str_contains($raw, 'angular') || \str_contains($raw, 'brutalist')) {
            return 'sharp';
        }
        if (\str_contains($raw, 'mixed') || \str_contains($raw, 'editorial')) {
            return 'mixed';
        }

        return 'soft';
    }

    private function resolveMarketLevel(string $marketPosition, string $briefText): string
    {
        $raw = \strtolower($marketPosition . ' ' . $briefText);
        if (\str_contains($raw, 'luxury') || \str_contains($raw, 'ultra premium')) {
            return 'luxury';
        }
        if (\str_contains($raw, 'budget') || \str_contains($raw, 'affordable')) {
            return 'budget';
        }
        if (\str_contains($raw, 'premium') || \str_contains($raw, 'trusted') || \str_contains($raw, 'expert')) {
            return 'premium';
        }

        return 'mainstream';
    }

    /**
     * @param array<string,mixed> $policy
     * @param list<string> $fallback
     * @return list<string>
     */
    private function resolveScaleTokens(array $policy, string $group, array $fallback): array
    {
        $tokens = $this->resolveTokenGroup($policy, $group);
        $values = [];
        foreach ($tokens as $value) {
            if ($this->isMeaningfulString($value)) {
                $values[] = \trim((string)$value);
            }
        }
        if (\count($values) >= 2) {
            return \array_values(\array_slice(\array_unique($values), 0, 4));
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $referenceImageInsights
     */
    private function referenceUsageRule(array $referenceImageInsights): string
    {
        $summary = $this->firstMeaningfulString([
            $referenceImageInsights['summary'] ?? null,
            $referenceImageInsights['visual_contract']['asset_usage_rule']['reference_image_role'] ?? null,
        ], 'style reference only');

        return $summary;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstMeaningfulString(array $values, string $fallback): string
    {
        foreach ($values as $value) {
            if ($this->isMeaningfulString($value)) {
                return \trim((string)$value);
            }
        }

        return $fallback;
    }

    private function isMeaningfulString(mixed $value): bool
    {
        if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
            return false;
        }
        $text = \trim((string)$value);
        if ($text === '') {
            return false;
        }

        return !$this->isPlaceholder($text);
    }

    private function isPlaceholder(mixed $value): bool
    {
        if (\is_array($value)) {
            return false;
        }
        if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
            return false;
        }
        $text = \strtolower(\trim((string)$value));
        $text = \preg_replace('/\s+/', ' ', $text) ?? $text;

        return \in_array($text, self::PLACEHOLDER_VALUES, true);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $paths
     */
    private function collectPlaceholderPaths(array $value, string $path, array &$paths): void
    {
        foreach ($value as $key => $item) {
            $current = $path === '' ? (string)$key : $path . '.' . (string)$key;
            if (\is_array($item)) {
                $this->collectPlaceholderPaths($item, $current, $paths);
                continue;
            }
            if ($this->isPlaceholder($item)) {
                $paths[] = $current;
            }
        }
    }

    private function normalizeHex(mixed $value): string
    {
        if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
            return '';
        }
        $text = \trim((string)$value);
        if (\preg_match('/^#?[0-9a-fA-F]{6}$/', $text) !== 1) {
            return '';
        }

        return '#' . \strtoupper(\ltrim($text, '#'));
    }

    private function normalizeToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? $value;
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_-');
    }
}
