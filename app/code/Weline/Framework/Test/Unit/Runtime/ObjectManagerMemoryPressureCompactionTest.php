<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
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
        self::assertGreaterThan(0, $result['metadata_entries_cleared']);
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'parsedClasses'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'classExistsCache'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'constructorCache'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'methodParamsMetadata'));
        self::assertSame([], $this->readStaticProperty(ObjectManager::class, 'reflections'));
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

final class ObjectManagerMemoryPressureCompactionFixture
{
    public function __construct()
    {
    }
}
