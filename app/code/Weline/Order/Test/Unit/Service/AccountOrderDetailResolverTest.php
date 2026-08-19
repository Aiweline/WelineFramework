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
        $resolver = new AccountOrderDetailResolver($orders);

        $detail = $resolver->resolve($this->ownedGroups(), 'f783cdc9-ad19-4a50-9137-eb9cea4741a6');

        self::assertIsArray($detail);
        self::assertSame('0813194997', $detail['display_number'] ?? null);
        self::assertSame('ZTOT Z6-MAX YBS300 PRO', $detail['items'][0]['name'] ?? null);
        self::assertSame(289500, $detail['money']['grand_total_minor'] ?? null);
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
