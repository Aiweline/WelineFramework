<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query\Store;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Service\Query\Store\FrontendWorkerCredentialType;
use Weline\Framework\Service\Query\Store\FrontendWorkerSessionPayloadCache;

final class FrontendWorkerSessionPayloadCacheTest extends TestCase
{
    protected function setUp(): void
    {
        FrontendWorkerSessionPayloadCache::resetProcessCache();
    }

    protected function tearDown(): void
    {
        FrontendWorkerSessionPayloadCache::resetProcessCache();
    }

    public function testProcessCacheIsCheckedBeforeSharedPool(): void
    {
        $pool = new CountingCachePool();
        $cache = new FrontendWorkerSessionPayloadCache(null, $pool);

        $identity = [
            'type' => FrontendWorkerCredentialType::SESSION,
            'scope_hash' => '',
            'credential_hash' => \hash('sha256', 'token-a'),
        ];
        $key = FrontendWorkerSessionPayloadCache::keyForIdentity($identity);
        $now = 1_000;
        $payload = ['secret' => 's1', 'expires_at' => $now + 60];

        $cache->put($key, $payload, $now + 60, $now);
        self::assertSame(1, $pool->sets);

        $pool->gets = 0;
        $hit = $cache->get($key, $now + 1);
        self::assertSame($payload, $hit);
        self::assertSame(0, $pool->gets, 'L1 process hit must not touch shared pool');

        FrontendWorkerSessionPayloadCache::resetProcessCache();
        $pool->values[$key] = [
            'v' => 1,
            'expires_at' => $now + 60,
            'payload' => $payload,
        ];
        $fromShared = $cache->get($key, $now + 1);
        self::assertSame($payload, $fromShared);
        self::assertSame(1, $pool->gets);

        $pool->gets = 0;
        $again = $cache->get($key, $now + 1);
        self::assertSame($payload, $again);
        self::assertSame(0, $pool->gets, 'warmed L1 must satisfy the next get');
    }

    public function testForgetClearsProcessAndShared(): void
    {
        $pool = new CountingCachePool();
        $cache = new FrontendWorkerSessionPayloadCache(null, $pool);

        $key = 'sess.v1.test';
        $now = 50;
        $cache->put($key, ['secret' => 'x'], $now + 10, $now);
        $cache->forget($key);
        self::assertNull($cache->get($key, $now + 1));
        self::assertSame(1, $pool->deletes);
    }

    public function testExpiredProcessEntryFallsThrough(): void
    {
        $pool = new CountingCachePool();
        $cache = new FrontendWorkerSessionPayloadCache(null, $pool);
        $key = 'sess.v1.expired';
        $now = 100;
        $cache->put($key, ['secret' => 'old'], $now + 5, $now);
        self::assertNull($cache->get($key, $now + 6));
        self::assertSame(1, $pool->gets);
    }
}

/** @internal */
final class CountingCachePool implements CachePoolInterface
{
    /** @var array<string, mixed> */
    public array $values = [];
    public int $gets = 0;
    public int $sets = 0;
    public int $deletes = 0;

    public function getIdentity(): string
    {
        return 'test';
    }

    public function getTip(): string
    {
        return '';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function get(string $key): mixed
    {
        $this->gets++;
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->sets++;
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->deletes++;
        unset($this->values[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function clear(): bool
    {
        $this->values = [];
        return true;
    }

    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get((string)$key);
        }
        return $out;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string)$key);
        }
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => 'test',
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
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
