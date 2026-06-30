<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

final class ObjectManagerReflectionClassMetadataCacheTest extends TestCase
{
    protected function setUp(): void
    {
        ObjectManager::relieveMemoryPressure(true);
        ObjectManager::clearInstances();
    }

    protected function tearDown(): void
    {
        ObjectManager::relieveMemoryPressure(true);
        ObjectManager::clearInstances();
    }

    public function testMakeKeepsReflectionMetadataIsolatedPerTargetClass(): void
    {
        ObjectManager::make(ObjectManagerMetadataCacheSourceFixture::class);

        $targetDependency = new ObjectManagerMetadataCacheTargetDependency();
        $target = ObjectManager::make(ObjectManagerMetadataCacheTargetFixture::class, [
            'targetDependency' => $targetDependency,
        ]);

        self::assertSame($targetDependency, $target->targetDependency);
        self::assertNull($target->optionalDependency);
        self::assertSame(0, $target->count);
    }

    public function testMakeResolvesUnionTypedConstructorDependency(): void
    {
        $target = ObjectManager::make(ObjectManagerUnionTypedTargetFixture::class);

        self::assertInstanceOf(ObjectManagerUnionTypedDependency::class, $target->dependency);
    }
}

final class ObjectManagerMetadataCacheSourceFixture
{
    public function __construct(
        public ObjectManagerMetadataCacheSourceDependencyA $dependencyA,
        public ObjectManagerMetadataCacheSourceDependencyB $dependencyB,
        public ObjectManagerMetadataCacheSourceDependencyC $dependencyC
    ) {
    }
}

final class ObjectManagerMetadataCacheSourceDependencyA
{
    public function __construct()
    {
    }
}

final class ObjectManagerMetadataCacheSourceDependencyB
{
    public function __construct()
    {
    }
}

final class ObjectManagerMetadataCacheSourceDependencyC
{
    public function __construct()
    {
    }
}

final class ObjectManagerMetadataCacheTargetFixture
{
    public function __construct(
        public ObjectManagerMetadataCacheTargetDependency $targetDependency,
        public ?ObjectManagerMetadataCacheOptionalDependency $optionalDependency = null,
        public int $count = 0
    ) {
    }
}

final class ObjectManagerMetadataCacheTargetDependency
{
    public function __construct()
    {
    }
}

final class ObjectManagerMetadataCacheOptionalDependency
{
    public function __construct()
    {
    }
}

final class ObjectManagerUnionTypedTargetFixture
{
    public function __construct(
        public ObjectManagerUnionTypedDependency|ObjectManagerUnionTypedFallbackDependency $dependency
    ) {
    }
}

final class ObjectManagerUnionTypedDependency
{
    public function __construct()
    {
    }
}

final class ObjectManagerUnionTypedFallbackDependency
{
    public function __construct()
    {
    }
}
