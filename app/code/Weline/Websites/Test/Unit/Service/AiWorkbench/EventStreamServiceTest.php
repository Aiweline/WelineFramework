<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\EventStreamService;

class EventStreamServiceTest extends AbstractAiWorkbenchPersistenceCase
{
    private EventStreamService $eventStreamService;

    public function setUp(): void
    {
        parent::setUp();
        $this->eventStreamService = ObjectManager::getInstance(EventStreamService::class);
    }

    public function testAppendAndReplayEvents(): void
    {
        $session = $this->createTrackedSession();

        $firstEventId = $this->eventStreamService->appendEvent(
            $session->getId(),
            1,
            'domain',
            'domain_purchase_started',
            ['domain' => 'example.com']
        );
        $secondEventId = $this->eventStreamService->appendEvent(
            $session->getId(),
            1,
            'domain',
            'domain_purchase_completed',
            ['domain' => 'example.com', 'order_id' => 18],
            'success'
        );

        $latestEventId = $this->eventStreamService->getLatestEventId($session->getId(), 1);
        $incrementalEvents = $this->eventStreamService->listEventsAfterId($session->getId(), 1, $firstEventId);
        $recentEvents = $this->eventStreamService->listRecentEvents($session->getId(), 1, 10);

        $this->assertGreaterThan(0, $firstEventId);
        $this->assertGreaterThan($firstEventId, $secondEventId);
        $this->assertSame($secondEventId, $latestEventId);
        $this->assertCount(1, $incrementalEvents);
        $this->assertSame($secondEventId, $incrementalEvents[0]['event_id']);
        $this->assertSame('domain_purchase_completed', $incrementalEvents[0]['event_type']);
        $this->assertSame('success', $incrementalEvents[0]['level']);
        $this->assertSame(
            ['domain' => 'example.com', 'order_id' => 18],
            $incrementalEvents[0]['payload']
        );
        $this->assertCount(2, $recentEvents);
        $this->assertSame($firstEventId, $recentEvents[0]['event_id']);
        $this->assertSame($secondEventId, $recentEvents[1]['event_id']);
        $this->assertSame('domain_purchase_started', $recentEvents[0]['event_type']);
        $this->assertSame('domain_purchase_completed', $recentEvents[1]['event_type']);
    }
}
