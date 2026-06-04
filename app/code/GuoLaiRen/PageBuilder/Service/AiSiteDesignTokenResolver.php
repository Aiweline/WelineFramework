<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * Resolve site-level design tokens from plan_json.
 */
final class AiSiteDesignTokenResolver
{
    private const FOUNDATION_FONT = '"Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif';

    /**
     * @var array<string, string>
     */
    private const FOUNDATION_PALETTE_ROLE_MAP = [
        'primary' => '#1d4ed8',
        'secondary' => '#0f172a',
        'accent' => '#2563eb',
        'surface' => '#ffffff',
        'background' => '#f8fafc',
        'text' => '#0f172a',
        'body' => '#334155',
        'button' => '#1d4ed8',
        'surface_alt' => '#eef2ff',
        'muted_text' => '#64748b',
        'copy_panel_bg' => '#ffffff',
        'copy_panel_text' => '#0f172a',
        'cta_bg' => '#1d4ed8',
        'cta_text' => '#ffffff',
        'scrim' => '#0f172a',
        'shadow' => '#0f172a33',
    ];

    /**
     * @param array<string, mixed> $blueprintOrPlan
     * @return array<string, mixed>
     */
    public function resolveFromBlueprint(array $blueprintOrPlan): array
    {
        $themeDesign = \is_array($blueprintOrPlan['theme_design'] ?? null)
            ? $blueprintOrPlan['theme_design']
            : [];
        if ($themeDesign === [] && \is_array($blueprintOrPlan['plan_json']['theme_design'] ?? null)) {
            $themeDesign = $blueprintOrPlan['plan_json']['theme_design'];
        }

        $palette = \is_array($themeDesign['color_scheme'] ?? null)
            ? $themeDesign['color_scheme']
            : (\is_array($blueprintOrPlan['palette'] ?? null) ? $blueprintOrPlan['palette'] : []);
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null)
            ? $themeDesign['typography_spacing_radius']
            : [];

        $fontFamily = \trim((string)($typography['font_family'] ?? $blueprintOrPlan['theme_style']['font_family'] ?? ''));
        if ($fontFamily === '') {
            $fontFamily = self::FOUNDATION_FONT;
        }

        $roleMap = $this->buildPaletteRoleMap($palette);

        return [
            'font_display' => $fontFamily,
            'font_body' => $fontFamily,
            'radius' => \trim((string)($typography['radius_scale'] ?? '12px')) ?: '12px',
            'spacing' => \trim((string)($typography['spacing_scale'] ?? '24px')) ?: '24px',
            'palette_role_map' => $roleMap,
            'tone_of_voice' => \trim((string)($themeDesign['tone_of_voice'] ?? '专业、清晰、可信')),
            'cta_tone' => \trim((string)($themeDesign['cta_tone'] ?? '直接行动')),
        ];
    }

    /**
     * @param array<string, mixed> $palette
     * @return array<string, string>
     */
    private function buildPaletteRoleMap(array $palette): array
    {
        $map = self::FOUNDATION_PALETTE_ROLE_MAP;
        foreach ($map as $role => $defaultValue) {
            $value = \trim((string)($palette[$role] ?? ''));
            if ($value !== '') {
                $map[$role] = $value;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $tokens
     */
    public function buildRootCssVariables(array $tokens): string
    {
        $display = $this->cssEscape((string)($tokens['font_display'] ?? ''));
        $body = $this->cssEscape((string)($tokens['font_body'] ?? $display));
        $radius = $this->cssEscape((string)($tokens['radius'] ?? '12px'));
        $spacing = $this->cssEscape((string)($tokens['spacing'] ?? '24px'));

        $lines = [
            ':root{',
            '--pb-font-display:' . $display . ';',
            '--pb-font-body:' . $body . ';',
            '--pb-radius:' . $radius . ';',
            '--pb-spacing:' . $spacing . ';',
        ];

        $roles = \is_array($tokens['palette_role_map'] ?? null) ? $tokens['palette_role_map'] : [];
        foreach ($roles as $role => $hex) {
            $roleKey = \preg_replace('/[^a-z0-9_-]+/i', '-', (string)$role) ?? (string)$role;
            $lines[] = '--pb-color-' . $roleKey . ':' . $this->cssEscape((string)$hex) . ';';
        }

        $lines[] = '}';

        return \implode('', $lines);
    }

    private function cssEscape(string $value): string
    {
        return \str_replace(["\r", "\n", '"'], '', $value);
    }
}
