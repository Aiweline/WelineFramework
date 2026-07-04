<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CachePoolTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $objectManagerInstancesBackup = [];

    /** @var object{events:array<int, array{name:string,data:array<string, mixed>}>, dispatch:callable} */
    private object $eventSpy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManagerInstancesBackup = $this->getObjectManagerInstances();
        $this->eventSpy = new class {
            /** @var array<int, array{name:string,data:array<string, mixed>}> */
            public array $events = [];

            /**
             * @param array<string, mixed> $data
             */
            public function dispatch(string $name, array $data = []): void
            {
                $this->events[] = [
                    'name' => $name,
                    'data' => $data,
                ];
            }
        };

        $instances = $this->objectManagerInstancesBackup;
        $instances[EventsManager::class] = $this->eventSpy;
        $this->setObjectManagerInstances($instances);
    }

    protected function tearDown(): void
    {
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup);
        parent::tearDown();
    }

    public function testDisabledPoolBypassesStorageAndReportsMisses(): void
    {
        $adapter = new CachePoolSpyAdapter();
        $pool = new CachePool('unit_disabled', $adapter, 'disabled test', false, 300, false);

        $this->assertNull($pool->get('key'));
        $this->assertTrue($pool->set('key', 'value'));
        $this->assertFalse($pool->has('key'));

        $stats = $pool->getStats();

        $this->assertSame(0, $adapter->getCalls);
        $this->assertSame(0, $adapter->setCalls);
        $this->assertSame(0, $adapter->hasCalls);
        $this->assertSame(1, $stats['misses']);
        $this->assertFalse($stats['enabled']);
    }

    public function testClearDispatchesCacheFlushedEvent(): void
    {
        $adapter = new CachePoolSpyAdapter();
        $pool = new CachePool('unit_clear', $adapter, 'clear tip');

        $this->assertTrue($pool->clear());
        $this->assertCount(1, $this->eventSpy->events);
        $this->assertSame('Weline_Framework_Cache::integration::cache_flushed', $this->eventSpy->events[0]['name']);
        $this->assertSame('unit_clear', $this->eventSpy->events[0]['data']['identity']);
        $this->assertSame('clear', $this->eventSpy->events[0]['data']['operation']);
        $this->assertSame('clear tip', $this->eventSpy->events[0]['data']['tip']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getObjectManagerInstances(): array
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);

        /** @var array<string, mixed> $instances */
        $instances = $property->getValue();
        return $instances;
    }

    /**
     * @param array<string, mixed> $instances
     */
    private function setObjectManagerInstances(array $instances): void
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);
        $property->setValue(null, $instances);
    }
}

final class CachePoolSpyAdapter implements CacheAdapterInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;
    public int $deleteCalls = 0;
    public int $clearCalls = 0;
    public int $hasCalls = 0;

    /** @var array<string, mixed> */
    private array $storage = [];

    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setCalls++;
        $this->storage[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->deleteCalls++;
        unset($this->storage[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->clearCalls++;
        $this->storage = [];
        return true;
    }

    public function has(string $key): bool
    {
        $this->hasCalls++;
        return \array_key_exists($key, $this->storage);
    }
}
