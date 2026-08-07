<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
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

    public function testCheckHealthAcceptsOnlyMatchingMonotonicPongObservation(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            launchId: 'worker-monotonic-launch',
            pid: \getmypid(),
            state: ServiceInstance::STATE_READY,
        );
        $control = new class extends MasterControlServer {
            private ?array $observation = null;

            public function sendToInstance(string $launchId, string $message): bool
            {
                unset($launchId);
                $ping = ControlMessage::decode(\rtrim($message, "\n"));
                if (!\is_array($ping)) {
                    return false;
                }
                $pong = ControlMessage::decode(ControlMessage::pongForPing($ping));
                $this->observation = \is_array($pong)
                    ? ControlMessage::monotonicPongObservation($pong)
                    : null;

                return true;
            }

            public function getLastPongObservation(string $launchId): ?array
            {
                unset($launchId);
                return $this->observation;
            }
        };
        $orchestrator = new class([$instance], $control) extends ServiceOrchestrator {
            public function __construct(
                private readonly array $workers,
                private readonly ControlPlaneServerInterface $server,
            ) {
            }

            public function getInstancesByRole(string $role): array
            {
                return $role === 'worker' ? $this->workers : [];
            }

            public function getControlServer(): ?ControlPlaneServerInterface
            {
                return $this->server;
            }
        };

        self::assertTrue((new WorkerScaler($orchestrator, new WorkerProvider()))->checkHealth(\getmypid(), 0.2));
    }

    public function testCheckHealthRejectsLegacyPongAndControlExceptions(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            launchId: 'worker-legacy-launch',
            pid: \getmypid(),
            state: ServiceInstance::STATE_READY,
        );
        $legacyControl = new class extends MasterControlServer {
            public function sendToInstance(string $launchId, string $message): bool
            {
                unset($launchId, $message);
                return true;
            }

            public function getLastPongObservation(string $launchId): ?array
            {
                unset($launchId);
                return null;
            }
        };
        $exceptionControl = new class extends MasterControlServer {
            public function sendToInstance(string $launchId, string $message): bool
            {
                unset($launchId, $message);
                throw new \RuntimeException('synthetic control failure');
            }
        };

        $makeOrchestrator = static fn(ControlPlaneServerInterface $server): ServiceOrchestrator =>
            new class([$instance], $server) extends ServiceOrchestrator {
                public function __construct(
                    private readonly array $workers,
                    private readonly ControlPlaneServerInterface $server,
                ) {
                }

                public function getInstancesByRole(string $role): array
                {
                    return $role === 'worker' ? $this->workers : [];
                }

                public function getControlServer(): ?ControlPlaneServerInterface
                {
                    return $this->server;
                }
            };

        self::assertFalse((new WorkerScaler(
            $makeOrchestrator($legacyControl),
            new WorkerProvider(),
        ))->checkHealth(\getmypid(), 0.001));
        self::assertFalse((new WorkerScaler(
            $makeOrchestrator($exceptionControl),
            new WorkerProvider(),
        ))->checkHealth(\getmypid(), 0.2));
    }
}
