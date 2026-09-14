<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderMailNotifier;

final class OrderMailNotifierContractTest extends TestCase
{
    public function testMapStatusToChannel(): void
    {
        $n = new OrderMailNotifier();
        self::assertSame(OrderMailNotifier::CHANNEL_SHIPPED, $n->mapStatusToChannel(Order::STATUS_FULFILLED));
        self::assertSame(OrderMailNotifier::CHANNEL_SHIPPED, $n->mapStatusToChannel('shipped'));
        self::assertSame(OrderMailNotifier::CHANNEL_REFUND, $n->mapStatusToChannel(Order::STATUS_REFUNDED));
        self::assertSame(OrderMailNotifier::CHANNEL_REFUND, $n->mapStatusToChannel('refund'));
        self::assertSame(OrderMailNotifier::CHANNEL_STATUS_CHANGED, $n->mapStatusToChannel('processing'));
    }

    public function testNotifyStatusChangedRespectsFlag(): void
    {
        $n = new OrderMailNotifier();
        $order = $this->createMock(Order::class);
        $result = $n->notifyStatusChanged($order, 'pending', 'processing', false);
        self::assertTrue(!empty($result['skipped']));
        self::assertSame('notify_customer_false', $result['message']);
    }
}
