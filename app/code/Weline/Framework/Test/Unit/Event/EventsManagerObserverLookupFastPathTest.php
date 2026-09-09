<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventRegistryInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

final class EventsManagerObserverLookupFastPathTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
    }

    public function testDispatchDoesNotRepeatHasObserversAfterPositiveRegistryHit(): void
    {
        $this->registerProbeObserver();

        $registry = $this->createMock(EventRegistryInterface::class);
        $registry->expects($this->never())->method('hasObservers');
        $registry->expects($this->once())
            ->method('getRegistry')
            ->willReturn([
                'events' => [
                    'Unit_Event::sample' => [
                        'observers' => [
                            [
                                'instance' => EventsManagerLookupProbeObserver::class,
                                'module' => 'Unit_Event',
                                'module_status' => true,
                                'disabled' => 'false',
                            ],
                        ],
                    ],
                ],
                'dynamic_patterns' => [],
            ]);
        $registry->expects($this->never())
            ->method('matchPattern');

        $manager = new EventsManager($this->createMock(XmlReader::class), $registry);
        $data = [];
        $manager->dispatch('Unit_Event::sample', $data);

        self::assertTrue($data['seen'] ?? false);
    }

    public function testColdManagersAndDifferentUnregisteredEventsNeverScanModuleXml(): void
    {
        $reads = 0;
        $reader = $this->createMock(XmlReader::class);
        $reader->method('read')->willReturnCallback(static function () use (&$reads): array {
            ++$reads;
            return [];
        });
        $registry = new EventsManagerMutableRegistry();
        foreach (range(1, 3) as $request) {
            $manager = new EventsManager($reader, $registry);
            $data = ['request' => $request];
            $manager->dispatch('Weline_I18n::header-currency-switcher-data', $data);
            $manager->dispatch('Unit_Event::other_optional_hook', $data);
            $manager->resetRequestState();
            self::assertFalse($manager->hasObservers('Weline_I18n::header-currency-switcher-data'));
            self::assertSame(['request' => $request], $data);
        }
        self::assertSame(0, $reads, 'A complete registry is authoritative for negative lookups, including cold workers.');
    }

    public function testForceReloadMakesNewAndRemovedObserversVisibleAfterCachedLookup(): void
    {
        $this->registerProbeObserver();
        $reader = $this->createMock(XmlReader::class);
        $reader->expects(self::never())->method('read');
        $registry = new EventsManagerMutableRegistry();
        $manager = new EventsManager($reader, $registry);
        self::assertFalse($manager->hasObservers('Unit_Event::late'));

        $registry->next = ['events' => ['Unit_Event::late' => ['observers' => [$this->observer()]]], 'dynamic_patterns' => []];
        $registry->getRegistry(true);
        $data = [];
        $manager->dispatch('Unit_Event::late', $data);
        self::assertTrue($data['seen'] ?? false, 'A registry reload must invalidate negative observer results.');

        $registry->next = ['events' => ['Unit_Event::late' => ['observers' => []]], 'dynamic_patterns' => []];
        $registry->getRegistry(true);
        self::assertFalse($manager->hasObservers('Unit_Event::late'), 'Removed observers must not survive a registry reload.');
    }

    public function testDynamicRegistryObserversDoNotNeedAnXmlFallback(): void
    {
        $this->registerProbeObserver();
        $registry = new EventsManagerMutableRegistry();
        $registry->next = ['events' => [], 'dynamic_patterns' => [
            'Unit_Event::{name}' => ['observers' => [$this->observer()]],
        ]];
        $registry->getRegistry(true);
        $reader = $this->createMock(XmlReader::class);
        $reader->expects(self::never())->method('read');
        $manager = new EventsManager($reader, $registry);
        $data = [];
        $manager->dispatch('Unit_Event::dynamic', $data);
        self::assertTrue($data['seen'] ?? false);
    }

    public function testExplicitScanPublishesObserversAfterAnEarlierNegativeResult(): void
    {
        $this->registerProbeObserver();
        $registry = new EventsManagerMutableRegistry();
        $reads = 0;
        $reader = $this->createMock(XmlReader::class);
        $reader->method('read')->willReturnCallback(function () use (&$reads): array {
            ++$reads;
            return ['Unit_Event::event.xml' => ['Unit_Event::explicit' => [$this->observer()]]];
        });
        $manager = new EventsManager($reader, $registry);
        self::assertFalse($manager->hasObservers('Unit_Event::explicit'));
        self::assertSame(0, $reads);
        $manager->scanEvents();
        $data = [];
        $manager->dispatch('Unit_Event::explicit', $data);
        self::assertTrue($data['seen'] ?? false);
        self::assertSame(1, $reads);
        $manager->clearObserverCache();
        self::assertFalse($manager->hasObservers('Unit_Event::explicit'));
    }

    private function observer(): array
    {
        return ['instance' => EventsManagerLookupProbeObserver::class, 'module' => 'Unit_Event',
            'module_status' => true, 'disabled' => 'false'];
    }

    public function testExplicitEmptyScanIsReusedUntilObserverCacheIsCleared(): void
    {
        $reads = 0;
        $reader = $this->createMock(XmlReader::class);
        $reader->method('read')->willReturnCallback(static function () use (&$reads): array {
            ++$reads;
            return [];
        });
        $manager = new EventsManager($reader, new EventsManagerMutableRegistry());
        self::assertSame([], $manager->scanEvents());
        self::assertSame([], $manager->scanEvents());
        self::assertSame(1, $reads);
        $manager->clearObserverCache();
        self::assertSame([], $manager->scanEvents());
        self::assertSame(2, $reads);
    }

    private function registerProbeObserver(): void
    {
        $observer = new EventsManagerLookupProbeObserver();
        ObjectManager::setInstance(EventsManagerLookupProbeObserver::class, $observer);
    }
}

final class EventsManagerMutableRegistry implements EventRegistryInterface
{
    public array $next = [];
    private array $registry = ['events' => ['Unit_Event::known' => ['observers' => []]], 'dynamic_patterns' => []];

    public function getRegistry(bool $forceReload = false): array
    {
        if ($forceReload) { $this->registry = $this->next; }
        return $this->registry;
    }
    public function hasObservers(string $eventName): bool
    {
        if (($this->registry['events'][$eventName]['observers'] ?? []) !== []) { return true; }
        foreach ($this->registry['dynamic_patterns'] ?? [] as $pattern => $entry) {
            if ($this->matchPattern($pattern, $eventName) && ($entry['observers'] ?? []) !== []) { return true; }
        }
        return false;
    }
    public function matchPattern(string $pattern, string $eventName): bool
    {
        $matcher = (new \ReflectionClass(\Weline\Framework\Event\EventRegistry::class))->newInstanceWithoutConstructor();
        return $matcher->matchPattern($pattern, $eventName);
    }
}

final class EventsManagerLookupProbeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $event->setData('seen', true);
    }
}
