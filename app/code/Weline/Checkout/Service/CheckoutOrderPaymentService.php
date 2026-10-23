<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\MoneySnapshot;
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
        // 支付方式码只在真正发起网关扣款时才必需：资产结算（授信覆盖全额定金）与
        // 已付对账都不经过任何 Provider，前端也不应为了过校验编造 method code。
        // 因此该校验下移到扣款点，见下方 $amountMinor 判定之后。
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('checkout_payment_idempotency_required');
        }
        // Card Fields 一次只承载一个 PayPal order；拆单/express 直接回退跳转，
        // 避免生成多个卡单或把 express 地址流混进站内卡收银台。
        if (!empty($context['client_action'])
            && (count($orderUuids) !== 1 || !empty($context['express_checkout']))
        ) {
            $context['client_action'] = false;
            $context['client_action_fallback'] = 'unsupported_order_shape';
        }

        $transactions = [];
        $hasPending = false;
        $hasFailed = false;
        $hasPartial = false;
        $notificationPending = false;
        $redirectUrl = null;
        $clientAction = null;
        $lastPurpose = '';
        $chargedAmountMinor = 0;
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
            // 走到这里说明本单确实要发起网关扣款，此时 method code 才是必需的。
            if ($methodCode === '') {
                $this->releaseB2bCreditForDeposit($order->orderUuid, $typePayload, $idempotencyKey);
                throw new \InvalidArgumentException('checkout_payment_method_required');
            }
            $lastPurpose = $hangPurpose;

            $customerId = (int)($order->customerId ?? 0);
            $seedDiscountLines = [];
            if (\is_array($order->typePayload['discount_lines'] ?? null)) {
                $seedDiscountLines = $order->typePayload['discount_lines'];
            }
            $couponCode = strtoupper(trim((string) (
                $context['coupon_code']
                ?? $order->typePayload['coupon_code']
                ?? $order->money['coupon_code']
                ?? ''
            )));
            $moneyDiscountMinor = max(0, (int) ($order->money['discount_amount_minor'] ?? 0));
            // Checkout authority: when money has coupon discount but type_payload lines
            // were not persisted, seed a single coupon/cart line for Payment projection.
            if ($seedDiscountLines === [] && $moneyDiscountMinor > 0) {
                $seedDiscountLines = [[
                    'key' => $couponCode !== '' ? ('coupon:' . $couponCode) : 'discount',
                    'label' => $couponCode !== ''
                        ? (string) __('优惠券 (%1)', [$couponCode])
                        : (string) __('优惠'),
                    'amount_minor' => -1 * $moneyDiscountMinor,
                    'source_type' => $couponCode !== '' ? 'coupon' : 'cart',
                ]];
            }
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
                'discount_lines' => $seedDiscountLines,
                'shipping_snapshot' => $order->shipping,
                'coupon_code' => $couponCode,
                'checkout_entry' => (string) ($order->checkoutEntry ?? ''),
                'payment_mode' => strtolower(trim((string) ($context['payment_mode'] ?? ''))),
                'continue_pay' => !empty($context['continue_pay'])
                    || strtolower(trim((string) ($context['payment_mode'] ?? ''))) === 'continue_pay',
                'metadata' => [
                    'checkout_group_uuid' => $order->checkoutGroupUuid,
                    'display_number' => $order->displayNumber,
                    'purpose' => $hangPurpose !== '' ? $hangPurpose : 'full',
                    'hang_purpose' => $hangPurpose !== '' ? $hangPurpose : 'full',
                    'order_type' => $order->orderType,
                    'checkout_entry' => (string) ($order->checkoutEntry ?? ''),
                ],
                'idempotency_key' => $idempotencyKey . ':' . $order->orderUuid
                    . ($hangPurpose !== '' ? ':' . $hangPurpose : ''),
            ];
            // 选中支付方式激励：并入 discount_lines 并下调应付（与 PaymentService create 幂等）
            if ($hangPurpose === '' || $hangPurpose === 'full') {
                $paymentContext = (new CheckoutPaymentIncentiveApplier())
                    ->applyToPaymentContext($methodCode, $paymentContext);
                $amountMinor = (int)($paymentContext['amount_minor'] ?? $amountMinor);
                $this->syncOrderPaymentIncentive($order, $paymentContext);
            }
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
            // Prefer caller payment_entry; else express / continue_pay / checkout default.
            $paymentContext = \Weline\Payment\Service\PaymentEntry::stampContext(
                $paymentContext,
                !empty($paymentContext['express_checkout'])
                    ? \Weline\Payment\Service\PaymentEntry::EXPRESS
                    : (
                        !empty($paymentContext['continue_pay'])
                            ? \Weline\Payment\Service\PaymentEntry::CONTINUE_PAY
                            : \Weline\Payment\Service\PaymentEntry::CHECKOUT
                    ),
            );
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
            if (!empty($context['client_action'])) {
                $paymentContext['client_action'] = true;
            }
            if (!empty($context['client_action_fallback'])) {
                $paymentContext['client_action_fallback'] = (string) $context['client_action_fallback'];
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
            if ($clientAction === null && \is_array($safeResponse['client_action'] ?? null)) {
                $clientAction = $safeResponse['client_action'];
            }
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
                'amount_minor' => $amountMinor,
                'response' => $safeResponse,
            ];
            $chargedAmountMinor += max(0, $amountMinor);
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
                        $this->ensureOrderMoneyConserved($order->orderUuid, $this->orders->get($order->orderUuid)->money);
                        $this->notifyOrderPaidCaptured($order->orderUuid, [
                            'payment_method' => $transaction->methodCode,
                            'payment_transaction_id' => $transaction->id,
                            'payment_transaction_no' => $transaction->transactionNumber,
                            'suppress_customer_paid_mail' => true,
                            'hang_purpose' => 'balance',
                        ]);
                    } else {
                        $this->ensureOrderMoneyConserved($order->orderUuid, $this->orders->get($order->orderUuid)->money);
                        $this->notifyOrderPaidCaptured($order->orderUuid, [
                            'payment_method' => $transaction->methodCode,
                            'payment_transaction_id' => $transaction->id,
                            'payment_transaction_no' => $transaction->transactionNumber,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Provider already captured. Never mark recoverable/failed (double-charge risk).
                    // Log + one immediate retry; Success-page reconciler is the durable safety net.
                    $this->logOrderPaidNotificationFailure($order->orderUuid, $transaction, $e);
                    try {
                        $retryMeta = [
                            'payment_method' => $transaction->methodCode,
                            'payment_transaction_id' => $transaction->id,
                            'payment_transaction_no' => $transaction->transactionNumber,
                            'notification_retry' => 1,
                        ];
                        if ($hangPurpose === 'balance') {
                            $retryMeta['suppress_customer_paid_mail'] = true;
                            $retryMeta['hang_purpose'] = 'balance';
                        }
                        $this->notifyOrderPaidCaptured($order->orderUuid, $retryMeta);
                    } catch (\Throwable $retry) {
                        $this->logOrderPaidNotificationFailure($order->orderUuid, $transaction, $retry, true);
                        $notificationPending = true;
                        $hasPending = true;
                    }
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
            'requires_action' => $redirectUrl !== null || $clientAction !== null,
            'recoverable' => !in_array($outcome, ['paid', 'partial'], true) && !$notificationPending,
            'redirect_url' => $redirectUrl,
            'client_action' => $clientAction,
            'transactions' => $transactions,
            // 续付金额漂移检测：pending 跳转金额须与订单权威 grand 对齐。
            'amount_minor' => $chargedAmountMinor,
        ];
        if ($lastPurpose !== '') {
            $result['purpose'] = $lastPurpose;
        }
        if ($notificationPending) {
            $result['error_code'] = 'checkout_order_payment_notification_pending';
        }

        return $result;
    }

    /**
     * Confirm an on-site card payment (PayPal Card Fields) for an already-created
     * order. The transaction must belong to the order, and capture is performed
     * by the Payment shell using the provider reference persisted at create time.
     *
     * @param array<string, mixed> $context
     * @return array{
     *     paid:bool,
     *     outcome:'paid'|'pending'|'failed',
     *     status:string,
     *     requires_action:bool,
     *     recoverable:bool,
     *     redirect_url:?string,
     *     transactions:list<array<string,mixed>>,
     *     notification_pending?:bool
     * }
     */
    public function confirm(
        string $orderUuid,
        string $transactionNo,
        string $quoteToken,
        string $orderIdempotencyKey,
        array $context = [],
    ): array {
        $orderUuid = trim($orderUuid);
        $transactionNo = trim($transactionNo);
        $quoteToken = trim($quoteToken);
        $orderIdempotencyKey = trim($orderIdempotencyKey);
        if ($orderUuid === '' || $transactionNo === '') {
            throw new \InvalidArgumentException('checkout_payment_confirm_required');
        }

        // Bind capture to the submitted checkout session. A submitted order must
        // have a session; when the session exposes order_uuids, the order must be
        // one of them. Never capture a transaction for another order.
        $session = $quoteToken !== '' ? $this->checkoutSessions->get($quoteToken) : null;
        if (\is_array($session)) {
            $submitted = \is_array($session['submitted_result'] ?? null) ? $session['submitted_result'] : [];
            $sessionOrderUuids = array_values(array_filter(array_map(
                static fn (mixed $uuid): string => trim((string) $uuid),
                (array) ($submitted['order_uuids'] ?? []),
            )));
            if ($sessionOrderUuids !== [] && !\in_array($orderUuid, $sessionOrderUuids, true)) {
                throw new \InvalidArgumentException('checkout_payment_order_scope_mismatch');
            }
        } elseif ($quoteToken !== '') {
            throw new \InvalidArgumentException('checkout_payment_session_not_found');
        }

        $order = $this->orders->get($orderUuid);
        if (\in_array(strtolower(trim($order->status)), ['paid', 'fulfilled', 'completed'], true)) {
            return [
                'paid' => true,
                'outcome' => 'paid',
                'status' => PaymentTransactionRecord::STATUS_SUCCESS,
                'requires_action' => false,
                'recoverable' => false,
                'redirect_url' => null,
                'transactions' => [],
            ];
        }

        $transaction = $this->payments->confirmPayment($transactionNo, array_replace($context, [
            'order_uuid' => $orderUuid,
            'quote_token' => $quoteToken,
            'idempotency_key' => $orderIdempotencyKey !== ''
                ? $orderIdempotencyKey
                : (string) ($context['idempotency_key'] ?? ''),
        ]));
        $status = strtolower(trim($transaction->status));
        $transactionRow = [
            'order_uuid' => $orderUuid,
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transactionNumber,
            'method_code' => $transaction->methodCode,
            'status' => $status,
            'response' => $this->sanitizeResponse($transaction->response),
        ];

        if ($status === PaymentTransactionRecord::STATUS_SUCCESS) {
            $notificationPending = false;
            try {
                $this->ensureOrderMoneyConserved($orderUuid, $this->orders->get($orderUuid)->money);
                $this->notifyOrderPaidCaptured($orderUuid, [
                    'payment_method' => $transaction->methodCode,
                    'payment_transaction_id' => $transaction->id,
                    'payment_transaction_no' => $transaction->transactionNumber,
                ]);
            } catch (\Throwable $e) {
                // Provider already captured; never report failure (double-charge risk).
                $this->logOrderPaidNotificationFailure($orderUuid, $transaction, $e);
                $notificationPending = true;
            }

            $result = [
                'paid' => true,
                'outcome' => 'paid',
                'status' => PaymentTransactionRecord::STATUS_SUCCESS,
                'requires_action' => false,
                'recoverable' => false,
                'redirect_url' => null,
                'transactions' => [$transactionRow],
            ];
            if ($notificationPending) {
                $result['notification_pending'] = true;
            }

            return $result;
        }

        $failed = \in_array($status, [
            PaymentTransactionRecord::STATUS_FAILED,
            PaymentTransactionRecord::STATUS_REFUNDED,
        ], true);

        return [
            'paid' => false,
            'outcome' => $failed ? 'failed' : 'pending',
            'status' => $status,
            'requires_action' => false,
            'recoverable' => !$failed,
            'redirect_url' => null,
            'transactions' => [$transactionRow],
        ];
    }

    /**
     * 站内订单应付与激励扣减后的网关应付对齐（写入 money + type_payload.discount_lines）。
     *
     * @param array<string, mixed> $paymentContext
     */
    private function syncOrderPaymentIncentive(OrderReadResult $order, array $paymentContext): void
    {
        $gatewayAmountMinor = max(0, (int)($paymentContext['amount_minor'] ?? 0));
        $incentiveMinor = (int)($paymentContext['payment_method_incentive_amount_minor'] ?? 0);
        $lines = \is_array($paymentContext['discount_lines'] ?? null) ? $paymentContext['discount_lines'] : [];
        if ($incentiveMinor >= 0 && $lines === []) {
            // Still repair any pre-existing non-conserved snapshot before charge/notify.
            $this->ensureOrderMoneyConserved($order->orderUuid, $order->money);

            return;
        }

        $money = $order->money;
        $prevDiscount = max(0, (int)($money['discount_amount_minor'] ?? 0));
        $incentiveAbs = abs($incentiveMinor);
        // 若应付已含旧激励，先还原再写入新激励绝对值
        $prevIncentiveAbs = abs((int)($order->typePayload['payment_method_incentive_amount_minor'] ?? 0));
        $adminAbs = max(
            0,
            (int)($order->typePayload['admin_adjustment_amount_minor'] ?? 0),
        );
        if (
            class_exists(\Weline\Order\Service\DiscountLinesBySourceMerger::class)
            && \is_array($order->typePayload['discount_lines'] ?? null)
        ) {
            $adminAbs = max(
                $adminAbs,
                \Weline\Order\Service\DiscountLinesBySourceMerger::absSumForSource(
                    $order->typePayload['discount_lines'],
                    \Weline\Order\Service\DiscountLinesBySourceMerger::adminSource(),
                ),
            );
        }
        $baseDiscount = max(0, $prevDiscount - $prevIncentiveAbs - $adminAbs);
        $discountAmountMinor = $baseDiscount + $adminAbs + $incentiveAbs;
        $money['discount_amount_minor'] = $discountAmountMinor;
        $money['payment_method_incentive_amount_minor'] = $incentiveMinor;
        // NEVER trust gateway amount_minor as snapshot grand — OrderPaidContext requires
        // subtotal+shipping+tax+cod−discount === grand. UI/payable drift (e.g. tax omitted
        // in chrome) previously wrote grand=19300 while tax stayed 1584 → notify threw
        // order_paid_context_grand_total_mismatch → checkout_order_payment_notification_pending.
        $conserved = MoneySnapshot::fromArray($money)->withComputedGrandTotal();
        $money = array_replace($money, $conserved->toArray());
        $computedGrand = $conserved->grandTotalMinor;
        if ($gatewayAmountMinor > 0 && $gatewayAmountMinor !== $computedGrand && \function_exists('w_log_warning')) {
            w_log_warning('checkout payment incentive gateway/order grand drift', [
                'order_uuid' => $order->orderUuid,
                'gateway_amount_minor' => $gatewayAmountMinor,
                'computed_grand_minor' => $computedGrand,
            ], 'checkout_payment');
        }

        $mergedLines = $lines;
        if (
            class_exists(\Weline\Order\Service\DiscountLinesBySourceMerger::class)
            && \is_array($order->typePayload['discount_lines'] ?? null)
        ) {
            $mergedLines = \Weline\Order\Service\DiscountLinesBySourceMerger::merge(
                $order->typePayload['discount_lines'],
                $lines,
                [\Weline\Order\Service\DiscountLinesBySourceMerger::SOURCE_PAYMENT_INCENTIVE],
            );
        }

        try {
            $this->persistOrderMoneySnapshot($order->orderUuid, $money);
            $this->orders->mergeTypePayload($order->orderUuid, [
                'discount_lines' => $mergedLines,
                'payment_method_incentive_amount_minor' => $incentiveMinor,
                'admin_adjustment_amount_minor' => $adminAbs,
            ]);
        } catch (\Throwable) {
            // 支付仍以 paymentContext 金额为准；同步失败不阻断扣款
        }
    }

    /**
     * Repair money_snapshot so OrderPaidContext conservation holds before notifyOrderPaid.
     *
     * @param array<string, mixed> $money
     */
    private function ensureOrderMoneyConserved(string $orderUuid, array $money): void
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '' || $money === []) {
            return;
        }
        try {
            $current = MoneySnapshot::fromArray($money);
            $computed = $current->withComputedGrandTotal();
            if ($current->grandTotalMinor === $computed->grandTotalMinor) {
                return;
            }
            // Incomplete fixtures may only carry grand_total_minor; do not wipe them to 0.
            $componentGross = $current->subtotalMinor
                + $current->shippingAmountMinor
                + $current->taxAmountMinor
                + $current->codFeeAmountMinor;
            if ($componentGross <= 0 && $current->discountAmountMinor <= 0) {
                return;
            }
            $repaired = array_replace($money, $computed->toArray());
            $this->persistOrderMoneySnapshot($orderUuid, $repaired);
            if (\function_exists('w_log_warning')) {
                w_log_warning('checkout repaired non-conserved order money before paid notify', [
                    'order_uuid' => $orderUuid,
                    'before_grand_minor' => $current->grandTotalMinor,
                    'after_grand_minor' => $computed->grandTotalMinor,
                ], 'checkout_payment');
            }
        } catch (\Throwable) {
        }
    }

    /** @param array<string, mixed> $money */
    private function persistOrderMoneySnapshot(string $orderUuid, array $money): void
    {
        $toMajor = static fn (int $minor): float => round($minor / 100, 2);
        $discountAmountMinor = max(0, (int)($money['discount_amount_minor'] ?? 0));
        $grandTotalMinor = max(0, (int)($money['grand_total_minor'] ?? 0));
        /** @var \Weline\Order\Model\Order $row */
        $row = ObjectManager::getInstance(\Weline\Order\Model\Order::class);
        $row->clear()->where(\Weline\Order\Model\Order::schema_fields_ORDER_UUID, $orderUuid)->find();
        if (!$row->getId()) {
            return;
        }
        $row->setData(\Weline\Order\Model\Order::schema_fields_DISCOUNT_AMOUNT, $toMajor($discountAmountMinor));
        $row->setData(\Weline\Order\Model\Order::schema_fields_GRAND_TOTAL, $toMajor($grandTotalMinor));
        $row->setData(
            \Weline\Order\Model\Order::schema_fields_MONEY_SNAPSHOT_JSON,
            json_encode($money, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $row->save();
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
        $safe = [];
        $redirectUrl = $this->extractRedirectUrl($response);
        if ($redirectUrl !== null) {
            $safe['redirect_url'] = $redirectUrl;
        }
        $clientAction = $this->extractClientAction($response);
        if ($clientAction !== null) {
            $safe['client_action'] = $clientAction;
        }

        return $safe;
    }

    /**
     * Expose a provider-defined client action to the browser without teaching
     * Checkout what the action means. Only scalar data is forwarded; secrets and
     * nested provider payloads are dropped.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function extractClientAction(array $response): ?array
    {
        $raw = null;
        if (\is_array($response['payload']['client_action'] ?? null)) {
            $raw = $response['payload']['client_action'];
        } elseif (\is_array($response['client_action'] ?? null)) {
            $raw = $response['client_action'];
        }
        if ($raw === null) {
            return null;
        }

        $type = trim((string) ($raw['type'] ?? ''));
        $data = \is_array($raw['data'] ?? null) ? $raw['data'] : null;
        if ($type === '' || $data === null) {
            return null;
        }

        $safeData = [];
        foreach ($data as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $safeData[(string) $key] = $value;
            }
        }

        return [
            'type' => $type,
            'method_code' => trim((string) ($raw['method_code'] ?? '')),
            'provider_code' => trim((string) ($raw['provider_code'] ?? '')),
            'data' => $safeData,
        ];
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function notifyOrderPaidCaptured(string $orderUuid, array $metadata): void
    {
        try {
            $fresh = $this->orders->get($orderUuid);
            $this->ensureOrderMoneyConserved($orderUuid, $fresh->money);
        } catch (\Throwable) {
        }
        $this->orders->notifyOrderPaid($orderUuid, array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }

    private function logOrderPaidNotificationFailure(
        string $orderUuid,
        PaymentTransactionRecord $transaction,
        \Throwable $e,
        bool $isRetry = false,
    ): void {
        $message = ($isRetry ? 'retry ' : '') . 'checkout order paid notification failed: ' . $e->getMessage();
        if (\function_exists('w_log_error')) {
            w_log_error($message, [
                'order_uuid' => $orderUuid,
                'transaction_no' => (string)$transaction->transactionNumber,
                'transaction_id' => (int)$transaction->id,
                'method_code' => (string)$transaction->methodCode,
                'exception' => $e::class,
                'previous' => $e->getPrevious()?->getMessage(),
            ], 'checkout_payment');
        }
    }
}
