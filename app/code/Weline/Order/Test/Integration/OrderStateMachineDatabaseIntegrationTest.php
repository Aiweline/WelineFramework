<?php

declare(strict_types=1);

namespace Weline\Order\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderStateMachine;
use Weline\Order\Service\OrderStateTransitionException;

/**
 * PostgreSQL/local configured DB evidence for P2D-002 state CAS.
 *
 * The outer transaction is always rolled back.
 */
final class OrderStateMachineDatabaseIntegrationTest extends TestCase
{
    public function testTransitionCasRetryAndRollback(): void
    {
        $transaction = new Order();
        $transaction->beginTransaction();
        $number = 'P2D002-' . bin2hex(random_bytes(8));
        $orderId = 0;

        try {
            $order = (new Order())->setData([
                Order::schema_fields_ORDER_NUMBER => $number,
                Order::schema_fields_STATUS => Order::STATUS_PENDING,
                Order::schema_fields_STATE => Order::STATUS_PENDING,
                Order::schema_fields_CURRENCY => 'CNY',
                Order::schema_fields_STATE_VERSION => 0,
            ]);
            $saved = $order->save();
            $orderId = (int)($order->getId() ?: $saved);
            self::assertGreaterThan(0, $orderId);

            $machine = ObjectManager::getInstance(OrderStateMachine::class);
            $updated = $machine->transition($orderId, Order::STATUS_PROCESSING, 'p2d002');
            self::assertSame(Order::STATUS_PROCESSING, $updated->getData(Order::schema_fields_STATUS));
            self::assertSame(1, (int)$updated->getData(Order::schema_fields_STATE_VERSION));

            $retried = $machine->transition($orderId, Order::STATUS_PROCESSING, 'retry');
            self::assertSame(1, (int)$retried->getData(Order::schema_fields_STATE_VERSION));

            try {
                $machine->transition($orderId, Order::STATUS_COMPLETED);
                self::fail('illegal transition must fail');
            } catch (OrderStateTransitionException $exception) {
                self::assertSame(OrderStateMachine::ERROR_ILLEGAL_TRANSITION, $exception->errorCode());
            }
        } finally {
            $transaction->rollBack();
        }

        if ($orderId > 0) {
            $missing = (new Order())
                ->where(Order::schema_fields_ORDER_NUMBER, $number)
                ->find()
                ->fetch();
            self::assertFalse((bool)$missing->getId());
        }
    }
}
