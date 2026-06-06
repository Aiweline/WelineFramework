<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class AiSiteVisualBlockContractRenderer
{
    /**
     * @param array<string,mixed> $themePalette
     * @param array<string,mixed> $brief
     * @param array<string,mixed> $visualSignature
     * @param array<string,mixed> $pageDesignPlan
     */
    public function renderSectionVisualContract(
        array $themePalette,
        array $brief,
        string $contentLocale,
        bool $hasVerifiedHeroImage = false,
        array $visualSignature = [],
        array $pageDesignPlan = []
    ): string {
        $lines = [];
        $lines[] = 'AI block design guidance: generate one complete visitor-facing PageBuilder block from the current PlanJson block.';
        $lines[] = '- Structure is binding: return valid JSON, valid HTML/PHTML, css_extra, default_config, semantic root classes, responsive CSS, and no visible internal metadata.';
        $lines[] = '- Creative fields are open: headings, copy, layout motifs, image treatment, and micro-interactions should follow the PlanJson intent without being policed by fixed keyword gates.';
        $lines[] = '- Default style direction when the PlanJson is silent: dark neon chess/casino atmosphere, luminous card-table surfaces, sharp contrast, electric cyan/magenta/gold accents, polished game-floor tension.';
        $lines[] = '- Use the current block role exactly. Do not reuse another block role, CTA pattern, image, or card layout simply to satisfy a generic template.';
        $lines[] = '- Visible copy language: ' . ($contentLocale !== '' ? $contentLocale : 'use the workspace default locale') . '. Brand names, product names, URLs, API/APK, and proper nouns may stay as-is.';
        $lines[] = '- content_locale (HARD): ' . ($contentLocale !== '' ? $contentLocale : 'workspace default') . '. All visitor-visible copy, editable defaults, alt/title/aria labels, and CTA text must use this locale unless the token is a brand/proper noun.';
        $lines[] = '- Typography: use design-token fonts through var(--pb-font-display) and var(--pb-font-body); avoid hardcoded generic stacks.';
        $lines[] = '- Layout quality: choose a composition that fits this block role. Prefer distinctive section-specific structure over repeated title/paragraph/button/card-grid shells.';
        $lines[] = '- [gate#visual_depth] css_extra must create visible layered depth through surfaces, borders, shadows, scrims, texture, or linear-gradient(...) treatment; flat placeholder slabs are invalid.';
        $lines[] = '- [gate#responsive_support] css_responsive must include @media (max-width: 768px) and @media (max-width: 420px), safe stacking, min-width:0, max-width:100%, and stable clamp( or fixed breakpoint font sizing.';
        $lines[] = '- Semantic affordance contract: anything shaped like a button, tab, pill, badge, step, carousel dot, indicator, input, progress bar, chip, stat, or control must contain localized visible text or a meaningful icon with accessible label. Pure decoration must not look clickable or form-like.';
        $lines[] = '- Negative visual tokens: no unlabeled dots, empty rounded pills, blank horizontal bars, empty input-like strips, orphan carousel indicators, iconless step markers, decorative control rows, or placeholder UI chrome. Delete them or convert them into labeled proof, step, status, or action content.';
        $lines[] = '- Universal negative prompt coverage: reject placeholder/wireframe/skeleton-looking sections, lorem ipsum or prompt/schema leakage, generic stock-card sameness, fake UI controls, unsupported claims or invented metrics/contact facts, unreadable overlays, low-contrast text, disconnected CTA placement, oversized empty gutters, broken/missing assets, decorative clutter that competes with the primary action, and responsive layouts that would overlap, crop, or create horizontal scroll.';
        $lines[] = '- Hero/banner scale: for opening, hero, banner, above-fold, or page-intro roles, default to a generous full-bleed or viewport-width media band with strong focal hierarchy and a readable text-safe overlay/panel. Do not use a small side thumbnail, narrow image island, or cramped split media as the default banner treatment.';
        $lines[] = '- Responsive quality: include breakpoint CSS for mobile stacking, min-width:0 on flex/grid children, max-width:100% media, overflow protection, and fixed breakpoint font sizes.';
        $lines[] = $hasVerifiedHeroImage
            ? '- [gate#visual_assets_safe] Image strategy: 保留已验证的 <img> and use only verified_asset_src_allowlist values supplied in context; keep slot/role trace intact.'
            : '- [gate#visual_assets_safe] visual.image：本区块没有验证后的图片素材；create the visual atmosphere with CSS, gradients, overlays, masks, borders, pseudo-elements, or SVG motifs instead of broken placeholder images. verified_asset_src_allowlist is empty.';
        $lines[] = '- [gate#theme_visible] Use confirmed palette hex tokens in CSS so theme identity is visible, readable, and not replaced with unrelated colors.';
        $lines[] = '- [gate#stage1_content_visible] Required facts, block goals, and page intent must become finished visitor copy or visible structured affordances, not hidden prompt metadata.';
        $lines[] = '- [gate#content_quality] Each block must have a clear information job, finished headings/body/proof/action copy, and no placeholder/internal labels.';
        $lines[] = '- [gate#visual_negative_prompt_coverage] Before returning, explicitly avoid common bad-output families: placeholder layout, template repetition, meaningless decoration, fake controls, fake facts, unsafe assets, unreadable contrast, cluttered hierarchy, and responsive breakage.';
        $lines[] = '- [gate#language_consistency] The final block must not mix planning language, source-language leftovers, or malformed fragments outside allowed brand/proper nouns.';
        $lines[] = '- [gate#render_data_quality] Returned html_content/css_extra/css_responsive/default_config must be internally consistent, editable, valid, and free of visible schema/task text.';
        $lines[] = '- [gate#shared_blocks_ready] The block must not duplicate header/footer/shared chrome or rely on sibling blocks to be understandable.';
        $lines[] = '- [self-check before return] Reject and rewrite if any gate above fails, especially empty controls, missing labels, weak hero scale, missing responsive CSS, unsafe image use, or unreadable contrast.';

        $themeLine = $this->renderThemePaletteLine($themePalette);
        if ($themeLine !== '') {
            $lines[] = $themeLine;
        }
        $factsLine = $this->renderMustIncludeFactsLine($brief);
        if ($factsLine !== '') {
            $lines[] = $factsLine;
        }
        $roleLine = $this->renderCurrentBlockRoleLine($brief);
        if ($roleLine !== '') {
            $lines[] = $roleLine;
        }
        $visualSignatureLine = $this->renderVisualSignatureLine($visualSignature);
        if ($visualSignatureLine !== '') {
            $lines[] = $visualSignatureLine;
        }
        $pageDesignLine = $this->renderPageDesignPlanLine($pageDesignPlan);
        if ($pageDesignLine !== '') {
            $lines[] = $pageDesignLine;
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $themePalette
     * @param array<string,mixed> $brief
     */
    public function renderSharedRegionVisualContract(
        string $region,
        array $themePalette,
        array $brief,
        string $contentLocale
    ): string {
        $lines = [];
        $lines[] = 'AI Shared ' . $region . ' Contract: generate one shared chrome component that supports the page style without taking over the content blocks.';
        $lines[] = '- Structure is binding: valid JSON/PHTML, no visible internal metadata, no duplicated page-section body.';
        $lines[] = '- Default style when the plan is silent: dark neon chess/casino chrome with restrained glow, readable contrast, and premium game-room polish.';
        $lines[] = '- Visible copy language: ' . ($contentLocale !== '' ? $contentLocale : 'use the workspace default locale') . '.';
        $lines[] = '- content_locale (HARD): ' . ($contentLocale !== '' ? $contentLocale : 'workspace default') . '.';
        $lines[] = '- Use var(--pb-font-display) and var(--pb-font-body); do not hardcode generic font stacks.';
        $themeLine = $this->renderThemePaletteLine($themePalette);
        if ($themeLine !== '') {
            $lines[] = $themeLine;
        }
        $brandWords = $this->collectBrandWords($brief);
        if ($brandWords !== []) {
            $lines[] = '- Brand words to preserve where natural: ' . \implode(', ', \array_slice($brandWords, 0, 4)) . '.';
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $visualSignature
     */
    private function renderVisualSignatureLine(array $visualSignature): string
    {
        $parts = [];
        foreach (['composition_pattern', 'spatial_rhythm', 'media_strategy', 'surface_treatment', 'interaction_pattern'] as $key) {
            $value = $this->compactPromptValue((string)($visualSignature[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts === []
            ? ''
            : '- visual_signature (HARD layout contract): ' . \implode('; ', $parts) . '. Gate compliance is not an excuse for template sameness.';
    }

    /**
     * @param array<string,mixed> $pageDesignPlan
     */
    private function renderPageDesignPlanLine(array $pageDesignPlan): string
    {
        $parts = [];
        foreach (['aesthetic_direction', 'composition_system', 'motion_language', 'image_style', 'interaction_style', 'anti_monotony_rule', 'composition_motif'] as $key) {
            $value = $this->compactPromptValue((string)($pageDesignPlan[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts === [] ? '' : '- page_design_plan (page-level design brief): ' . \implode('; ', $parts) . '.';
    }

    /**
     * @param array<string,mixed> $themePalette
     */
    private function renderThemePaletteLine(array $themePalette): string
    {
        $tokens = [];
        foreach ($themePalette as $key => $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '' && \preg_match('/^#[0-9a-f]{3,8}$/i', $value) === 1) {
                $tokens[] = (string)$key . '=' . $value;
            }
        }

        return $tokens === []
            ? '- 当前 scope 未提供 hex token；use safe readable fallback palette roles and do not invent unrelated accent colors.'
            : '- Palette tokens available: ' . \implode(', ', \array_slice($tokens, 0, 8)) . '.';
    }

    /**
     * @param array<string,mixed> $brief
     */
    private function renderCurrentBlockRoleLine(array $brief): string
    {
        $parts = [];
        foreach (['task_key', 'section_code', 'block_key', 'page_flow_role', 'block_goal', 'page_goal'] as $key) {
            $value = $this->compactPromptValue((string)($brief[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts === [] ? '' : '- Current block context: ' . \implode('; ', $parts) . '.';
    }

    /**
     * @param array<string,mixed> $brief
     */
    private function renderMustIncludeFactsLine(array $brief): string
    {
        $facts = [];
        foreach (\is_array($brief['must_include_facts'] ?? null) ? $brief['must_include_facts'] : [] as $fact) {
            if (\is_array($fact)) {
                $fact = $fact['text'] ?? $fact['value'] ?? '';
            }
            $fact = $this->compactPromptValue((string)$fact);
            if ($fact !== '') {
                $facts[] = $fact;
            }
        }

        return $facts === [] ? '' : '- Content facts to cover naturally: ' . \implode(' | ', \array_slice($facts, 0, 6)) . '.';
    }

    /**
     * @param array<string,mixed> $brief
     * @return list<string>
     */
    private function collectBrandWords(array $brief): array
    {
        $words = [];
        foreach (['brand_name', 'site_title', 'product_name', 'target_domain'] as $key) {
            $value = $this->compactPromptValue((string)($brief[$key] ?? ''));
            if ($value !== '') {
                $words[] = $value;
            }
        }

        return \array_values(\array_unique($words));
    }

    private function compactPromptValue(string $value): string
    {
        $value = \trim((string)\preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        return \mb_substr($value, 0, 220);
    }
}
