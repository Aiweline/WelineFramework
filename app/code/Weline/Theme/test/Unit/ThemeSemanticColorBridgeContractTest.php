<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

/**
 * Semantic color matrix: palette → Foundation → component tone bridge.
 */
class ThemeSemanticColorBridgeContractTest extends TestCase
{
    public function testRootStatusTokensBridgeFromPaletteColorVars(): void
    {
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');

        self::assertStringContainsString('--weline-theme-success: var(--color-success,', $foundation);
        self::assertStringContainsString('--weline-theme-warning: var(--color-warning,', $foundation);
        self::assertStringContainsString('--weline-theme-danger: var(--color-danger,', $foundation);
        self::assertStringContainsString('--weline-theme-info: var(--color-info,', $foundation);
        self::assertStringContainsString('--weline-theme-secondary: var(--color-secondary,', $foundation);
        self::assertStringContainsString('--weline-theme-success-surface: var(--color-success-bg-subtle,', $foundation);
        self::assertStringContainsString('--weline-theme-warning-surface: var(--color-warning-bg-subtle,', $foundation);
        self::assertStringContainsString('--weline-theme-danger-surface: var(--color-danger-bg-subtle,', $foundation);
        self::assertStringContainsString('--weline-theme-info-surface: var(--color-info-bg-subtle,', $foundation);
        self::assertStringContainsString('--weline-theme-secondary-surface: var(--color-secondary-bg-subtle,', $foundation);
    }

    public function testBackendAreaBridgesStatusAndSecondaryFromBackendPalette(): void
    {
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        $backendBlock = $this->extractBackendAreaBlock($foundation);

        foreach ([
            '--weline-theme-success: var(--backend-color-success,',
            '--weline-theme-warning: var(--backend-color-warning,',
            '--weline-theme-danger: var(--backend-color-danger,',
            '--weline-theme-info: var(--backend-color-info,',
            '--weline-theme-secondary: var(--backend-color-secondary,',
            '--weline-theme-success-surface: var(--backend-color-success-bg-subtle,',
            '--weline-theme-warning-surface: var(--backend-color-warning-bg-subtle,',
            '--weline-theme-danger-surface: var(--backend-color-danger-bg-subtle,',
            '--weline-theme-info-surface: var(--backend-color-info-bg-subtle,',
            '--weline-theme-secondary-surface: var(--backend-color-secondary-bg-subtle,',
            '--weline-theme-primary-surface: var(--backend-color-primary-bg-subtle,',
        ] as $needle) {
            self::assertStringContainsString($needle, $backendBlock, $needle);
        }
    }

    public function testSecondaryToneIsFirstClassOnSharedComponents(): void
    {
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');

        self::assertStringContainsString('.w-button[data-tone="secondary"]', $foundation);
        self::assertStringContainsString('.w-button[data-variant="outline"][data-tone="secondary"]', $foundation);
        self::assertStringContainsString('.w-button[data-variant="soft"][data-tone="secondary"]', $foundation);
        self::assertStringContainsString('.w-badge[data-tone="secondary"]', $foundation);
        self::assertStringContainsString('.w-text[data-tone="secondary"]', $foundation);
        self::assertStringContainsString('.w-alert[data-tone="secondary"]', $foundation);
        self::assertStringContainsString('[data-w-background="secondary"]', $foundation);
    }

    public function testLightPalettesUseSoftDefaultBorders(): void
    {
        $backendLight = $this->read('app/code/Weline/Theme/view/theme/backend/colors/_light.css');
        $frontendLight = $this->read('app/code/Weline/Theme/view/theme/frontend/colors/_light.css');

        self::assertStringContainsString('--backend-color-border-default: #e2e8f0;', $backendLight);
        self::assertStringNotContainsString('--backend-color-border-default: #64748b;', $backendLight);
        self::assertStringContainsString('--color-border-default: #e2e8f0;', $frontendLight);
        self::assertStringNotContainsString('--color-border-default: #64748b;', $frontendLight);
        self::assertStringContainsString('--color-border-strong: #94a3b8;', $frontendLight);
    }

    public function testDefaultPalettesExposeInheritableSemanticMatrix(): void
    {
        $frontendDefault = $this->read('app/code/Weline/Theme/view/theme/frontend/colors/_default.css');
        $frontendColors = $this->read('app/code/Weline/Theme/view/theme/frontend/variables/_colors.css');
        $backendDefault = $this->read('app/code/Weline/Theme/view/theme/backend/colors/_default.css');

        foreach ([
            '--color-secondary:',
            '--color-secondary-hover:',
            '--color-secondary-bg-subtle:',
            '--color-on-secondary:',
            '--color-success-hover:',
            '--color-success-bg-subtle:',
            '--color-danger:',
            '--color-danger-bg-subtle:',
            '--color-warning-border-subtle:',
            '--color-info-text-emphasis:',
            '--color-text:',
            '--color-text-subtle:',
            '--color-surface:',
            '--color-surface-subtle:',
            '--color-border-subtle:',
            '--color-border-strong:',
        ] as $needle) {
            self::assertStringContainsString($needle, $frontendDefault, 'frontend _default: ' . $needle);
            self::assertStringContainsString($needle, $frontendColors, 'frontend _colors: ' . $needle);
        }

        foreach ([
            '--backend-color-secondary:',
            '--backend-color-secondary-bg-subtle:',
            '--backend-color-on-secondary:',
            '--backend-color-success-active:',
            '--backend-color-danger-border-subtle:',
            '--backend-color-warning-text-emphasis:',
            '--backend-color-info-bg-subtle:',
            '--backend-color-on-primary:',
            '--backend-color-surface:',
            '--backend-color-border-subtle:',
            '--backend-color-border-strong:',
        ] as $needle) {
            self::assertStringContainsString($needle, $backendDefault, 'backend _default: ' . $needle);
        }
    }

    public function testHardConstraintsForbidPrivateColorsOnBaseComponents(): void
    {
        $catalog = $this->read('app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php');
        self::assertStringContainsString("'id' => 'theme_base_components_token_only'", $catalog);
        self::assertStringContainsString('basic/foundation components', $catalog);
    }

    private function extractBackendAreaBlock(string $foundation): string
    {
        $start = strpos($foundation, '[data-w-area="backend"]');
        self::assertNotFalse($start, 'backend area selector must exist');
        $open = strpos($foundation, '{', $start);
        self::assertNotFalse($open);
        $depth = 0;
        $len = strlen($foundation);
        for ($i = $open; $i < $len; $i++) {
            $ch = $foundation[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($foundation, $start, $i - $start + 1);
                }
            }
        }
        self::fail('backend area block was not closed');
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
