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
        $lines[] = 'AI block design guidance: generate one complete visitor-facing PageBuilder block from the current BuildPlan block.';
        $lines[] = '- Structure is binding: return valid JSON, valid HTML/PHTML, css_extra, default_config, semantic root classes, responsive CSS, and no visible internal metadata.';
        $lines[] = '- Creative fields are open: headings, copy, layout motifs, image treatment, and micro-interactions should follow the BuildPlan intent without being policed by fixed keyword gates.';
        $lines[] = '- Default style direction when the BuildPlan is silent: dark neon chess/casino atmosphere, luminous card-table surfaces, sharp contrast, electric cyan/magenta/gold accents, polished game-floor tension.';
        $lines[] = '- Use the current block role exactly. Do not reuse another block role, CTA pattern, image, or card layout simply to satisfy a generic template.';
        $lines[] = '- Visible copy language: ' . ($contentLocale !== '' ? $contentLocale : 'use the workspace default locale') . '. Brand names, product names, URLs, API/APK, and proper nouns may stay as-is.';
        $lines[] = '- Typography: use design-token fonts through var(--pb-font-display) and var(--pb-font-body); avoid hardcoded generic stacks.';
        $lines[] = '- Layout quality: choose a composition that fits this block role. Prefer distinctive section-specific structure over repeated title/paragraph/button/card-grid shells.';
        $lines[] = '- Responsive quality: include breakpoint CSS for mobile stacking, min-width:0 on flex/grid children, max-width:100% media, overflow protection, and fixed breakpoint font sizes.';
        $lines[] = $hasVerifiedHeroImage
            ? '- Image strategy: use the verified image URL supplied in context and keep its slot/role trace intact.'
            : '- Image strategy: if no verified image is supplied, create the visual atmosphere with CSS, gradients, overlays, masks, borders, pseudo-elements, or SVG motifs instead of broken placeholder images.';

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
        $lines[] = 'AI shared ' . $region . ' design guidance: generate one shared chrome component that supports the page style without taking over the content blocks.';
        $lines[] = '- Structure is binding: valid JSON/PHTML, no visible internal metadata, no duplicated page-section body.';
        $lines[] = '- Default style when the plan is silent: dark neon chess/casino chrome with restrained glow, readable contrast, and premium game-room polish.';
        $lines[] = '- Visible copy language: ' . ($contentLocale !== '' ? $contentLocale : 'use the workspace default locale') . '.';
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

        return $parts === [] ? '' : '- BuildPlan visual signature: ' . \implode('; ', $parts) . '.';
    }

    /**
     * @param array<string,mixed> $pageDesignPlan
     */
    private function renderPageDesignPlanLine(array $pageDesignPlan): string
    {
        $parts = [];
        foreach (['aesthetic_direction', 'composition_system', 'motion_language', 'image_style', 'interaction_style'] as $key) {
            $value = $this->compactPromptValue((string)($pageDesignPlan[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts === [] ? '' : '- Page design direction: ' . \implode('; ', $parts) . '.';
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

        return $tokens === [] ? '' : '- Palette tokens available: ' . \implode(', ', \array_slice($tokens, 0, 8)) . '.';
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
