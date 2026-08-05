<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Scope 隔离配额限流（TASK-P1D-004-RATE）。
 *
 * 共享原子 CAS 计数；失败时 fail-closed（拒绝请求）。
 */
final class ScopeRateLimiter
{
    private readonly ScopeRateLimitStateStore $store;

    public function __construct(?ScopeRateLimitStateStore $store = null)
    {
        $this->store = $store ?? new ScopeRateLimitStateStore();
    }

    public function allow(
        ScopeIdentity $scope,
        string $bucket,
        int $limit,
        int $windowSeconds = 60,
        string $subject = '',
        ?int $now = null,
    ): bool {
        $bucket = ScopeRateLimitKey::normalizeBucket($bucket);
        $key = ScopeRateLimitKey::of($scope, $bucket, $subject);
        if ($limit <= 0) {
            $this->bumpDenied($scope, $bucket);

            return false;
        }
        $now ??= \time();
        if (!$this->store->consume($key, $limit, $windowSeconds, $now)) {
            $this->bumpDenied($scope, $bucket);

            return false;
        }

        return true;
    }

    public function assertAllowed(
        ScopeIdentity $scope,
        string $bucket,
        int $limit,
        int $windowSeconds = 60,
        string $subject = '',
        ?int $now = null,
    ): void {
        if (!$this->allow($scope, $bucket, $limit, $windowSeconds, $subject, $now)) {
            throw new \RuntimeException('scope_rate_limited');
        }
    }

    public function clearKey(ScopeIdentity $scope, string $bucket, string $subject = ''): void
    {
        $this->store->clearKey(ScopeRateLimitKey::of($scope, $bucket, $subject));
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    public function clearDeniedMetrics(string $bucket): void
    {
        $this->store->clearMetricsForBucket(ScopeRateLimitKey::normalizeBucket($bucket));
    }

    public function deniedCount(string $bucket): int
    {
        $bucket = ScopeRateLimitKey::normalizeBucket($bucket);

        return $this->metrics()[$bucket] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function metrics(): array
    {
        return $this->store->metrics();
    }

    public function driver(): string
    {
        return $this->store->driver();
    }

    private function bumpDenied(ScopeIdentity $scope, string $bucket): void
    {
        $this->store->bumpDenied([
            $bucket,
            $bucket . '@' . $scope->canonicalKey(),
        ]);
    }
}
