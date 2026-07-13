<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\SharedStateServiceManager;

final class ServiceOrchestratorSharedSidecarRecoveryTest extends TestCase
{
    public function testMasterEnsuresSharedSidecarsBeforeRenewingConsumerLease(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public int $ensureCalls = 0;
            public int $renewCalls = 0;

            public function ensureRuntime(
                string $requesterInstanceName,
                array $config,
                array $envConfig = [],
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureCalls++;

                return [
                    'session' => ['port' => 26277],
                    'memory' => ['enabled' => true, 'port' => 26278],
                ];
            }

            public function renewInstanceConsumers(string $instanceName, ?array $roles = null): array
            {
                $this->renewCalls++;

                return [
                    ControlMessage::ROLE_SESSION_SERVER => true,
                    ControlMessage::ROLE_MEMORY_SERVER => true,
                ];
            }
        };

        $orchestrator = new class($manager) extends ServiceOrchestrator {
            public function __construct(private readonly SharedStateServiceManager $manager)
            {
                parent::__construct();
            }

            public function recover(): bool
            {
                return $this->ensureSharedStateRuntimeForWorkers('test');
            }

            public function renew(string $consumerCode, array $roles): array
            {
                return $this->renewSharedStateConsumersForWorkersInstance($consumerCode, $roles);
            }

            protected function createSharedStateServiceManagerForRecovery(): SharedStateServiceManager
            {
                return $this->manager;
            }
        };

        $context = new ServiceContext(
            instanceName: 'ai-test-sidecar-recovery',
            epoch: 1,
            controlPort: 35819,
            masterPid: \getmypid() ?: 1,
            host: '127.0.0.1',
            mainPort: 9512,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'io',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: []
        );
        $property = new \ReflectionProperty(ServiceOrchestrator::class, 'context');
        $property->setValue($orchestrator, $context);

        self::assertTrue($orchestrator->recover());
        self::assertSame([
            ControlMessage::ROLE_SESSION_SERVER => true,
            ControlMessage::ROLE_MEMORY_SERVER => true,
        ], $orchestrator->renew('ai-test-sidecar-recovery', [
            ControlMessage::ROLE_SESSION_SERVER,
            ControlMessage::ROLE_MEMORY_SERVER,
        ]));
        self::assertSame(2, $manager->ensureCalls);
        self::assertSame(1, $manager->renewCalls);
    }
}
