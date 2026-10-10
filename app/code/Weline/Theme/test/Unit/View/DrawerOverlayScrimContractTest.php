<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Admin OffCanvas/drawer scrim must follow backend overlay tokens (solid, no blur).
 */
final class DrawerOverlayScrimContractTest extends TestCase
{
    public function testBackendAreaBridgesOverlayTokenWithoutBlurScrim(): void
    {
        $foundation = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/ui/weline-foundation.css',
        );
        $backendTheme = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/backend/assets/css/theme.css',
        );
        $ink = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/backend/colors/_ink.css',
        );

        self::assertStringContainsString(
            '--weline-theme-overlay: var(--backend-color-bg-overlay',
            $foundation,
        );
        self::assertMatchesRegularExpression(
            '/\.w-overlay\s*\{[^}]*background:\s*var\(--weline-theme-overlay\)/s',
            $foundation,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.w-overlay\s*\{[^}]*backdrop-filter/s',
            $foundation,
        );
        self::assertStringContainsString(
            '--weline-theme-overlay: var(--backend-theme-overlay);',
            $backendTheme,
        );
        self::assertStringContainsString(
            '--backend-color-bg-overlay: rgba(12, 12, 12, 0.985);',
            $ink,
        );
        self::assertStringNotContainsString(
            '--backend-color-bg-overlay: rgba(28, 28, 28, 0.48);',
            $ink,
        );
    }
}
