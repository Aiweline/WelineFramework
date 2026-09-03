<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 契约：唯一地址必须自动成为默认（create / delete / list 惰性修复）。
 */
final class AddressSoleDefaultContractTest extends TestCase
{
    public function testDeliveryServiceEnsuresSoleAddressIsDefault(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/DeliveryAddressService.php');

        $this->assertStringContainsString('ensureSoleDefaultByCustomer', $source);
        $this->assertStringContainsString('countByCustomer', $source);
        $this->assertStringContainsString('$this->ensureSoleDefaultByCustomer($customerId);', $source);
        $this->assertStringContainsString(
            'if ($this->countByCustomer($customerId) === 0) {',
            $source
        );
        $this->assertStringContainsString(
            '$data[DeliveryAddress::schema_fields_IS_DEFAULT] = 1;',
            $source
        );
        $this->assertStringContainsString(
            '$this->ensureSoleDefaultByCustomer($ownerId);',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/if \(count\(\$items\) !== 1\) \{\s*return;/s',
            $source
        );
    }

    public function testShippingServiceEnsuresSoleAddressIsDefault(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingAddressService.php');

        $this->assertStringContainsString('ensureSoleDefault', $source);
        $this->assertStringContainsString('countAll', $source);
        $this->assertStringContainsString('$this->ensureSoleDefault();', $source);
        $this->assertStringContainsString('if ($this->countAll() === 0) {', $source);
        $this->assertStringContainsString(
            '$data[ShippingAddress::schema_fields_IS_DEFAULT] = 1;',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/if \(count\(\$items\) !== 1\) \{\s*return;/s',
            $source
        );
    }
}
