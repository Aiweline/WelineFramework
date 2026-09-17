<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Model\Customer;
use Weline\Customer\Service\AnonymousPaidCustomerBinder;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;

final class AnonymousPaidCustomerBinderTest extends TestCase
{
    public function testCreatesAnonymousCustomerAndAttachesGuestOrder(): void
    {
        $customer = $this->createMock(Customer::class);
        $customer->method('getId')->willReturn(42);
        $customer->expects(self::once())->method('setAnonymousAccount')->with(true)->willReturnSelf();
        $customer->expects(self::once())->method('setMustSetPassword')->with(true)->willReturnSelf();
        $customer->expects(self::once())->method('save')->willReturn(true);

        $accounts = $this->createMock(CustomerAccountService::class);
        $accounts->method('findByEmail')->with('buyer@example.com')->willReturn(null);
        $accounts->expects(self::once())->method('register')->willReturn(['customer' => $customer]);

        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'ord-1',
            checkoutGroupUuid: 'grp-1',
            status: 'paid',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            shipping: ['address' => ['name' => 'Sandbox Buyer', 'email' => 'buyer@example.com']],
            customerId: null,
            customerEmail: 'buyer@example.com',
        ));
        $orders->expects(self::once())
            ->method('attachCustomerToGuestOrders')
            ->with(42, ['ord-1'])
            ->willReturn(['ord-1']);

        $binder = new AnonymousPaidCustomerBinder($accounts, $orders);
        $result = $binder->bindPaidOrder('ord-1');

        self::assertSame('created_anonymous', $result['outcome']);
        self::assertSame(42, $result['customer_id']);
        self::assertTrue($result['attached']);
    }

    public function testDoesNotBindRegisteredEmail(): void
    {
        $registered = $this->createMock(Customer::class);
        $registered->method('getId')->willReturn(9);
        $registered->method('isAnonymousAccount')->willReturn(false);

        $accounts = $this->createMock(CustomerAccountService::class);
        $accounts->method('findByEmail')->willReturn($registered);
        $accounts->expects(self::never())->method('register');

        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'ord-2',
            checkoutGroupUuid: 'grp-2',
            status: 'paid',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            shipping: ['address' => ['email' => 'member@example.com']],
            customerId: null,
            customerEmail: 'member@example.com',
        ));
        $orders->expects(self::never())->method('attachCustomerToGuestOrders');

        $binder = new AnonymousPaidCustomerBinder($accounts, $orders);
        $result = $binder->bindPaidOrder('ord-2');

        self::assertSame('registered_email_contact_only', $result['outcome']);
        self::assertNull($result['customer_id']);
        self::assertFalse($result['attached']);
    }

    public function testSkipsWhenNoEmail(): void
    {
        $accounts = $this->createMock(CustomerAccountService::class);
        $accounts->expects(self::never())->method('register');

        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'ord-3',
            checkoutGroupUuid: 'grp-3',
            status: 'paid',
            currency: 'USD',
            websiteId: 1,
            storeId: 1,
            shipping: ['address' => ['name' => 'No Mail', 'phone' => '123']],
            customerId: null,
            customerEmail: null,
        ));

        $binder = new AnonymousPaidCustomerBinder($accounts, $orders);
        $result = $binder->bindPaidOrder('ord-3');

        self::assertSame('skipped_no_email', $result['outcome']);
    }
}
