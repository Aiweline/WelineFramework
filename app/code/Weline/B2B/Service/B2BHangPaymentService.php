<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderHang;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;

/**
 * Storefront hang deposit/balance payment entry (account CTA → checkout purpose).
 */
final class B2BHangPaymentService
{
    public const ERROR_AUTH = 'b2b_hang_payment_auth_required';
    public const ERROR_FORBIDDEN = 'b2b_hang_payment_forbidden';
    public const ERROR_PURPOSE = 'b2b_hang_payment_purpose_invalid';
    public const ERROR_STATE = 'b2b_hang_payment_state_invalid';
    public const ERROR_METHOD = 'b2b_hang_payment_method_required';

    public function __construct(
        private readonly B2BHangOrderService $hangOrders,
        private readonly ?OrderFacadeInterface $orders = null,
        private readonly ?CheckoutOrderPaymentService $payments = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function paymentContext(string $orderUuid, string $purpose, ?int $customerId): array
    {
        $hang = $this->requireHangForCustomer($orderUuid, $purpose, $customerId);
        $amountMinor = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
            ? $hang->depositAmountMinor
            : $hang->balanceAmountMinor;

        return [
            'success' => true,
            'ok' => true,
            'order_uuid' => $hang->orderRef,
            'purpose' => $purpose,
            'hang_status' => $hang->hangStatus,
            'amount_minor' => $amountMinor,
            'deposit_amount_minor' => $hang->depositAmountMinor,
            'balance_amount_minor' => $hang->balanceAmountMinor,
            'currency' => 'CNY',
            'label' => $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
                ? (string)__('支付定金')
                : (string)__('支付尾款'),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function startPayment(
        string $orderUuid,
        string $purpose,
        string $paymentMethod,
        string $paymentIdempotencyKey,
        ?int $customerId,
        array $context = [],
    ): array {
        $hang = $this->requireHangForCustomer($orderUuid, $purpose, $customerId);
        $paymentMethod = strtolower(trim($paymentMethod));
        $paymentIdempotencyKey = trim($paymentIdempotencyKey);
        if ($paymentMethod === '') {
            throw new B2BConflictException(self::ERROR_METHOD, __('请选择支付方式'));
        }
        if ($paymentIdempotencyKey === '' || strlen($paymentIdempotencyKey) > 128) {
            throw new B2BConflictException(self::ERROR_PURPOSE, __('支付幂等键无效'));
        }

        $payContext = [
            'purpose' => $purpose,
            'hang_purpose' => $purpose,
            'deposit_amount_minor' => $hang->depositAmountMinor,
            'balance_amount_minor' => $hang->balanceAmountMinor,
            'country_code' => (string)($context['country_code'] ?? ''),
            'locale' => (string)($context['locale'] ?? ''),
            'environment' => (string)($context['environment'] ?? 'sandbox'),
            'quote_token' => (string)($context['quote_token'] ?? ''),
            'checkout_token' => (string)($context['checkout_token'] ?? $context['quote_token'] ?? ''),
        ];

        $payment = $this->payments()->pay(
            [$hang->orderRef],
            $paymentMethod,
            $paymentIdempotencyKey,
            $payContext,
        );

        $updated = $this->hangOrders->getByOrderRef($hang->orderRef);

        return [
            'success' => true,
            'ok' => true,
            'order_uuid' => $hang->orderRef,
            'purpose' => $purpose,
            'hang_status' => $updated?->hangStatus ?? $hang->hangStatus,
            'payment' => $payment,
        ];
    }

    private function requireHangForCustomer(string $orderUuid, string $purpose, ?int $customerId): B2BOrderHang
    {
        $orderUuid = trim($orderUuid);
        $purpose = strtolower(trim($purpose));
        if ($customerId === null || $customerId <= 0) {
            throw new B2BConflictException(self::ERROR_AUTH, __('请先登录后再继续支付'));
        }
        if (!in_array($purpose, [
            B2BHangOrderService::PURPOSE_DEPOSIT,
            B2BHangOrderService::PURPOSE_BALANCE,
        ], true)) {
            throw new B2BConflictException(self::ERROR_PURPOSE, __('支付用途无效'));
        }
        if ($orderUuid === '') {
            throw new B2BConflictException(self::ERROR_PURPOSE, __('订单编号无效'));
        }

        $hang = $this->hangOrders->getByOrderRef($orderUuid);
        if ($hang === null) {
            throw new B2BConflictException(B2BHangOrderService::ERROR_NOT_FOUND, __('B2B hang 不存在'));
        }
        if ((string)$hang->customerId !== (string)$customerId) {
            throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权支付该挂单'));
        }

        $expected = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
            ? B2BOrderHang::STATUS_AWAITING_DEPOSIT
            : B2BOrderHang::STATUS_AWAITING_BALANCE;
        if ($hang->hangStatus !== $expected) {
            throw new B2BConflictException(
                self::ERROR_STATE,
                __('当前挂单状态不可支付'),
                ['hang_status' => $hang->hangStatus, 'purpose' => $purpose],
            );
        }

        // Soft ownership check against Order projection when available.
        try {
            $order = $this->orders()->get($orderUuid);
            $owner = (int)($order->customerId ?? 0);
            if ($owner > 0 && $owner !== $customerId) {
                throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权支付该订单'));
            }
        } catch (B2BConflictException $e) {
            throw $e;
        } catch (\Throwable) {
            // Order read is optional for hang-only fixtures.
        }

        return $hang;
    }

    private function orders(): OrderFacadeInterface
    {
        if ($this->orders instanceof OrderFacadeInterface) {
            return $this->orders;
        }
        $resolved = ObjectManager::getInstance(OrderFacadeInterface::class);
        if (!$resolved instanceof OrderFacadeInterface) {
            throw new \RuntimeException('b2b_hang_payment_order_facade_missing');
        }

        return $resolved;
    }

    private function payments(): CheckoutOrderPaymentService
    {
        if ($this->payments instanceof CheckoutOrderPaymentService) {
            return $this->payments;
        }
        $resolved = ObjectManager::getInstance(CheckoutOrderPaymentService::class);
        if (!$resolved instanceof CheckoutOrderPaymentService) {
            throw new \RuntimeException('b2b_hang_payment_checkout_payment_missing');
        }

        return $resolved;
    }
}
