<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: 仓映射页变体 A（货源轨 + 行内绑定 + 翻页）。
 */
final class WarehouseIndexTemplateContractTest extends TestCase
{
    public function testWarehouseIndexUsesProviderCoverageBoardVariantA(): void
    {
        $root = dirname(__DIR__, 3);
        $path = $root . '/view/templates/Backend/Warehouse/index.phtml';
        self::assertFileExists($path);
        $tpl = (string)file_get_contents($path);

        self::assertStringContainsString('data-variant="A"', $tpl);
        self::assertStringContainsString('data-testid="dropship-wh-rail"', $tpl);
        self::assertStringContainsString('--weline-theme-primary-surface', $tpl);
        self::assertStringContainsString('a[aria-current="true"]', $tpl);
        self::assertStringContainsString('background:var(--weline-theme-primary)', $tpl);
        self::assertStringContainsString('color:var(--weline-theme-on-primary)', $tpl);
        self::assertStringNotContainsString('rgba(255,255,255,.08)', $tpl);
        self::assertStringContainsString('data-testid="dropship-wh-coverage"', $tpl);
        self::assertStringContainsString('data-testid="dropship-wh-stats"', $tpl);
        self::assertStringContainsString('data-testid="dropship-wh-pagination"', $tpl);
        self::assertStringContainsString('w-pagination', $tpl);
        self::assertStringContainsString('远程履约覆盖', $tpl);
        self::assertStringContainsString('万能货源中心', $tpl);
        self::assertStringContainsString('按国家自动配对', $tpl);
        self::assertStringContainsString('拉取远程仓', $tpl);
        self::assertStringContainsString('w:scope', $tpl);
        self::assertStringContainsString('w:inventory:warehouse:select', $tpl);
        self::assertStringContainsString('options-json', $tpl);
        self::assertStringContainsString('whOptionsJson', $tpl);
        self::assertStringContainsString('localIdValue', $tpl);
        self::assertStringContainsString('data-bind-row', $tpl);
        self::assertStringContainsString('data-save-url', $tpl);
        self::assertStringContainsString('Weline.UI.toast', $tpl);
        self::assertStringNotContainsString('data-testid="dropship-wh-bind-panel"', $tpl);
        self::assertStringNotContainsString('保存映射', $tpl);
        self::assertStringNotContainsString('w:theme:address', $tpl);
        self::assertStringNotContainsString('w:dropship:remote-warehouse:select', $tpl);
        self::assertStringNotContainsString('window.alert(', $tpl);
        self::assertStringNotContainsString('已有映射', $tpl);
        self::assertStringNotContainsString('data-testid="dropship-wh-table"', $tpl);
    }

    public function testWarehouseControllerBuildsCoverageBoard(): void
    {
        $root = dirname(__DIR__, 3);
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/Warehouse.php');

        self::assertStringContainsString('coverageBoard', $ctrl);
        self::assertStringContainsString('providerSummaries', $ctrl);
        self::assertStringContainsString('selected_provider', $ctrl);
        self::assertStringContainsString('coverage_ready', $ctrl);
        self::assertStringContainsString('货源履约仓对接', $ctrl);
        self::assertStringContainsString('postSyncCountryPairs', $ctrl);
        self::assertStringContainsString('resolveWorkScope', $ctrl);
        self::assertStringContainsString('resolveMapStoreId', $ctrl);
        self::assertStringContainsString('default.__website__.default', $ctrl);
        self::assertStringContainsString('websiteId === null', $ctrl);
        self::assertStringContainsString('page_size', $ctrl);
        self::assertStringContainsString('wantsJsonResponse', $ctrl);
        self::assertStringContainsString('assign(\'pagination\'', $ctrl);
        self::assertStringNotContainsString('assign(\'maps\'', $ctrl);
    }
}
