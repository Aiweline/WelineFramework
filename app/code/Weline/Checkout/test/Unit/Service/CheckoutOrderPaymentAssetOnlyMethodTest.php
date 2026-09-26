<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Checkout\Service\CheckoutSuccessUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Framework\Http\Url;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\PaymentFacadeInterface;

/**
 * 资产结算（B2B 授信覆盖全额定金）不经过任何 Provider，因此不得强制要求 payment_method。
 *
 * 对应店面契约：不启用即隐藏的支付方式不会出现在结账列表里，前端不再编造 method code；
 * 只有真正要发起网关扣款时才必须有 method code。
 */
final class CheckoutOrderPaymentAssetOnlyMethodTest extends TestCase
{
    public function testZeroCashDepositSettlesWithoutProviderMethodCode(): void
    {
        $order = new OrderReadResult(
            orderUuid: 'order-asset-zero-1',
            checkoutGroupUuid: 'group-asset-zero-1',
            status: 'pending',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: ['grand_total_minor' => 11500],
            customerId: 47,
            orderType: 'tob',
            typePayload: [
                'deposit_amount_minor' => 3000,
                'balance_amount_minor' => 8500,
                'hang_status' => 'awaiting_deposit',
                'discount_kind' => 'asset_b2b_credit',
                'b2b_credit_apply_base_minor' => 3000,
                'b2b_credit_cash_deposit_minor' => 0,
                'b2b_credit_status' => 'committed',
            ],
        );
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn($order);
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::never())->method('tryCreatePayment');

        $result = $this->service($orders, $payments)->pay(
            ['order-asset-zero-1'],
            '',
            'asset-zero-key-1',
            ['purpose' => 'deposit'],
        );

        self::assertFalse($result['paid']);
        self::assertSame('partial', $result['outcome']);
        self::assertSame('deposit', $result['purpose'] ?? null);
        self::assertSame('b2b_credit', $result['transactions'][0]['method_code'] ?? null);
        self::assertSame('success', $result['transactions'][0]['status'] ?? null);
    }

    public function testProviderMethodStillRequiredWhenRealChargeIsNeeded(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-retail-1',
            checkoutGroupUuid: 'group-retail-1',
            status: 'pending',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: ['grand_total_minor' => 19900],
            customerId: 8,
        ));

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::never())->method('tryCreatePayment');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('checkout_payment_method_required');

        $this->service($orders, $payments)->pay(
            ['order-retail-1'],
            '',
            'retail-key-1',
        );
    }

    public function testAlreadyPaidReconcileAcceptsEmptyProviderMethod(): void
    {
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturn(new OrderReadResult(
            orderUuid: 'order-already-paid-empty-1',
            checkoutGroupUuid: 'group-already-paid-empty-1',
            status: 'paid',
            currency: 'CNY',
            websiteId: 0,
            storeId: 0,
            money: ['grand_total_minor' => 19900],
            customerId: 8,
        ));
        $orders->expects(self::never())->method('notifyOrderPaid');

        $payments = $this->createMock(PaymentFacadeInterface::class);
        $payments->expects(self::never())->method('tryCreatePayment');

        $result = $this->service($orders, $payments)->pay(
            ['order-already-paid-empty-1'],
            '',
            'already-paid-empty-key-1',
        );

        self::assertTrue($result['paid']);
        self::assertSame('already_paid', $result['transactions'][0]['status'] ?? null);
    }

    private function service(
        OrderFacadeInterface $orders,
        PaymentFacadeInterface $payments,
    ): CheckoutOrderPaymentService {
        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $path, array $params = []): string {
                $query = $params === [] ? '' : '?' . http_build_query($params);

                return 'http://shop.test/' . ltrim($path, '/') . $query;
            },
        );

        return new CheckoutOrderPaymentService(
            $orders,
            $payments,
            new CheckoutSuccessUrlBuilder($url),
            new InMemoryCheckoutSessionStore(),
        );
    }
}
