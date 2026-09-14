<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Model\PaymentCheckoutSession;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Poll readiness for L2 handoff before redirecting to browser_landing_url.
 */
final class PaymentBrowserHandoffReadinessService
{
    public const HANDOFF_MAX_WAIT_MS = 15000;
    /** @var list<int> */
    public const POLL_INTERVALS_MS = [500, 1000, 2000];

    public function __construct(
        private readonly PaymentCheckoutSessionPersistenceService $sessionPersistence,
    ) {
    }

    /**
     * @return array{
     *   ready:bool,
     *   landing_url:?string,
     *   message:string,
     *   poll_after_ms:int,
     *   degraded:bool
     * }
     */
    public function evaluate(string $checkoutSessionCode, int $elapsedMs = 0): array
    {
        $session = $this->sessionPersistence->loadByCheckoutSessionCode($checkoutSessionCode);
        if ($session === null) {
            return [
                'ready' => false,
                'landing_url' => null,
                'message' => (string) __('支付会话不存在或已过期。'),
                'poll_after_ms' => 0,
                'degraded' => false,
            ];
        }

        $landing = $this->sessionPersistence->landingSnapshot($session);
        $baseUrl = $landing['browser_landing_url'];
        if ($baseUrl === '') {
            return [
                'ready' => false,
                'landing_url' => null,
                'message' => (string) __('未配置业务成功页。'),
                'poll_after_ms' => 0,
                'degraded' => false,
            ];
        }

        $transactionReady = $this->isTransactionReady($session);
        $degraded = $elapsedMs >= self::HANDOFF_MAX_WAIT_MS;
        $ready = $transactionReady || $degraded;
        $params = is_array($landing['browser_landing_params'] ?? null) ? $landing['browser_landing_params'] : [];
        $snapshot = $session->getContextSnapshot();
        $transactionNo = trim((string) ($snapshot[PaymentCheckoutSessionPersistenceService::CONTEXT_TRANSACTION_NO] ?? ''));
        if ($transactionNo === '') {
            $transactionNo = trim((string) $session->getData(PaymentCheckoutSession::schema_fields_ACTIVE_INTENT_CODE));
        }
        if ($transactionNo !== '') {
            $params[PaymentCheckoutSessionPersistenceService::CONTEXT_TRANSACTION_NO] = $transactionNo;
        }

        // Express review is only for awaiting_confirm; after paid success, prefer checkout success.
        $baseUrl = $landing['browser_landing_url'];
        if ($transactionReady && $this->isExpressReviewLanding($baseUrl)) {
            $successParams = $params;
            unset($successParams['source']);
            $baseUrl = $this->buildCheckoutSuccessUrl($successParams, $transactionNo);
        }

        $landingUrl = $ready ? $this->mergeLandingUrl($baseUrl, $params, $degraded) : null;

        return [
            'ready' => $ready,
            'landing_url' => $landingUrl,
            'message' => $ready
                ? ($degraded && !$transactionReady
                    ? (string) __('支付已成功，订单信息同步中。')
                    : (string) __('订单已就绪，正在跳转…'))
                : (string) __('正在确认订单…'),
            'poll_after_ms' => $this->nextPollIntervalMs($elapsedMs),
            'degraded' => $degraded && !$transactionReady,
        ];
    }

    private function isExpressReviewLanding(string $url): bool
    {
        return str_contains($url, 'checkout/express-review');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildCheckoutSuccessUrl(array $params, string $transactionNo): string
    {
        $query = [];
        foreach (['checkout_group_uuid', 'checkout_token', 'order_uuid'] as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                $query[$key] = $value;
            }
        }
        if ($transactionNo !== '' && empty($query['order_uuid'])) {
            try {
                /** @var PaymentTransaction $txn */
                $txn = \Weline\Framework\Manager\ObjectManager::getInstance(PaymentTransaction::class);
                $txn->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
                $orderUuid = trim((string) $txn->getData(PaymentTransaction::schema_fields_ORDER_ID));
                if ($orderUuid !== '') {
                    $query['order_uuid'] = $orderUuid;
                }
            } catch (\Throwable) {
            }
        }
        $path = '/checkout/success';
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $path;
    }

    private function isTransactionReady(PaymentCheckoutSession $session): bool
    {
        $snapshot = $session->getContextSnapshot();
        if (!empty($snapshot[PaymentCheckoutSessionPersistenceService::CONTEXT_BROWSER_LANDING_READY])) {
            return true;
        }

        $transactionNo = trim((string) ($snapshot[PaymentCheckoutSessionPersistenceService::CONTEXT_TRANSACTION_NO] ?? ''));
        if ($transactionNo === '') {
            $transactionNo = trim((string) $session->getData(PaymentCheckoutSession::schema_fields_ACTIVE_INTENT_CODE));
        }
        if ($transactionNo === '') {
            return false;
        }

        /** @var PaymentTransaction $transaction */
        $transaction = \Weline\Framework\Manager\ObjectManager::getInstance(PaymentTransaction::class);
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);

        return $transaction->getId() && $transaction->isSuccess();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function mergeLandingUrl(string $baseUrl, array $params, bool $degraded): string
    {
        $parts = parse_url($baseUrl);
        if (!\is_array($parts)) {
            return $baseUrl;
        }

        $isAbsolute = !empty($parts['scheme']) && !empty($parts['host']);
        $query = [];
        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            if (!\is_array($query)) {
                $query = [];
            }
        }
        foreach ($params as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $query[(string) $key] = $value;
            }
        }
        if ($degraded) {
            $query['degraded'] = '1';
        }

        if ($isAbsolute) {
            $rebuilt = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
            $rebuilt .= $parts['path'] ?? '';
        } else {
            $rebuilt = $parts['path'] ?? $baseUrl;
            if ($rebuilt === $baseUrl && str_contains($baseUrl, '?')) {
                $rebuilt = explode('?', $baseUrl, 2)[0];
            }
            if ($rebuilt === '' || ($rebuilt[0] ?? '') !== '/') {
                // Relative path without leading slash / opaque — keep original if unparseable.
                if (($parts['path'] ?? '') === '' && empty($parts['query'])) {
                    return $baseUrl;
                }
            }
        }
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    private function nextPollIntervalMs(int $elapsedMs): int
    {
        $accumulated = 0;
        foreach (self::POLL_INTERVALS_MS as $interval) {
            $accumulated += $interval;
            if ($elapsedMs < $accumulated) {
                return $interval;
            }
        }

        return end(self::POLL_INTERVALS_MS) ?: 2000;
    }
}
