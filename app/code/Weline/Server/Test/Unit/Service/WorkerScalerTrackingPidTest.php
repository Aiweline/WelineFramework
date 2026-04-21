<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Control\ControlPlaneServerInterface;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\WorkerScaler;

final class WorkerScalerTrackingPidTest extends TestCase
{
    public function testCheckHealthUsesTrackedRootPidWhenChildPidIsStale(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            launchId: 'worker-launch',
            pid: 999999,
            state: ServiceInstance::STATE_READY,
        );
        $instance->setProcessTreePids(999999, \getmypid(), \getmypid());

        $orchestrator = new class([$instance]) extends ServiceOrchestrator {
            /**
             * @param list<ServiceInstance> $workers
             */
            public function __construct(private readonly array $workers)
            {
            }

            /**
             * @return list<ServiceInstance>
             */
            public function getInstancesByRole(string $role): array
            {
                return $role === 'worker' ? $this->workers : [];
            }

            public function getControlServer(): ?ControlPlaneServerInterface
            {
                return null;
            }
        };

        $scaler = new WorkerScaler($orchestrator, new WorkerProvider());

        self::assertTrue($scaler->checkHealth(999999));
    }
}
