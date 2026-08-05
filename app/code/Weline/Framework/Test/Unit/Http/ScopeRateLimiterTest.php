<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Http\ScopeRateLimitKey;
use Weline\Framework\Http\ScopeRateLimiter;
use Weline\Framework\Http\ScopeRateLimitStateStore;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * TEST-P1D-06：A 配额耗尽不影响 B；key 含完整 Scope。
 */
final class ScopeRateLimiterTest extends TestCase
{
    public function testKeyContainsFullScope(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'a', ScopeIdentity::MODE_TEST);
        $key = ScopeRateLimitKey::of($scope, 'api', '192.0.2.1');
        self::assertStringContainsString($scope->canonicalKey(), $key);
        self::assertStringContainsString('api', $key);
        self::assertStringContainsString('test', $key);
        self::assertStringNotContainsString('192.0.2.1', $key);
        self::assertMatchesRegularExpression('/subject:[a-f0-9]{64}$/', $key);
    }

    public function testExhaustADoesNotAffectB(): void
    {
        $adapter = new ScopeRateLimitAtomicAdapterDouble();
        $limiter = $this->limiter($adapter);
        $a = ScopeIdentity::store(1, 'shop', 'a', ScopeIdentity::MODE_NORMAL);
        $b = ScopeIdentity::store(1, 'shop', 'b', ScopeIdentity::MODE_NORMAL);
        self::assertTrue($limiter->allow($a, 'checkout', 2, 60, now: 100));
        self::assertTrue($limiter->allow($a, 'checkout', 2, 60, now: 101));
        self::assertFalse($limiter->allow($a, 'checkout', 2, 60, now: 102));
        self::assertTrue($limiter->allow($b, 'checkout', 2, 60, now: 102));
        self::assertGreaterThan(0, $limiter->deniedCount('checkout'));
        $limiter->clearKey($a, 'checkout');
        self::assertTrue($limiter->allow($a, 'checkout', 2, 60, now: 103));
    }

    public function testZeroLimitFailClosed(): void
    {
        $limiter = $this->limiter(new ScopeRateLimitAtomicAdapterDouble());
        $scope = ScopeIdentity::global();
        self::assertFalse($limiter->allow($scope, 'x', 0));
        self::assertSame(1, $limiter->deniedCount('x'));
    }

    public function testWindowResetAndCasConflictRetry(): void
    {
        $adapter = new ScopeRateLimitAtomicAdapterDouble();
        $adapter->conflictsRemaining = 1;
        $limiter = $this->limiter($adapter);
        $scope = ScopeIdentity::store(1, 'shop', 'a', ScopeIdentity::MODE_NORMAL);

        self::assertTrue($limiter->allow($scope, 'api', 1, 10, now: 100));
        self::assertFalse($limiter->allow($scope, 'api', 1, 10, now: 109));
        self::assertTrue($limiter->allow($scope, 'api', 1, 10, now: 110));
    }

    public function testSeparateLimiterInstancesShareQuotaAndMetrics(): void
    {
        $adapter = new ScopeRateLimitAtomicAdapterDouble();
        $one = $this->limiter($adapter);
        $two = $this->limiter($adapter);
        $scope = ScopeIdentity::store(1, 'shop', 'a', ScopeIdentity::MODE_TEST);

        self::assertTrue($one->allow($scope, 'login', 1, 60, '198.51.100.7', 100));
        self::assertFalse($two->allow($scope, 'login', 1, 60, '198.51.100.7', 101));
        self::assertSame(1, $one->deniedCount('login'));
        self::assertSame($one->metrics(), $two->metrics());
        $one->allow($scope, 'other', 0);
        $one->clearDeniedMetrics('login');
        self::assertSame(0, $two->deniedCount('login'));
        self::assertSame(1, $two->deniedCount('other'));
    }

    public function testClearKeyDoesNotClearAnotherSubject(): void
    {
        $limiter = $this->limiter(new ScopeRateLimitAtomicAdapterDouble());
        $scope = ScopeIdentity::store(1, 'shop', 'a', ScopeIdentity::MODE_NORMAL);

        self::assertTrue($limiter->allow($scope, 'api', 1, 60, 'one', 100));
        self::assertTrue($limiter->allow($scope, 'api', 1, 60, 'two', 100));
        $limiter->clearKey($scope, 'api', 'one');
        self::assertTrue($limiter->allow($scope, 'api', 1, 60, 'one', 101));
        self::assertFalse($limiter->allow($scope, 'api', 1, 60, 'two', 101));
    }

    public function testStoreOutageFailsClosed(): void
    {
        $adapter = new ScopeRateLimitAtomicAdapterDouble();
        $adapter->available = false;
        $limiter = $this->limiter($adapter);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope_rate_limit_unavailable');
        $limiter->allow(ScopeIdentity::global(), 'api', 1);
    }

    public function testNonAtomicAdapterIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope_rate_limit_non_atomic_adapter');
        new ScopeRateLimitStateStore(new ScopeRateLimitNonAtomicAdapterDouble());
    }

    public function testAtomicAdapterWithoutHealthProbeIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope_rate_limit_health_check_required');
        new ScopeRateLimitStateStore(new ScopeRateLimitAtomicWithoutHealthAdapterDouble());
    }

    private function limiter(ScopeRateLimitAtomicAdapterDouble $adapter): ScopeRateLimiter
    {
        return new ScopeRateLimiter(new ScopeRateLimitStateStore(
            $adapter,
            driverName: 'test_atomic',
        ));
    }
}

final class ScopeRateLimitAtomicAdapterDouble implements AtomicCacheAdapterInterface, CacheAdapterHealthInterface
{
    /** @var array<string, mixed> */
    private array $values = [];
    public bool $available = true;
    public int $conflictsRemaining = 0;

    public function get(string $key): mixed
    {
        return $this->available ? ($this->values[$key] ?? null) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->available) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        if (!$this->available) {
            return false;
        }
        $this->values = [];

        return true;
    }

    public function has(string $key): bool
    {
        return $this->available && \array_key_exists($key, $this->values);
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        if (!$this->available) {
            return false;
        }
        if ($this->conflictsRemaining > 0) {
            $this->conflictsRemaining--;
            return false;
        }
        $current = $this->values[$key] ?? null;
        if ($current !== $expected) {
            return false;
        }
        if ($value === null) {
            unset($this->values[$key]);
        } else {
            $this->values[$key] = $value;
        }

        return true;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}

final class ScopeRateLimitNonAtomicAdapterDouble implements CacheAdapterInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }
}

final class ScopeRateLimitAtomicWithoutHealthAdapterDouble implements AtomicCacheAdapterInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        return true;
    }
}
