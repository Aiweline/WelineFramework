<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Same-form second address instance (checkout billing) needs meta-prefix.
 */
final class AddressMetaPrefixContractTest extends TestCase
{
    public function testAddressTaglibExposesMetaPrefixInConfig(): void
    {
        $path = dirname(__DIR__, 2) . '/Taglib/Address.php';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString("attributes['meta-prefix']", $src);
        self::assertStringContainsString("'metaPrefix' => \$metaPrefix", $src);
        self::assertStringContainsString('meta-prefix', $src);
    }

    public function testAddressJsAppliesMetaPrefixToMetadataFields(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/address.js';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('function metaFieldName(group, name)', $src);
        self::assertStringContainsString('group.metaPrefix', $src);
        self::assertStringContainsString('config.metaPrefix', $src);
        self::assertStringContainsString('data-billing-address-cascade', $src);
        self::assertStringContainsString('billing_postal_code', $src);
    }
}
