<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentPaletteRoleMapTest extends TestCase
{
    public function testPaletteRoleMapRepairsUnreadableTextRolesOnLightSurface(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'buildPaletteRoleMapFromThemePalette');
        $method->setAccessible(true);

        $roleMap = $method->invoke($service, [
            'primary' => '#1A2C38',
            'accent' => '#E0744B',
            'surface' => '#F7F8FA',
            'surface_alt' => '#F7F8FA',
            'text' => '#FFFFFF',
            'muted_text' => '#FFFFFF',
            'shadow' => '#FFFFFF',
        ], false);

        self::assertIsArray($roleMap);
        self::assertSame('#1A2C38', $roleMap['text'] ?? null);
        self::assertSame('#1A2C38', $roleMap['muted_text'] ?? null);
        self::assertSame('#1A2C38', $roleMap['shadow'] ?? null);
        self::assertSame('#1A2C38', $roleMap['copy_panel_text'] ?? null);
        self::assertNotSame('#FFFFFF', $roleMap['cta_text'] ?? null);
    }

    public function testThemePromptExposesReadableRoleMapBeforeRawPalette(): void
    {
        $service = new AiSitePageComponentGenerationService();
        $method = new \ReflectionMethod($service, 'buildThemeContractPromptAddon');
        $method->setAccessible(true);

        $prompt = $method->invoke($service, [
            'theme_design' => [
                'name' => 'Support operations',
                'visual_tone' => 'calm',
                'font_family' => 'var(--pb-font-body)',
                'style_signature' => 'support cards',
                'palette' => [
                    'primary' => '#1A2C38',
                    'accent' => '#E0744B',
                    'surface' => '#F7F8FA',
                    'surface_alt' => '#FFFFFF',
                    'text' => '#FFFFFF',
                    'muted_text' => '#FFFFFF',
                ],
            ],
        ]);

        self::assertIsString($prompt);
        self::assertStringContainsString('readable_palette_role_map', $prompt);
        self::assertStringContainsString('cta_bg=#E0744B', $prompt);
        self::assertStringContainsString('cta_text=#1A2C38', $prompt);
        self::assertStringContainsString('HARD palette role execution', $prompt);

        $roleMapPosition = \strpos($prompt, 'readable_palette_role_map');
        $palettePosition = \strpos($prompt, '- palette:');
        self::assertNotFalse($roleMapPosition);
        self::assertNotFalse($palettePosition);
        self::assertLessThan($palettePosition, $roleMapPosition);
    }
}
