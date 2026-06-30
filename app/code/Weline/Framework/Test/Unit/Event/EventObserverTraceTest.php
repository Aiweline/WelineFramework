<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;

final class EventObserverTraceTest extends TestCase
{
    private ?Context $previousContext = null;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::reset();
        ObjectManager::clearInstances();
    }

    protected function tearDown(): void
    {
        RequestLifecycleTrace::reset();
        ObjectManager::clearInstances();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
    }

    public function testObserverDispatchStillRecordsTraceSpanWhenTraceIsEnabled(): void
    {
        ObjectManager::setInstance(EventObserverTraceProbe::class, new EventObserverTraceProbe());

        $event = new Event([
            'observers' => [
                ['instance' => EventObserverTraceProbe::class],
            ],
        ]);
        $event->setName('Unit_Event::trace');
        $event->dispatch();

        $targetSpan = null;
        foreach (RequestLifecycleTrace::getSpans() as $span) {
            if (($span['name'] ?? null) === 'observer::Weline::Framework::Test::Unit::Event::EventObserverTraceProbe') {
                $targetSpan = $span;
                break;
            }
        }

        self::assertNotNull($targetSpan);
        self::assertSame('observer', $targetSpan['category'] ?? null);
        self::assertSame('event::Unit_Event::trace', $targetSpan['parent'] ?? null);
    }
}

final class EventObserverTraceProbe implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $event->setData('executed', true);
    }
}
