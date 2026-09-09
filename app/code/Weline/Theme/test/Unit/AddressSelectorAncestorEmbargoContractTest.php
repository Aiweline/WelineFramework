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
        self::assertStringContainsString("name = name + ' · ' + placeName", $src);
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
        self::assertSame('2.2.284', (string)($module['version'] ?? ''));
    }
}
