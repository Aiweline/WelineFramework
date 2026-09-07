<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Global catalog country pickers (country-only or cascade) must keep the world list.
 */
final class AddressCountryOnlyGlobalCatalogContractTest extends TestCase
{
    public function testAddressJsKeepsCountryCatalogForGlobalCountryPickers(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/address.js';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("var countryOnly = levels.length === 1 && levels[0] === 'country'", $src);
        self::assertStringContainsString("catalog === 'global' && levels.indexOf('country') > -1", $src);
        self::assertStringContainsString('group.countryOnly = countryOnly', $src);
        self::assertStringContainsString("!(group.catalog === 'global' && group.controls.country)", $src);
        self::assertStringContainsString('Keep the global country list until a country is fixed/selected', $src);
        self::assertStringContainsString("root.getAttribute('data-catalog')", $src);
        $loader = (string)file_get_contents(dirname(__DIR__, 2) . '/view/statics/js/address-loader.js');
        self::assertStringContainsString('20260907-district-single2', $loader);
    }
}
