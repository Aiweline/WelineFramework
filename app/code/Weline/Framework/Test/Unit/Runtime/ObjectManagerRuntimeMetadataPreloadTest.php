<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ObjectManagerRuntimeMetadataPreloadTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        ObjectManager::clearInstances();
        ObjectManager::relieveMemoryPressure(true);
        $this->resetPreloadState();
    }

    protected function tearDown(): void
    {
        Runtime::resetModeCache();
        ObjectManager::clearInstances();
        ObjectManager::relieveMemoryPressure(true);
        $this->resetPreloadState();
    }

    public function testPreloadRuntimeMetadataWarmsGeneratedMetadataWithoutCreatingInstances(): void
    {
        self::assertSame([], ObjectManager::getInstances());

        ObjectManager::preloadRuntimeMetadata();

        self::assertTrue($this->readObjectManagerProperty('precompiledLoaded'));
        self::assertTrue($this->readObjectManagerProperty('compiledFactoriesLoaded'));
        self::assertIsInt($this->readObjectManagerProperty('generatedPluginRegistryMtime'));
        self::assertSame([], ObjectManager::getInstances());
    }

    private function resetPreloadState(): void
    {
        $this->writeObjectManagerProperty('precompiledMetadata', null);
        $this->writeObjectManagerProperty('precompiledLoaded', false);
        $this->writeObjectManagerProperty('compiledFactories', null);
        $this->writeObjectManagerProperty('compiledFactoriesLoaded', false);
        $this->writeObjectManagerProperty('generatedPluginRegistry', null);
        $this->writeObjectManagerProperty('generatedPluginRegistryMtime', null);
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
