<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Order\Service\OrderFacade;
use Weline\Payment\Api\Data\Actor;

final class OrderPayableResolverOrderTypeTest extends TestCase
{
    public function testSnapshotIncludesOrderTypeAndTypePayload(): void
    {
        $resolver = OrderPayableResolver::forTesting([
            'ord-tob-1' => [
                'order_uuid' => 'ord-tob-1',
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => 'CNY',
                'website_id' => 1,
                'store_id' => 1,
                'customer_id' => '9',
                'order_type' => 'tob',
                'type_payload' => [
                    'discount_kind' => 'asset_b2b_credit',
                    'b2b_credit_cash_deposit_minor' => 1000,
                ],
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 10000,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'discount_amount_minor' => 0,
                    'grand_total_minor' => 10000,
                ],
                'items' => [],
            ],
        ], OrderFacade::forTesting());

        $ctx = $resolver->resolve('ord-tob-1', Actor::fromArray([
            'actor_type' => 'customer',
            'actor_id' => '9',
        ]));
        $snap = $resolver->snapshot($ctx);
        self::assertSame('tob', $snap->getData('order_type'));
        self::assertSame('tob', $snap->getArray('metadata')['order_type'] ?? null);
        self::assertSame(
            'asset_b2b_credit',
            $snap->getArray('metadata')['type_payload']['discount_kind'] ?? null,
        );
    }
}
