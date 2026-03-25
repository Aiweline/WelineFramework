<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\EventStreamService;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;
use Weline\Websites\Service\DomainPurchaseService;

class DomainPurchaseWorkbenchServiceTest extends AbstractAiWorkbenchPersistenceCase
{
    private EventStreamService $eventStreamService;

    public function setUp(): void
    {
        parent::setUp();
        $this->eventStreamService = ObjectManager::getInstance(EventStreamService::class);
    }

    public function testQueuePurchasePersistsQueuedStateAndEvent(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);
        $service = $this->createService();

        $result = $service->queuePurchase($session->getId(), 1, [
            'target_domain' => 'queued-demo.local.test',
            'registrar_account_id' => 33,
            'preferred_registrar_account_id' => 33,
        ]);

        $fresh = $this->sessionService->loadById($session->getId(), 1);
        $events = $this->eventStreamService->listRecentEvents($session->getId(), 1, 10);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['startable']);
        $this->assertNotEmpty($result['stream_token']);
        $this->assertNotNull($fresh);
        $this->assertSame('queued-demo.local.test', $fresh?->getSelectedDomain());
        $this->assertSame(33, $fresh?->getRegistrarAccountId());
        $this->assertSame('queued', $fresh?->getScopeArray()['domain_purchase_status'] ?? '');
        $this->assertSame('domain_purchase_requested', $events[0]['event_type'] ?? '');
        $this->assertSame('queued-demo.local.test', $events[0]['payload']['domain'] ?? '');
    }

    public function testExecuteQueuedPurchaseCompletesInFakeMode(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, [
            'fake_mode' => 1,
            'build_execution_mode' => 'local_fake_demo',
        ]);
        $service = $this->createService();

        $queued = $service->queuePurchase($session->getId(), 1, [
            'target_domain' => 'fake-flow.local.test',
            'registrar_account_id' => 19,
            'preferred_registrar_account_id' => 19,
        ]);

        $emitted = [];
        $result = $service->executeQueuedPurchase(
            $session->getId(),
            1,
            (string)$queued['stream_token'],
            static function (string $event, array $payload) use (&$emitted): void {
                $emitted[] = [$event, $payload];
            }
        );

        $fresh = $this->sessionService->loadById($session->getId(), 1);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['completed']);
        $this->assertNotNull($fresh);
        $this->assertSame('completed', $fresh?->getScopeArray()['domain_purchase_status'] ?? '');
        $this->assertSame('completed', $result['state']['status'] ?? '');
        $this->assertNotEmpty($emitted);
        $this->assertSame('start', $emitted[0][0] ?? '');
    }

    public function testExecuteQueuedPurchaseCompletesWhenLifecycleReportsCompleted(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, []);

        $purchaseService = $this->createMock(DomainPurchaseService::class);
        $purchaseService->expects($this->once())
            ->method('createAndProcessOrder')
            ->willReturn([
                'success' => true,
                'message' => 'purchased',
                'order_id' => 51,
            ]);

        $lifecycleService = $this->createMock(DomainLifecycleOrchestrationService::class);
        $lifecycleService->expects($this->once())
            ->method('startPurchasedLifecycle')
            ->willReturn([
                'success' => true,
                'message' => 'lifecycle started',
                'order_id' => 81,
            ]);
        $lifecycleService->expects($this->once())
            ->method('getDomainLifecycleStatus')
            ->with('real-flow.local.test')
            ->willReturn([
                'success' => true,
                'data' => [
                    'order' => [
                        'order_id' => 81,
                        'status' => 'completed',
                        'lifecycle_stage' => 'completed',
                        'error_message' => '',
                    ],
                ],
            ]);
        $lifecycleService->expects($this->never())
            ->method('processOrder');

        $service = $this->createService($purchaseService, $lifecycleService);
        $queued = $service->queuePurchase($session->getId(), 1, [
            'target_domain' => 'real-flow.local.test',
            'registrar_account_id' => 22,
            'preferred_registrar_account_id' => 22,
        ]);

        $result = $service->executeQueuedPurchase($session->getId(), 1, (string)$queued['stream_token']);
        $fresh = $this->sessionService->loadById($session->getId(), 1);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['completed']);
        $this->assertNotNull($fresh);
        $this->assertSame('completed', $fresh?->getScopeArray()['domain_purchase_status'] ?? '');
        $this->assertSame(81, (int)($result['state']['order_id'] ?? 0));
    }

    private function createService(
        ?DomainPurchaseService $purchaseService = null,
        ?DomainLifecycleOrchestrationService $lifecycleService = null
    ): DomainPurchaseWorkbenchService {
        return new DomainPurchaseWorkbenchService(
            $this->sessionService,
            $this->eventStreamService,
            $purchaseService ?? $this->createMock(DomainPurchaseService::class),
            $lifecycleService ?? $this->createMock(DomainLifecycleOrchestrationService::class)
        );
    }
}
