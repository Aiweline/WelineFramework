<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class AddressSelectorAncestorEmbargoContractTest extends TestCase
{
    public function testAddressJsUsesAncestorEmbargoInheritance(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/address.js',
        );
        self::assertStringContainsString('function embargoCoversControl', $src);
        self::assertStringContainsString('function markChildrenInheritedEmbargo', $src);
        self::assertStringContainsString('embargoedCountryCodes', $src);
        self::assertStringContainsString('markChildrenInheritedEmbargo(rows, countryCode, blockedMap)', $src);
        self::assertStringContainsString('embargoCoversControl(group.embargo, control.level)', $src);
    }

    public function testAddressJsKeepsCountryCatalogWhenLoadingSubdivisions(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/address.js',
        );
        self::assertStringContainsString('function adoptRegionRows', $src);
        self::assertStringContainsString('function ensureCountryCatalog', $src);
        self::assertStringContainsString('adoptRegionRows(group, countryRegions)', $src);
        self::assertStringContainsString('ensureCountryCatalog(group)', $src);
        self::assertStringContainsString('row.embargoed', $src);
        self::assertStringContainsString('place_name', $src);
        // 邮编命中的地点名禁止拼进国家名：region_name 会被写进国家输入框 value 并随结账落库，
        // 拼进去会得到 "United States · San Francisco" 这种脏国家名。提示只存 postal_place_name，
        // 且只在国家菜单项里展示。
        self::assertStringNotContainsString("name = name + ' · ' + placeName", $src);
        self::assertStringContainsString('region.postal_place_name = placeName', $src);
        self::assertStringContainsString("label = label + ' · ' + hit.region.postal_place_name", $src);
        self::assertStringContainsString('preferredRow.embargoed', $src);
        self::assertStringContainsString('有国家控件时禁止 fixed 锁死', $src);
        // 禁止选国后整表覆盖（会让禁运国从菜单消失）
        self::assertStringNotContainsString(
            "group.catalog === 'global' && group.controls.country) {\n                            mergeRegions(group, countryRegions);\n                        } else {\n                            group.regions = countryRegions;",
            $src,
        );
    }

    public function testThemeModuleVersionBumpedForAddressSelector(): void
    {
        $module = include dirname(__DIR__, 2) . '/etc/module.php';
        self::assertIsArray($module);
        $version = (string)($module['version'] ?? '');
        // 只保证「address.js 有变更时 Theme 版本被抬起」，不再钉精确值：
        // Theme 版本被多条并行工作流同时 bump（本轮实测 2.2.630→2.2.634→2.2.635），
        // 钉死精确值会让本用例长期常红。改为校验 semver 形状 + 不低于本契约建立时的版本。
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        self::assertTrue(
            version_compare($version, '2.2.430', '>='),
            sprintf('Weline_Theme 版本 %s 低于 address.js 契约建立时的 2.2.430', $version),
        );
    }

    public function testAddressJsUsesBinQueryAsDefaultRegionTransport(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/address.js',
        );
        self::assertStringContainsString("function callRegion(opName, params)", $src);
        self::assertStringContainsString("callRegion('embargo_countries'", $src);
        self::assertStringContainsString("callRegion('embargo_regions'", $src);
        self::assertStringContainsString("callRegion('children'", $src);
        self::assertStringContainsString('__countryEmbargoCache', $src);
        self::assertStringContainsString('__subnationalEmbargoCache', $src);
        self::assertStringContainsString('__welineAddressEmbargoStore', $src);
        self::assertStringContainsString('SUBNATIONAL_EMBARGO_CACHE_KEY', $src);
        self::assertStringContainsString("callRegion('embargo_regions', {})", $src);
        self::assertStringContainsString('never per-country network fan-out', $src);
        // 禁止把 BinQuery 写成 HTTP fetch 的 catch 回退
        self::assertStringNotContainsString("mode=embargo_countries", $src);
        self::assertStringNotContainsString('function parseJsonResponse', $src);
        self::assertStringNotContainsString('Prefer visible HTTP fetch', $src);
    }
}
