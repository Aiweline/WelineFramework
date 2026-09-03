<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\BackendOrderListPresenter;

final class BackendOrderListPresenterTest extends TestCase
{
    public function testPresentUsesCreateTimeAndShippingNameForAnonymousGuest(): void
    {
        $presenter = new BackendOrderListPresenter();
        $row = $presenter->present([
            'customer_id' => null,
            'customer_name' => null,
            'customer_email' => null,
            'created_at' => null,
            'create_time' => '2026-09-02 13:17:56.338489',
            'shipping_address' => [
                'name' => '沙盒买家',
                'phone' => '13800138000',
            ],
        ]);

        self::assertTrue($row['is_anonymous']);
        self::assertSame('沙盒买家', $row['customer_name']);
        self::assertSame('', $row['customer_email']);
        self::assertSame('13800138000', $row['customer_phone']);
        self::assertSame('2026-09-02 13:17:56', $row['created_at']);
    }

    public function testPresentCombinesFirstLastNameAndEmailFromShippingAddress(): void
    {
        $presenter = new BackendOrderListPresenter();
        $row = $presenter->present([
            'customer_id' => 0,
            'customer_name' => '',
            'customer_email' => '',
            'created_at' => '',
            'create_time' => '2026-09-02 00:38:05.025188',
            'shipping_address' => json_encode([
                'firstname' => 'Test',
                'lastname' => 'Buyer',
                'email' => 'buyer@example.com',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertTrue($row['is_anonymous']);
        self::assertSame('Test Buyer', $row['customer_name']);
        self::assertSame('buyer@example.com', $row['customer_email']);
        self::assertSame('2026-09-02 00:38:05', $row['created_at']);
    }

    public function testPresentKeepsRegisteredCustomerFieldsWithoutAnonymousFlag(): void
    {
        $presenter = new BackendOrderListPresenter();
        $row = $presenter->present([
            'customer_id' => 12,
            'customer_name' => '正式客户',
            'customer_email' => 'member@example.com',
            'created_at' => '2026-08-01 10:00:00',
            'create_time' => '2026-08-01 10:00:01',
            'shipping_address' => ['name' => '地址姓名'],
        ]);

        self::assertFalse($row['is_anonymous']);
        self::assertSame('正式客户', $row['customer_name']);
        self::assertSame('member@example.com', $row['customer_email']);
        self::assertSame('2026-08-01 10:00:01', $row['created_at']);
    }
}
