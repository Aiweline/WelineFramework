<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Taglib\AddressQuick;

final class AddressQuickContractTest extends TestCase
{
    public function testTagNameAndSelfClose(): void
    {
        self::assertSame('theme:address-quick', AddressQuick::name());
        self::assertTrue(AddressQuick::tag_self_close());
        self::assertNull(AddressQuick::parent());
        self::assertStringContainsString('filter-target', AddressQuick::document());
    }

    public function testSourceExposesFilterAndAddDockingAttributes(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Theme/Taglib/AddressQuick.php');
        self::assertStringContainsString("return 'theme:address-quick'", $src);
        self::assertStringContainsString('data-w-address-quick', $src);
        self::assertStringContainsString('data-filter-target', $src);
        self::assertStringContainsString('data-filter-attr', $src);
        self::assertStringContainsString('data-mode', $src);
        self::assertStringContainsString('Address::renderHtml', $src);
        self::assertStringContainsString('address-quick-loader.js', $src);
        self::assertStringContainsString('add-action', $src);
        self::assertStringContainsString('weline:address-quick:change', AddressQuick::document());
    }

    public function testJsFiltersOnSelectionChange(): void
    {
        $js = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/js/address-quick.js');
        self::assertStringContainsString('weline:address-quick:change', $js);
        self::assertStringContainsString('data-filter-target', $js);
        self::assertStringContainsString('applyFilter', $js);
        self::assertStringContainsString('row.hidden', $js);
    }
}
