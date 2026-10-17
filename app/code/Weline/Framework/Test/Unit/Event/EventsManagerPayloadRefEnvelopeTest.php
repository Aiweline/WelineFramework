<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventRegistryInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

final class EventsManagerPayloadRefEnvelopeTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
        parent::tearDown();
    }

    public function testFlatArrayPayloadMutatesCallerWithoutObserversPollution(): void
    {
        ObjectManager::setInstance(EventsManagerPayloadProbeObserver::class, new EventsManagerPayloadProbeObserver());
        $manager = new EventsManager($this->createMock(XmlReader::class), $this->registryWith(
            EventsManagerPayloadProbeObserver::class,
        ));

        $payload = [
            'marker' => \str_repeat('X', 8192),
            'result' => null,
        ];
        $manager->dispatch('Unit_Event::payload_ref', $payload);

        self::assertSame('ok', $payload['result']);
        self::assertArrayNotHasKey('observers', $payload);
        self::assertSame(8192, \strlen((string)$payload['marker']));
    }

    public function testNestedDataKeyStillMutatesCallerBag(): void
    {
        ObjectManager::setInstance(EventsManagerNestedPayloadProbeObserver::class, new EventsManagerNestedPayloadProbeObserver());
        $manager = new EventsManager($this->createMock(XmlReader::class), $this->registryWith(
            EventsManagerNestedPayloadProbeObserver::class,
        ));

        $payload = ['data' => ['operation' => 'x', 'result' => null]];
        $manager->dispatch('Unit_Event::payload_nested', $payload);

        self::assertSame('nested-ok', $payload['data']['result'] ?? null);
        self::assertArrayNotHasKey('observers', $payload);
    }

    /**
     * layout_resolve (and peers) pass ['data' => DataObject] and observers call
     * $event->getData('data') for the whole bag. Digging into DataObject fields
     * for key "data" must not return null and skip Theme path↔layout claims.
     */
    public function testNestedDataObjectBagIsReturnedByGetDataData(): void
    {
        ObjectManager::setInstance(
            EventsManagerNestedDataObjectProbeObserver::class,
            new EventsManagerNestedDataObjectProbeObserver(),
        );
        $manager = new EventsManager($this->createMock(XmlReader::class), $this->registryWith(
            EventsManagerNestedDataObjectProbeObserver::class,
            'Unit_Event::payload_nested_object',
        ));

        $bag = new DataObject([
            'request_path' => 'shop',
            'claimed' => false,
            'layout_path' => '',
        ]);
        $payload = ['data' => $bag];
        $manager->dispatch('Unit_Event::payload_nested_object', $payload);

        self::assertTrue((bool)$bag->getData('claimed'));
        self::assertSame('shop', (string)$bag->getData('layout_path'));
        self::assertSame($bag, $payload['data']);
        self::assertArrayNotHasKey('observers', $payload);
    }

    public function testReleaseBoundPayloadDoesNotNullCallerBagAndClearsStickiness(): void
    {
        ObjectManager::setInstance(EventsManagerPayloadProbeObserver::class, new EventsManagerPayloadProbeObserver());
        $manager = new EventsManager($this->createMock(XmlReader::class), $this->registryWith(
            EventsManagerPayloadProbeObserver::class,
        ));

        $payload = [
            'marker' => \str_repeat('Y', 4096),
            'result' => null,
        ];
        $manager->dispatch('Unit_Event::payload_ref', $payload);

        self::assertSame('ok', $payload['result']);
        self::assertSame(4096, \strlen((string)$payload['marker']), 'release 不得把调用方袋置 null');

        $stored = $manager->getEventData('Unit_Event::payload_ref');
        self::assertInstanceOf(Event::class, $stored);
        self::assertFalse($stored->isPayloadBound());
        self::assertNull($stored->getEvenData('result'), '存盘 Event 不得继续挂住大袋引用');

        $manager->resetRequestState();
        self::assertNull($manager->getEventData('Unit_Event::payload_ref'));
        self::assertSame('ok', $payload['result']);
    }

    private function registryWith(string $observerClass, string $eventName = ''): EventRegistryInterface
    {
        $observer = [
            'instance' => $observerClass,
            'module' => 'Unit_Event',
            'module_status' => true,
            'disabled' => 'false',
        ];

        return new class($observer, $eventName) implements EventRegistryInterface {
            public function __construct(private array $observer, private string $extraEvent = '')
            {
            }

            public function getRegistry(bool $forceReload = false): array
            {
                $events = [
                    'Unit_Event::payload_ref' => ['observers' => [$this->observer]],
                    'Unit_Event::payload_nested' => ['observers' => [$this->observer]],
                ];
                if ($this->extraEvent !== '') {
                    $events[$this->extraEvent] = ['observers' => [$this->observer]];
                }

                return [
                    'events' => $events,
                    'dynamic_patterns' => [],
                ];
            }

            public function hasObservers(string $eventName): bool
            {
                return isset($this->getRegistry()['events'][$eventName]['observers'][0]);
            }

            public function matchPattern(string $pattern, string $eventName): bool
            {
                return false;
            }
        };
    }
}

final class EventsManagerPayloadProbeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $event->setData('result', 'ok');
    }
}

final class EventsManagerNestedPayloadProbeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $event->setData('result', 'nested-ok');
    }
}

final class EventsManagerNestedDataObjectProbeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        $data->setData('claimed', true);
        $data->setData('layout_path', (string)$data->getData('request_path'));
    }
}
