<?php

declare(strict_types=1);

namespace WeShop\Address\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Service\AddressPageDataService;
use WeShop\Address\Service\AddressService;
use Weline\I18n\Model\I18n;

class AddressPageDataServiceTest extends TestCase
{
    public function testBuildProvidesDefaultAddressAndCountries(): void
    {
        $addressService = $this->createMock(AddressService::class);
        $addressService->expects($this->once())
            ->method('getCustomerAddresses')
            ->with(12)
            ->willReturn([
                [
                    'address_id' => 1,
                    'name' => 'Office',
                    'is_default' => false,
                ],
                [
                    'address_id' => 2,
                    'name' => 'Home',
                    'is_default' => true,
                ],
            ]);

        $i18n = $this->createMock(I18n::class);
        $i18n->expects($this->once())
            ->method('getCountries')
            ->with('en')
            ->willReturn([
                'US' => 'United States',
                'GB' => 'United Kingdom',
            ]);

        $service = new AddressPageDataService($addressService, $i18n);
        $result = $service->build(12);

        $this->assertSame(2, $result['address_count']);
        $this->assertSame(2, $result['default_address']['address_id']);
        $this->assertSame(
            [
                ['code' => 'US', 'name' => 'United States'],
                ['code' => 'GB', 'name' => 'United Kingdom'],
            ],
            $result['countries']
        );
    }
}
