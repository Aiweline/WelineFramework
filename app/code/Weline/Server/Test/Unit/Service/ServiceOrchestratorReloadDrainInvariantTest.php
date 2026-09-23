<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Memory\MemoryPressureController;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\ServiceRegistry;

final class ServiceOrchestratorReloadDrainInvariantTest extends TestCase
{
    public function testNginxBackedDirectReloadOutlivesHostGatewayKeepalive(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([
            'wls.orchestrator.reload_drain_timeout_sec' => 5.0,
            'wls.edge.adapter' => 'nginx',
            'wls.edge.nginx' => [
                'upstream_keepalive_timeout_sec' => 5,
            ],
        ]);

        $masterWait = $this->invokePrivate($orchestrator, 'resolveWorkerReloadDrainTimeout');
        $workerSoft = $this->invokePrivate(
            $orchestrator,
            'resolveWorkerReloadSoftDrainTimeout',
            [$masterWait],
        );

        self::assertSame(20.0, $masterWait);
        self::assertSame(15.0, $workerSoft);
        self::assertGreaterThan(
            GatewayPaths::UPSTREAM_KEEPALIVE_TIMEOUT_SEC,
            $workerSoft,
        );
        self::assertGreaterThan($workerSoft, $masterWait);
    }

    public function testLongerManagedNginxKeepaliveRaisesBothDrainDeadlines(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([
            'wls.orchestrator.reload_drain_timeout_sec' => 5.0,
            'wls.edge.adapter' => 'nginx',
            'wls.edge.nginx' => [
                'upstream_keepalive_timeout_sec' => 30,
            ],
        ]);

        $masterWait = $this->invokePrivate($orchestrator, 'resolveWorkerReloadDrainTimeout');
        $workerSoft = $this->invokePrivate(
            $orchestrator,
            'resolveWorkerReloadSoftDrainTimeout',
            [$masterWait],
        );

        self::assertSame(40.0, $masterWait);
        self::assertSame(35.0, $workerSoft);
    }

    public function testPureWlsDirectReloadDoesNotInheritNginxDrainFloor(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([
            'wls.orchestrator.reload_drain_timeout_sec' => 5.0,
            'wls.edge.adapter' => 'wls',
        ]);

        $masterWait = $this->invokePrivate($orchestrator, 'resolveWorkerReloadDrainTimeout');
        $workerSoft = $this->invokePrivate(
            $orchestrator,
            'resolveWorkerReloadSoftDrainTimeout',
            [$masterWait],
        );

        self::assertSame(10.0, $masterWait);
        self::assertSame(5.0, $workerSoft);
    }

    public function testDirectNewFirstUsesBoundedBatchesUnlessForceWasExplicit(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([]);

        self::assertSame(
            [[1, 2, 3], [4, 5], [6, 7]],
            $this->invokePrivate(
                $orchestrator,
                'getWorkerRestartBatches',
                [[1, 2, 3, 4, 5, 6, 7], false],
            ),
        );
        self::assertSame(
            [[1, 2, 3, 4, 5, 6, 7]],
            $this->invokePrivate(
                $orchestrator,
                'getWorkerRestartBatches',
                [[1, 2, 3, 4, 5, 6, 7], true],
            ),
        );
    }

    public function testForceReloadDoesNotEnableDirectNewFirstSurge(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ServiceOrchestrator.php',
        );
        self::assertStringContainsString(
            '$directNewFirst = !$forceReload && $this->supportsDirectNewFirstReload();',
            $source,
        );
        self::assertStringContainsString(
            'no new-first surge',
            $source,
        );
    }

    public function testSharedFdAndReuseportEnableDirectNewFirst(): void
    {
        foreach (['shared_fd', 'reuseport'] as $listenerMode) {
            $orchestrator = $this->createOrchestratorWithContext([], $listenerMode);
            self::assertTrue(
                (bool)$this->invokePrivate($orchestrator, 'supportsDirectNewFirstReload'),
                "listener_mode={$listenerMode}",
            );
        }

        $workerPorts = $this->createOrchestratorWithContext([], 'worker_ports');
        self::assertFalse(
            (bool)$this->invokePrivate($workerPorts, 'supportsDirectNewFirstReload'),
        );

        $nonDirect = $this->createOrchestratorWithContext([], 'shared_fd', false);
        self::assertFalse(
            (bool)$this->invokePrivate($nonDirect, 'supportsDirectNewFirstReload'),
        );
    }

    public function testLastAcceptorFloorBlocksZeroReadyDrainWithoutAlternateCapacity(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([
            'wls.orchestrator.worker_reload_min_ready' => 0,
        ]);

        self::assertSame(
            1,
            $this->invokePrivate(
                $orchestrator,
                'resolveWorkerReloadBatchMinReady',
                [1, false, false],
            ),
        );
        self::assertSame(
            0,
            $this->invokePrivate(
                $orchestrator,
                'resolveWorkerReloadBatchMinReady',
                [1, true, false],
            ),
        );
        self::assertSame(
            0,
            $this->invokePrivate(
                $orchestrator,
                'resolveWorkerReloadBatchMinReady',
                [1, false, true],
            ),
        );
        // configured 0 still floors to ≥1 when no alternate capacity
        self::assertSame(
            1,
            $this->invokePrivate(
                $orchestrator,
                'resolveWorkerReloadBatchMinReady',
                [4, false, false],
            ),
        );

        $auto = $this->createOrchestratorWithContext([]);
        // default floor(2/3*4)=2, then max(1,2)=2
        self::assertSame(
            2,
            $this->invokePrivate(
                $auto,
                'resolveWorkerReloadBatchMinReady',
                [4, false, false],
            ),
        );
    }

    public function testFailedReplacementTripsAutomaticReloadCircuitUntilCanonicalRecovery(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([]);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);

        $this->invokePrivate($orchestrator, 'noteAutomaticCodeReloadFailure');
        self::assertTrue((bool)$this->invokePrivate($orchestrator, 'shouldBlockAutomaticCodeReload'));

        $registry = $this->readPrivate($orchestrator, 'registry');
        self::assertInstanceOf(ServiceRegistry::class, $registry);

        $readyOne = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 9555,
            ipcClientId: 101,
        );
        $readyTwo = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            state: ServiceInstance::STATE_READY,
            port: 9555,
            ipcClientId: 102,
        );
        $registry->addInstance($readyOne);
        self::assertTrue((bool)$this->invokePrivate($orchestrator, 'shouldBlockAutomaticCodeReload'));

        $registry->addInstance($readyTwo);
        self::assertFalse((bool)$this->invokePrivate($orchestrator, 'shouldBlockAutomaticCodeReload'));
        self::assertFalse($this->readPrivate($orchestrator, 'automaticCodeReloadCircuitOpen'));
    }

    public function testExplicitWaitingReloadBypassesCircuitInReloadAllGate(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([]);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->invokePrivate($orchestrator, 'noteAutomaticCodeReloadFailure');
        $this->writePrivate($orchestrator, 'rollingRestartClientId', 42);

        // Circuit remains open for FileWatcher while capacity is down; reloadAll
        // skips the circuit when a waiting client id is present.
        self::assertTrue((bool)$this->invokePrivate($orchestrator, 'shouldBlockAutomaticCodeReload'));
        self::assertSame(42, $this->readPrivate($orchestrator, 'rollingRestartClientId'));
    }

    public function testMemoryPressureCapacityMutationDefersDuringWorkerReload(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([]);
        $this->writePrivate($orchestrator, 'desiredState', ['worker' => 4]);
        $this->writePrivate($orchestrator, 'workerReloadCapacityTransitionInProgress', true);
        $controller = new MemoryPressureController();
        $controller->setBudgetCeiling(8);

        self::assertFalse($orchestrator->scaleDownOneWorkerForMemoryPressure($controller));
        self::assertFalse($orchestrator->scaleUpOneWorkerForMemoryPressure($controller));
        self::assertSame(
            4,
            $this->readPrivate($orchestrator, 'desiredState')['worker'] ?? null,
        );
    }

    public function testReloadTargetsStayInsideCurrentMemoryPressureDesiredCapacity(): void
    {
        $orchestrator = $this->createOrchestratorWithContext([]);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 7,
        ]);

        $active = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 7,
            state: ServiceInstance::STATE_READY,
        );
        $retired = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 8,
            state: ServiceInstance::STATE_DRAINING,
        );
        $retired->setMeta('memory_pressure_scale_down', true);

        $targets = $this->invokePrivate(
            $orchestrator,
            'restrictWorkerReloadInstancesToDesiredCapacity',
            [[$active, $retired]],
        );

        self::assertSame([$active], $targets);
        self::assertSame(ServiceInstance::STATE_DRAINING, $retired->state);
        self::assertTrue((bool)$retired->getMeta('memory_pressure_scale_down', false));
    }

    /**
     * @param array<string,mixed> $config
     */
    private function createOrchestratorWithContext(
        array $config,
        string $listenerMode = 'shared_fd',
        bool $isDirect = true,
    ): ServiceOrchestrator {
        $selection = new RuntimeSelection(
            requestedTopology: $isDirect ? RequestedTopology::Direct : RequestedTopology::Dispatcher,
            effectiveTopology: $isDirect ? EffectiveTopology::Direct : EffectiveTopology::Dispatcher,
            source: 'test',
            osFamily: 'darwin',
            eventLoopDriver: 'event',
            sslEngine: 'stream',
            listenerMode: $listenerMode,
            policyCompatible: true,
            reasonCodes: [],
            reason: 'unit-test',
        );

        $context = $this->getMockBuilder(ServiceContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isDirect', 'getConfig'])
            ->getMock();
        $context->method('isDirect')->willReturn($isDirect);
        $context->method('getConfig')->willReturnCallback(
            static fn (string $path, mixed $default = null): mixed =>
                \array_key_exists($path, $config) ? $config[$path] : $default,
        );

        $selectionProperty = new \ReflectionProperty(ServiceContext::class, 'runtimeSelection');
        $selectionProperty->setValue($context, $selection);

        $orchestrator = new ServiceOrchestrator();
        $contextProperty = new \ReflectionProperty($orchestrator, 'context');
        $contextProperty->setValue($orchestrator, $context);

        return $orchestrator;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokePrivate(
        ServiceOrchestrator $orchestrator,
        string $method,
        array $arguments = [],
    ): mixed {
        $reflection = new \ReflectionMethod($orchestrator, $method);

        return $reflection->invokeArgs($orchestrator, $arguments);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
