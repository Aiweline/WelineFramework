<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * Storefront pixel must be a required header default_injection (eager bootstrap).
 */
final class StorefrontPixelBootstrapWidgetContractTest extends TestCase
{
    public function testWidgetDeclaresRequiredHeaderPixelInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        $tpl = 'Weline_Visitor::templates/frontend/widgets/storefront-pixel-bootstrap.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/storefront-pixel-bootstrap.phtml');
        self::assertStringContainsString('@widget.code {storefront-pixel-bootstrap}', $src);
        self::assertStringContainsString('@widget.slot {header-pixel-bootstrap}', $src);
        self::assertStringContainsString('"layout_type":"homepage"', $src);
        self::assertStringContainsString('"layout_type":"product"', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"header-pixel-bootstrap"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testWidgetTemplateUsesEagerBootstrapService(): void
    {
        $tpl = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/frontend/widgets/storefront-pixel-bootstrap.phtml'
        );
        $svc = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Service/PixelBootstrapHtmlService.php'
        );
        self::assertStringContainsString("render(['eager' => true])", $tpl);
        // 视图编译器会在文件头插入 hash 块；真实 declare 语句会导致 E_COMPILE_ERROR。
        self::assertDoesNotMatchRegularExpression('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m', $tpl);
        self::assertStringContainsString('header-widget-eager', $svc);
        self::assertStringContainsString('__WelinePixelPending', $svc);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $svc);
        self::assertStringContainsString("@\\filemtime(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js')", $svc);
        self::assertStringContainsString("@\\filemtime(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-worker.js')", $svc);
    }

    public function testThemeAndHanfuHeadersDeclarePixelBootstrapSlot(): void
    {
        $theme = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/partials/header/default.phtml'
        );
        $hanfu = (string) \file_get_contents(
            \dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/partials/header/default.phtml'
        );
        foreach ([$theme, $hanfu] as $src) {
            self::assertStringContainsString('<w:slot id="header-pixel-bootstrap"', $src);
            self::assertStringContainsString('storefront-pixel-bootstrap', $src);
            self::assertStringContainsString('layout-header-pixel-bootstrap', $src);
        }
    }
}
