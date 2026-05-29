<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteDesignPolicyRegistry
{
    public const DEFAULT_POLICY_ID = 'premium_web_v1';
    public const DEFAULT_POLICY_VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    public function get(string $policyId = self::DEFAULT_POLICY_ID): array
    {
        $policyId = \trim($policyId) !== '' ? \trim($policyId) : self::DEFAULT_POLICY_ID;
        if ($policyId !== self::DEFAULT_POLICY_ID) {
            throw new \InvalidArgumentException('Unknown design policy: ' . $policyId);
        }

        $body = [
            'policy_id' => self::DEFAULT_POLICY_ID,
            'version' => self::DEFAULT_POLICY_VERSION,
            'priority_rules' => [
                'priority.user_requirements_first',
                'priority.user_style_first',
                'priority.default_premium_when_unspecified',
                'priority.optimize_bad_user_visual_request',
            ],
            'quality_floor' => [
                'Clear visual hierarchy',
                'Consistent spacing',
                'Integrated image treatment',
                'Responsive layout without horizontal overflow',
                'Accessible contrast and focus states',
            ],
            'rule_catalog' => $this->ruleCatalog(),
            'default_tokens' => [
                'layout' => [
                    'container_max_width' => '1280px',
                    'desktop_columns' => 12,
                    'mobile_columns' => 4,
                ],
                'spacing' => [
                    'desktop_section_padding' => '120px',
                    'mobile_section_padding' => '64px',
                    'grid_gap' => '24px',
                ],
                'typography' => [
                    'h1' => '64px desktop / 44px mobile',
                    'h2' => '40px desktop / 30px mobile',
                    'body' => '16px',
                    'line_height' => '1.7',
                ],
                'colors' => [
                    'primary_count_max' => 3,
                    'contrast_min' => 'WCAG AA',
                    'saturation' => 'controlled',
                ],
                'radius' => [
                    'component_radius' => '12px',
                    'media_radius' => '16px',
                ],
                'motion' => [
                    'duration' => '180ms-400ms',
                    'easing' => 'standard',
                    'reduced_motion' => true,
                ],
            ],
            'default_recipes' => [
                'hero_media_focus',
                'proof_band',
                'feature_grid',
                'conversion_cta',
            ],
            'banned_patterns' => [
                'lorem ipsum',
                'random spacing',
                'too many colors',
                'hard black shadow',
                'image pasted beside text',
                'generic template copy',
            ],
            'full_policy_prompt' => $this->fullPolicyPrompt(),
            'compact_policy_prompt' => $this->compactPolicyPrompt(),
        ];

        return \array_replace($body, [
            'hash' => $this->hashPolicy($body),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function policyRef(string $policyId = self::DEFAULT_POLICY_ID): array
    {
        $policy = $this->get($policyId);

        return [
            'policy_id' => (string)$policy['policy_id'],
            'policy_version' => (string)$policy['version'],
            'policy_hash' => (string)$policy['hash'],
            'source' => self::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function registeredPolicyIds(): array
    {
        return [self::DEFAULT_POLICY_ID];
    }

    public function hasPolicy(string $policyId): bool
    {
        return \in_array($policyId, $this->registeredPolicyIds(), true);
    }

    /**
     * @return list<string>
     */
    public function ruleIds(string $policyId = self::DEFAULT_POLICY_ID): array
    {
        $catalog = $this->get($policyId)['rule_catalog'] ?? [];
        if (!\is_array($catalog)) {
            return [];
        }

        return \array_values(\array_map('strval', \array_keys($catalog)));
    }

    public function hasRule(string $ruleId, string $policyId = self::DEFAULT_POLICY_ID): bool
    {
        $ruleId = \trim($ruleId);
        if ($ruleId === '') {
            return false;
        }

        return \array_key_exists($ruleId, $this->get($policyId)['rule_catalog']);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function ruleCatalog(): array
    {
        $rules = [
            'priority.user_requirements_first' => 'User requirements override default taste rules.',
            'priority.user_style_first' => 'Explicit user style wins over premium defaults.',
            'priority.default_premium_when_unspecified' => 'Use the premium baseline when the user is underspecified.',
            'priority.optimize_bad_user_visual_request' => 'Preserve intent while correcting low-quality visual choices.',
            'layout.grid_alignment' => 'Align sections and components to a clear grid.',
            'layout.4_8_spacing' => 'Use spacing that lands on a 4px or 8px rhythm.',
            'layout.container_1120_1280' => 'Keep main content inside an intentional max-width container.',
            'layout.section_padding_desktop_96_140' => 'Use generous desktop vertical rhythm.',
            'layout.section_padding_mobile_56_80' => 'Use compact but breathable mobile vertical rhythm.',
            'layout.card_padding_24_40' => 'Keep card padding consistent across repeated components.',
            'layout.body_width_560_720' => 'Keep long body copy readable.',
            'typography.refined_font_stack' => 'Use a deliberate, readable type stack.',
            'typography.h1_responsive_steps' => 'Use fixed heading sizes per breakpoint; do not scale font-size with vw or clamp().',
            'typography.h2_responsive_steps' => 'Use fixed section heading sizes per breakpoint; do not scale font-size with vw or clamp().',
            'typography.body_16_18' => 'Keep body copy between 16px and 18px.',
            'typography.body_line_height_165_180' => 'Keep body line-height comfortable.',
            'typography.cn_letter_spacing_safe' => 'Avoid unsafe letter spacing for Chinese copy.',
            'typography.en_title_negative_tracking' => 'Allow restrained title tracking for English only when appropriate.',
            'color.max_2_3_primary_colors' => 'Limit the dominant palette.',
            'color.low_saturation' => 'Prefer controlled saturation unless the user asks otherwise.',
            'color.no_neon_unless_user_requests' => 'Avoid neon color unless explicitly requested.',
            'color.readable_contrast' => 'Maintain readable contrast.',
            'image.integrated_not_pasted' => 'Integrate images into the composition.',
            'image.object_fit_cover' => 'Crop imagery intentionally.',
            'image.shared_radius' => 'Use consistent image radii.',
            'image.gradient_overlay' => 'Use overlays only to improve readability.',
            'image.text_safe_zone' => 'Place copy on a deliberate contrast-safe zone when media is busy.',
            'image.ambient_glow' => 'Use ambient image treatment subtly.',
            'image.edge_fade' => 'Blend image edges where useful.',
            'image.no_style_mismatch' => 'Avoid mismatched image styles.',
            'background.soft_gradient' => 'Use subtle gradients when they add structure.',
            'background.radial_glow' => 'Use radial glow as a restrained supporting effect.',
            'background.subtle_grain' => 'Use texture only when it supports the design.',
            'background.masked_image' => 'Mask images when needed for layout integration.',
            'background.readability_overlay' => 'Keep copy readable over media.',
            'background.scrim_text_panel' => 'Use a scrim plus text panel for copy over detailed photos.',
            'component.unified_radius' => 'Keep component radius consistent.',
            'component.unified_shadow' => 'Keep shadows restrained and consistent.',
            'component.unified_border' => 'Use borders consistently.',
            'component.bento_allowed' => 'Allow bento layout when it fits the brief.',
            'component.magazine_layout_allowed' => 'Allow editorial layout when it fits the brief.',
            'button.primary_secondary_clear' => 'Make primary and secondary actions distinct.',
            'button.specific_cta_text' => 'Use specific CTA copy.',
            'button.hover_subtle' => 'Keep hover states subtle.',
            'motion.subtle_transform_opacity' => 'Use subtle transform and opacity motion.',
            'motion.duration_180_400' => 'Keep motion within a restrained duration range.',
            'motion.easing_standard' => 'Use standard easing.',
            'motion.prefers_reduced_motion' => 'Respect reduced motion.',
            'motion.no_spin_bounce_flash' => 'Avoid spin, bounce, and flashing motion.',
            'responsive.desktop_multi_column' => 'Use columns on large screens when helpful.',
            'responsive.tablet_reduced_columns' => 'Reduce columns on tablet.',
            'responsive.mobile_single_column' => 'Use single-column mobile layouts.',
            'responsive.no_horizontal_scroll' => 'Prevent horizontal overflow.',
            'a11y.alt_focus_semantic' => 'Keep image alt text, focus states, and semantic structure.',
            'ban.reason_fields' => 'Contract fields must not carry explanatory reasoning.',
            'ban.random_spacing' => 'Reject inconsistent spacing.',
            'ban.too_many_colors' => 'Reject uncontrolled palettes.',
            'ban.hard_black_shadow' => 'Reject harsh default shadows.',
            'ban.image_module_split' => 'Reject pasted image/text module splits.',
            'ban.lorem_ipsum' => 'Reject placeholder copy.',
        ];

        $catalog = [];
        foreach ($rules as $id => $description) {
            $catalog[$id] = [
                'id' => $id,
                'category' => \strstr($id, '.', true) ?: 'general',
                'description' => $description,
            ];
        }

        return $catalog;
    }

    private function fullPolicyPrompt(): string
    {
        return \implode("\n", [
            'premium_web_v1 design policy:',
            '- Prioritize explicit user requirements and explicit user style.',
            '- When the brief is underspecified, produce a refined premium web design.',
            '- Use concrete layout, typography, color, media, motion, responsive, and accessibility rules.',
            '- Any copy over photography or detailed texture must sit inside a visible scrim/text-panel safe zone with explicit foreground contrast; never place body copy directly on a busy image.',
            '- Do not output rationale fields into the build contract.',
        ]);
    }

    private function compactPolicyPrompt(): string
    {
        return 'Use premium_web_v1 rule ids and concrete design tokens. Preserve explicit user style, avoid rationale fields, keep build prompts scoped to the current task, and require readable scrim/text-panel safe zones for text over busy media.';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function hashPolicy(array $body): string
    {
        return 'sha256:' . \hash('sha256', (string)\json_encode(
            $body,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        ));
    }
}
