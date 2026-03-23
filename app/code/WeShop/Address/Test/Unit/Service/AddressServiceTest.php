<?php

declare(strict_types=1);

namespace WeShop\Address\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Service\AddressService;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;

class AddressServiceTest extends TestCase
{
    public function testGetCustomerAddressesNormalizesDeliveryAddressRows(): void
    {
        $deliveryAddressService = $this->createMock(DeliveryAddressService::class);
        $deliveryAddressService->expects($this->once())
            ->method('getListByCustomer')
            ->with(9, ['is_enabled' => 1])
            ->willReturn([
                [
                    'delivery_address_id' => 5,
                    'customer_id' => 9,
                    'name' => 'Home',
                    'contact_name' => 'Ada Lovelace',
                    'contact_phone' => '+1 555 0100',
                    'country' => 'us',
                    'province' => 'CA',
                    'city' => 'San Francisco',
                    'district' => 'Mission',
                    'street' => '123 Market Street',
                    'postal_code' => '94105',
                    'is_default' => 1,
                    'is_enabled' => 1,
                ],
            ]);

        $service = new AddressService($deliveryAddressService);
        $result = $service->getCustomerAddresses(9);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['address_id']);
        $this->assertSame('Ada', $result[0]['firstname']);
        $this->assertSame('Lovelace', $result[0]['lastname']);
        $this->assertSame('US', $result[0]['country_id']);
        $this->assertSame('CA', $result[0]['region']);
        $this->assertTrue((bool) $result[0]['is_default']);
        $this->assertStringContainsString('San Francisco', (string) $result[0]['full_address']);
    }

    public function testSaveAddressMapsLegacyPayloadToDeliveryAddressFields(): void
    {
        $savedModel = $this->getMockBuilder(DeliveryAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $savedModel->expects($this->once())
            ->method('getData')
            ->willReturn([
                'delivery_address_id' => 8,
                'customer_id' => 7,
                'name' => 'Ada Lovelace',
                'contact_name' => 'Ada Lovelace',
                'contact_phone' => '+44 20 0000 0000',
                'country' => 'GB',
                'province' => 'London',
                'city' => 'London',
                'district' => '',
                'street' => '10 Downing Street',
                'postal_code' => 'SW1A 2AA',
                'is_default' => 1,
                'is_enabled' => 1,
            ]);

        $deliveryAddressService = $this->createMock(DeliveryAddressService::class);
        $deliveryAddressService->expects($this->once())
            ->method('create')
            ->with(7, $this->callback(static function (array $payload): bool {
                return $payload[DeliveryAddress::schema_fields_CONTACT_NAME] === 'Ada Lovelace'
                    && $payload[DeliveryAddress::schema_fields_CONTACT_PHONE] === '+44 20 0000 0000'
                    && $payload[DeliveryAddress::schema_fields_COUNTRY] === 'GB'
                    && $payload[DeliveryAddress::schema_fields_PROVINCE] === 'London'
                    && $payload[DeliveryAddress::schema_fields_CITY] === 'London'
                    && $payload[DeliveryAddress::schema_fields_STREET] === '10 Downing Street'
                    && $payload[DeliveryAddress::schema_fields_POSTAL_CODE] === 'SW1A 2AA'
                    && $payload[DeliveryAddress::schema_fields_IS_DEFAULT] === 1;
            }))
            ->willReturn($savedModel);

        $service = new AddressService($deliveryAddressService);
        $result = $service->saveAddress([
            'customer_id' => 7,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'telephone' => '+44 20 0000 0000',
            'country_id' => 'gb',
            'region' => 'London',
            'city' => 'London',
            'street' => '10 Downing Street',
            'postcode' => 'sw1a 2aa',
            'is_default' => true,
        ]);

        $this->assertSame(8, $result['address_id']);
        $this->assertSame('Ada', $result['firstname']);
        $this->assertSame('Lovelace', $result['lastname']);
        $this->assertSame('GB', $result['country_id']);
        $this->assertSame('SW1A 2AA', $result['postcode']);
    }
}
