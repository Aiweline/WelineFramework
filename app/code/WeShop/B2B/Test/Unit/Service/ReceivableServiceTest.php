<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Service\AccountService;
use WeShop\B2B\Service\ReceivableService;
use WeShop\Order\Model\Order;

class ReceivableServiceTest extends TestCase
{
    public function testCreateFromCreditOrderRejectsInvalidCustomerId(): void
    {
        $service = new ReceivableService($this->createMock(AccountService::class));
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(10);

        $this->expectException(\InvalidArgumentException::class);

        $service->createFromCreditOrder($order, 0, 100, '2026-04-01');
    }

    public function testCreateFromCreditOrderRejectsNonPositiveAmount(): void
    {
        $service = new ReceivableService($this->createMock(AccountService::class));
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Receivable amount must be positive');

        $service->createFromCreditOrder($order, 12, 0, '2026-04-01');
    }

    public function testCreateFromCreditOrderRejectsInvalidDueDate(): void
    {
        $service = new ReceivableService($this->createMock(AccountService::class));
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Due date format must be YYYY-MM-DD');

        $service->createFromCreditOrder($order, 12, 100, '04/01/2026');
    }

    public function testCreateFromCreditOrderRejectsInvalidOrderId(): void
    {
        $service = new ReceivableService($this->createMock(AccountService::class));
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order ID is required');

        $service->createFromCreditOrder($order, 12, 100, '2026-04-01');
    }
}
