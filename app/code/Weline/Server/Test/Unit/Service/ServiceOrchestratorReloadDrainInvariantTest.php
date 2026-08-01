<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Memory\MemoryPressureController;
use Weline\Server\Service\ServiceOrchestrator;

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

        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/ServiceOrchestrator.php',
        );
        self::assertStringContainsString(
            '$singleGenerationBatch = $forceReload;',
            $source,
        );
        self::assertStringNotContainsString(
            '$singleGenerationBatch = $forceReload || $directNewFirst;',
            $source,
        );
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
    private function createOrchestratorWithContext(array $config): ServiceOrchestrator
    {
        $context = $this->createMock(ServiceContext::class);
        $context->method('isDirect')->willReturn(true);
        $context->method('getConfig')->willReturnCallback(
            static fn (string $path, mixed $default = null): mixed =>
                \array_key_exists($path, $config) ? $config[$path] : $default,
        );

        $orchestrator = new ServiceOrchestrator();
        $property = new \ReflectionProperty($orchestrator, 'context');
        $property->setValue($orchestrator, $context);

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
