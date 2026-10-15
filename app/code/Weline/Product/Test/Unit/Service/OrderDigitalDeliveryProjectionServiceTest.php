<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Product\Api\ProductDownloadEntitlementInterface;
use Weline\Product\Service\OrderDigitalDeliveryProjectionService;

final class OrderDigitalDeliveryProjectionServiceTest extends TestCase
{
    private const ORDER_UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-111111111111';
    private const ENTITLEMENT_UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-222222222222';
    private const OFFER_UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('__')) {
            eval('function __(mixed $text): string { return (string)$text; }');
        }
    }

    public function testProjectReturnsEntitlementHrefWithoutPublicRoutes(): void
    {
        $order = new OrderReadResult(
            orderUuid: self::ORDER_UUID,
            checkoutGroupUuid: 'cg',
            status: 'paid',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            items: [[
                'name' => 'Golden Triangle Pack',
                'fulfillment_metadata' => [
                    'digital_download' => [
                        'global_offer_uuid' => self::OFFER_UUID,
                    ],
                ],
            ]],
            customerId: 42,
        );

        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->with(self::ORDER_UUID)->willReturn($order);

        $entitlements = $this->createMock(ProductDownloadEntitlementInterface::class);
        $entitlements->method('grantForPaidOrder')->with(self::ORDER_UUID)->willReturn([[
            'entitlement_uuid' => self::ENTITLEMENT_UUID,
            'global_offer_uuid' => self::OFFER_UUID,
            'name' => 'Pack.html',
            'replayed' => false,
        ]]);

        $service = new OrderDigitalDeliveryProjectionService($entitlements, $orders);
        $projected = $service->project(self::ORDER_UUID, 42, false);

        self::assertSame(OrderDigitalDeliveryProjectionService::SCHEMA_VERSION, $projected['schema_version']);
        self::assertCount(1, $projected['entries']);
        self::assertSame('/product-download/' . self::ENTITLEMENT_UUID, $projected['entries'][0]['href']);
        self::assertStringNotContainsString('/routes/', $projected['entries'][0]['href']);
        self::assertSame('file_download', $projected['entries'][0]['kind']);
        self::assertSame('Golden Triangle Pack', $projected['entries'][0]['product_name']);
    }

    public function testProjectDeniesForeignCustomerWithoutCapability(): void
    {
        $order = new OrderReadResult(
            orderUuid: self::ORDER_UUID,
            checkoutGroupUuid: 'cg',
            status: 'paid',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            customerId: 42,
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($order);
        $entitlements = $this->createMock(ProductDownloadEntitlementInterface::class);
        $entitlements->expects(self::never())->method('grantForPaidOrder');

        $service = new OrderDigitalDeliveryProjectionService($entitlements, $orders);
        $projected = $service->project(self::ORDER_UUID, 99, false);
        self::assertSame([], $projected['entries']);
    }
}
