<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Event\EventScanner;
use Weline\Framework\Runtime\Runtime;

final class EventRegistryRuntimeCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::resetModeCache();
        $this->setStaticProperty('runtimeRegistryCache', null);
        $this->setStaticProperty('runtimeRegistryMtime', null);
    }

    public function testPersistentRuntimeTrustsLoadedRegistryCache(): void
    {
        Runtime::setMode('wls');

        $registry = new EventRegistry(
            $this->createMock(EventScanner::class),
            $this->createMock(XmlReader::class)
        );

        $cachedRegistry = [
            'events' => [
                'Unit_Event::sample' => [
                    'observers' => [
                        ['module' => 'Unit_Event', 'instance' => 'Unit\\Observer\\Sample'],
                    ],
                ],
            ],
            'event_to_module' => [
                'Unit_Event::sample' => 'Unit_Event',
            ],
            'dynamic_patterns' => [],
        ];

        $this->setObjectProperty($registry, 'cachedRegistry', $cachedRegistry);
        $this->setObjectProperty($registry, 'cachedFileMtime', -1);

        self::assertSame($cachedRegistry, $registry->getRegistry());
        self::assertTrue($registry->hasObservers('Unit_Event::sample'));
    }

    public function testPersistentRuntimeReusesRegistryAcrossRegistryInstances(): void
    {
        Runtime::setMode('wls');

        $cachedRegistry = [
            'events' => [
                'Unit_Event::runtime_cache' => [
                    'observers' => [
                        ['module' => 'Unit_Event', 'instance' => 'Unit\\Observer\\RuntimeCache'],
                    ],
                ],
            ],
            'event_to_module' => [
                'Unit_Event::runtime_cache' => 'Unit_Event',
            ],
            'dynamic_patterns' => [],
        ];

        $this->setStaticProperty('runtimeRegistryCache', $cachedRegistry);
        $this->setStaticProperty('runtimeRegistryMtime', 123);

        $registry = new EventRegistry(
            $this->createMock(EventScanner::class),
            $this->createMock(XmlReader::class)
        );

        self::assertSame($cachedRegistry, $registry->getRegistry());
        self::assertTrue($registry->hasObservers('Unit_Event::runtime_cache'));
    }

    private function setObjectProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function setStaticProperty(string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(EventRegistry::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue(null, $value);
    }
}
