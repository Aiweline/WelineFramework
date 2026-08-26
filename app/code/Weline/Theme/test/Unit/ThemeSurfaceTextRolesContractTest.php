<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class ThemeSurfaceTextRolesContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testQuietButtonFollowsSurfaceForegroundToken(): void
    {
        $foundation = $this->read('app/code/Weline/Theme/view/ui/css/foundation.css');
        self::assertMatchesRegularExpression(
            '/\.w-button\[data-tone="quiet"\]\s*\{[^}]*color:\s*var\(--w-surface-fg,\s*var\(--weline-theme-text\)\)/s',
            $foundation
        );
    }

    public function testInverseHeaderLanguageAndCurrencyTriggersUseThemeLightTokens(): void
    {
        $themeCss = $this->read('app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css');
        self::assertStringContainsString('.w-language-switcher__trigger.w-button', $themeCss);
        self::assertStringContainsString('.w-currency-switcher__trigger.w-button', $themeCss);
        self::assertStringContainsString('--weline-theme-header-text', $themeCss);
        self::assertStringContainsString('--weline-theme-text-on-dark', $themeCss);
        self::assertStringContainsString('[data-surface="inverse"] .w-language-switcher__trigger.w-button', $themeCss);
        self::assertStringContainsString('[data-surface="inverse"] .w-currency-switcher__trigger.w-button', $themeCss);
    }

    public function testMegaMenuIsRegisteredAsLazyUiComponent(): void
    {
        $ui = $this->read('app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertStringContainsString("['mega-menu', './components/weline-mega-menu.js']", $ui);
        self::assertStringContainsString("['mega-menu', './components/weline-mega-menu.css']", $ui);

        $assets = $this->read('app/code/Weline/Theme/etc/weline-ui-assets.json');
        self::assertStringContainsString('"mega-menu-js"', $assets);
        self::assertStringContainsString('"mega-menu-css"', $assets);
        self::assertStringContainsString('js/components/mega-menu.js', $assets);

        self::assertFileExists(BP . 'app/code/Weline/Theme/view/ui/js/components/mega-menu.js');
        self::assertFileExists(BP . 'app/code/Weline/Theme/view/ui/css/components/mega-menu.css');
        self::assertFileExists(BP . 'app/code/Weline/Theme/doc/widgets/mega-menu.md');

        $panel = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/mega-menu-panel.phtml');
        self::assertStringContainsString('data-w-component="mega-menu"', $panel);
        self::assertStringContainsString('w-mega-menu', $panel);
    }

    public function testMegaMenuTopChromeUsesNavSecondaryBackground(): void
    {
        $header = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml');
        self::assertMatchesRegularExpression(
            '/\.header-category-panel\.is-megamenu\s*\{[^}]*background:\s*var\(--weline-chrome-bg-dark-secondary/s',
            $header
        );
        self::assertMatchesRegularExpression(
            '/\.mega-menu-sidebar\s*\{[^}]*background:\s*var\(--weline-chrome-bg-dark-secondary/s',
            $header
        );
    }

    public function testSurfaceTextRolesDocCoversLanguageCurrencyTriggers(): void
    {
        $doc = $this->read('app/code/Weline/Theme/doc/theme-surface-text-roles.md');
        self::assertStringContainsString('.w-language-switcher__trigger', $doc);
        self::assertStringContainsString('.w-currency-switcher__trigger', $doc);
        self::assertStringContainsString('--w-surface-fg', $doc);
    }

    public function testFooterLocaleDeclaresInverseSurfaceForLanguageCurrencyTriggers(): void
    {
        $footer = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/footer/default.phtml');
        self::assertStringContainsString('class="footer-locale w-surface-inverse"', $footer);
        self::assertStringContainsString('data-surface="inverse"', $footer);

        $footerChrome = $this->read('app/code/Weline/Theme/view/statics/css/widgets/footer-chrome-amazon.css');
        self::assertStringContainsString('.weline-footer .footer-locale .w-language-switcher__trigger', $footerChrome);
        self::assertStringContainsString('.weline-footer .footer-locale .w-currency-switcher__trigger', $footerChrome);
    }
}
