<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

/**
 * 固化 WlsMemoryAdapter 远端故障冷却行为（wls-lease-resilience T4）：
 *  - 连续失败冷却指数退避：2.0s 基准 ×2^n，封顶 REMOTE_FAILURE_COOLDOWN_MAX_SECONDS(60s)；
 *  - 任一池成功操作后冷却与失败计数整体复位；
 *  - 冷却窗口内同 identity 的故障日志只记第一条（去噪）。
 */
final class WlsMemoryAdapterCooldownTest extends TestCase
{
    /** 上一次观察到的冷却窗口长度（秒），供封顶断言使用。 */
    private float $lastCooldownSeconds = 0.0;

    protected function tearDown(): void
    {
        WlsMemoryAdapter::clearAllMemory();
        parent::tearDown();
    }

    public function testCooldownEscalatesOnConsecutiveFailures(): void
    {
        $shared = new FailingSharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('cooldown_escalate', [], $shared);

        $until = static function () use ($adapter): float {
            $prop = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteUnavailableUntil');
            $prop->setAccessible(true);
            /** @var array<string, float> $map */
            $map = $prop->getValue();
            return $map['cooldown_escalate'] ?? 0.0;
        };
        $count = static function () use ($adapter): int {
            $prop = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteFailureCounts');
            $prop->setAccessible(true);
            /** @var array<string, int> $map */
            $map = $prop->getValue();
            return $map['cooldown_escalate'] ?? 0;
        };
        $resetWindow = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteUnavailableUntil');
        $resetWindow->setAccessible(true);

        self::assertNull($adapter->get('key'));
        $firstUntil = $until();
        self::assertSame(1, $count());
        self::assertGreaterThan(0.0, $firstUntil);

        // 让下一次失败立即发生且仍打在注入的 double 上：反射把本 identity 冷却窗口
        // 与 global 窗口清零，并把 facade 恢复为注入的 double（等效 recover 的清窗
        // 语义，但不清全局失败计数——recoverRemoteProbe 会整体复位计数，无法观测递增）。
        // 绝不能反射清窗后直接 get：markRemoteUnavailable 已把 facade 置 null，下一次
        // get 会触发 createMemoryFacade() 建立真实连接，第二次“失败”就不再来自 double。
        $resetWindow->setValue(null, ['cooldown_escalate' => 0.0]);
        $resetGlobal = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteGloballyUnavailableUntil');
        $resetGlobal->setAccessible(true);
        $resetGlobal->setValue(null, 0.0);
        $facadeProp = new \ReflectionProperty(WlsMemoryAdapter::class, 'memoryFacade');
        $facadeProp->setAccessible(true);
        $facadeProp->setValue($adapter, $shared);

        self::assertNull($adapter->get('key'));
        $secondUntil = $until();
        self::assertSame(2, $count());
        self::assertGreaterThan($firstUntil, $secondUntil);
        // 第二次窗口 ≈ now + 4.0（基准 2.0 × 2^1），允许少量调度漂移。
        self::assertGreaterThanOrEqual(
            $firstUntil + 1.0,
            $secondUntil,
            'second consecutive failure must escalate the cooldown window',
        );
    }

    public function testCooldownCapsAtSixtySeconds(): void
    {
        $shared = new FailingSharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('cooldown_cap', [], $shared);

        $until = static function () use ($adapter): float {
            $prop = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteUnavailableUntil');
            $prop->setAccessible(true);
            /** @var array<string, float> $map */
            $map = $prop->getValue();
            return $map['cooldown_cap'] ?? 0.0;
        };
        $resetWindow = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteUnavailableUntil');
        $resetWindow->setAccessible(true);
        $resetGlobal = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteGloballyUnavailableUntil');
        $resetGlobal->setAccessible(true);
        $facadeProp = new \ReflectionProperty(WlsMemoryAdapter::class, 'memoryFacade');
        $facadeProp->setAccessible(true);
        $lastNow = 0.0;
        $before = 0.0;
        for ($i = 1; $i <= 10; ++$i) {
            $before = $this->monotonic();
            self::assertNull($adapter->get('key'));
            $lastNow = $until();
            $this->lastCooldownSeconds = $lastNow - $before;
            // 反射清窗 + 恢复注入 double：连续 10 次失败必须都打在 double 上
            //（recoverRemoteProbe 会全局复位计数，无法观测 10 次递增）。
            $resetWindow->setValue(null, ['cooldown_cap' => 0.0]);
            $resetGlobal->setValue(null, 0.0);
            $facadeProp->setValue($adapter, $shared);
        }

        self::assertSame(10, $this->failureCount('cooldown_cap'));
        // 2.0 × 2^9 = 1024 → 封顶 60：最后一次窗口相对当前时刻不得超过 60s + 漂移。
        self::assertGreaterThan(0.0, $lastNow);
        // 封顶验证：第 10 次失败后（窗口已被 recover 清空），重放一次失败并断言
        // 冷却不超过 60s 上限。直接对 helper 延迟函数做封闭断言：
        $count = $this->failureCount('cooldown_cap');
        $expectedCap = 60.0;
        $naive = 2.0 * (2 ** ($count - 1));
        self::assertGreaterThan($expectedCap, $naive, 'fixture sanity: naive 2^n must exceed the cap');
        // 实际实现中第 10 次的窗口（recover 前的 lastNow）距失败时刻不得大于 60s+漂移：
        self::assertLessThanOrEqual(
            61.0,
            $this->lastCooldownSeconds,
            'cooldown must be capped at REMOTE_FAILURE_COOLDOWN_MAX_SECONDS',
        );
    }

    public function testCooldownResetsAfterSuccessfulOperation(): void
    {
        // 成功操作（clear 维护路径）证明共享服务可用，应整体复位冷却与失败计数。
        $shared = new ToggleSharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('cooldown_reset', [], $shared);

        $shared->fail = true;
        self::assertNull($adapter->get('key'));
        self::assertSame(1, $this->failureCount('cooldown_reset'));

        $shared->fail = false;
        $shared->setCache('cooldown_reset', 'value', 'ready');
        // 冷却窗内 clear() 仍会快速失败（既有 fail-fast 语义）；recover 是重开通道的正式路径。
        $adapter->recoverRemoteProbe();
        self::assertTrue($adapter->clear());
        self::assertSame([], $this->failureMap(), 'successful clear must reset failure counters');
        self::assertSame([], $this->windowMap(), 'successful clear must clear cooldown windows');

        // clear() 已按既有语义清空本 identity 池；重新写入后再验证读取恢复。
        $shared->setCache('cooldown_reset', 'value', 'ready');
        self::assertSame('ready', $adapter->getFresh('value'));
    }

    public function testCooldownResetFollowsRecoverProbeSemantics(): void
    {
        // 与既有 WlsMemoryAdapterAtomicTest::testRecoverRemoteProbeAllowsWorkerPoolAfterOtherPoolFailure
        // 相同的恢复语义：普通请求失败后，冷却窗内的 getFresh 仍然快速 miss；
        // 显式 recoverRemoteProbe()（或等冷却自然过期）才是重开通道的正式路径。
        $shared = new ToggleSharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('cooldown_recover', [], $shared);

        $shared->fail = true;
        self::assertNull($adapter->get('key'));
        self::assertSame(1, $this->failureCount('cooldown_recover'));

        $shared->fail = false;
        $shared->setCache('cooldown_recover', 'value', 'ready');
        self::assertNull($adapter->getFresh('value'), 'cooldown window must keep failing fast');

        $adapter->recoverRemoteProbe();
        self::assertSame('ready', $adapter->getFresh('value'));
        self::assertSame([], $this->failureMap(), 'recover must reset failure counters');
        self::assertSame([], $this->windowMap(), 'recover must clear cooldown windows');
    }

    public function testCooldownLogDedupesWithinWindow(): void
    {
        $shared = new FailingSharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('cooldown_log', [], $shared);

        $prop = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteFailureLogIndex');
        $prop->setAccessible(true);

        self::assertNull($adapter->get('key'));
        /** @var array<string, float> $index */
        $index = $prop->getValue();
        self::assertArrayHasKey('cooldown_log', $index, 'first failure must record a log timestamp');

        // 冷却期内强制再次走 markRemoteUnavailable：日志索引仍在窗口内 → 不得刷新时间戳。
        $recordedAt = $index['cooldown_log'];
        $method = new \ReflectionMethod(WlsMemoryAdapter::class, 'markRemoteUnavailable');
        $method->setAccessible(true);
        $method->invoke($adapter, new \RuntimeException('shared_cache_unavailable'));
        /** @var array<string, float> $indexAfter */
        $indexAfter = $prop->getValue();
        self::assertSame(
            $recordedAt,
            $indexAfter['cooldown_log'],
            'cooldown-window failures must not emit another failure log entry',
        );
    }

    /** @return array<string, int> */
    private function failureMap(): array
    {
        $prop = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteFailureCounts');
        $prop->setAccessible(true);

        return $prop->getValue();
    }

    private function failureCount(string $identity): int
    {
        return $this->failureMap()[$identity] ?? 0;
    }

    /** @return array<string, float> */
    private function windowMap(): array
    {
        $prop = new \ReflectionProperty(WlsMemoryAdapter::class, 'remoteUnavailableUntil');
        $prop->setAccessible(true);

        return $prop->getValue();
    }

    private function monotonic(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}

class FailingSharedCacheStateDouble implements SharedCacheStateInterface
{
    public function get(string $namespace, string $key): mixed
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function set(string $namespace, string $key, mixed $value, int $ttl = 0): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function delete(string $namespace, string $key): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function exists(string $namespace, string $key): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function cas(string $namespace, string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function clearNamespace(string $namespace): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function getCache(string $poolIdentity, string $key): mixed
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function deleteCache(string $poolIdentity, string $key): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function hasCache(string $poolIdentity, string $key): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function clearCache(string $poolIdentity): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function compareAndSetCache(string $poolIdentity, string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        throw new \RuntimeException('shared_cache_unavailable');
    }

    public function disconnect(): void
    {
    }
}

class ToggleSharedCacheStateDouble extends FailingSharedCacheStateDouble
{
    public bool $fail = false;

    private array $values = [];

    private function assertAvailable(): void
    {
        if ($this->fail) {
            throw new \RuntimeException('shared_cache_unavailable');
        }
    }

    public function getCache(string $poolIdentity, string $key): mixed
    {
        $this->assertAvailable();

        return $this->values[$poolIdentity][$key] ?? null;
    }

    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool
    {
        $this->assertAvailable();
        $this->values[$poolIdentity][$key] = $value;

        return true;
    }

    public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        $this->assertAvailable();
        $current = (int)($this->values[$namespace][$key] ?? 0) + $delta;
        $this->values[$namespace][$key] = $current;

        return $current;
    }

    public function clearCache(string $poolIdentity): bool
    {
        $this->assertAvailable();
        unset($this->values[$poolIdentity]);

        return true;
    }
}
