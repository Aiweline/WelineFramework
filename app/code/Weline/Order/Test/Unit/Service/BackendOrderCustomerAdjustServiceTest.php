<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\Order;
use Weline\Order\Service\BackendOrderCustomerAdjustService;

final class BackendOrderCustomerAdjustServiceTest extends TestCase
{
    public function testAddressFormValuesFlattensAliases(): void
    {
        $service = new BackendOrderCustomerAdjustService();
        $form = $service->addressFormValues([
            'fullname_name' => 'we',
            'telephone' => '13800000000',
            'country_code' => 'cn',
            'province' => '四川省',
            'city' => '成都市',
            'district' => '新都区',
            'street' => 'test',
            'postal_code' => '610500',
            'province_region_id' => 15860,
        ]);

        self::assertSame('we', $form['name']);
        self::assertSame('13800000000', $form['phone']);
        self::assertSame('CN', $form['country_code']);
        self::assertSame('四川省', $form['province']);
        self::assertSame('test', $form['address1']);
        self::assertSame('610500', $form['postcode']);
        self::assertSame('15860', $form['province_region_id']);
    }

    public function testNormalizeUpdatePayloadMergesAddressAndIgnoresMoneyFields(): void
    {
        $service = new BackendOrderCustomerAdjustService();
        $payload = $service->normalizeUpdatePayload([
            'customer_id' => '47',
            'customer_name' => 'we',
            'customer_email' => 'a@b.com',
            'notes' => 'ops note',
            'grand_total' => '9999',
            'items' => [['sku' => 'X']],
            'shipping_name' => '收货人',
            'shipping_phone' => '139',
            'shipping_country_code' => 'CN',
            'shipping_province' => '四川省',
            'shipping_city' => '成都市',
            'shipping_district' => '新都区',
            'shipping_address1' => 'new-street',
            'shipping_postcode' => '610501',
            'billing_name' => '账单',
            'billing_address1' => 'bill-street',
            'billing_country_code' => 'CN',
        ], [
            'name' => 'old',
            'province_region_id' => 15860,
        ], [
            'name' => 'old-bill',
        ]);

        self::assertSame(47, $payload[Order::schema_fields_CUSTOMER_ID]);
        self::assertSame('we', $payload[Order::schema_fields_CUSTOMER_NAME]);
        self::assertSame('ops note', $payload[Order::schema_fields_NOTES]);
        self::assertArrayNotHasKey('grand_total', $payload);
        self::assertArrayNotHasKey('items', $payload);

        $shipping = json_decode((string)$payload[Order::schema_fields_SHIPPING_ADDRESS], true);
        self::assertIsArray($shipping);
        self::assertSame('收货人', $shipping['name']);
        self::assertSame('new-street', $shipping['address1']);
        self::assertSame('610501', $shipping['postcode']);
        self::assertSame(15860, $shipping['province_region_id']);

        $billing = json_decode((string)$payload[Order::schema_fields_BILLING_ADDRESS], true);
        self::assertIsArray($billing);
        self::assertSame('账单', $billing['name']);
        self::assertSame('bill-street', $billing['address1']);
    }

    public function testNormalizeAllowsClearingCustomerId(): void
    {
        $service = new BackendOrderCustomerAdjustService();
        $payload = $service->normalizeUpdatePayload([
            'customer_id' => '',
            'customer_name' => 'guest',
        ]);

        self::assertNull($payload[Order::schema_fields_CUSTOMER_ID]);
        self::assertSame('guest', $payload[Order::schema_fields_CUSTOMER_NAME]);
    }
}
