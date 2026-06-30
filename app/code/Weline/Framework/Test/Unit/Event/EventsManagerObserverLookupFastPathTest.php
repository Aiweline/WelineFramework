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
        ObjectManager::setInstance(EventsManagerLookupProbeObserver::class, new EventsManagerLookupProbeObserver());

        $registry = $this->createMock(EventRegistryInterface::class);
        $registry->expects($this->once())
            ->method('hasObservers')
            ->with('Unit_Event::sample')
            ->willReturn(true);
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
}

final class EventsManagerLookupProbeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $event->setData('seen', true);
    }
}
