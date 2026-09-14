<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentTransactionRecord;
use Weline\Payment\Api\PaymentFacadeInterface;

/**
 * Starts payment for Checkout V2 orders through the stable Weline_Payment boundary.
 *
 * Money, currency and ownership always come from the persisted Order projection.
 */
final class CheckoutOrderPaymentService
{
    public function __construct(
        private readonly OrderFacadeInterface $orders,
        private readonly PaymentFacadeInterface $payments,
        private readonly CheckoutSuccessUrlBuilder $successUrlBuilder,
        private readonly CheckoutSessionStoreInterface $checkoutSessions,
    ) {
    }

    /**
     * @param list<string> $orderUuids
     * @param array<string, mixed> $context Non-monetary payment context only.
     * @return array{
     *     paid:bool,
     *     outcome:'paid'|'partial'|'pending'|'failed',
     *     status:string,
     *     requires_action:bool,
     *     recoverable:bool,
     *     redirect_url:?string,
     *     purpose?:string,
     *     transactions:list<array<string,mixed>>
     * }
     */
    public function pay(
        array $orderUuids,
        string $methodCode,
        string $idempotencyKey,
        array $context = [],
    ): array {
        $methodCode = strtolower(trim($methodCode));
        $idempotencyKey = trim($idempotencyKey);
        $orderUuids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $uuid): string => trim((string)$uuid), $orderUuids),
        )));
        if ($orderUuids === []) {
            throw new \InvalidArgumentException('checkout_payment_order_required');
        }
        if ($methodCode === '') {
            throw new \InvalidArgumentException('checkout_payment_method_required');
        }
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('checkout_payment_idempotency_required');
        }

        $transactions = [];
        $hasPending = false;
        $hasFailed = false;
        $hasPartial = false;
        $notificationPending = false;
        $redirectUrl = null;
        $lastPurpose = '';
        foreach ($orderUuids as $orderUuid) {
            $order = $this->orders->get($orderUuid);
            $hangPurpose = $this->resolveHangPurpose(
                $order,
                strtolower(trim((string)($context['hang_purpose'] ?? $context['purpose'] ?? ''))),
            );
            if (in_array(strtolower(trim($order->status)), ['paid', 'fulfilled', 'completed'], true)) {
                // P0-2: Order already paid but hang may still need reconcile (async path drift).
                $reconciled = $this->reconcileHangAfterAlreadyPaid(
                    $order->orderUuid,
                    $hangPurpose,
                    $idempotencyKey,
                    $methodCode,
                );
                $transactions[] = [
                    'order_uuid' => $order->orderUuid,
                    'transaction_id' => null,
                    'transaction_no' => '',
                    'method_code' => $methodCode,
                    'status' => 'already_paid',
                    'hang_reconciled' => $reconciled,
                    'response' => [],
                ];
                if ($hangPurpose === 'deposit') {
                    $hasPartial = true;
                    $lastPurpose = 'deposit';
                } elseif ($hangPurpose === 'balance') {
                    $lastPurpose = 'balance';
                }
                continue;
            }
            $amountMinor = (int)($order->money['grand_total_minor'] ?? 0);
            $typePayload = $order->typePayload;
            if ($hangPurpose === 'deposit' || ($hangPurpose === '' && strtolower($order->orderType) === 'tob')) {
                $hangPurpose = $hangPurpose !== '' ? $hangPurpose : 'deposit';
                // Cash deposit is authoritative when credit apply was planned (0 allowed).
                if (array_key_exists('b2b_credit_cash_deposit_minor', $typePayload)) {
                    $amountMinor = max(0, (int)$typePayload['b2b_credit_cash_deposit_minor']);
                } elseif (array_key_exists('deposit_amount_minor', $context)) {
                    $amountMinor = max(0, (int)$context['deposit_amount_minor']);
                } elseif (isset($typePayload['deposit_amount_minor'])) {
                    $amountMinor = max(0, (int)$typePayload['deposit_amount_minor']);
                } else {
                    $hangAmounts = $this->hangAmountsForOrder($order->orderUuid);
                    $amountMinor = max(0, (int)($hangAmounts['deposit_amount_minor'] ?? 0));
                }
            } elseif ($hangPurpose === 'balance') {
                if (isset($context['balance_amount_minor']) && (int)$context['balance_amount_minor'] > 0) {
                    $amountMinor = (int)$context['balance_amount_minor'];
                } elseif (isset($typePayload['balance_amount_minor'])) {
                    $amountMinor = (int)$typePayload['balance_amount_minor'];
                } else {
                    $hangAmounts = $this->hangAmountsForOrder($order->orderUuid);
                    $amountMinor = (int)($hangAmounts['balance_amount_minor'] ?? 0);
                }
            }

            // P0-4: credit reserve only for deposit purpose (never on balance).
            if ($hangPurpose === 'deposit') {
                $creditReserved = $this->reserveB2bCreditForDeposit($order, $typePayload, $idempotencyKey);
                if ($creditReserved !== null) {
                    $typePayload = $creditReserved;
                }
            }

            // Zero cash deposit: credit covered the deposit — skip PSP, mark deposit paid.
            if ($hangPurpose === 'deposit' && $amountMinor <= 0) {
                $lastPurpose = $hangPurpose;
                $this->notifyHangDepositPaid(
                    $order->orderUuid,
                    'b2b_credit_zero_cash_' . $order->orderUuid,
                    $methodCode !== '' ? $methodCode : 'b2b_credit',
                );
                $transactions[] = [
                    'order_uuid' => $order->orderUuid,
                    'transaction_id' => null,
                    'transaction_no' => 'b2b_credit_zero_cash',
                    'method_code' => 'b2b_credit',
                    'status' => PaymentTransactionRecord::STATUS_SUCCESS,
                    'response' => [],
                ];
                $hasPartial = true;
                continue;
            }

            if ($amountMinor <= 0) {
                $this->releaseB2bCreditForDeposit($order->orderUuid, $typePayload, $idempotencyKey);
                throw new \RuntimeException('checkout_payment_amount_invalid');
            }
            $lastPurpose = $hangPurpose;

            $customerId = (int)($order->customerId ?? 0);
            $paymentContext = [
                'order_id' => $order->orderUuid,
                'payable_type' => 'weline_order',
                'payable_id' => $order->orderUuid,
                'payable_status' => $order->status,
                'amount_minor' => $amountMinor,
                'amount' => $amountMinor / 100.0,
                'currency' => strtoupper($order->currency),
                'currency_code' => strtoupper($order->currency),
                'website_id' => $order->websiteId,
                'store_id' => $order->storeId,
                'customer_id' => $customerId,
                'actor_type' => $customerId > 0 ? 'customer' : 'guest',
                'actor_id' => $customerId > 0 ? (string)$customerId : 'anonymous',
                'items' => $order->items,
                'totals' => $order->money,
                'shipping_snapshot' => $order->shipping,
                'coupon_code' => trim((string) ($context['coupon_code'] ?? '')),
                'metadata' => [
                    'checkout_group_uuid' => $order->checkoutGroupUuid,
                    'display_number' => $order->displayNumber,
                    'purpose' => $hangPurpose !== '' ? $hangPurpose : 'full',
                    'hang_purpose' => $hangPurpose !== '' ? $hangPurpose : 'full',
                    'order_type' => $order->orderType,
                ],
                'idempotency_key' => $idempotencyKey . ':' . $order->orderUuid
                    . ($hangPurpose !== '' ? ':' . $hangPurpose : ''),
            ];
            if (!empty($context['express_checkout'])) {
                try {
                    /** @var \Weline\Payment\Api\PaymentExpressFacadeInterface $express */
                    $express = ObjectManager::getInstance(\Weline\Payment\Api\PaymentExpressFacadeInterface::class);
                    $paymentContext = $express->withExpressContext($paymentContext, $methodCode);
                } catch (\Throwable) {
                    $paymentContext['express_checkout'] = true;
                    $paymentContext['metadata']['express_checkout'] = true;
                }
            }
            $guestToken = trim((string) ($context['guest_token'] ?? ''));
            if ($guestToken !== '') {
                $paymentContext['guest_token'] = $guestToken;
                $paymentContext['metadata']['guest_token'] = $guestToken;
            }
            if (array_key_exists('requires_shipping', $context)) {
                $paymentContext['requires_shipping'] = (bool) $context['requires_shipping'];
            }
            foreach (['country_code', 'language_code', 'locale', 'timezone', 'scope', 'environment'] as $key) {
                if (array_key_exists($key, $context) && !is_array($context[$key])) {
                    $paymentContext[$key] = $context[$key];
                }
            }

            $checkoutToken = trim((string) (
                $context['checkout_token']
                ?? $context['quote_token']
                ?? ''
            ));
            if ($checkoutToken !== '') {
                $this->prolongSubmittedSuccessCapability($checkoutToken);
            }
            $landingExtras = [
                'source' => 'payment_return',
                'checkout_group_uuid' => $order->checkoutGroupUuid,
            ];
            if ($hangPurpose !== '') {
                $landingExtras['purpose'] = $hangPurpose;
            }
            if ($checkoutToken !== '') {
                $landingExtras['checkout_token'] = $checkoutToken;
            }
            $expressCheckout = !empty($context['express_checkout']);
            $explicitLanding = trim((string) ($context['browser_landing_url'] ?? ''));
            if ($expressCheckout) {
                $paymentContext['browser_landing_url'] = $explicitLanding !== ''
                    ? $explicitLanding
                    : $this->successUrlBuilder->buildExpressReview([
                        'checkout_group_uuid' => $order->checkoutGroupUuid,
                        'checkout_token' => $checkoutToken !== '' ? $checkoutToken : null,
                    ]);
                $paymentContext['express_checkout'] = true;
            } else {
                $paymentContext['browser_landing_url'] = $explicitLanding !== ''
                    ? $explicitLanding
                    : $this->successUrlBuilder->buildForOrders(
                        [$order->orderUuid],
                        $landingExtras,
                    );
            }
            $landingParams = [];
            if ($order->checkoutGroupUuid !== '') {
                $landingParams['checkout_group_uuid'] = $order->checkoutGroupUuid;
            }
            if ($checkoutToken !== '') {
                $landingParams['checkout_token'] = $checkoutToken;
            }
            if ($hangPurpose !== '') {
                $landingParams['purpose'] = $hangPurpose;
            }
            if ($landingParams !== []) {
                $paymentContext['browser_landing_params'] = $landingParams;
            }

            try {
                $transaction = $this->payments->tryCreatePayment($methodCode, $paymentContext);
            } catch (\Throwable $e) {
                $this->releaseB2bCreditForDeposit($order->orderUuid, $typePayload, $idempotencyKey);
                throw $e;
            }
            if (!$transaction instanceof PaymentTransactionRecord) {
                $this->releaseB2bCreditForDeposit($order->orderUuid, $typePayload, $idempotencyKey);
                throw new \RuntimeException('checkout_payment_method_unavailable');
            }
            $this->rememberOrderPaymentMethod($order->orderUuid, (string)$transaction->methodCode);

            if ($expressCheckout && trim((string) $transaction->transactionNumber) !== '') {
                // Prefer landing with transaction_no so popup return can open review directly.
                $paymentContext['browser_landing_url'] = $this->successUrlBuilder->buildExpressReview([
                    'transaction_no' => $transaction->transactionNumber,
                    'checkout_group_uuid' => $order->checkoutGroupUuid !== '' ? $order->checkoutGroupUuid : null,
                    'checkout_token' => $checkoutToken !== '' ? $checkoutToken : null,
                ]);
                try {
                    /** @var \Weline\Payment\Service\PaymentCheckoutSessionPersistenceService $sessions */
                    $sessions = ObjectManager::getInstance(
                        \Weline\Payment\Service\PaymentCheckoutSessionPersistenceService::class
                    );
                    if (is_object($sessions) && method_exists($sessions, 'updateBrowserLanding')) {
                        $sessions->updateBrowserLanding(
                            (string) $transaction->transactionNumber,
                            $paymentContext['browser_landing_url'],
                            ['transaction_no' => $transaction->transactionNumber] + $landingParams,
                        );
                    }
                } catch (\Throwable) {
                }
            }

            $status = strtolower(trim($transaction->status));
            $paid = $status === PaymentTransactionRecord::STATUS_SUCCESS;
            if (!$paid && in_array($status, [
                PaymentTransactionRecord::STATUS_FAILED,
                PaymentTransactionRecord::STATUS_REFUNDED,
            ], true)) {
                $hasFailed = true;
                $this->releaseB2bCreditForDeposit($order->orderUuid, $typePayload, $idempotencyKey);
            } elseif (!$paid) {
                $hasPending = true;
            }
            $safeResponse = $this->sanitizeResponse($transaction->response);
            $transactionRedirect = $safeResponse['redirect_url'] ?? null;
            if ($redirectUrl === null && is_string($transactionRedirect) && $transactionRedirect !== '') {
                $redirectUrl = $transactionRedirect;
            }
            $transactions[] = [
                'order_uuid' => $order->orderUuid,
                'transaction_id' => $transaction->id,
                'transaction_no' => $transaction->transactionNumber,
                'method_code' => $transaction->methodCode,
                'status' => $status,
                'response' => $safeResponse,
            ];
            if ($paid) {
                try {
                    if ($hangPurpose === 'deposit') {
                        $this->notifyHangDepositPaid(
                            $order->orderUuid,
                            (string)$transaction->transactionNumber,
                            (string)$transaction->methodCode,
                        );
                        $hasPartial = true;
                    } elseif ($hangPurpose === 'balance') {
                        $this->notifyHangBalancePaid(
                            $order->orderUuid,
                            (string)$transaction->transactionNumber,
                        );
                        $this->orders->notifyOrderPaid($order->orderUuid, [
                            'payment_method' => $transaction->methodCode,
                            'payment_transaction_id' => $transaction->id,
                            'payment_transaction_no' => $transaction->transactionNumber,
                        ]);
                    } else {
                        $this->orders->notifyOrderPaid($order->orderUuid, [
                            'payment_method' => $transaction->methodCode,
                            'payment_transaction_id' => $transaction->id,
                            'payment_transaction_no' => $transaction->transactionNumber,
                        ]);
                    }
                } catch (\Throwable) {
                    // The provider has already captured/accepted payment. Do
                    // not report a retryable failure that could charge again;
                    // keep the order in a reconciliation-pending state.
                    $notificationPending = true;
                    $hasPending = true;
                }
            }
        }

        $outcome = $hasFailed
            ? 'failed'
            : ($hasPending ? 'pending' : ($hasPartial ? 'partial' : 'paid'));
        $status = $notificationPending
            ? PaymentTransactionRecord::STATUS_PROCESSING
            : match ($outcome) {
            'paid', 'partial' => PaymentTransactionRecord::STATUS_SUCCESS,
            'failed' => PaymentTransactionRecord::STATUS_FAILED,
            default => PaymentTransactionRecord::STATUS_PENDING,
        };

        $result = [
            'paid' => $outcome === 'paid',
            'outcome' => $outcome,
            'status' => $status,
            'requires_action' => $redirectUrl !== null,
            'recoverable' => !in_array($outcome, ['paid', 'partial'], true) && !$notificationPending,
            'redirect_url' => $redirectUrl,
            'transactions' => $transactions,
        ];
        if ($lastPurpose !== '') {
            $result['purpose'] = $lastPurpose;
        }
        if ($notificationPending) {
            $result['error_code'] = 'checkout_order_payment_notification_pending';
        }

        return $result;
    }

    private function resolveHangPurpose(OrderReadResult $order, string $hangPurpose): string
    {
        if ($hangPurpose !== '') {
            return $hangPurpose;
        }
        if (strtolower(trim($order->orderType)) !== 'tob') {
            return '';
        }
        $hang = $this->hangForOrder($order->orderUuid);
        if ($hang === null) {
            return '';
        }
        $status = (string)($hang['hang_status'] ?? '');

        return match ($status) {
            'awaiting_deposit' => 'deposit',
            'awaiting_balance' => 'balance',
            default => '',
        };
    }

    /** @return array{deposit_amount_minor?:int,balance_amount_minor?:int,hang_status?:string}|null */
    private function hangForOrder(string $orderUuid): ?array
    {
        if (!interface_exists(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class)) {
            return null;
        }
        try {
            $bridge = ObjectManager::getInstance(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class);
            if (!$bridge instanceof \Weline\B2B\Api\B2BHangPaymentBridgeInterface) {
                return null;
            }

            return $bridge->getHangByOrderRef($orderUuid);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{deposit_amount_minor:int,balance_amount_minor:int} */
    private function hangAmountsForOrder(string $orderUuid): array
    {
        $hang = $this->hangForOrder($orderUuid);

        return [
            'deposit_amount_minor' => (int)($hang['deposit_amount_minor'] ?? 0),
            'balance_amount_minor' => (int)($hang['balance_amount_minor'] ?? 0),
        ];
    }

    private function notifyHangDepositPaid(string $orderUuid, string $intentCode, string $methodCode = ''): void
    {
        if (!interface_exists(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class)) {
            return;
        }
        try {
            $bridge = ObjectManager::getInstance(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class);
            if (!$bridge instanceof \Weline\B2B\Api\B2BHangPaymentBridgeInterface) {
                return;
            }
            $bridge->onDepositPaid($orderUuid, $intentCode !== '' ? $intentCode : 'deposit_' . $orderUuid);
            $this->markOrderPaymentPartial($orderUuid, $methodCode);
        } catch (\Throwable) {
            // Hang row may be absent in unit fixtures; payment amount path still stands.
        }
    }

    /** P0-2: when Order is already paid, still advance hang if purpose requires it. */
    private function reconcileHangAfterAlreadyPaid(
        string $orderUuid,
        string $hangPurpose,
        string $idempotencyKey,
        string $methodCode = '',
    ): bool {
        if ($hangPurpose !== 'deposit' && $hangPurpose !== 'balance') {
            return false;
        }
        if (!interface_exists(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class)) {
            return false;
        }
        try {
            $bridge = ObjectManager::getInstance(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class);
            if (!$bridge instanceof \Weline\B2B\Api\B2BHangPaymentBridgeInterface) {
                return false;
            }
            $intent = 'already_paid_' . $hangPurpose . '_' . ($idempotencyKey !== '' ? $idempotencyKey : $orderUuid);
            $before = $bridge->getHangByOrderRef($orderUuid);
            $bridge->reconcilePaymentSuccess($orderUuid, $hangPurpose, $intent);
            if ($hangPurpose === 'deposit') {
                $this->markOrderPaymentPartial($orderUuid, $methodCode);
            }
            $after = $bridge->getHangByOrderRef($orderUuid);

            return ($before['hang_status'] ?? null) !== ($after['hang_status'] ?? null)
                || ($after !== null && in_array((string)($after['hang_status'] ?? ''), [
                    'awaiting_merchant_approval',
                    'awaiting_balance',
                    'completed',
                ], true));
        } catch (\Throwable) {
            return false;
        }
    }

    private function markOrderPaymentPartial(string $orderUuid, string $methodCode = ''): void
    {
        $orderUuid = trim($orderUuid);
        $methodCode = strtolower(trim($methodCode));
        if ($orderUuid === '') {
            return;
        }
        try {
            if ($this->orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                $this->orders->mergeTypePayload($orderUuid, [
                    'payment_status' => 'partial',
                    'hang_status' => 'awaiting_merchant_approval',
                ]);
            }
        } catch (\Throwable) {
            // Soft-fail projection.
        }
        try {
            if (!class_exists(\Weline\Order\Model\Order::class)) {
                return;
            }
            /** @var \Weline\Order\Model\Order $model */
            $model = ObjectManager::getInstance(\Weline\Order\Model\Order::class);
            $model->reset()->load(\Weline\Order\Model\Order::schema_fields_ORDER_UUID, $orderUuid);
            if (!(int)$model->getId()) {
                return;
            }
            $current = strtolower(trim((string)$model->getData(\Weline\Order\Model\Order::schema_fields_PAYMENT_STATUS)));
            if ($current !== \Weline\Order\Model\Order::PAYMENT_STATUS_PAID) {
                $model->setData(
                    \Weline\Order\Model\Order::schema_fields_PAYMENT_STATUS,
                    \Weline\Order\Model\Order::PAYMENT_STATUS_PARTIAL,
                );
            }
            if ($methodCode !== '') {
                $existingMethod = trim((string)$model->getData(\Weline\Order\Model\Order::schema_fields_PAYMENT_METHOD));
                if ($existingMethod === '') {
                    $model->setData(\Weline\Order\Model\Order::schema_fields_PAYMENT_METHOD, $methodCode);
                }
            }
            $model->save();
        } catch (\Throwable) {
            // Soft-fail DB write.
        }
    }

    private function rememberOrderPaymentMethod(string $orderUuid, string $methodCode): void
    {
        $orderUuid = trim($orderUuid);
        $methodCode = strtolower(trim($methodCode));
        if ($orderUuid === '' || $methodCode === '' || !class_exists(\Weline\Order\Model\Order::class)) {
            return;
        }
        try {
            /** @var \Weline\Order\Model\Order $model */
            $model = ObjectManager::getInstance(\Weline\Order\Model\Order::class);
            $model->reset()->load(\Weline\Order\Model\Order::schema_fields_ORDER_UUID, $orderUuid);
            if (!(int)$model->getId()) {
                return;
            }
            $existingMethod = trim((string)$model->getData(\Weline\Order\Model\Order::schema_fields_PAYMENT_METHOD));
            if ($existingMethod !== '') {
                return;
            }
            $model->setData(\Weline\Order\Model\Order::schema_fields_PAYMENT_METHOD, $methodCode)->save();
        } catch (\Throwable) {
            // Soft-fail DB write.
        }
    }

    /**
     * @param array<string,mixed> $typePayload
     * @return array<string,mixed>|null updated type_payload
     */
    private function reserveB2bCreditForDeposit(
        OrderReadResult $order,
        array $typePayload,
        string $idempotencyKey,
    ): ?array {
        if (strtolower($order->orderType) !== 'tob') {
            return null;
        }
        $creditStatus = strtolower(trim((string)($typePayload['b2b_credit_status'] ?? '')));
        if (in_array($creditStatus, ['committed', 'released'], true)) {
            return null;
        }
        $applyBase = max(0, (int)($typePayload['b2b_credit_apply_base_minor'] ?? 0));
        if ($applyBase <= 0 || ($typePayload['discount_kind'] ?? '') !== 'asset_b2b_credit') {
            return null;
        }
        if (!class_exists(\Weline\B2B\Service\B2BDepositCreditOrchestrator::class)) {
            return null;
        }
        try {
            $orch = ObjectManager::getInstance(\Weline\B2B\Service\B2BDepositCreditOrchestrator::class);
            if (!$orch instanceof \Weline\B2B\Service\B2BDepositCreditOrchestrator) {
                return null;
            }
            $customerId = (string)($order->customerId ?? '');
            $result = $orch->reserveForDeposit(
                $customerId,
                $order->websiteId,
                $order->orderUuid,
                $idempotencyKey,
                [
                    'apply_base_minor' => $applyBase,
                    'apply_checkout_minor' => (int)($typePayload['b2b_credit_apply_checkout_minor'] ?? 0),
                    'cash_deposit_minor' => (int)($typePayload['b2b_credit_cash_deposit_minor'] ?? 0),
                    'base_currency' => (string)($typePayload['fx_base_currency'] ?? ''),
                    'checkout_currency' => (string)($typePayload['fx_checkout_currency'] ?? ''),
                    'fx' => [
                        'rate' => (string)($typePayload['fx_rate'] ?? ''),
                        'label' => (string)($typePayload['fx_rate_label'] ?? ''),
                    ],
                ],
            );
            if (!$result['ok']) {
                throw new \RuntimeException('b2b_credit_reserve_failed:' . (string)($result['error'] ?? ''));
            }
            $merged = array_merge($typePayload, $result['type_payload']);
            if ($this->orders instanceof \Weline\Order\Service\OrderFacade) {
                $this->orders->mergeTypePayload($order->orderUuid, $merged);
            }

            return $merged;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $typePayload */
    private function releaseB2bCreditForDeposit(
        string $orderUuid,
        array $typePayload,
        string $idempotencyKey,
    ): void {
        if (!class_exists(\Weline\B2B\Service\B2BDepositCreditOrchestrator::class)) {
            return;
        }
        try {
            $orch = ObjectManager::getInstance(\Weline\B2B\Service\B2BDepositCreditOrchestrator::class);
            if (!$orch instanceof \Weline\B2B\Service\B2BDepositCreditOrchestrator) {
                return;
            }
            $result = $orch->releaseOnFailure($typePayload, $orderUuid, $idempotencyKey);
            if ($this->orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                $this->orders->mergeTypePayload($orderUuid, $result['type_payload']);
            }
        } catch (\Throwable) {
            // Soft-fail release.
        }
    }

    private function notifyHangBalancePaid(string $orderUuid, string $intentCode): void
    {
        if (!interface_exists(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class)) {
            return;
        }
        try {
            $bridge = ObjectManager::getInstance(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class);
            if (!$bridge instanceof \Weline\B2B\Api\B2BHangPaymentBridgeInterface) {
                return;
            }
            $bridge->onBalancePaid($orderUuid, $intentCode !== '' ? $intentCode : 'balance_' . $orderUuid);
        } catch (\Throwable) {
            // Soft-fail: balance charge already succeeded at Payment boundary.
        }
    }

    /**
     * Only publish the action fields Checkout needs. Raw Provider payloads may
     * contain credentials, signatures or processor diagnostics.
     *
     * @param array<string, mixed> $response
     * @return array{redirect_url?:string}
     */
    private function sanitizeResponse(array $response): array
    {
        $redirectUrl = $this->extractRedirectUrl($response);

        return $redirectUrl === null ? [] : ['redirect_url' => $redirectUrl];
    }

    /** @param array<string, mixed> $response */
    private function extractRedirectUrl(array $response): ?string
    {
        foreach (['redirect_url', 'redirect', 'url'] as $key) {
            $candidate = trim((string)($response[$key] ?? ''));
            if ($this->isSafeRedirectUrl($candidate)) {
                return $candidate;
            }
        }
        foreach (['payload', 'gateway_response', 'response', 'next_action'] as $key) {
            $nested = $response[$key] ?? null;
            if (is_array($nested)) {
                $candidate = $this->extractRedirectUrl($nested);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function isSafeRedirectUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function prolongSubmittedSuccessCapability(string $checkoutToken): void
    {
        $session = $this->checkoutSessions->get($checkoutToken);
        if (!is_array($session)) {
            return;
        }
        if ((string)($session['state'] ?? '') !== CheckoutSession::STATE_SUBMITTED) {
            return;
        }
        $this->checkoutSessions->put(
            $checkoutToken,
            $session,
            gmdate('Y-m-d H:i:s', time() + CheckoutSession::TTL_SUBMITTED_SUCCESS_SECONDS),
        );
    }
}
