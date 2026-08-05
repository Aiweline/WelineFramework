<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Order\Service\OrderFacade;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableSnapshot;

final class OrderPayableResolverTest extends TestCase
{
    public function testSnapshotIsImmutableMinorUnitFromOrder(): void
    {
        $resolver = OrderPayableResolver::forTesting([
            'ord-x' => [
                'order_uuid' => 'ord-x',
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => 'CNY',
                'website_id' => 0,
                'store_id' => 3,
                'customer_id' => '9',
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 500,
                    'shipping_amount_minor' => 50,
                    'tax_amount_minor' => 0,
                    'discount_amount_minor' => 0,
                    'grand_total_minor' => 550,
                ],
                'scope' => [
                    'website_id' => 0,
                    'store_id' => 3,
                    'currency' => 'CNY',
                ],
                'items' => [
                    ['item_uuid' => 'i1', 'name' => 'X', 'qty_minor' => 1, 'row_total_minor' => 500],
                ],
                'display_number' => 'DN-X',
            ],
        ]);

        $ctx = $resolver->resolve('ord-x');
        $snap = $resolver->snapshot($ctx);

        self::assertSame(OrderPayableResolver::PAYABLE_TYPE, $snap->getPayableType());
        self::assertSame('ord-x', $snap->getPayableId());
        self::assertSame(550, $snap->getAmountMinor());
        self::assertSame('CNY', $snap->getCurrencyCode());
        self::assertSame(0, (int) $snap->getArray('scope')['website_id']);
        self::assertSame(3, (int) $snap->getArray('scope')['store_id']);
        self::assertSame(500, (int) ($snap->getArray('amounts')['subtotal_amount_minor'] ?? -1));
        self::assertTrue($resolver->canPay($snap, Actor::fromArray([
            'actor_type' => 'customer',
            'actor_id' => '9',
        ])));
        self::assertFalse($resolver->canPay($snap, Actor::fromArray([
            'actor_type' => 'customer',
            'actor_id' => 'other',
        ])));
    }

    public function testPaidStatusBlocksPay(): void
    {
        $resolver = OrderPayableResolver::forTesting([
            'ord-y' => [
                'order_uuid' => 'ord-y',
                'status' => 'paid',
                'payment_status' => 'paid',
                'currency' => 'USD',
                'website_id' => 0,
                'store_id' => 0,
                'money' => [
                    'currency' => 'USD',
                    'subtotal_minor' => 100,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'grand_total_minor' => 100,
                ],
                'scope' => ['website_id' => 0, 'store_id' => 0, 'currency' => 'USD'],
                'items' => [],
            ],
        ]);
        $snap = $resolver->snapshot($resolver->resolve('ord-y'));
        self::assertSame('paid', $snap->getData('status'));
        self::assertFalse($resolver->canPay($snap, Actor::fromArray([
            'actor_type' => 'guest',
            'actor_id' => 'g1',
        ])));
        self::assertContains(OrderPayableResolver::PAYABLE_TYPE, $resolver->getBusinessTags($snap));
        self::assertInstanceOf(PayableSnapshot::class, $snap);
    }

    public function testRealOrderFacadeProjectionEnforcesCustomerOwner(): void
    {
        $facade = OrderFacade::forTesting();
        $created = $facade->create(new CreateCheckoutGroupCommand(
            idempotencyKey: 'resolver-owner',
            requestHash: hash('sha256', 'resolver-owner'),
            websiteId: 0,
            storeId: 3,
            customerId: 9,
            currency: 'CNY',
            lines: [['name' => 'Owner Item', 'qty_minor' => 1, 'unit_price_minor' => 550]],
        ));
        $resolver = new OrderPayableResolver($facade);
        $snapshot = $resolver->snapshot($resolver->resolve($created->orderUuids[0]));

        self::assertSame(
            ['actor_type' => 'customer', 'actor_id' => '9'],
            $snapshot->getArray(PayableSnapshot::FIELD_OWNER),
        );
        self::assertTrue($resolver->canPay($snapshot, Actor::fromArray([
            'actor_type' => 'customer',
            'actor_id' => '9',
        ])));
        self::assertFalse($resolver->canPay($snapshot, Actor::fromArray([
            'actor_type' => 'customer',
            'actor_id' => '10',
        ])));
    }
}
