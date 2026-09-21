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
        if (!in_array($outcome, ['paid', 'pending', 'failed', 'partial'], true)) {
            throw new \InvalidArgumentException('checkout_payment_recovery_outcome_invalid');
        }
        $purpose = strtolower(trim((string)($payment['purpose'] ?? '')));
        if ($purpose !== '' && !in_array($purpose, ['deposit', 'balance', 'full'], true)) {
            throw new \InvalidArgumentException('checkout_payment_recovery_purpose_invalid');
        }
        $previous = $session['payment_result'] ?? null;
        if (is_array($previous) && $previous !== []) {
            $this->appendPaymentAttemptHistory($session, $previous);
        }
        $entries = is_array($session['payment_recovery_entries'] ?? null)
            ? $session['payment_recovery_entries']
            : [];
        if ($purpose === 'deposit' || $purpose === 'balance') {
            $entries[$purpose] = $payment;
            $session['payment_recovery_entries'] = $entries;
        }
        $session['payment_result'] = $payment;
        $this->sessions->put(trim($quoteToken), $session);
    }

    /**
     * Read-only audit trail of prior payment_result snapshots.
     *
     * @return list<array{outcome?: mixed, status?: mixed, transactions?: mixed, recorded_at?: mixed}>
     */
    public function history(string $quoteToken, string $orderIdempotencyKey): array
    {
        $session = $this->submittedSession($quoteToken, $orderIdempotencyKey);
        if ($session === null) {
            return [];
        }
        $history = $session['payment_attempt_history'] ?? null;
        if (!is_array($history)) {
            return [];
        }

        return array_values($history);
    }

    /** @return array<string, mixed>|null */
    public function getByPurpose(string $quoteToken, string $orderIdempotencyKey, string $purpose): ?array
    {
        $session = $this->submittedSession($quoteToken, $orderIdempotencyKey);
        if ($session === null) {
            return null;
        }
        $purpose = strtolower(trim($purpose));
        $entries = is_array($session['payment_recovery_entries'] ?? null)
            ? $session['payment_recovery_entries']
            : [];
        if (isset($entries[$purpose]) && is_array($entries[$purpose])) {
            return $entries[$purpose];
        }
        $payment = $session['payment_result'] ?? null;
        if (is_array($payment)
            && strtolower(trim((string)($payment['purpose'] ?? ''))) === $purpose
        ) {
            return $payment;
        }

        return null;
    }

    public function canRetry(string $quoteToken, string $orderIdempotencyKey): bool
    {
        $payment = $this->get($quoteToken, $orderIdempotencyKey);

        return is_array($payment)
            && strtolower(trim((string)($payment['outcome'] ?? ''))) === 'failed'
            && (bool)($payment['recoverable'] ?? true);
    }

    /**
     * Browser cancel/failure: flip pending→failed so resumePaymentV2 canRetry works.
     * Appends prior payment_result into payment_attempt_history via record().
     *
     * @param array<string, mixed> $extra
     */
    public function markBrowserCancel(string $quoteToken, ?string $orderIdempotencyKey = null, array $extra = []): bool
    {
        $quoteToken = trim($quoteToken);
        if ($quoteToken === '') {
            return false;
        }
        $session = $this->sessions->get($quoteToken);
        if (!is_array($session)) {
            return false;
        }
        $idempotencyKey = trim((string)($orderIdempotencyKey ?? ($session['idempotency_key'] ?? '')));
        if ($idempotencyKey === '' || !$this->matchesSubmittedSession($session, $idempotencyKey)) {
            return false;
        }
        $payment = is_array($session['payment_result'] ?? null) ? $session['payment_result'] : [];
        $outcome = strtolower(trim((string)($payment['outcome'] ?? '')));
        if (in_array($outcome, ['paid', 'partial'], true)) {
            return false;
        }

        try {
            $this->record($quoteToken, $idempotencyKey, array_replace([
                'paid' => false,
                'outcome' => 'failed',
                'status' => 'cancelled',
                'requires_action' => false,
                'recoverable' => true,
                'redirect_url' => null,
                'transactions' => is_array($payment['transactions'] ?? null) ? $payment['transactions'] : [],
                'cancel_source' => 'browser_cancel',
            ], $extra));
        } catch (\Throwable) {
            return false;
        }

        return true;
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
            if ($payment !== []) {
                $this->appendPaymentAttemptHistory($session, $payment);
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

    /**
     * Append a compact snapshot of the previous payment_result (FIFO, max 50).
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payment
     */
    private function appendPaymentAttemptHistory(array &$session, array $payment): void
    {
        $history = is_array($session['payment_attempt_history'] ?? null)
            ? $session['payment_attempt_history']
            : [];
        $recordedAt = trim((string)($payment['recorded_at'] ?? ''));
        if ($recordedAt === '') {
            $recordedAt = gmdate('c');
        }
        $history[] = [
            'outcome' => $payment['outcome'] ?? null,
            'status' => $payment['status'] ?? null,
            'transactions' => is_array($payment['transactions'] ?? null)
                ? $payment['transactions']
                : [],
            'recorded_at' => $recordedAt,
        ];
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        $session['payment_attempt_history'] = array_values($history);
    }
}
