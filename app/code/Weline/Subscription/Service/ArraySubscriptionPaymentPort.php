<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Subscription\Api\SubscriptionPaymentPortInterface;

/** Deterministic memory Payment port for P4B-002 tests. */
final class ArraySubscriptionPaymentPort implements SubscriptionPaymentPortInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $byOrder = [];

    /** @var array<string, mixed>|null */
    private ?array $nextResult = null;

    private int $startCalls = 0;
    private int $queryCalls = 0;

    public static function forTesting(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $result */
    public function setNextResult(array $result): void
    {
        $this->nextResult = $result;
    }

    /** @param array<string, mixed> $result */
    public function setOrderResult(string $orderRef, array $result): void
    {
        $this->byOrder[trim($orderRef)] = $this->normalize($result, trim($orderRef));
    }

    public function startPeriodPayment(array $command): array
    {
        $orderRef = trim((string) ($command['order_ref'] ?? ''));
        if ($orderRef === '') {
            throw new \InvalidArgumentException('subscription_payment_order_required');
        }
        if (isset($this->byOrder[$orderRef])) {
            return $this->byOrder[$orderRef] + ['replayed' => true];
        }
        $this->startCalls++;
        $result = $this->normalize($this->nextResult ?? [
            'status' => SubscriptionBillingAttemptStore::STATUS_SUCCEEDED,
            'terminal' => true,
        ], $orderRef);
        $this->nextResult = null;
        $this->byOrder[$orderRef] = $result;
        return $result;
    }

    public function queryPeriodPayment(array $command): array
    {
        $orderRef = trim((string) ($command['order_ref'] ?? ''));
        $this->queryCalls++;
        if (!isset($this->byOrder[$orderRef])) {
            return $this->normalize([
                'status' => SubscriptionBillingAttemptStore::STATUS_UNKNOWN,
                'terminal' => false,
                'error_code' => 'subscription_payment_not_found',
            ], $orderRef) + ['replayed' => true];
        }
        return $this->byOrder[$orderRef] + ['replayed' => true];
    }

    public function startCallCount(): int
    {
        return $this->startCalls;
    }

    public function queryCallCount(): int
    {
        return $this->queryCalls;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function normalize(array $result, string $orderRef): array
    {
        return [
            'status' => strtolower(trim((string) ($result['status'] ?? 'unknown'))),
            'terminal' => !empty($result['terminal']),
            'intent_code' => $result['intent_code'] ?? ('pi_' . substr(hash('sha256', $orderRef), 0, 16)),
            'payment_attempt_code' => $result['payment_attempt_code']
                ?? ('pa_' . substr(hash('sha256', $orderRef), 0, 16)),
            'error_code' => $result['error_code'] ?? null,
        ];
    }
}

