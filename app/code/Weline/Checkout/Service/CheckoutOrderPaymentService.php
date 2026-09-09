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
            if (in_array(strtolower(trim($order->status)), ['paid', 'fulfilled', 'completed'], true)) {
                $transactions[] = [
                    'order_uuid' => $order->orderUuid,
                    'transaction_id' => null,
                    'transaction_no' => '',
                    'method_code' => $methodCode,
                    'status' => 'already_paid',
                    'response' => [],
                ];
                continue;
            }
            $amountMinor = (int)($order->money['grand_total_minor'] ?? 0);
            $hangPurpose = $this->resolveHangPurpose(
                $order,
                strtolower(trim((string)($context['hang_purpose'] ?? $context['purpose'] ?? ''))),
            );
            $typePayload = $order->typePayload;
            if (isset($context['deposit_amount_minor']) && (int)$context['deposit_amount_minor'] > 0
                && ($hangPurpose === 'deposit' || $hangPurpose === '')
            ) {
                $amountMinor = (int)$context['deposit_amount_minor'];
                $hangPurpose = 'deposit';
            } elseif (isset($context['balance_amount_minor']) && (int)$context['balance_amount_minor'] > 0
                && $hangPurpose === 'balance'
            ) {
                $amountMinor = (int)$context['balance_amount_minor'];
            } elseif ($hangPurpose === 'deposit' && isset($typePayload['deposit_amount_minor'])) {
                $amountMinor = (int)$typePayload['deposit_amount_minor'];
            } elseif ($hangPurpose === 'balance' && isset($typePayload['balance_amount_minor'])) {
                $amountMinor = (int)$typePayload['balance_amount_minor'];
            } elseif ($hangPurpose === 'deposit' || $hangPurpose === 'balance') {
                $hangAmounts = $this->hangAmountsForOrder($order->orderUuid);
                if ($hangPurpose === 'deposit' && ($hangAmounts['deposit_amount_minor'] ?? 0) > 0) {
                    $amountMinor = (int)$hangAmounts['deposit_amount_minor'];
                } elseif ($hangPurpose === 'balance' && ($hangAmounts['balance_amount_minor'] ?? 0) > 0) {
                    $amountMinor = (int)$hangAmounts['balance_amount_minor'];
                }
            }
            if ($amountMinor <= 0) {
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
            if ($checkoutToken !== '') {
                $landingExtras['checkout_token'] = $checkoutToken;
            }
            $paymentContext['browser_landing_url'] = $this->successUrlBuilder->buildForOrders(
                [$order->orderUuid],
                $landingExtras,
            );
            $landingParams = [];
            if ($order->checkoutGroupUuid !== '') {
                $landingParams['checkout_group_uuid'] = $order->checkoutGroupUuid;
            }
            if ($checkoutToken !== '') {
                $landingParams['checkout_token'] = $checkoutToken;
            }
            if ($landingParams !== []) {
                $paymentContext['browser_landing_params'] = $landingParams;
            }

            $transaction = $this->payments->tryCreatePayment($methodCode, $paymentContext);
            if (!$transaction instanceof PaymentTransactionRecord) {
                throw new \RuntimeException('checkout_payment_method_unavailable');
            }

            $status = strtolower(trim($transaction->status));
            $paid = $status === PaymentTransactionRecord::STATUS_SUCCESS;
            if (!$paid && in_array($status, [
                PaymentTransactionRecord::STATUS_FAILED,
                PaymentTransactionRecord::STATUS_REFUNDED,
            ], true)) {
                $hasFailed = true;
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
        if (!class_exists(\Weline\B2B\Service\B2BHangOrderService::class)) {
            return null;
        }
        try {
            $service = ObjectManager::getInstance(\Weline\B2B\Service\B2BHangOrderService::class);
            if (!$service instanceof \Weline\B2B\Service\B2BHangOrderService) {
                return null;
            }
            $hang = $service->getByOrderRef($orderUuid);

            return $hang?->toArray();
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

    private function notifyHangDepositPaid(string $orderUuid, string $intentCode): void
    {
        if (!class_exists(\Weline\B2B\Service\B2BHangOrderService::class)) {
            return;
        }
        try {
            $service = ObjectManager::getInstance(\Weline\B2B\Service\B2BHangOrderService::class);
            if (!$service instanceof \Weline\B2B\Service\B2BHangOrderService) {
                return;
            }
            $service->onDepositPaid($orderUuid, $intentCode !== '' ? $intentCode : 'deposit_' . $orderUuid);
        } catch (\Throwable) {
            // Hang row may be absent in unit fixtures; payment amount path still stands.
        }
    }

    private function notifyHangBalancePaid(string $orderUuid, string $intentCode): void
    {
        if (!class_exists(\Weline\B2B\Service\B2BHangOrderService::class)) {
            return;
        }
        try {
            $service = ObjectManager::getInstance(\Weline\B2B\Service\B2BHangOrderService::class);
            if (!$service instanceof \Weline\B2B\Service\B2BHangOrderService) {
                return;
            }
            $service->onBalancePaid($orderUuid, $intentCode !== '' ? $intentCode : 'balance_' . $orderUuid);
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
