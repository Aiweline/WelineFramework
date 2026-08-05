<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Api\CashAttemptPortInterface;

/**
 * 测试用现金 Attempt 端口。
 */
final class ArrayCashAttemptPort implements CashAttemptPortInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $attempts = [];

    /** @var array<string, array<string, mixed>> */
    private array $refunds = [];

    /** @var array<string, string> event_id => attempt_id */
    private array $byEvent = [];

    /** @var array<string, string> event_id => refund_id */
    private array $refundByEvent = [];

    private bool $failNextAttempt = false;
    private int $seq = 0;

    public static function forTesting(): self
    {
        return new self();
    }

    public function failNextAttempt(): void
    {
        $this->failNextAttempt = true;
    }

    public function attempt(array $request): array
    {
        $eventId = trim((string) ($request['event_id'] ?? ''));
        if ($eventId !== '' && isset($this->byEvent[$eventId])) {
            $id = $this->byEvent[$eventId];

            return [
                'ok' => true,
                'attempt_id' => $id,
                'status' => (string) $this->attempts[$id]['status'],
                'idempotent' => true,
            ];
        }

        if ($this->failNextAttempt) {
            $this->failNextAttempt = false;

            return [
                'ok' => false,
                'attempt_id' => null,
                'status' => 'failed',
                'error' => 'cash_attempt_failed',
            ];
        }

        $this->seq++;
        $id = 'cash_' . $this->seq;
        $this->attempts[$id] = [
            'attempt_id' => $id,
            'payable_id' => (string) ($request['payable_id'] ?? ''),
            'amount_minor' => (int) ($request['amount_minor'] ?? 0),
            'event_id' => $eventId,
            'status' => 'succeeded',
        ];
        if ($eventId !== '') {
            $this->byEvent[$eventId] = $id;
        }

        return [
            'ok' => true,
            'attempt_id' => $id,
            'status' => 'succeeded',
            'idempotent' => false,
        ];
    }

    public function refund(array $request): array
    {
        $eventId = trim((string) ($request['event_id'] ?? ''));
        if ($eventId !== '' && isset($this->refundByEvent[$eventId])) {
            $id = $this->refundByEvent[$eventId];

            return [
                'ok' => true,
                'refund_id' => $id,
                'status' => 'succeeded',
                'idempotent' => true,
            ];
        }

        $attemptId = (string) ($request['attempt_id'] ?? '');
        if ($attemptId === '' || !isset($this->attempts[$attemptId])) {
            return [
                'ok' => false,
                'refund_id' => null,
                'status' => 'failed',
                'error' => 'cash_attempt_missing',
            ];
        }

        $this->seq++;
        $id = 'cref_' . $this->seq;
        $this->refunds[$id] = [
            'refund_id' => $id,
            'attempt_id' => $attemptId,
            'amount_minor' => (int) ($request['amount_minor'] ?? 0),
            'event_id' => $eventId,
            'status' => 'succeeded',
        ];
        if ($eventId !== '') {
            $this->refundByEvent[$eventId] = $id;
        }

        return [
            'ok' => true,
            'refund_id' => $id,
            'status' => 'succeeded',
            'idempotent' => false,
        ];
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    public function refundCount(): int
    {
        return count($this->refunds);
    }
}
