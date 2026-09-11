<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 事件供应商管理 UI 原型契约：控制器与模板须覆盖双来源、三变体、DEV 不上报。
 */
final class TrackingVendorPrototypeContractTest extends TestCase
{
    public function testControllerExposesPrototypeRouteAndMockVendors(): void
    {
        $path = \dirname(__DIR__, 4) . '/Controller/Backend/TrackingVendorPrototype.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('TrackingVendorPrototype', $src);
        self::assertStringContainsString('PROTOTYPE ONLY', $src);
        self::assertStringContainsString("'source' => 'module'", $src);
        self::assertStringContainsString("'source' => 'custom'", $src);
        self::assertStringContainsString("'mode' => 'sandbox'", $src);
        self::assertStringContainsString('dev_no_report', $src);
        self::assertStringContainsString('ga4', $src);
        self::assertStringContainsString('gtm', $src);
        self::assertStringContainsString('custom_demo', $src);
        self::assertStringContainsString('event_map', $src);
        self::assertStringContainsString('sandbox_js', $src);
    }

    public function testPrototypeTemplateHasThreeVariantsAndDevBanner(): void
    {
        $path = \dirname(__DIR__, 4) . '/view/templates/Backend/TrackingVendorPrototype/index.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('data-testid="tracking-vendor-prototype"', $src);
        self::assertStringContainsString('data-variant-panel="1"', $src);
        self::assertStringContainsString('data-variant-panel="2"', $src);
        self::assertStringContainsString('data-variant-panel="3"', $src);
        self::assertStringContainsString('tvp-dev-banner', $src);
        self::assertStringContainsString('DEV：不上报', $src);
        self::assertStringContainsString('tvp-fold', $src);
        self::assertStringContainsString('tvp-v2-item', $src);
        self::assertStringContainsString('tvp-state-fold', $src);
        self::assertStringContainsString('tvp-variant-switcher', $src);
        self::assertStringContainsString('事件搭接', $src);
        self::assertStringContainsString('双 inject', $src);
        self::assertStringContainsString('source-module', $src);
        self::assertStringContainsString('source-custom', $src);
    }

    public function testPrototypeCssUsesThemeTokensNotInventedPalette(): void
    {
        $path = \dirname(__DIR__, 4) . '/view/statics/css/tracking-vendor-prototype.css';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('--weline-', $src);
        self::assertStringNotContainsString('#F4F1EA', $src);
        self::assertStringNotContainsString('#D97757', $src);
    }
}
