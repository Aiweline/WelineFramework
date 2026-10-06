<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Service\AccountOrderDetailResolver;

final class AccountOrderDetailResolverTest extends TestCase
{
    public function testOwnedOrderUuidResolvesACompleteAccountDetailProjection(): void
    {
        if (!class_exists(AccountOrderDetailResolver::class)) {
            self::fail('AccountOrderDetailResolver must own account-order detail resolution.');
        }

        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($this->paidOrder());
        $images = new class implements \Weline\Order\Api\OrderCatalogImageResolverInterface {
            public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string
            {
                return trim($reference);
            }

            public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array
            {
                return [];
            }
        };
        $resolver = new AccountOrderDetailResolver($orders, $images);

        $detail = $resolver->resolve($this->ownedGroups(), 'f783cdc9-ad19-4a50-9137-eb9cea4741a6');

        self::assertIsArray($detail);
        self::assertSame('0813194997', $detail['display_number'] ?? null);
        self::assertSame('ZTOT Z6-MAX YBS300 PRO', $detail['items'][0]['name'] ?? null);
        self::assertSame('/media/catalog/demo-z6.jpg', $detail['items'][0]['image_src'] ?? null);
        self::assertNotSame('', (string)($detail['items'][0]['image_fallback'] ?? ''));
        self::assertSame(289500, $detail['money']['grand_total_minor'] ?? null);
    }

    public function testMissingSnapshotImageFallsBackToProductMainImage(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
            checkoutGroupUuid: 'c447babc-f8dd-4f54-921c-55b89c1bcd3d',
            status: 'paid',
            currency: 'USD',
            websiteId: 0,
            storeId: 0,
            items: [[
                'name' => 'Legacy Line',
                'sku' => 'LEGACY-1',
                'product_id' => 42,
                'qty_minor' => 1,
                'unit_price_minor' => 100,
                'row_total_minor' => 100,
            ]],
            money: [
                'subtotal_minor' => 100,
                'shipping_amount_minor' => 0,
                'tax_amount_minor' => 0,
                'grand_total_minor' => 100,
            ],
            displayNumber: 'LEGACY',
            customerId: 7,
        ));
        $images = new class implements \Weline\Order\Api\OrderCatalogImageResolverInterface {
            public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string
            {
                return '';
            }

            public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array
            {
                return [42 => '/media/catalog/product-42.jpg'];
            }
        };
        $resolver = new AccountOrderDetailResolver($orders, $images);

        $detail = $resolver->resolve($this->ownedGroups(), 'f783cdc9-ad19-4a50-9137-eb9cea4741a6');

        self::assertSame('/media/catalog/product-42.jpg', $detail['items'][0]['image_src'] ?? null);
    }

    public function testOrderOutsideCustomerGroupsIsRejectedBeforeReadingItsDetail(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->expects(self::never())->method('get');
        $resolver = new AccountOrderDetailResolver($orders);

        self::assertNull($resolver->resolve($this->ownedGroups(), 'foreign-order-uuid'));
    }

    /** @return list<array<string, mixed>> */
    private function ownedGroups(): array
    {
        return [[
            'group_uuid' => 'c447babc-f8dd-4f54-921c-55b89c1bcd3d',
            'orders' => [[
                'order_uuid' => 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
            ]],
        ]];
    }

    private function paidOrder(): OrderReadResult
    {
        return new OrderReadResult(
            orderUuid: 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
            checkoutGroupUuid: 'c447babc-f8dd-4f54-921c-55b89c1bcd3d',
            status: 'paid',
            currency: 'USD',
            websiteId: 0,
            storeId: 0,
            items: [[
                'name' => 'ZTOT Z6-MAX YBS300 PRO',
                'sku' => 'ZTOT-Z6-MAX',
                'product_id' => 11,
                'image' => '/media/catalog/demo-z6.jpg',
                'qty_minor' => 1,
                'unit_price_minor' => 289500,
                'row_total_minor' => 289500,
            ]],
            money: [
                'subtotal_minor' => 289500,
                'shipping_amount_minor' => 0,
                'tax_amount_minor' => 0,
                'grand_total_minor' => 289500,
            ],
            displayNumber: '0813194997',
            customerId: 7,
        );
    }
}
