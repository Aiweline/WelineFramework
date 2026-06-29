<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ObjectManagerGeneratedPluginRegistryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        ObjectManager::relieveMemoryPressure(true);
        ObjectManager::clearInstances();
    }

    protected function tearDown(): void
    {
        Runtime::resetModeCache();
        ObjectManager::relieveMemoryPressure(true);
        ObjectManager::clearInstances();
    }

    public function testPersistentRuntimeTrustsAlreadyLoadedPluginRegistry(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        $this->writeObjectManagerProperty('generatedPluginRegistryMtime', -123);
        $this->writeObjectManagerProperty('generatedPluginRegistry', ['class_to_plugins' => []]);

        self::assertSame(
            ObjectManagerGeneratedPluginRegistryCacheFixture::class,
            ObjectManager::parserClass(ObjectManagerGeneratedPluginRegistryCacheFixture::class)
        );

        self::assertSame(-123, $this->readObjectManagerProperty('generatedPluginRegistryMtime'));
    }

    public function testNonPersistentRuntimeRefreshesPluginRegistryFileState(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_CLI);
        $this->writeObjectManagerProperty('generatedPluginRegistryMtime', -123);
        $this->writeObjectManagerProperty('generatedPluginRegistry', ['class_to_plugins' => []]);

        self::assertSame(
            ObjectManagerGeneratedPluginRegistryCacheFixture::class,
            ObjectManager::parserClass(ObjectManagerGeneratedPluginRegistryCacheFixture::class)
        );

        self::assertNotSame(-123, $this->readObjectManagerProperty('generatedPluginRegistryMtime'));
    }

    private function readObjectManagerProperty(string $property): mixed
    {
        $reflection = new \ReflectionProperty(ObjectManager::class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue();
    }

    private function writeObjectManagerProperty(string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(ObjectManager::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue(null, $value);
    }
}

final class ObjectManagerGeneratedPluginRegistryCacheFixture
{
}
