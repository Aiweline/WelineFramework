<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorWidgetContractsPass().
 */
final class VisitorWidgetContractsTest extends TestCase
{
    public function testAllVisitorWidgetContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'StorefrontPixelBootstrapWidget::testWidgetDeclaresRequiredHeaderPixelInjection' => function () {
            $this->assert_StorefrontPixelBootstrapWidget_testWidgetDeclaresRequiredHeaderPixelInjection();
        },
            'StorefrontPixelBootstrapWidget::testWidgetTemplateUsesEagerBootstrapService' => function () {
            $this->assert_StorefrontPixelBootstrapWidget_testWidgetTemplateUsesEagerBootstrapService();
        },
            'StorefrontPixelBootstrapWidget::testThemeAndHanfuHeadersDeclarePixelBootstrapSlot' => function () {
            $this->assert_StorefrontPixelBootstrapWidget_testThemeAndHanfuHeadersDeclarePixelBootstrapSlot();
        },
        ];
    }

private function assert_StorefrontPixelBootstrapWidget_testWidgetDeclaresRequiredHeaderPixelInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/frontend/widgets/storefront-pixel-bootstrap.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/frontend/widgets/storefront-pixel-bootstrap.phtml');
    self::assertStringContainsString('@widget.code {storefront-pixel-bootstrap}', $src);
    self::assertStringContainsString('@widget.slot {header-pixel-bootstrap}', $src);
    self::assertStringContainsString('"layout_type":"homepage"', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"slot":"header-pixel-bootstrap"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_StorefrontPixelBootstrapWidget_testWidgetTemplateUsesEagerBootstrapService(): void
{
    $tpl = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/frontend/widgets/storefront-pixel-bootstrap.phtml');
    $svc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelBootstrapHtmlService.php');
    self::assertStringContainsString("render(['eager' => true])", $tpl);
    // 视图编译器会在文件头插入 hash 块；真实 declare 语句会导致 E_COMPILE_ERROR。
    self::assertDoesNotMatchRegularExpression('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m', $tpl);
    self::assertStringContainsString('header-widget-eager', $svc);
    self::assertStringContainsString('__WelinePixelPending', $svc);
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $svc);
    // Api/Worker bust with Frontend deploy assetVersion — not sticky Pixel version.
    self::assertStringContainsString('resolveFrontendApiAssetVersion', $svc);
    self::assertStringContainsString('var/deploy/current.json', $svc);
    // Storefront with Frontend runtime must not dual-inject Api (scope-bootstrap loop).
    self::assertStringContainsString('hasFrontendRuntime', $svc);
    self::assertStringContainsString('weline-frontend-runtime-config', $svc);
    self::assertStringContainsString('hasApiScript', $svc);
    self::assertStringContainsString('!window.WelineApiConfig.workerUrl', $svc);
}

private function assert_StorefrontPixelBootstrapWidget_testThemeAndHanfuHeadersDeclarePixelBootstrapSlot(): void
{
    $theme = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/partials/header/default.phtml');
    $hanfu = (string) \file_get_contents('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/frontend/partials/header/default.phtml');
    foreach ([$theme, $hanfu] as $src) {
        self::assertStringContainsString('<w:slot id="header-pixel-bootstrap"', $src);
        self::assertStringContainsString('storefront-pixel-bootstrap', $src);
        self::assertStringContainsString('layout-header-pixel-bootstrap', $src);
    }
}
}
