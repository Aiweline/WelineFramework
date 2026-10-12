<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Model\Event\Delivery;

final class DeliveryRetryPolicy
{
    /** @var list<int> */
    private const STANDARD_DELAYS = [30, 120, 600, 1800, 7200];
    /** @var list<int> */
    private const TRANSPORT_DELAYS = [5, 30, 120, 600];

    public function __construct(private readonly Delivery $deliveryModel)
    {
    }

    public function maxAttempts(string $policy): int
    {
        return $policy === 'none' ? 1 : 6;
    }

    public function shouldRetry(string $policy, int $failedAttempt): bool
    {
        return $failedAttempt < $this->maxAttempts($policy);
    }

    public function retryDelaySeconds(int $deliveryId, int $failedAttempt): int
    {
        $base = self::STANDARD_DELAYS[max(0, min(count(self::STANDARD_DELAYS) - 1, $failedAttempt - 1))];
        $u = hexdec(substr(hash('sha256', $deliveryId . ':' . $failedAttempt), 0, 8)) / 4294967295;
        return max(1, (int)round($base * (0.8 + 0.4 * $u)));
    }

    public function nextRetryAt(int $deliveryId, int $failedAttempt, ?int $now = null): string
    {
        $databaseNow = $now ?? $this->databaseUtcTimestamp();
        return gmdate('Y-m-d H:i:s', $databaseNow + $this->retryDelaySeconds($deliveryId, $failedAttempt));
    }

    public function transportDelaySeconds(int $transportRetryCount): int
    {
        return self::TRANSPORT_DELAYS[max(0, min(count(self::TRANSPORT_DELAYS) - 1, $transportRetryCount - 1))];
    }

    private function databaseUtcTimestamp(): int
    {
        $connector = $this->deliveryModel->getConnection()->getConnector();
        $sql = 'SELECT ' . $connector->getSqlDialect()->unixEpochExpression(true);
        $statement = $connector->getWrappedConnection()->prepare($sql);
        if (!$statement->execute()) {
            throw new \RuntimeException((string)__('无法读取数据库 UTC 当前时间'));
        }
        $value = $statement->fetchColumn();
        $timestamp = is_numeric($value) ? (int)$value : 0;
        if ($timestamp < 1) {
            throw new \RuntimeException((string)__('数据库 UTC 当前时间无效'));
        }
        return $timestamp;
    }
}
