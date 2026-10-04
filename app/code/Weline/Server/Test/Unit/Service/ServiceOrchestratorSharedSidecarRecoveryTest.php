<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Runtime\RuntimeSelection;
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
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 26277,
                        'token_file_name' => 'session-server-26277.token',
                    ],
                    'memory' => [
                        'enabled' => true,
                        'host' => '127.0.0.1',
                        'port' => 26278,
                        'token_file_name' => 'memory-server-26278.token',
                    ],
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
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 26277,
                        'token_file_name' => 'session-server-26277.token',
                    ],
                    'memory_service' => [
                        'enabled' => true,
                        'host' => '127.0.0.1',
                        'port' => 26278,
                        'token_file_name' => 'memory-server-26278.token',
                    ],
                ],
            ]
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

    private static function runtimeSelection(): RuntimeSelection
    {
        return RuntimeSelection::fromArray([
            'requested_topology' => 'auto',
            'effective_topology' => 'dispatcher',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => 'single',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }

    public function testReloadFailureCarriesOnlyTheCurrentBoundedRedactedRecoveryCause(): void
    {
        $secret = \str_repeat('d', 64);
        $manager = new class($secret) extends SharedStateServiceManager {
            public bool $fail = true;
            public function __construct(private readonly string $secret) {}
            public function ensureRuntime(string $requesterInstanceName, array $config, array $envConfig = [], bool $frontend = false, bool $forceRestart = false): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('endpoint unavailable token=' . $this->secret
                        . ' credential="short-private-value" AUTH ' . $this->secret . ' ' . \str_repeat('detail ', 250));
                }
                return ['session' => ['host' => '127.0.0.1', 'port' => 26277, 'token_file_name' => 'session-server-26277.token'],
                    'memory' => ['enabled' => false]];
            }
        };
        $orchestrator = new class($manager) extends ServiceOrchestrator {
            public function __construct(private readonly SharedStateServiceManager $manager) { parent::__construct(); }
            public function recover(): bool { return $this->ensureSharedStateRuntimeForWorkers('test'); }
            protected function createSharedStateServiceManagerForRecovery(): SharedStateServiceManager { return $this->manager; }
        };
        $context = new ServiceContext(
            instanceName: 'ai-test-sidecar-diagnostic', epoch: 1, controlPort: 35819, masterPid: \getmypid() ?: 1,
            host: '127.0.0.1', mainPort: 9512, sslEnabled: false, sslCert: '', sslKey: '',
            runtimeSelection: self::runtimeSelection(), daemon: true, debug: false, windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls'], 'session' => ['host' => '127.0.0.1', 'port' => 26277, 'token_file_name' => 'session-server-26277.token'],
                'memory_service' => ['enabled' => false]]],
        );
        (new \ReflectionProperty(ServiceOrchestrator::class, 'context'))->setValue($orchestrator, $context);
        $orchestrator->getRegistry()->registerProvider(new \Weline\Server\Service\Provider\WorkerProvider());
        $orchestrator->getRegistry()->addInstance(new \Weline\Server\Service\Contract\ServiceInstance('worker', 1));
        $terminal = '';
        $control = $this->createMock(\Weline\Server\Service\Control\ControlPlaneServerInterface::class);
        $control->method('sendTo')->willReturnCallback(static function (int $client, string $line) use (&$terminal): bool {
            $terminal = (string)(ControlMessage::decode(\trim($line))['reason'] ?? '');
            return true;
        });
        (new \ReflectionProperty(ServiceOrchestrator::class, 'controlServer'))->setValue($orchestrator, $control);
        (new \ReflectionProperty(ServiceOrchestrator::class, 'rollingRestartClientId'))->setValue($orchestrator, 12);
        $orchestrator->reloadService('worker');
        self::assertStringContainsString('RuntimeException: endpoint unavailable', $terminal);
        self::assertStringNotContainsString($secret, $terminal);
        self::assertStringNotContainsString('short-private-value', $terminal);
        self::assertLessThanOrEqual(1024, \strlen($terminal));

        $manager->fail = false;
        self::assertTrue($orchestrator->recover());
        $detail = new \ReflectionProperty(ServiceOrchestrator::class, 'sharedStateRecoveryFailureDetail');
        self::assertSame('', $detail->getValue($orchestrator));
        (new \ReflectionProperty(ServiceOrchestrator::class, 'masterShutdownIntent'))->setValue($orchestrator, true);
        self::assertFalse($orchestrator->recover());
        self::assertSame('Shared sidecar maintenance is disabled by Master shutdown/stop intent.', $detail->getValue($orchestrator));
    }
}
