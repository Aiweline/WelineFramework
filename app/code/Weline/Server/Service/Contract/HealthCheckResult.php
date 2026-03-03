<?php
declare(strict_types=1);

namespace Weline\Server\Service\Contract;

/**
 * 健康检查结果
 */
class HealthCheckResult
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_UNHEALTHY = 'unhealthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $details = [],
    ) {}

    public static function healthy(string $message = 'OK'): self
    {
        return new self(self::STATUS_HEALTHY, $message);
    }

    public static function unhealthy(string $message): self
    {
        return new self(self::STATUS_UNHEALTHY, $message);
    }

    public static function degraded(string $message): self
    {
        return new self(self::STATUS_DEGRADED, $message);
    }

    public static function unknown(string $message = ''): self
    {
        return new self(self::STATUS_UNKNOWN, $message);
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }
}
