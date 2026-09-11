<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: 仓与国家映射须对齐货源平台 w-* 后台，禁止 Bootstrap 脚手架与裸 ID 主交互。
 */
final class WarehouseIndexTemplateContractTest extends TestCase
{
    public function testWarehouseIndexUsesThemeComponentsAndChineseLabels(): void
    {
        $root = dirname(__DIR__, 3);
        $path = $root . '/view/templates/Backend/Warehouse/index.phtml';
        self::assertFileExists($path);
        $tpl = (string)file_get_contents($path);

        self::assertStringContainsString('w-card', $tpl);
        self::assertStringContainsString('w-table', $tpl);
        self::assertStringContainsString('w-field', $tpl);
        self::assertStringContainsString('w-button', $tpl);
        self::assertStringContainsString('w-input', $tpl);
        self::assertStringContainsString('w:scope', $tpl);
        self::assertStringContainsString('w:form', $tpl);
        self::assertStringContainsString('data-dropship-admin="warehouses"', $tpl);
        self::assertStringContainsString('data-testid="dropship-warehouse-map"', $tpl);

        self::assertStringContainsString('货源供应商', $tpl);
        self::assertStringContainsString('作用范围', $tpl);
        self::assertStringContainsString('远程仓', $tpl);
        self::assertStringContainsString('本地仓', $tpl);
        self::assertStringContainsString('保存映射', $tpl);
        self::assertStringContainsString('暂无映射', $tpl);

        self::assertStringContainsString('w:theme:address', $tpl);
        self::assertStringContainsString('selection="single"', $tpl);
        self::assertStringContainsString('levels="country"', $tpl);
        self::assertStringContainsString('catalog="global"', $tpl);
        self::assertStringContainsString('country-name="cj_country_code"', $tpl);
        self::assertStringContainsString('data-testid="dropship-wh-country"', $tpl);

        self::assertStringNotContainsString('form-control', $tpl);
        self::assertStringNotContainsString('container-fluid', $tpl);
        self::assertStringNotContainsString('btn-primary', $tpl);
        self::assertStringNotContainsString('table-striped', $tpl);
        self::assertStringNotContainsString('placeholder="provider (cj)"', $tpl);
        self::assertStringNotContainsString('placeholder="US"', $tpl);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*\bname="cj_country_code"/', $tpl);
    }

    public function testWarehouseControllerResolvesScopeAndProviders(): void
    {
        $root = dirname(__DIR__, 3);
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/Warehouse.php');

        self::assertStringContainsString('SystemConfigTargetScopeService', $ctrl);
        self::assertStringContainsString('StoreCatalogInterface', $ctrl);
        self::assertStringContainsString('DropshipChannelManager', $ctrl);
        self::assertStringContainsString('target_scope', $ctrl);
        self::assertStringContainsString('selected_scope', $ctrl);
        self::assertStringContainsString('providers', $ctrl);
        self::assertStringContainsString('toArray()', $ctrl);
    }
}
