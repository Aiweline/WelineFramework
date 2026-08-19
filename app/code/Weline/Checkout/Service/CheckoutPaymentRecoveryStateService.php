<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Stores the sanitized payment outcome beside the submitted quote session.
 * This lets HTTP replays return the same result without starting a new charge.
 */
final class CheckoutPaymentRecoveryStateService
{
    public function __construct(
        private readonly CheckoutSessionStoreInterface $sessions,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        private readonly ?ConnectionFactory $connectionFactory = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(string $quoteToken, string $orderIdempotencyKey): ?array
    {
        $session = $this->submittedSession($quoteToken, $orderIdempotencyKey);
        if ($session === null) {
            return null;
        }
        $payment = $session['payment_result'] ?? null;

        return is_array($payment) ? $payment : null;
    }

    /** @param array<string, mixed> $payment */
    public function record(string $quoteToken, string $orderIdempotencyKey, array $payment): void
    {
        $session = $this->submittedSession($quoteToken, $orderIdempotencyKey);
        if ($session === null) {
            throw new \RuntimeException('checkout_payment_recovery_session_conflict');
        }
        $outcome = strtolower(trim((string)($payment['outcome'] ?? '')));
        if (!in_array($outcome, ['paid', 'pending', 'failed'], true)) {
            throw new \InvalidArgumentException('checkout_payment_recovery_outcome_invalid');
        }
        $session['payment_result'] = $payment;
        $this->sessions->put(trim($quoteToken), $session);
    }

    public function canRetry(string $quoteToken, string $orderIdempotencyKey): bool
    {
        $payment = $this->get($quoteToken, $orderIdempotencyKey);

        return is_array($payment)
            && strtolower(trim((string)($payment['outcome'] ?? ''))) === 'failed'
            && (bool)($payment['recoverable'] ?? true);
    }

    /**
     * Atomically convert a retryable failure into a non-retryable in-progress
     * claim before the external payment provider is called.
     */
    public function beginRetry(
        string $quoteToken,
        string $orderIdempotencyKey,
        string $paymentIdempotencyKey,
    ): bool {
        $quoteToken = trim($quoteToken);
        $orderIdempotencyKey = trim($orderIdempotencyKey);
        $paymentIdempotencyKey = trim($paymentIdempotencyKey);
        if ($quoteToken === '' || $orderIdempotencyKey === '' || $paymentIdempotencyKey === '') {
            return false;
        }

        $claim = function () use ($quoteToken, $orderIdempotencyKey): bool {
            $session = $this->sessions->getForUpdate($quoteToken);
            if (!$this->matchesSubmittedSession($session, $orderIdempotencyKey)) {
                return false;
            }
            $payment = $session['payment_result'] ?? null;
            if (!is_array($payment)
                || strtolower(trim((string)($payment['outcome'] ?? ''))) !== 'failed'
                || !(bool)($payment['recoverable'] ?? true)) {
                return false;
            }
            $session['payment_result'] = [
                'paid' => false,
                'outcome' => 'pending',
                'status' => 'retry_in_progress',
                'requires_action' => false,
                'recoverable' => false,
                'redirect_url' => null,
                'transactions' => [],
            ];
            $this->sessions->put($quoteToken, $session);

            return true;
        };

        if ($this->sessions instanceof InMemoryCheckoutSessionStore) {
            return $claim();
        }
        $transactions = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        $connection = $this->connectionFactory ?? ConnectionFactory::getInstance();

        return (bool)$transactions->run($connection, $claim);
    }

    /** @return array<string, mixed>|null */
    private function submittedSession(string $quoteToken, string $orderIdempotencyKey): ?array
    {
        $quoteToken = trim($quoteToken);
        $orderIdempotencyKey = trim($orderIdempotencyKey);
        if ($quoteToken === '' || $orderIdempotencyKey === '') {
            return null;
        }
        $session = $this->sessions->get($quoteToken);
        if (!$this->matchesSubmittedSession($session, $orderIdempotencyKey)) {
            return null;
        }

        return $session;
    }

    /** @param array<string, mixed>|null $session */
    private function matchesSubmittedSession(?array $session, string $orderIdempotencyKey): bool
    {
        if (!is_array($session)
            || (string)($session['state'] ?? '') !== CheckoutSession::STATE_SUBMITTED) {
            return false;
        }
        $storedKey = (string)($session['idempotency_key'] ?? '');

        return $storedKey !== '' && hash_equals($storedKey, $orderIdempotencyKey);
    }
}
