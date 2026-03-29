<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService;

class DomainLifecycleBridgeServiceTest extends AbstractAiWorkbenchPersistenceCase
{
    private DomainLifecycleBridgeService $bridgeService;

    public function setUp(): void
    {
        parent::setUp();
        $this->bridgeService = ObjectManager::getInstance(DomainLifecycleBridgeService::class);
    }

    public function testBuildLifecycleStatusReturnsExpectedStructure(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);

        $status = $this->bridgeService->buildLifecycleStatus($session);

        $this->assertIsArray($status);
        $this->assertArrayHasKey('domain', $status);
        $this->assertArrayHasKey('stage', $status);
        $this->assertArrayHasKey('stage_label', $status);
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('status_label', $status);
        $this->assertArrayHasKey('is_ready', $status);
        $this->assertArrayHasKey('updated_at', $status);
    }

    public function testBuildLifecycleStatusWithNoDomainReturnsIdle(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);

        $status = $this->bridgeService->buildLifecycleStatus($session);

        $this->assertSame('idle', $status['status']);
        $this->assertFalse($status['is_ready']);
    }

    public function testBuildLifecycleStatusWithQueuedPurchase(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, [
            'target_domain' => 'queued-test.example',
            'domain_purchase_status' => 'queued',
        ]);

        $status = $this->bridgeService->buildLifecycleStatus($session);

        $this->assertSame('queued', $status['status']);
        $this->assertSame('queued-test.example', $status['domain']);
    }

    public function testBuildLifecycleStatusWithCompletedPurchase(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, [
            'target_domain' => 'completed-test.example',
            'domain_purchase_status' => 'completed',
            'domain_purchase_stage' => 'completed',
        ]);

        $status = $this->bridgeService->buildLifecycleStatus($session);

        $this->assertSame('completed', $status['status']);
        $this->assertTrue($status['is_ready']);
    }

    public function testBuildLifecycleStatusWithFailedPurchase(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, [
            'target_domain' => 'failed-test.example',
            'domain_purchase_status' => 'failed',
        ]);

        $status = $this->bridgeService->buildLifecycleStatus($session);

        $this->assertSame('failed', $status['status']);
        $this->assertFalse($status['is_ready']);
    }

    public function testAppendLifecycleEventEmitsDomainStageChanged(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);

        $eventId = $this->bridgeService->appendLifecycleEvent(
            $session->getId(),
            1,
            'purchase',
            ['domain' => 'test.example', 'stage' => 'purchase']
        );

        $this->assertGreaterThan(0, $eventId);

        $events = $this->eventStreamService->listRecentEvents($session->getId(), 1, 10);
        $domainEvents = array_filter(
            $events,
            static fn(array $e): bool => str_starts_with((string)($e['event_type'] ?? ''), 'domain_lifecycle_')
        );

        $this->assertNotEmpty($domainEvents);
        $lastEvent = end($domainEvents);
        $this->assertSame('domain_lifecycle_stage_changed', $lastEvent['event_type']);
    }

    public function testIsDomainReadyForBuildReturnsTrueWhenCompleted(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, [
            'target_domain' => 'ready-test.example',
            'domain_purchase_status' => 'completed',
            'domain_purchase_stage' => 'completed',
        ]);

        $isReady = $this->bridgeService->isDomainReadyForBuild($session);

        $this->assertTrue($isReady);
    }

    public function testIsDomainReadyForBuildReturnsFalseWhenNotCompleted(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, [
            'target_domain' => 'not-ready-test.example',
            'domain_purchase_status' => 'running',
        ]);

        $isReady = $this->bridgeService->isDomainReadyForBuild($session);

        $this->assertFalse($isReady);
    }

    public function testIsDomainReadyForBuildReturnsFalseWithNoDomain(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);

        $isReady = $this->bridgeService->isDomainReadyForBuild($session);

        $this->assertFalse($isReady);
    }

    public function testGetStageLabelReturnsCorrectLabels(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);

        $this->assertSame('准备中', $this->bridgeService->getStageLabel('idle', $session));
        $this->assertSame('购买中', $this->bridgeService->getStageLabel('purchase', $session));
        $this->assertSame('DNS 解析中', $this->bridgeService->getStageLabel('dns', $session));
        $this->assertSame('SSL 证书中', $this->bridgeService->getStageLabel('ssl', $session));
        $this->assertSame('已完成', $this->bridgeService->getStageLabel('completed', $session));
        $this->assertSame('失败', $this->bridgeService->getStageLabel('failed', $session));
        $this->assertSame('未知', $this->bridgeService->getStageLabel('unknown_stage', $session));
    }
}
