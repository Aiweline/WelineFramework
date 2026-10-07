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

    public function testStorefrontHeadAssetsPrefixIncludesFoucBootstrapAndCriticalTag(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/assets-prefix.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('css-fouc-bootstrap.phtml', $src);
        self::assertStringContainsString('theme:layout-critical', $src);
        self::assertStringContainsString('weline-theme-prepaint.js', $src);
    }

    public function testStorefrontHeadAssetsSuffixMarksLayoutCssAndCssReady(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/assets-suffix.phtml';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('data-weline-layout-css', $src);
        self::assertStringContainsString('weline-css-ready.js', $src);
    }

    public function testCssReadyScriptSetsPendingAndFailOpen(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/ui/js/weline-css-ready.js';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("dataset.welineCss", $src);
        self::assertStringContainsString('data-weline-layout-css', $src);
        self::assertStringContainsString('1800', $src);
    }
}
