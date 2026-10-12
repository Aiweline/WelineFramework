<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class AddressSchemaLabelsContractTest extends TestCase
{
    public function testAddressJsAppliesSchemaLabelsFromCountryProfile(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/address.js'
        );
        self::assertStringContainsString('function applySchemaLabels', $js);
        self::assertStringContainsString('paintExternalPostalLabel', $js);
        self::assertStringContainsString('weline:address:schema-labels', $js);
        self::assertStringContainsString('applySchemaLabels(group, profile)', $js);
    }
}
