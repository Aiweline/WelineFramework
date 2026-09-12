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
        $fullDeposit = $hang->depositAmountMinor;
        $cashDeposit = $fullDeposit;
        $creditApply = 0;
        $currency = 'CNY';
        try {
            $read = $this->orders()->get($hang->orderRef);
            $currency = strtoupper((string)($read->currency ?: $currency));
            $tp = $read->typePayload;
            if ($purpose === B2BHangOrderService::PURPOSE_DEPOSIT
                && array_key_exists('b2b_credit_cash_deposit_minor', $tp)
            ) {
                $cashDeposit = max(0, (int)$tp['b2b_credit_cash_deposit_minor']);
                $amountMinor = $cashDeposit;
                $creditApply = max(0, (int)($tp['b2b_credit_apply_checkout_minor'] ?? 0));
            }
        } catch (\Throwable) {
            // Fall back to hang full deposit.
        }

        return [
            'success' => true,
            'ok' => true,
            'order_uuid' => $hang->orderRef,
            'purpose' => $purpose,
            'hang_status' => $hang->hangStatus,
            'amount_minor' => $amountMinor,
            'deposit_amount_minor' => $fullDeposit,
            'b2b_credit_cash_deposit_minor' => $cashDeposit,
            'b2b_credit_apply_checkout_minor' => $creditApply,
            'balance_amount_minor' => $hang->balanceAmountMinor,
            'currency' => $currency,
            'payment_methods' => $this->loadPaymentMethods($amountMinor, $currency),
            'revision_pending' => $this->isBalanceRevisionPending($hang->orderRef),
            'revision_version' => $this->balanceRevisionVersion($hang->orderRef),
            'label' => $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
                ? (string)__('支付定金')
                : (string)__('支付尾款'),
        ];
    }

    /**
     * Active checkout payment methods for hang panel (fail-soft empty list).
     *
     * @return list<array{code:string,title:string}>
     */
    private function loadPaymentMethods(int $amountMinor, string $currency): array
    {
        try {
            if (!function_exists('w_query')) {
                return $this->fallbackActiveMethodCodes();
            }
            $result = w_query('payment', 'getCheckoutPaymentMethods', [
                'currency' => $currency,
                'amount' => $amountMinor / 100.0,
                'amount_minor' => $amountMinor,
            ]);
            $list = [];
            if (is_array($result)) {
                $raw = is_array($result['data'] ?? null) ? $result['data'] : $result;
                if (is_array($raw)) {
                    $list = $raw;
                }
            }
            $out = [];
            foreach ($list as $method) {
                if (!is_array($method)) {
                    continue;
                }
                $code = strtolower(trim((string)($method['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                if (array_key_exists('enabled', $method) && !$method['enabled']) {
                    continue;
                }
                $title = trim((string)($method['title'] ?? $method['name'] ?? $code));
                $out[] = [
                    'code' => $code,
                    'title' => $title !== '' ? $title : $code,
                ];
            }

            return $out !== [] ? $out : $this->fallbackActiveMethodCodes();
        } catch (\Throwable) {
            return $this->fallbackActiveMethodCodes();
        }
    }

    /**
     * @return list<array{code:string,title:string}>
     */
    private function fallbackActiveMethodCodes(): array
    {
        try {
            if (!class_exists(\Weline\Payment\Service\PaymentMethodManager::class)) {
                return [];
            }
            $mgr = ObjectManager::getInstance(\Weline\Payment\Service\PaymentMethodManager::class);
            if (!$mgr instanceof \Weline\Payment\Service\PaymentMethodManager) {
                return [];
            }
            $out = [];
            foreach ($mgr->getActiveMethods([]) as $method) {
                if (!$method instanceof \Weline\Payment\Model\PaymentMethod) {
                    continue;
                }
                $code = strtolower(trim((string)$method->getData(\Weline\Payment\Model\PaymentMethod::schema_fields_CODE)));
                if ($code === '') {
                    continue;
                }
                $title = trim((string)$method->getData(\Weline\Payment\Model\PaymentMethod::schema_fields_NAME));
                $out[] = [
                    'code' => $code,
                    'title' => $title !== '' ? $title : $code,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
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
            'balance_amount_minor' => $hang->balanceAmountMinor,
            'country_code' => (string)($context['country_code'] ?? ''),
            'locale' => (string)($context['locale'] ?? ''),
            'environment' => (string)($context['environment'] ?? 'sandbox'),
            'quote_token' => (string)($context['quote_token'] ?? ''),
            'checkout_token' => (string)($context['checkout_token'] ?? $context['quote_token'] ?? ''),
        ];
        // Prefer order type_payload cash deposit; do not force full hang deposit.
        if ($purpose === B2BHangOrderService::PURPOSE_DEPOSIT) {
            try {
                $tp = $this->orders()->get($hang->orderRef)->typePayload;
                if (array_key_exists('b2b_credit_cash_deposit_minor', $tp)) {
                    $payContext['deposit_amount_minor'] = max(0, (int)$tp['b2b_credit_cash_deposit_minor']);
                } else {
                    $payContext['deposit_amount_minor'] = $hang->depositAmountMinor;
                }
            } catch (\Throwable) {
                $payContext['deposit_amount_minor'] = $hang->depositAmountMinor;
            }
        }

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

        if ($purpose === B2BHangOrderService::PURPOSE_BALANCE) {
            $this->assertBalanceRevisionNotPending($hang->orderRef);
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

    private function assertBalanceRevisionNotPending(string $orderRef): void
    {
        if ($this->isBalanceRevisionPending($orderRef)) {
            throw new B2BConflictException(
                self::ERROR_STATE,
                __('尾款改价待确认，暂不可支付'),
                ['order_ref' => $orderRef],
            );
        }
    }

    private function isBalanceRevisionPending(string $orderRef): bool
    {
        try {
            $tp = $this->orders()->get($orderRef)->typePayload;

            return (bool)($tp['hang_revision_pending'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function balanceRevisionVersion(string $orderRef): int
    {
        try {
            $tp = $this->orders()->get($orderRef)->typePayload;

            return max(0, (int)($tp['hang_revision_version'] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
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
