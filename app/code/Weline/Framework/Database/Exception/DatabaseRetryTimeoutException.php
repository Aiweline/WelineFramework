<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Exception;

/**
 * Structured failure raised when a retryable database-busy condition cannot
 * complete inside its single deadline.
 */
final class DatabaseRetryTimeoutException extends \PDOException
{
    public readonly string $sqlState;

    public readonly int $driverCode;

    public function __construct(
        public readonly string $driver,
        public readonly string $reason,
        public readonly int $attempts,
        public readonly int $budgetMilliseconds,
        public readonly float $elapsedMilliseconds,
        public readonly bool $cooperativeWaitAvailable,
        ?\Throwable $previous = null
    ) {
        $previousErrorInfo = $previous instanceof \PDOException ? $previous->errorInfo : null;
        $this->sqlState = (string)($previousErrorInfo[0] ?? 'HY000');
        $this->driverCode = (int)($previousErrorInfo[1] ?? 0);

        parent::__construct(
            \sprintf(
                '%s 数据库繁忙重试失败：reason=%s, attempts=%d, budget=%dms, elapsed=%.3fms',
                \strtoupper($driver),
                $reason,
                $attempts,
                $budgetMilliseconds,
                $elapsedMilliseconds
            ),
            self::normalizePreviousCode($previous),
            $previous
        );
        $this->errorInfo = $previousErrorInfo;
    }

    /**
     * Stable fields for logs, telemetry and protocol-safe error mapping.
     *
     * @return array<string, bool|float|int|string>
     */
    public function context(): array
    {
        return [
            'driver' => $this->driver,
            'reason' => $this->reason,
            'sql_state' => $this->sqlState,
            'driver_code' => $this->driverCode,
            'attempts' => $this->attempts,
            'budget_ms' => $this->budgetMilliseconds,
            'elapsed_ms' => $this->elapsedMilliseconds,
            'cooperative_wait_available' => $this->cooperativeWaitAvailable,
        ];
    }

    private static function normalizePreviousCode(?\Throwable $previous): int
    {
        if ($previous === null) {
            return 0;
        }

        $code = $previous->getCode();
        return \is_int($code) ? $code : (\is_numeric($code) ? (int)$code : 0);
    }
}
