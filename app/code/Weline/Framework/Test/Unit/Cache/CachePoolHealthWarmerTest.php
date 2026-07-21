<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Service\CachePoolHealthWarmer;

class CachePoolHealthWarmerTest extends TestCase
{
    public function testHealthWarmerWritesAndReadsSentinelOnEveryPool(): void
    {
        $pools = [
            'pool_a' => new CacheHealthFakePool('pool_a'),
            'pool_b' => new CacheHealthFakePool('pool_b'),
            'pool_c' => new CacheHealthFakePool('pool_c'),
        ];
        $manager = new CacheHealthFakeManager($pools);

        $warmer = new CachePoolHealthWarmer($manager);
        $this->assertTrue($warmer->canWarm());

        $result = $warmer->warm();
        $this->assertSame(3, $result['warmed']);
        $this->assertSame(0, $result['skipped']);

        foreach ($pools as $pool) {
            $this->assertSame(1, $pool->writes, 'each pool must be written once');
            $this->assertSame(1, $pool->reads, 'each pool must be read once');
            $this->assertSame(1, $pool->deletes, 'sentinel must be cleaned up after read');
            $this->assertNull($pool->get(CachePoolHealthWarmer::SENTINEL_KEY));
        }
    }

    public function testHealthWarmerCountsFailingPoolsAsSkipped(): void
    {
        $pools = [
            'good' => new CacheHealthFakePool('good'),
            'bad' => new CacheHealthFakePool('bad', failOnSet: true),
        ];
        $manager = new CacheHealthFakeManager($pools);

        $warmer = new CachePoolHealthWarmer($manager);
        $result = $warmer->warm();
        $this->assertSame(1, $result['warmed']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testNameAndPriorityAreStable(): void
    {
        $warmer = new CachePoolHealthWarmer(new CacheHealthFakeManager([]));
        $this->assertSame('framework.cache_pool_health', $warmer->getName());
        $this->assertSame(0, $warmer->getPriority());
        $this->assertSame('__all__', $warmer->getTargetPool());
    }
}

final class CacheHealthFakeManager extends CacheManager
{
    /**
     * @param array<string, CachePoolInterface> $pools
     */
    public function __construct(private array $pools)
    {
    }

    public function getPoolIdentities(): array
    {
        return \array_keys($this->pools);
    }

    public function pool(string $identity): CachePoolInterface
    {
        if (!isset($this->pools[$identity])) {
            throw new \RuntimeException("pool {$identity} not registered");
        }
        return $this->pools[$identity];
    }

    public function hasPool(string $identity): bool
    {
        return isset($this->pools[$identity]);
    }
}

final class CacheHealthFakePool implements CachePoolInterface
{
    /** @var array<string, mixed> */
    private array $store = [];
    public int $reads = 0;
    public int $writes = 0;
    public int $deletes = 0;

    public function __construct(private string $identity, private bool $failOnSet = false)
    {
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getTip(): string
    {
        return '';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        return [];
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        return false;
    }

    public function deleteMultiple(array $keys): bool
    {
        return false;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->identity,
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function get(string $key): mixed
    {
        $this->reads++;
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($this->failOnSet) {
            throw new \RuntimeException('write failed');
        }
        $this->writes++;
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->deletes++;
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->set($key, $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->delete($key);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->has($key);
    }
}
