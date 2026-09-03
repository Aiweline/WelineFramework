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
        $landingUrl = $ready ? $this->mergeLandingUrl($baseUrl, $landing['browser_landing_params'], $degraded) : null;

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
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $baseUrl;
        }

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

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
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
