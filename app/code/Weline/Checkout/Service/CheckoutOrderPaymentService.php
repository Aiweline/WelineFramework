<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

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
    ) {
    }

    /**
     * @param list<string> $orderUuids
     * @param array<string, mixed> $context Non-monetary payment context only.
     * @return array{
     *     paid:bool,
     *     outcome:'paid'|'pending'|'failed',
     *     status:string,
     *     requires_action:bool,
     *     recoverable:bool,
     *     redirect_url:?string,
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
        $notificationPending = false;
        $redirectUrl = null;
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
            if ($amountMinor <= 0) {
                throw new \RuntimeException('checkout_payment_amount_invalid');
            }

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
                'metadata' => [
                    'checkout_group_uuid' => $order->checkoutGroupUuid,
                    'display_number' => $order->displayNumber,
                ],
                'idempotency_key' => $idempotencyKey . ':' . $order->orderUuid,
            ];
            foreach (['country_code', 'language_code', 'locale', 'timezone', 'scope', 'environment'] as $key) {
                if (array_key_exists($key, $context) && !is_array($context[$key])) {
                    $paymentContext[$key] = $context[$key];
                }
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
                    $this->orders->notifyOrderPaid($order->orderUuid, [
                        'payment_method' => $transaction->methodCode,
                        'payment_transaction_id' => $transaction->id,
                        'payment_transaction_no' => $transaction->transactionNumber,
                    ]);
                } catch (\Throwable) {
                    // The provider has already captured/accepted payment. Do
                    // not report a retryable failure that could charge again;
                    // keep the order in a reconciliation-pending state.
                    $notificationPending = true;
                    $hasPending = true;
                }
            }
        }

        $outcome = $hasFailed ? 'failed' : ($hasPending ? 'pending' : 'paid');
        $status = $notificationPending
            ? PaymentTransactionRecord::STATUS_PROCESSING
            : match ($outcome) {
            'paid' => PaymentTransactionRecord::STATUS_SUCCESS,
            'failed' => PaymentTransactionRecord::STATUS_FAILED,
            default => PaymentTransactionRecord::STATUS_PENDING,
        };

        $result = [
            'paid' => $outcome === 'paid',
            'outcome' => $outcome,
            'status' => $status,
            'requires_action' => $redirectUrl !== null,
            'recoverable' => $outcome !== 'paid' && !$notificationPending,
            'redirect_url' => $redirectUrl,
            'transactions' => $transactions,
        ];
        if ($notificationPending) {
            $result['error_code'] = 'checkout_order_payment_notification_pending';
        }

        return $result;
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
        foreach (['gateway_response', 'response', 'next_action'] as $key) {
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
}
