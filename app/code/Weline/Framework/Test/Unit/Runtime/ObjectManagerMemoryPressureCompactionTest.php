<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\MemoryPressureAwareInterface;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Manager\ObjectManager;

final class ObjectManagerMemoryPressureCompactionTest extends TestCase
{
    protected function setUp(): void
    {
        ObjectManager::clearInstances();
    }

    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
    }

    public function testRelieveMemoryPressureClearsMemoryStoresAndMetadataCaches(): void
    {
        $probe = new ObjectManagerMemoryPressureCompactionProbeStore();
        ObjectManager::setInstance(ObjectManagerMemoryPressureCompactionProbeStore::class, $probe);

        ObjectManager::parserClass(ObjectManagerMemoryPressureCompactionFixture::class);
        ObjectManager::isStaticClass(ObjectManagerMemoryPressureCompactionFixture::class);
        ObjectManager::getInstance(ObjectManagerMemoryPressureCompactionFixture::class, [], false);

        $getMethodParams = new \ReflectionMethod(ObjectManager::class, 'getMethodParams');
        $getMethodParams->setAccessible(true);
        $getMethodParams->invoke(null, ObjectManagerMemoryPressureCompactionFixture::class, '__construct');

        self::assertNotSame([], $this->readStaticProperty(ObjectManager::class, 'parsedClasses'));
        self::assertNotSame([], $this->readStaticProperty(ObjectManager::class, 'classExistsCache'));
        self::assertNotSame([], $this->readStaticProperty(ObjectManager::class, 'constructorCache'));
        self::assertNotSame([], $this->readStaticProperty(ObjectManager::class, 'methodParamsMetadata'));

        $result = ObjectManager::relieveMemoryPressure(true);

        self::assertTrue($probe->wasCleared());
        self::assertSame(1, $result['memory_store_clears']);
        self::assertSame(0, $result['memory_store_evictions']);
        self::assertGreaterThan(0, $result['metadata_entries_cleared']);
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'parsedClasses'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'classExistsCache'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'constructorCache'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'methodParamsMetadata'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'reflections'));
    }

    public function testSoftRelieveEvictsHalfWithoutClearingEntireStore(): void
    {
        $probe = new ObjectManagerMemoryPressureSoftProbeStore();
        ObjectManager::setInstance(ObjectManagerMemoryPressureSoftProbeStore::class, $probe);

        $result = ObjectManager::relieveMemoryPressure(false);

        self::assertFalse($probe->wasCleared());
        self::assertSame(0, $result['memory_store_clears']);
        self::assertSame(2, $result['memory_store_evictions']);
        self::assertSame(2, $probe->getMemoryItemCount());
    }

    private function readStaticProperty(string $class, string $property): mixed
    {
        $reflection = new \ReflectionProperty($class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue();
    }
}

final class ObjectManagerMemoryPressureCompactionProbeStore implements MemoryStoreInterface
{
    private bool $cleared = false;

    public function getMemoryUsage(): int
    {
        return 1024;
    }

    public function getMemoryItemCount(): int
    {
        return 1;
    }

    public function getMaxItems(): int
    {
        return 16;
    }

    public function getMaxMemory(): int
    {
        return 16384;
    }

    public function evict(int $count): int
    {
        return $count > 0 ? 1 : 0;
    }

    public function clearMemory(): void
    {
        $this->cleared = true;
    }

    public function warmUp(int $limit = 1000): int
    {
        return 0;
    }

    public function wasCleared(): bool
    {
        return $this->cleared;
    }
}

final class ObjectManagerMemoryPressureSoftProbeStore implements MemoryStoreInterface, MemoryPressureAwareInterface
{
    /** @var list<string> */
    private array $items = ['a', 'b', 'c', 'd'];
    private bool $cleared = false;

    public function getMemoryUsage(): int
    {
        return \count($this->items) * 64;
    }

    public function getMemoryItemCount(): int
    {
        return \count($this->items);
    }

    public function getMaxItems(): int
    {
        return 16;
    }

    public function getMaxMemory(): int
    {
        return 16384;
    }

    public function evict(int $count): int
    {
        $n = 0;
        while ($n < $count && $this->items !== []) {
            \array_shift($this->items);
            $n++;
        }

        return $n;
    }

    public function clearMemory(): void
    {
        $this->items = [];
        $this->cleared = true;
    }

    public function warmUp(int $limit = 1000): int
    {
        return 0;
    }

    public function relievePressure(bool $aggressive): int
    {
        if ($aggressive) {
            $n = \count($this->items);
            $this->clearMemory();

            return $n;
        }

        return $this->evict(\max(1, (int)\ceil(\count($this->items) / 2)));
    }

    public function wasCleared(): bool
    {
        return $this->cleared;
    }
}

final class ObjectManagerMemoryPressureCompactionFixture
{
    public function __construct()
    {
    }
}
