<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\AdapterFactory;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CacheManagerBehaviorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $objectManagerInstancesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManagerInstancesBackup = $this->getObjectManagerInstances();
        $instances = $this->objectManagerInstancesBackup;
        $instances[EventsManager::class] = new class {
            /**
             * @param array<string, mixed> $data
             */
            public function dispatch(string $name, array $data = []): void
            {
            }
        };
        $this->setObjectManagerInstances($instances);
    }

    protected function tearDown(): void
    {
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup);
        parent::tearDown();
    }

    public function testClearAllClearsConfiguredIdentityEvenWhenNotPreInstantiated(): void
    {
        $factory = new CacheManagerSpyAdapterFactory();
        $manager = new CacheManager($factory);
        $this->setManagerConfig($manager, [
            'default' => 'file',
            'pools' => [
                'unit_runtime_cache' => ['permanent' => false],
                'unit_runtime_permanent' => ['permanent' => true],
            ],
        ]);

        $manager->clearAll();

        $this->assertArrayHasKey('unit_runtime_cache', $factory->created);
        $this->assertSame(1, $factory->created['unit_runtime_cache']->clearCalls);
        $this->assertSame(0, $factory->created['unit_runtime_permanent']->clearCalls);
    }

    public function testFlushAllIncludesConfiguredPermanentIdentity(): void
    {
        $factory = new CacheManagerSpyAdapterFactory();
        $manager = new CacheManager($factory);
        $this->setManagerConfig($manager, [
            'default' => 'file',
            'pools' => [
                'unit_runtime_cache' => ['permanent' => false],
                'unit_runtime_permanent' => ['permanent' => true],
            ],
        ]);

        $manager->flushAll();

        $this->assertArrayHasKey('unit_runtime_permanent', $factory->created);
        $this->assertSame(1, $factory->created['unit_runtime_permanent']->clearCalls);
    }

    public function testGetAllStatsIncludesRegisteredConfiguredIdentity(): void
    {
        $factory = new CacheManagerSpyAdapterFactory();
        $manager = new CacheManager($factory);
        $this->setManagerConfig($manager, [
            'default' => 'file',
            'pools' => [
                'unit_runtime_cache' => ['permanent' => false],
                'unit_runtime_permanent' => ['permanent' => true],
            ],
        ]);

        $stats = $manager->getAllStats();

        $this->assertArrayHasKey('unit_runtime_cache', $stats);
        $this->assertArrayHasKey('unit_runtime_permanent', $stats);
        $this->assertSame('unit_runtime_cache', $stats['unit_runtime_cache']['identity']);
        $this->assertSame('unit_runtime_permanent', $stats['unit_runtime_permanent']['identity']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setManagerConfig(CacheManager $manager, array $config): void
    {
        $reflection = new ReflectionClass($manager);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $property->setValue($manager, $config);
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

final class CacheManagerSpyAdapterFactory extends AdapterFactory
{
    /** @var array<string, CacheManagerSpyAdapter> */
    public array $created = [];

    public function __construct()
    {
    }

    public function create(string $driver, string $identity): CacheAdapterInterface
    {
        return $this->created[$identity] ??= new CacheManagerSpyAdapter();
    }
}

final class CacheManagerSpyAdapter implements CacheAdapterInterface
{
    public int $clearCalls = 0;

    /** @var array<string, mixed> */
    private array $storage = [];

    public function get(string $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
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
        return \array_key_exists($key, $this->storage);
    }
}
