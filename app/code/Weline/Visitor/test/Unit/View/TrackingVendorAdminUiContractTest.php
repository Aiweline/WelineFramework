<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 正式事件供应商页（变体2）UI 契约：成品层次，非原型克隆。
 */
final class TrackingVendorAdminUiContractTest extends TestCase
{
    private function template(): string
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml';
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }

    public function testFormalPageHasToolbarWithoutDuplicatePageH1(): void
    {
        $src = $this->template();
        self::assertStringContainsString('data-testid="tracking-vendor-admin"', $src);
        self::assertStringContainsString('data-variant-winner="2"', $src);
        // 后台壳已有页标题；内容区用工具条，禁止再叠一层「事件供应商」h1
        self::assertStringNotContainsString('<h1 class="h4"', $src);
        self::assertStringContainsString('data-testid="tv-toolbar"', $src);
        self::assertStringContainsString('tv-sync', $src);
        self::assertStringContainsString('tv-open-create', $src);
    }

    public function testModeHintLivesInsideConfigPanelNotAboveTabs(): void
    {
        $src = $this->template();
        $tabsPos = \strpos($src, 'id="tv-tabs"');
        $hintPos = \strpos($src, 'tvp-mode-hint');
        $configPos = \strpos($src, 'data-panel="config"');
        self::assertNotFalse($tabsPos);
        self::assertNotFalse($hintPos);
        self::assertNotFalse($configPos);
        self::assertGreaterThan($tabsPos, $hintPos, '模式说明须在 Tab 之后');
        self::assertGreaterThan($configPos, $hintPos - 1);
        self::assertLessThan($hintPos, $configPos, '模式说明须落在 config 面板内');
    }

    public function testEnableControlIsSingleLabelCheckbox(): void
    {
        $src = $this->template();
        self::assertStringContainsString('data-testid="tv-enable"', $src);
        self::assertStringContainsString('系统像素默认启用且不可关闭', $src);
        self::assertStringContainsString('data-locked="1"', $src);
        self::assertStringContainsString('class="tvp-check"', $src);
        // 禁止「启用」外层 label + 「启用此供应商」内层双标签叠层
        self::assertDoesNotMatchRegularExpression(
            '/<label>\s*<lang>启用<\/lang>\s*<\/label>\s*<label>\s*<input[^>]*name="enabled"/',
            $src
        );
    }

    public function testWorkScopeToolbarUsesOfficialScopeTaglib(): void
    {
        $src = $this->template();
        self::assertStringContainsString('data-testid="tv-topbar"', $src);
        self::assertStringContainsString("fetch('Weline_Visitor::templates/Backend/TrackingVendor/partials/scope-toolbar.phtml')", $src);
        self::assertStringContainsString('data-tab="scope"', $src);
        self::assertStringContainsString('路径过滤', $src);
        self::assertStringContainsString('配置范围', $src);
        self::assertStringContainsString('配置范围是继承制度', $src);
        self::assertStringContainsString('路径过滤不是网站/店铺/渠道范围', $src);
        self::assertStringContainsString('tv-config-scope-summary', $src);

        $toolbar = \dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/partials/scope-toolbar.phtml';
        self::assertFileExists($toolbar);
        $tb = (string)\file_get_contents($toolbar);
        self::assertStringContainsString('<w:scope', $tb);
        self::assertStringContainsString('tracking-vendor-work-scope', $tb);
        self::assertStringContainsString('data-align="start"', $tb);
        self::assertStringContainsString('继承：渠道 ← 店铺 ← 网站', $tb);
        self::assertStringContainsString('tv-work-scope-inherit', $tb);

        $js = \dirname(__DIR__, 3) . '/view/statics/js/backend/tracking-vendor-work-scope.js';
        self::assertFileExists($js);
        $jsBody = (string)\file_get_contents($js);
        self::assertStringContainsString("getElementById('tracking-vendor-work-scope')", $jsBody);
        self::assertStringContainsString('target_scope', $jsBody);

        $css = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/css/tracking-vendor-admin.css');
        self::assertStringContainsString('.tvp-topbar', $css);
        self::assertStringContainsString('justify-content: space-between', $css);
    }

    public function testGa4AndGtmVendorsEmbedUnifiedSystemConfig(): void
    {
        $src = $this->template();
        self::assertStringContainsString('data-testid="tv-system-config-embed"', $src);
        self::assertStringContainsString('<w:config:embed', $src);
        self::assertStringContainsString('module="Weline_Visitor"', $src);
        self::assertStringContainsString('group="visitor_ga4"', $src);
        self::assertStringContainsString('group="visitor_gtm"', $src);
        self::assertStringContainsString('data-testid="tv-open-system-config"', $src);
        self::assertStringContainsString('data-testid="tv-cred-legacy-hint"', $src);
        self::assertStringContainsString('weline_systemconfig/backend/config', $src);
        // 禁止在 w:config:embed 属性内插 PHP 开标签
        self::assertDoesNotMatchRegularExpression(
            '/<w:config:embed[^>]*(<\?=|<\?php)/',
            $src
        );
    }
}
