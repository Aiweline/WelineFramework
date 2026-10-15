<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Theme\LayoutCriticalCssService;
use Weline\Theme\Taglib\ThemeLayoutCritical;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class LayoutCriticalCssContractTest extends TestCase
{
    public function testLayoutCriticalTagNameAndAreaAttr(): void
    {
        self::assertSame('theme:layout-critical', ThemeLayoutCritical::name());
        self::assertArrayHasKey('area', ThemeLayoutCritical::attr());
    }

    public function testBaselineFrontendCriticalCssInlinesShellRules(): void
    {
        $html = (new LayoutCriticalCssService())->renderInlineStyle('frontend');
        self::assertStringContainsString('data-weline-layout-critical', $html);
        self::assertStringContainsString('.w-frontend-shell', $html);
        self::assertStringContainsString('.weline-page-wrapper', $html);
    }

    public function testBaselineBackendCriticalCssIncludesNarrowShellBreakpoint(): void
    {
        $html = (new LayoutCriticalCssService())->renderInlineStyle('backend');
        self::assertStringContainsString('data-weline-layout-critical', $html);
        self::assertStringContainsString('.w-backend-shell', $html);
        self::assertStringContainsString('@media (max-width: 63.99rem)', $html);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $html);
        self::assertStringContainsString('grid-template-areas: "topbar" "main"', $html);
        // Unlayered critical must open with drawer data-state (cannot rely on @layer alone).
        self::assertStringContainsString('.w-backend-sidebar[data-state="open"]', $html);
        self::assertStringContainsString('--weline-z-overlay', $html);
        self::assertStringContainsString('@media (max-width: 47.99rem)', $html);
        self::assertStringContainsString('.w-backend-topbar__center', $html);
        self::assertStringContainsString('.w-language-switcher__current', $html);
        self::assertStringContainsString('@media (min-width: 64rem)', $html);
        self::assertStringContainsString('--backend-theme-sidebar-collapsed-width', $html);
        self::assertStringContainsString('scrollbar-gutter: stable both-edges', $html);
        self::assertStringContainsString('padding-inline-start', $html);
        self::assertStringContainsString('padding-inline-end', $html);
    }

    public function testStorefrontHeadAssetsPrefixIsCssOnlyAmazonStyle(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/assets-prefix.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('css-fouc-bootstrap.phtml', $src);
        self::assertStringContainsString('theme:layout-critical', $src);
        self::assertStringNotContainsString('weline-theme-prepaint.js', $src);
        self::assertStringNotContainsString('ThemeHeadChromeJsPack', $src);
        self::assertStringNotContainsString('<script', $src);
    }

    public function testMinimalHeadIncludesCriticalWithoutHardStyleLeafOrThemeScript(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/minimal.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('css-fouc-bootstrap.phtml', $src);
        self::assertStringContainsString('theme:layout-critical', $src);
        // FOUC waits on critical inline; theme pack must not carry data-weline-layout-css.
        self::assertStringNotContainsString('data-weline-layout-css', $src);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $src);
        self::assertStringNotContainsString('weline-theme-prepaint.js', $src);
        self::assertStringNotContainsString('weline-ui.js', $src);
        self::assertStringNotContainsString('<script', $src);
    }

    public function testStorefrontHeadAssetsSuffixHasNoCssReadyScript(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/assets-suffix.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('data-weline-layout-css', $src);
        self::assertStringNotContainsString('weline-css-ready.js', $src);
        self::assertStringNotContainsString('<script', $src);
        self::assertStringContainsString('module-declarations', $src);
    }

    public function testCssReadyScriptSetsPendingAndFailOpen(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/ui/js/weline-css-ready.js';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("dataset.welineCss", $src);
        self::assertStringContainsString('data-weline-layout-css', $src);
        self::assertStringContainsString('1800', $src);
    }

    public function testAmazonFoucBootstrapHasNoHeadJsOrBodyHide(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/templates/partials/head/css-fouc-bootstrap.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('amazon-progressive', $src);
        self::assertStringNotContainsString('<script', $src);
        self::assertStringNotContainsString('visibility:hidden', $src);
        self::assertStringNotContainsString('data-weline-css="pending"', $src);
    }

    public function testPartialsEnsuresCssReadyWhenFoucPendingWithoutUnlock(): void
    {
        $src = (string) file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');
        self::assertStringContainsString('ensureStorefrontHeadCssReadyGate', $src);
        self::assertStringContainsString('templates/partials/head/css-ready.phtml', $src);
        self::assertStringContainsString('data-weline-theme-head-pack="after-css"', $src);
        $ready = (string) file_get_contents(BP . 'app/code/Weline/Theme/view/templates/partials/head/css-ready.phtml');
        self::assertStringContainsString('weline-css-ready.js', $ready);
    }

    public function testBodyEndDeclaresThemeChromeModules(): void
    {
        $body = (string) file_get_contents(
            BP . 'app/code/Weline/Theme/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        self::assertStringContainsString('data-weline-load="themePrepaint,welineUi"', $body);
        $modules = (string) file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/frontend/weline.modules.js'
        );
        self::assertStringContainsString('themePrepaint:', $modules);
        self::assertStringContainsString('welineUi:', $modules);
        self::assertStringContainsString('Weline_Theme::ui/weline-theme-prepaint.js', $modules);
        self::assertStringContainsString('Weline_Theme::ui/weline-ui.js', $modules);
    }
}
