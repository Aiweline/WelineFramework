<?php

declare(strict_types=1);

namespace Weline\Framework\Router\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Router\Observer\UrlGuardOverflowTracker;

class UrlGuardOverflowTrackerTest extends TestCase
{
    private UrlGuardTrackerFakePool $pool;

    protected function setUp(): void
    {
        $this->pool = new UrlGuardTrackerFakePool();
        UrlGuardOverflowTracker::setPool($this->pool);
    }

    protected function tearDown(): void
    {
        UrlGuardOverflowTracker::setPool(null);
    }

    public function testFirstHitInitializesSnapshot(): void
    {
        $observer = new UrlGuardOverflowTracker();
        $payload = [
            'uri' => '/product/9999',
            'guard_name' => 'product_id_max',
            'details' => ['param' => 'id', 'value' => 9999, 'max' => 1000],
            'params_keys' => [],
            'timestamp' => 1700000000,
        ];
        $event = $this->buildEvent($payload);
        $observer->execute($event);

        $snapshot = $this->pool->store[UrlGuardOverflowTracker::KEY_PREFIX . 'product_id_max'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame(1, $snapshot['count']);
        $this->assertSame(9999, $snapshot['min_value']);
        $this->assertSame(9999, $snapshot['max_value']);
        $this->assertSame(1700000000, $snapshot['first_seen_at']);
        $this->assertSame(1700000000, $snapshot['last_seen_at']);
        $this->assertCount(1, $snapshot['recent']);
        $this->assertSame(9999, $snapshot['recent'][0]['value']);
    }

    public function testRepeatedHitsExpandMinMaxRange(): void
    {
        $observer = new UrlGuardOverflowTracker();

        foreach ([1500, 8000, 200_000, 1500] as $value) {
            $event = $this->buildEvent([
                'uri' => '/product/' . $value,
                'guard_name' => 'product_id_max',
                'details' => ['param' => 'id', 'value' => $value],
                'timestamp' => 1700000000 + $value,
            ]);
            $observer->execute($event);
        }

        $snapshot = $this->pool->store[UrlGuardOverflowTracker::KEY_PREFIX . 'product_id_max'];
        $this->assertSame(4, $snapshot['count']);
        $this->assertSame(1500, $snapshot['min_value']);
        $this->assertSame(200_000, $snapshot['max_value']);
        $this->assertCount(4, $snapshot['recent']);
        $this->assertSame(1500, $snapshot['recent'][0]['value'], 'most recent is at index 0');
    }

    public function testRecentSamplesRespectsCap(): void
    {
        $observer = new UrlGuardOverflowTracker();

        for ($i = 0; $i < UrlGuardOverflowTracker::RECENT_SAMPLES_MAX + 5; $i++) {
            $event = $this->buildEvent([
                'uri' => '/product/' . $i,
                'guard_name' => 'product_id_max',
                'details' => ['param' => 'id', 'value' => $i],
            ]);
            $observer->execute($event);
        }

        $snapshot = $this->pool->store[UrlGuardOverflowTracker::KEY_PREFIX . 'product_id_max'];
        $this->assertCount(UrlGuardOverflowTracker::RECENT_SAMPLES_MAX, $snapshot['recent']);
    }

    public function testEmptyGuardNameIsIgnored(): void
    {
        $observer = new UrlGuardOverflowTracker();
        $event = $this->buildEvent([
            'uri' => '/x',
            'guard_name' => '',
            'details' => [],
        ]);
        $observer->execute($event);
        $this->assertSame([], $this->pool->store);
    }

    public function testReadSnapshotReturnsStoredArrayOrNull(): void
    {
        $observer = new UrlGuardOverflowTracker();
        $event = $this->buildEvent([
            'uri' => '/x',
            'guard_name' => 'g',
            'details' => ['param' => 'id', 'value' => 1],
        ]);
        $observer->execute($event);

        $snapshot = UrlGuardOverflowTracker::readSnapshot('g');
        $this->assertIsArray($snapshot);
        $this->assertSame(1, $snapshot['count']);

        $missing = UrlGuardOverflowTracker::readSnapshot('does_not_exist');
        $this->assertNull($missing);
    }

    private function buildEvent(array $payload): Event
    {
        return new Event($payload);
    }
}

final class UrlGuardTrackerFakePool implements CachePoolInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function getIdentity(): string
    {
        return 'url_guard_test';
    }

    public function getTip(): string
    {
        return 'test';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $k => $v) {
            $this->set((string)$k, $v, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->getIdentity(),
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
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
}
