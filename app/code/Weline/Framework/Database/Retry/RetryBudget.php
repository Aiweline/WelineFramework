<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Retry;

/**
 * A monotonic, single-deadline budget shared by all attempts of one retry flow.
 *
 * The deadline is intentionally immutable: callers may cap an individual wait
 * to the remaining time, but no layer can reset or extend the total budget.
 */
final class RetryBudget
{
    private readonly float $startedAtNanoseconds;

    private readonly float $deadlineNanoseconds;

    private function __construct(
        private readonly int $budgetMilliseconds,
        ?float $startedAtNanoseconds = null
    ) {
        if ($budgetMilliseconds <= 0) {
            throw new \InvalidArgumentException('Retry budget must be greater than zero milliseconds.');
        }

        $this->startedAtNanoseconds = $startedAtNanoseconds ?? (float)\hrtime(true);
        $this->deadlineNanoseconds = $this->startedAtNanoseconds + ($budgetMilliseconds * 1_000_000);
    }

    public static function fromMilliseconds(
        int $budgetMilliseconds,
        ?float $startedAtNanoseconds = null
    ): self {
        return new self($budgetMilliseconds, $startedAtNanoseconds);
    }

    public function budgetMilliseconds(): int
    {
        return $this->budgetMilliseconds;
    }

    public function elapsedMilliseconds(): float
    {
        $elapsedNanoseconds = \max(0, \hrtime(true) - $this->startedAtNanoseconds);
        return $elapsedNanoseconds / 1_000_000;
    }

    public function remainingMicroseconds(): int
    {
        $remainingNanoseconds = $this->deadlineNanoseconds - \hrtime(true);
        if ($remainingNanoseconds <= 0) {
            return 0;
        }

        return (int)\max(1, \floor($remainingNanoseconds / 1_000));
    }

    public function capDelayMicroseconds(
        int $requestedMicroseconds,
        int $completionReserveMicroseconds = 0
    ): int
    {
        if ($requestedMicroseconds <= 0) {
            return 0;
        }

        $availableMicroseconds = \max(
            0,
            $this->remainingMicroseconds() - \max(0, $completionReserveMicroseconds)
        );
        return \min($requestedMicroseconds, $availableMicroseconds);
    }

    public function isExpired(): bool
    {
        return $this->remainingMicroseconds() === 0;
    }
}
