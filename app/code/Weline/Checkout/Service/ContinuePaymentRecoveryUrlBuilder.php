<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Builds /checkout#payment-recovery?… URLs for unpaid resumePaymentV2 flows.
 */
final class ContinuePaymentRecoveryUrlBuilder
{
    public function __construct(
        private readonly ?CheckoutSessionStoreInterface $sessions = null,
        private readonly ?string $storefrontBaseOverride = null,
    ) {
    }

    /**
     * @return array{
     *   continue_pay_url:string,
     *   reachable:bool,
     *   quote_token:string,
     *   idempotency_key:string,
     *   payment_method:string
     * }
     */
    public function build(string $quoteToken, string $orderUuid, ?int $websiteId = null): array
    {
        $quoteToken = trim($quoteToken);
        $orderUuid = trim($orderUuid);
        $empty = [
            'continue_pay_url' => '',
            'reachable' => false,
            'quote_token' => $quoteToken,
            'idempotency_key' => '',
            'payment_method' => '',
        ];
        if ($quoteToken === '' || $orderUuid === '') {
            return $empty;
        }

        $sessions = $this->sessions();
        if ($sessions === null) {
            return $empty;
        }
        $session = $sessions->get($quoteToken);
        if (!is_array($session)
            || (string)($session['state'] ?? '') !== CheckoutSession::STATE_SUBMITTED
        ) {
            return $empty;
        }

        $idempotencyKey = trim((string)($session['idempotency_key'] ?? ''));
        $payment = is_array($session['payment_result'] ?? null) ? $session['payment_result'] : [];
        $paymentMethod = $this->resolvePaymentMethod($payment, $session);
        if ($idempotencyKey === '' || $paymentMethod === '') {
            return $empty;
        }

        $orderStatus = strtolower(trim((string)($session['submitted_result']['status'] ?? '')));
        // Prefer order uuid membership check over stale session status.
        $orderUuids = is_array($session['submitted_result']['order_uuids'] ?? null)
            ? $session['submitted_result']['order_uuids']
            : [];
        $ownsOrder = false;
        foreach ($orderUuids as $uuid) {
            if (hash_equals(trim((string)$uuid), $orderUuid)) {
                $ownsOrder = true;
                break;
            }
        }
        if (!$ownsOrder && $orderUuids !== []) {
            return $empty;
        }

        $outcome = strtolower(trim((string)($payment['outcome'] ?? 'failed')));
        if ($outcome === 'paid' || $outcome === 'partial') {
            return $empty;
        }
        if ($orderStatus === 'cancelled' || $orderStatus === 'canceled') {
            return $empty;
        }

        $hash = $this->buildHash([
            'quote_token' => $quoteToken,
            'idempotency_key' => $idempotencyKey,
            'payment_method' => $paymentMethod,
            'order_uuid' => $orderUuid,
            'checkout_group_uuid' => trim((string)($session['submitted_result']['checkout_group_uuid'] ?? '')),
            'outcome' => $outcome === 'pending' ? 'failed' : ($outcome !== '' ? $outcome : 'failed'),
            'recoverable' => '1',
        ]);

        $base = $this->storefrontBase($websiteId);
        $path = '/checkout' . $hash;
        $url = $base !== '' ? rtrim($base, '/') . $path : $path;

        return [
            'continue_pay_url' => $url,
            'reachable' => true,
            'quote_token' => $quoteToken,
            'idempotency_key' => $idempotencyKey,
            'payment_method' => $paymentMethod,
        ];
    }

    /**
     * Relative hash fragment for storefront CTAs (same-origin).
     *
     * @param array<string, scalar|null> $params
     */
    public function buildHash(array $params): string
    {
        $query = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            $query[(string)$key] = $text;
        }

        return '#payment-recovery?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $session
     */
    private function resolvePaymentMethod(array $payment, array $session): string
    {
        $direct = trim((string)($payment['payment_method'] ?? $payment['method_code'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        $transactions = is_array($payment['transactions'] ?? null) ? $payment['transactions'] : [];
        foreach ($transactions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['method_code'] ?? $row['payment_method'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }
        $fromSession = trim((string)($session['payment_method'] ?? ''));

        return $fromSession;
    }

    private function sessions(): ?CheckoutSessionStoreInterface
    {
        if ($this->sessions instanceof CheckoutSessionStoreInterface) {
            return $this->sessions;
        }
        try {
            $resolved = ObjectManager::getInstance(CheckoutSessionStoreInterface::class);

            return $resolved instanceof CheckoutSessionStoreInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function storefrontBase(?int $websiteId): string
    {
        if ($this->storefrontBaseOverride !== null && $this->storefrontBaseOverride !== '') {
            return rtrim($this->storefrontBaseOverride, '/');
        }
        $websiteId = $websiteId !== null && $websiteId > 0 ? $websiteId : 0;
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            if ($websiteId > 0) {
                $website->load($websiteId);
            } else {
                $website->clear()->where(Website::schema_fields_ID, 0, '>=')->order(Website::schema_fields_ID, 'ASC')->find()->fetch();
            }
            if ($website->getId()) {
                $url = trim((string)$website->getUrl());
                if ($url !== '') {
                    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                        $url = 'https://' . ltrim($url, '/');
                    }

                    return rtrim($url, '/');
                }
            }
        } catch (\Throwable) {
        }

        try {
            $envHost = Env::getInstance()->getConfig('wls.host');
            $envHost = is_string($envHost) ? trim($envHost) : '';
            if ($envHost !== '') {
                if (!str_starts_with($envHost, 'http://') && !str_starts_with($envHost, 'https://')) {
                    $envHost = 'https://' . $envHost;
                }

                return rtrim($envHost, '/');
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
