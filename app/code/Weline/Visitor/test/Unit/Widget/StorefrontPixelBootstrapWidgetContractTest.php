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
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;
        self::assertArrayHasKey('storefront-pixel-bootstrap', $widgets);
        $widget = $widgets['storefront-pixel-bootstrap'];
        self::assertSame('storefront-pixel-bootstrap', $widget['code'] ?? null);
        self::assertSame('header', $widget['type'] ?? null);
        self::assertSame('header-pixel-bootstrap', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Visitor::templates/frontend/widgets/storefront-pixel-bootstrap.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('header-pixel-bootstrap', $injection['slot'] ?? null);
        self::assertSame('header', $injection['area'] ?? null);
        self::assertTrue(!empty($injection['required']));
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
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20260919-sticky-bus1'", $svc);
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
