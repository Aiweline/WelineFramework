<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutSessionAccessService;
use Weline\Customer\Model\Customer;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Customer\Service\GuestCheckoutConvertService;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;

final class GuestCheckoutConvertServiceTest extends TestCase
{
    public function testInspectHidesWhenOrderAlreadyBound(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-1',
            checkoutGroupUuid: 'group-1',
            status: 'paid',
            currency: 'CNY',
            websiteId: 1,
            storeId: 1,
            shipping: ['address' => ['email' => 'buyer@example.com']],
            customerId: 9,
            customerEmail: 'buyer@example.com',
        ));

        $service = $this->service($orders);
        $result = $service->inspect('qt_ok', 'order-1');
        self::assertSame('already_bound', $result['outcome']);
    }

    public function testInspectRequiresLoginWhenEmailExists(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($this->guestOrder());

        $accounts = $this->createMock(CustomerAccountService::class);
        $accounts->method('normalizeEmail')->willReturnCallback(
            static fn(string $email): string => strtolower(trim($email))
        );
        $accounts->method('findByEmail')->with('buyer@example.com')->willReturn(
            $this->createMock(Customer::class)
        );

        $service = $this->service($orders, $accounts);
        $result = $service->inspect('qt_ok', 'order-1');

        self::assertSame('login_required', $result['outcome']);
        self::assertSame('/customer/account/login', $result['redirect']);
    }

    public function testConvertCreatesSessionClaimsOrdersAndForcesPassword(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($this->guestOrder());
        $orders->expects(self::once())
            ->method('attachCustomerToGuestOrders')
            ->with(42, ['order-1', 'order-2'])
            ->willReturn(['order-1', 'order-2']);

        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'setMustSetPassword', 'save'])
            ->getMock();
        $customer->method('getId')->willReturn(42);
        $customer->method('setMustSetPassword')->with(true)->willReturnSelf();
        $customer->method('save')->willReturn(true);

        $accounts = $this->createMock(CustomerAccountService::class);
        $accounts->method('normalizeEmail')->willReturnCallback(
            static fn(string $email): string => strtolower(trim($email))
        );
        $accounts->method('findByEmail')->willReturn(null);
        $accounts->expects(self::once())->method('register')->willReturn(['customer' => $customer]);
        $accounts->expects(self::once())->method('loginCustomer')->with($customer);

        $service = $this->service($orders, $accounts);
        $result = $service->convert('qt_ok', 'order-1');

        self::assertSame('converted', $result['outcome']);
        self::assertSame('/customer/account/set-password', $result['redirect']);
        self::assertSame(['order-1', 'order-2'], $result['attached_order_uuids']);
    }

    private function guestOrder(): OrderReadResult
    {
        return new OrderReadResult(
            orderUuid: 'order-1',
            checkoutGroupUuid: 'group-1',
            status: 'paid',
            currency: 'CNY',
            websiteId: 1,
            storeId: 1,
            shipping: ['address' => ['email' => 'buyer@example.com']],
            customerId: null,
            customerEmail: null,
        );
    }

    private function service(
        OrderFacadeInterface $orders,
        ?CustomerAccountService $accounts = null,
    ): GuestCheckoutConvertService {
        $store = new class implements CheckoutSessionStoreInterface {
            public function put(string $quoteToken, array $payload, ?string $expiresAt = null): void
            {
            }

            public function get(string $quoteToken): ?array
            {
                return [
                    'state' => CheckoutSession::STATE_SUBMITTED,
                    'customer_id' => null,
                    'submitted_result' => [
                        'order_uuids' => ['order-1', 'order-2'],
                    ],
                ];
            }

            public function getForUpdate(string $quoteToken): ?array
            {
                return $this->get($quoteToken);
            }

            public function delete(string $quoteToken): bool
            {
                return false;
            }
        };

        return new GuestCheckoutConvertService(
            $accounts ?? $this->accountsMock(),
            $orders,
            new CheckoutSessionAccessService($store),
            $store,
        );
    }

    private function accountsMock(): CustomerAccountService
    {
        $accounts = $this->createMock(CustomerAccountService::class);
        $accounts->method('normalizeEmail')->willReturnCallback(
            static fn(string $email): string => strtolower(trim($email))
        );

        return $accounts;
    }
}
