<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Exception\StartupException;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\Provider\HttpRedirectProvider;
use Weline\Server\Service\Provider\MaintenanceWorkerProvider;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Edge\PureWlsPublicOrigin;
use Weline\Server\Service\Edge\NativeServingManifestStartupRecovery;
use Weline\Server\Service\Edge\Gateway\GatewayBackendIngressTokenStore;
use Weline\Server\Service\Runtime\HttpProtocolSelection;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\WindowsListenerHandoff;
use Weline\Server\Service\Runtime\WorkerReadinessState;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\ServiceOrchestrator;

class ServiceOrchestratorStartupTest extends TestCase
{
    private const TEST_POLICY_DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TEST_CONTAINER_DIGEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_PATH')) {
            \define('APP_PATH', BP . 'app' . DS);
        }
        if (!\defined('APP_CODE_PATH')) {
            \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
        }
        if (!\defined('APP_ETC_PATH')) {
            \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
        }
        if (!\defined('DEV_PATH')) {
            \define('DEV_PATH', BP . 'dev' . DS);
        }
        if (!\defined('PUB')) {
            \define('PUB', BP . 'pub' . DS);
        }
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', true);
        }
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    public function testCheckAndNotifyServerReadyRequiresStartupArm(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {};
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');

        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
    }

    public function testCheckAndNotifyServerReadyWaitsUntilAllRegisteredInstancesReady(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array{instanceName:string,totalServices:int}> */
            public array $startupReadyMarks = [];

            protected function markStartupPhaseRunning(ServiceContext $context, int $totalServices): void
            {
                $this->startupReadyMarks[] = [
                    'instanceName' => $context->instanceName,
                    'totalServices' => $totalServices,
                ];
            }
        };
        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            state: ServiceInstance::STATE_STARTING,
        ));
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([], $orchestrator->startupReadyMarks);

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        $worker->state = ServiceInstance::STATE_READY;
        $registry->updateInstance($worker);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([], $orchestrator->startupReadyMarks);

        $worker->setMeta('dispatcher_pool_confirmed_at', (\hrtime(true) / 1_000_000_000));
        $registry->updateInstance($worker);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertTrue($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([[
            'instanceName' => $context->instanceName,
            'totalServices' => 2,
        ]], $orchestrator->startupReadyMarks);
    }

    public function testServerReadyNotificationAcceptsFirstBusinessWorkerByDefault(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array{instanceName:string,totalServices:int}> */
            public array $startupReadyMarks = [];

            protected function markStartupPhaseRunning(ServiceContext $context, int $totalServices): void
            {
                $this->startupReadyMarks[] = [
                    'instanceName' => $context->instanceName,
                    'totalServices' => $totalServices,
                ];
            }
        };
        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 2,
            state: ServiceInstance::STATE_STARTING,
        ));
        $firstWorker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $firstWorker);
        $firstWorker->setMeta('dispatcher_pool_confirmed_at', (\hrtime(true) / 1_000_000_000));
        $registry->updateInstance($firstWorker);
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');

        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([], $orchestrator->startupReadyMarks);

        $secondWorker = $registry->getInstance('worker', 2);
        self::assertInstanceOf(ServiceInstance::class, $secondWorker);
        $secondWorker->state = ServiceInstance::STATE_READY;
        $secondWorker->setMeta('dispatcher_pool_confirmed_at', (\hrtime(true) / 1_000_000_000));
        $registry->updateInstance($secondWorker);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');

        self::assertTrue($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([[
            'instanceName' => $context->instanceName,
            'totalServices' => 3,
        ]], $orchestrator->startupReadyMarks);
    }

    public function testStartupAcceptanceAcceptsFirstBusinessWorkerByDefault(): void
    {
        $orchestrator = new ServiceOrchestrator();

        self::assertSame(8, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_WORKER, 8]
        ));
        $this->writePrivate($orchestrator, 'context', $this->createFrontendContext([
            'worker_startup_min_ready' => 'all',
        ]));
        self::assertSame(8, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_WORKER, 8]
        ));
        self::assertSame(2, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_DISPATCHER, 2]
        ));
        self::assertSame(0, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_WORKER, 0]
        ));
    }

    public function testStartupAcceptanceWaitsForDispatcherPoolConfirmation(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        );
        $registry->addInstance($worker);

        $startupAcceptance = [
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 1,
                'minReady' => 1,
            ],
        ];

        self::assertSame(
            [ControlMessage::ROLE_WORKER . ':0/1'],
            $this->invokePrivateWithArgs($orchestrator, 'collectStartupAcceptancePendingLabels', [$startupAcceptance])
        );

        $worker->setMeta('dispatcher_pool_confirmed_at', (\hrtime(true) / 1_000_000_000));
        $registry->updateInstance($worker);

        self::assertSame(
            [],
            $this->invokePrivateWithArgs($orchestrator, 'collectStartupAcceptancePendingLabels', [$startupAcceptance])
        );
    }

    public function testStartupAcceptanceDoesNotDeadlockBehindTemporaryMaintenancePool(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        ));

        $startupAcceptance = [
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 1,
                'minReady' => 1,
            ],
        ];

        self::assertSame(
            [],
            $this->invokePrivateWithArgs($orchestrator, 'collectStartupAcceptancePendingLabels', [$startupAcceptance])
        );
    }

    public function testMarkStartupPhaseRunningRestoresControlMetadataWhenInstanceFileIsPartial(): void
    {
        $instanceName = 'ut-ready-metadata-' . \bin2hex(\random_bytes(4));
        $manager = new ServerInstanceManager();
        $instanceFile = $manager->getInstanceFile($instanceName);
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 26895,
            masterPid: 60284,
            host: '127.0.0.1',
            mainPort: 443,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'auto',
                'effective_topology' => 'direct',
                'topology_source' => 'unit-test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'stream',
                'listener_mode' => 'shared_fd',
                'policy_compatible' => true,
                'reason_codes' => ['unit_test'],
                'reason' => 'unit test runtime selection',
            ]),
            daemon: false,
            debug: false,
            windowMode: true,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            httpRedirectPort: 80,
            workerCount: 4,
            workerBasePort: 16894,
            workerPort: 16895,
            publicHost: 'p11005ce4.weline.test',
        );
        $orchestrator = new class extends ServiceOrchestrator {
            public function markReady(ServiceContext $context, int $totalServices): void
            {
                $this->markStartupPhaseRunning($context, $totalServices);
            }
        };

        try {
            $httpProtocolSelection = HttpProtocolSelection::fromConfig([
                'http' => [
                    'protocols' => [HttpProtocolSelection::HTTP_1],
                    'preferred' => HttpProtocolSelection::HTTP_1,
                ],
            ], true)->toArray();
            ServerInstanceManager::atomicWriteJsonStatic($instanceFile, [
                'lifecycle_state' => 'starting',
                'edge_adapter' => 'nginx',
                'http_protocol_selection' => $httpProtocolSelection,
            ], 5);

            $orchestrator->markReady($context, 10);
            $data = \json_decode((string)\file_get_contents($instanceFile), true);

            self::assertIsArray($data);
            self::assertSame(60284, $data['master_pid'] ?? null);
            self::assertSame(26895, $data['control_port'] ?? null);
            self::assertTrue((bool)($data['master_enabled'] ?? false));
            self::assertSame('running', $data['startup_phase'] ?? null);
            self::assertSame(10, $data['server_ready_service_count'] ?? null);
            self::assertSame('nginx', $data['edge_adapter'] ?? null);
            self::assertSame($httpProtocolSelection, $data['http_protocol_selection'] ?? null);
        } finally {
            if (\is_file($instanceFile)) {
                @\unlink($instanceFile);
            }
            if (\is_file($instanceFile . '.lock')) {
                @\unlink($instanceFile . '.lock');
            }
        }
    }

    public function testDirectRuntimeReadyHonorsExplicitHomepageFailOpenOnly(): void
    {
        $context = new ServiceContext(
            instanceName: 'ut-homepage-fail-open',
            epoch: 1,
            controlPort: 26896,
            masterPid: 60285,
            host: '127.0.0.1',
            mainPort: 18096,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'direct',
                'effective_topology' => 'direct',
                'topology_source' => 'unit-test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'stream',
                'listener_mode' => 'shared_fd',
                'policy_compatible' => true,
                'reason_codes' => ['unit_test'],
                'reason' => 'unit test runtime selection',
            ]),
            daemon: false,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => [
                        'adapter' => 'nginx',
                        'nginx' => [
                            'managed' => true,
                            'auto_start' => true,
                        ],
                    ],
                    'http' => [
                        'protocols' => ['h1'],
                        'preferred' => 'h1',
                        'tls_session_resumption' => false,
                        'alt_svc' => false,
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: 18096,
            workerPort: 18096,
            publicHost: 'example.test',
        );
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'runtimePolicyPublishedDigest', self::TEST_POLICY_DIGEST);
        $this->writePrivate($orchestrator, 'containerRegistryDigest', self::TEST_CONTAINER_DIGEST);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            port: 18096,
            state: ServiceInstance::STATE_READY,
        );
        $worker->setMeta('policy_digest', self::TEST_POLICY_DIGEST);
        $worker->setMeta('container_registry_digest', self::TEST_CONTAINER_DIGEST);
        $worker->setMeta('topology', 'direct');
        $worker->setMeta('warmup_state', 'warm');
        $worker->setMeta('homepage_fpc', [
            'hit' => false,
            'fpc_status' => '',
            'source' => '',
            'full_uri' => 'https://example.test/',
            'http_status' => 500,
        ]);
        $worker->setMeta('readiness_protocol_version', WorkerReadinessState::READINESS_PROTOCOL_VERSION);
        $worker->setMeta('readiness_capabilities', [
            WorkerReadinessState::CAPABILITY_DYNAMIC_FIRST_RENDER_PROOF,
            WorkerReadinessState::CAPABILITY_COMPILED_CONTAINER_DIGEST,
        ]);
        $worker->setMeta('dynamic_first_render', [
            'ready' => true,
            'host' => 'example.test',
            'path' => '/',
            'status_code' => 200,
            'body_length' => 1024,
            'elapsed_ms' => 5.0,
            'target_ms' => 70.0,
            'attempts' => 1,
            'fpc_status' => 'MISS',
            'cache' => 'bypass',
            'reason' => 'rendered',
        ]);
        $worker->setMeta('listen_capabilities', [
            'bound' => true,
            'shared_listener' => true,
            'inherited_fd' => 9,
            'mode' => 'shared_fd',
            'event_loop' => 'select',
            'ssl_engine' => 'stream',
        ]);
        $worker->setMeta('worker_loop_started_at', (\hrtime(true) / 1_000_000_000));

        $previous = \getenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN');
        try {
            \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=0');
            self::assertFalse($this->invokePrivateWithArgs(
                $orchestrator,
                'isDirectReloadWorkerRuntimeReady',
                [$worker],
            ));

            \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=1');
            self::assertTrue($this->invokePrivateWithArgs(
                $orchestrator,
                'isDirectReloadWorkerRuntimeReady',
                [$worker],
            ));
        } finally {
            if ($previous === false) {
                \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN');
            } else {
                \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=' . $previous);
            }
        }
    }

    public function testCheckAndNotifyServerReadyKeepsFrontendReadyBoxWidthStableWithWideChineseLabels(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));

        $context = new ServiceContext(
            instanceName: 'ready-box-width',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 443,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            runtimeSelection: self::runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'https://p11005ce4.weline.test',
                ],
                'router' => [
                    'area_routes' => [
                        'backend' => ['prefix' => 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8'],
                        'rest_frontend' => ['prefix' => 'api123'],
                        'rest_backend' => ['prefix' => 'J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE'],
                    ],
                ],
            ],
            httpRedirectPort: 80,
            workerCount: 1,
            workerBasePort: 18080,
            workerPort: 18080,
            publicHost: 'p11005ce4.weline.test',
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        \ob_start();
        try {
            $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
            $output = (string) \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();
            throw $throwable;
        }

        self::assertStringContainsString('J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/', $output);
        self::assertStringContainsString('https://p11005ce4.weline.test/', $output);
        self::assertStringNotContainsString('Nginx 是唯一公网边缘', $output);
        self::assertStringNotContainsString('→ HTTPS', $output);

        $boxLines = [];
        foreach (\preg_split("/\r\n|\n|\r/", $this->stripAnsi($output)) as $line) {
            if (\preg_match('/^  [╔╠╟╚║]/u', $line) === 1) {
                $boxLines[] = $line;
            }
        }

        self::assertGreaterThanOrEqual(8, \count($boxLines));

        $expectedWidth = $this->displayWidth($boxLines[0]);
        foreach ($boxLines as $line) {
            self::assertLessThanOrEqual($expectedWidth + 2, $this->displayWidth($line), $line);
        }
    }

    public function testResetServerReadyNotificationAlsoDisarmsStartupGate(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'serverReadyNotified', true);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $orchestrator->resetServerReadyNotification();

        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotificationArmed'));
    }

    public function testBootstrapControlPlaneDisablesWindowsNativeSocketBridgeByDefault(): void
    {
        $server = new class extends MasterControlServer {
            public bool $bridgeEnabled = true;
            public string $host = '';
            public int $startedPort = 0;
            public bool $started = false;

            public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void
            {
                parent::setWindowsNativeSocketBridgeEnabled($enabled);
                $this->bridgeEnabled = $enabled;
            }

            public function start(string $host, int $port): bool
            {
                $this->host = $host;
                $this->startedPort = $port;
                $this->started = true;

                return true;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = $this->createWorkerInfraContext();
        $orchestrator->bootstrapControlPlane($context);

        self::assertTrue($server->started);
        self::assertSame('127.0.0.1', $server->host);
        self::assertSame($context->controlPort, $server->startedPort);
        self::assertFalse($server->bridgeEnabled);
    }

    public function testBootstrapControlPlanePublishesActualControlPortInContext(): void
    {
        $server = new class extends MasterControlServer {
            public int $requestedPort = 0;
            public int $actualPort = 23456;

            public function start(string $host, int $port): bool
            {
                unset($host);
                $this->requestedPort = $port;

                return true;
            }

            public function getPort(): int
            {
                return $this->actualPort;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = $this->createWorkerInfraContext();
        $orchestrator->bootstrapControlPlane($context);

        self::assertSame($context->controlPort, $server->requestedPort);
        self::assertSame(23456, $orchestrator->getContext()?->controlPort);
    }

    public function testBootstrapControlPlaneRetriesAutoAssignedPortAtAuthoritativeBind(): void
    {
        $server = new class extends MasterControlServer {
            /** @var list<int> */
            public array $requestedPorts = [];
            private int $actualPort = 0;

            public function start(string $host, int $port): bool
            {
                unset($host);
                $this->requestedPorts[] = $port;
                if (\count($this->requestedPorts) === 1) {
                    return false;
                }
                $this->actualPort = $port;

                return true;
            }

            public function getPort(): int
            {
                return $this->actualPort;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = $this->createWorkerInfraContext();
        $orchestrator->bootstrapControlPlane($context);

        self::assertSame([$context->controlPort, $context->controlPort + 1], $server->requestedPorts);
        self::assertSame($context->controlPort + 1, $orchestrator->getContext()?->controlPort);
    }

    public function testBootstrapControlPlaneDoesNotRetryExplicitControlPort(): void
    {
        $server = new class extends MasterControlServer {
            /** @var list<int> */
            public array $requestedPorts = [];

            public function start(string $host, int $port): bool
            {
                unset($host);
                $this->requestedPorts[] = $port;

                return false;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = $this->createWorkerInfraContext(serverConfig: [
            'control_port' => 19981,
            'control_port_scan_max' => 64,
        ]);

        try {
            $orchestrator->bootstrapControlPlane($context);
            self::fail('Explicit control port bind failure must abort startup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('19981-19981', $exception->getMessage());
            self::assertSame([19981], $server->requestedPorts);
        }
    }

    public function testBootstrapControlPlaneCanEnableWindowsNativeSocketBridgeExplicitly(): void
    {
        $server = new class extends MasterControlServer {
            public bool $bridgeEnabled = false;

            public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void
            {
                parent::setWindowsNativeSocketBridgeEnabled($enabled);
                $this->bridgeEnabled = $enabled;
            }

            public function start(string $host, int $port): bool
            {
                return true;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
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
                    'orchestrator' => [
                        'ipc_windows_native_socket_bridge' => true,
                    ],
                    'runtime' => [
                        'container_registry_digest' => self::TEST_CONTAINER_DIGEST,
                    ],
                ],
            ],
        );

        $orchestrator->bootstrapControlPlane($context);

        self::assertTrue($server->bridgeEnabled);
    }

    public function testForegroundSpawnTracksLauncherUntilRegisterAndThenSwitchesToServicePid(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            state: ServiceInstance::STATE_STARTING,
        );
        $instance->setMeta('spawn_transport', 'processer_create_foreground');

        $this->invokePrivateWithArgs($orchestrator, 'applySpawnedProcessTree', [$instance, 1202]);

        self::assertSame(0, $instance->pid);
        self::assertSame(0, $instance->getRootPid());
        self::assertSame(1202, $instance->getLauncherPid());
        self::assertSame(1202, $instance->getTrackingPid());

        $this->invokePrivateWithArgs($orchestrator, 'applyRegisteredServicePid', [$instance, 2202]);

        self::assertSame(2202, $instance->pid);
        self::assertSame(2202, $instance->getRootPid());
        self::assertSame(1202, $instance->getLauncherPid());
        self::assertSame(2202, $instance->getTrackingPid());
    }

    public function testCreateControlServerCanEnableSupervisorFromContextConfig(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public function createConfiguredControlServer(ServiceContext $context): object
            {
                $reflection = new \ReflectionProperty(ServiceOrchestrator::class, 'context');
                $reflection->setAccessible(true);
                $reflection->setValue($this, $context);

                return $this->createControlServer();
            }
        };

        $context = new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
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
                    'supervisor' => [
                        'enabled' => true,
                        'channel' => 'channel-test',
                    ],
                ],
            ],
        );

        $server = $orchestrator->createConfiguredControlServer($context);

        self::assertInstanceOf(\Weline\Server\Service\Control\HybridControlPlaneServer::class, $server);
        self::assertTrue($server->isSupervisorEnabled());
        self::assertSame('channel-test', $server->supervisorChannelId());
    }

    public function testWaitForStartupAcceptanceConsumesPendingStopRequestImmediately(): void
    {
        $server = new class extends MasterControlServer {
            public ?\Closure $pollHook = null;
            public int $pollCalls = 0;

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;
                if ($this->pollHook !== null) {
                    $hook = $this->pollHook;
                    $this->pollHook = null;
                    $hook();
                }

                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);

        $server->pollHook = function () use ($orchestrator): void {
            $this->writePrivate($orchestrator, 'pendingStopReason', 'startup-stop');
            $this->writePrivate($orchestrator, 'pendingStopProgressClientId', 66);
        };

        $this->invokePrivateWithArgs($orchestrator, 'waitForStartupAcceptance', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 2,
                'minReady' => 2,
            ],
        ], $this->createWorkerInfraContext()]);

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'startup-stop',
            'progressClientId' => 66,
        ]], $orchestrator->stopAllCalls);
        self::assertSame(1, $server->pollCalls);
    }

    public function testHandleStartupFailureHandsOverToUnifiedStopFlow(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'handleStartupFailure', [
            new \RuntimeException('startup boom'),
            'deferred child startup exception',
        ]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'running'));
        self::assertSame('startup_failure', $this->readPrivate($orchestrator, 'pendingStopReason'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'pendingStopSkipDrain'));
        self::assertSame('deferred child startup exception: startup boom', $this->readPrivate($orchestrator, 'startupFailureReason'));

        self::assertTrue($this->invokePrivate($orchestrator, 'consumePendingStopRequest'));
        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'startup_failure',
            'progressClientId' => null,
        ]], $orchestrator->stopAllCalls);
    }

    public function testHandleStartupFailureInsideFiberDefersStopSchedulingToMainLoop(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);

        $shouldAbort = null;
        $startupFiber = new \Fiber(function () use ($orchestrator, &$shouldAbort): void {
            $this->invokePrivateWithArgs($orchestrator, 'handleStartupFailure', [
                new \RuntimeException('startup fiber boom'),
                'deferred child startup exception',
            ]);
            $shouldAbort = $this->invokePrivate($orchestrator, 'shouldAbortStartupTransition');
        });
        $startupFiber->start();

        self::assertTrue($startupFiber->isTerminated());
        self::assertTrue($shouldAbort);
        self::assertSame('startup_failure', $this->readPrivate($orchestrator, 'pendingStopReason'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'pendingStopSkipDrain'));
        self::assertSame([], $this->readPrivate($orchestrator, 'mainLoopTasks'));

        self::assertTrue($this->invokePrivate($orchestrator, 'consumePendingStopRequest'));
        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'startup_failure',
            'progressClientId' => null,
        ]], $orchestrator->stopAllCalls);
    }

    public function testPendingStopFiberIsNotStartedUntilMainLoopTick(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, string> */
            public array $stopReasons = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopReasons[] = $reason;
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        self::assertTrue($orchestrator->requestStop('signal-like-stop'));
        self::assertTrue($this->invokePrivate($orchestrator, 'consumePendingStopRequest'));

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        $stopFiber = $tasks['control:stop_all']['fiber'] ?? null;
        self::assertInstanceOf(\Fiber::class, $stopFiber);
        self::assertFalse($stopFiber->isStarted());
        self::assertSame([], $orchestrator->stopReasons);

        $this->invokePrivate($orchestrator, 'tickMainLoopTasks');

        self::assertSame(['signal-like-stop'], $orchestrator->stopReasons);
    }

    public function testStartupAcceptanceTimeoutThrowsStructuredExceptionAndPersistsDiagnostics(): void
    {
        $instanceName = 'ut-startup-failure-' . \bin2hex(\random_bytes(4));
        $manager = new ServerInstanceManager();
        $instanceFile = $manager->getInstanceFile($instanceName);
        $context = $this->createWorkerInfraContextForInstance($instanceName);
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->setStartupTimeout(1.5);
        $orchestrator->setStartupMaxDuration(9.0);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            port: $context->getWorkerPort(),
            pid: 0,
            state: ServiceInstance::STATE_STARTING,
        ));

        try {
            $this->invokePrivateWithArgs($orchestrator, 'handleStartupAcceptanceTimeout', [[
                ControlMessage::ROLE_WORKER => [
                    'displayName' => 'HTTP Worker',
                    'expected' => 1,
                    'minReady' => 1,
                ],
            ], $context, 1.75]);
            self::fail('Expected startup timeout to throw a structured exception.');
        } catch (StartupException $exception) {
            self::assertSame('WLS_STARTUP_READY_TIMEOUT', $exception->getWlsErrorCode());
            self::assertStringContainsString('worker:0/1', $exception->getMessage());
            self::assertSame($instanceName, $exception->getContext()['instance'] ?? null);
            self::assertNotEmpty($exception->getDiagnostics());
            self::assertStringContainsString('role=worker#1', $exception->getDiagnostics()[0]);
        } finally {
            $this->registerFileCleanup($instanceFile);
        }

        $persisted = $manager->getRawInstanceData($instanceName);
        self::assertIsArray($persisted);
        self::assertSame(StartupException::class, $persisted['startup_failure_class'] ?? null);
        self::assertSame('WLS_STARTUP_READY_TIMEOUT', $persisted['startup_failure_code'] ?? null);
        self::assertSame(['worker:0/1'], $persisted['startup_failure_pending'] ?? null);
        self::assertSame($instanceName, $persisted['startup_failure_context']['instance'] ?? null);
        self::assertNotEmpty($persisted['startup_failure_diagnostics'] ?? []);
        self::assertStringContainsString('role=worker#1', $persisted['startup_failure_diagnostics'][0] ?? '');
    }

    public function testCloseIpcServerTracksCloseReasonInLifecycleState(): void
    {
        $server = new class extends MasterControlServer {
            public bool $closed = false;

            public function flushPendingWrites(float $maxSeconds = 2.0): void
            {
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'controlServer', $server);

        $this->invokePrivateWithArgs($orchestrator, 'closeIpcServer', ['test_close']);

        self::assertTrue($server->closed);
        self::assertNull($this->readPrivate($orchestrator, 'controlServer'));
        self::assertStringContainsString(
            'control_server_close_reason=test_close',
            $orchestrator->describeLifecycleState()
        );
    }

    public function testWaitForStartupAcceptanceDoesNotScheduleEarlyRecoveryForStuckCriticalEntrypoint(): void
    {
        $server = new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            public bool $startupTimeoutHandled = false;

            protected function handleStartupAcceptanceTimeout(array $startupAcceptance, ServiceContext $context, float $elapsed): void
            {
                unset($startupAcceptance, $context, $elapsed);
                $this->startupTimeoutHandled = true;
            }
        };
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-stuck',
            port: $context->mainPort,
            pid: 0,
            state: ServiceInstance::STATE_STARTING,
            startedAt: (\hrtime(true) / 1_000_000_000) - 25.0,
        );
        $registry->addInstance($dispatcher);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $orchestrator->setStartupTimeout(0.02);

        $this->invokePrivateWithArgs($orchestrator, 'waitForStartupAcceptance', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 2,
                'minReady' => 2,
            ],
        ], $context]);

        self::assertTrue($orchestrator->startupTimeoutHandled);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayNotHasKey('dispatcher:1', $queue);

        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->getMeta('startup_acceptance_recovery_reason'));
    }

    public function testWaitForStartupAcceptanceDoesNotRecoverFreshCriticalEntrypointTooEarly(): void
    {
        $server = new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            public bool $startupTimeoutHandled = false;

            protected function handleStartupAcceptanceTimeout(array $startupAcceptance, ServiceContext $context, float $elapsed): void
            {
                unset($startupAcceptance, $context, $elapsed);
                $this->startupTimeoutHandled = true;
            }
        };
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-fresh',
            port: $context->mainPort,
            pid: 0,
            state: ServiceInstance::STATE_STARTING,
            startedAt: (\hrtime(true) / 1_000_000_000) - 2.0,
        );
        $registry->addInstance($dispatcher);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $orchestrator->setStartupTimeout(0.02);

        $this->invokePrivateWithArgs($orchestrator, 'waitForStartupAcceptance', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 2,
                'minReady' => 2,
            ],
        ], $context]);

        self::assertTrue($orchestrator->startupTimeoutHandled);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayNotHasKey('dispatcher:1', $queue);

        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->getMeta('startup_acceptance_recovery_reason'));
    }

    public function testWaitForInstanceReadyReturnsFalseWhenPendingStopRequestIsConsumed(): void
    {
        $server = new class extends MasterControlServer {
            public ?\Closure $pollHook = null;
            public int $pollCalls = 0;

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;
                if ($this->pollHook !== null) {
                    $hook = $this->pollHook;
                    $this->pollHook = null;
                    $hook();
                }

                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);

        $server->pollHook = function () use ($orchestrator): void {
            $this->writePrivate($orchestrator, 'pendingStopReason', 'reload-stop');
        };

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForInstanceReady', [
            ControlMessage::ROLE_WORKER,
            1,
            0.5,
            null,
        ]);

        self::assertFalse($ready);

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'reload-stop',
            'progressClientId' => null,
        ]], $orchestrator->stopAllCalls);
        self::assertSame(1, $server->pollCalls);
    }

    public function testResolveChildProcessLogFlagForcesCriticalBackgroundLogs(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();

        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new SessionServerProvider(), $context]
        ));
        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new MemoryServerProvider(), $context]
        ));
        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new DispatcherProvider(), $context]
        ));
        self::assertNull($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new WorkerProvider(), $context]
        ));
    }

    public function testStartProvidersBatchRunsPortPreflightForPhaseOneDispatcher(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $prepareCalls = [];
            public array $capturedCommands = [];
            public int $batchCreateCalls = 0;

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                $this->prepareCalls[] = [$role, $port];

                return false;
            }

            protected function batchCreateProcesses(array $commands): array
            {
                $this->batchCreateCalls++;
                $this->capturedCommands = $commands;

                return [];
            }
        };

        try {
            $context = $this->persistTestContext($this->createWorkerInfraContext());
            $this->writePrivate($orchestrator, 'context', $context);
            $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [
                [new DispatcherProvider()],
                $context,
            ]);
            self::fail('Dispatcher startup must fail fast when its launch port is unavailable.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('dispatcher#1 port 8080 is unavailable', $e->getMessage());
        }

        self::assertSame([[ControlMessage::ROLE_DISPATCHER, 8080]], $orchestrator->prepareCalls);
        self::assertSame([], $orchestrator->capturedCommands);
        self::assertSame(0, $orchestrator->batchCreateCalls);
    }

    public function testMarkSpawnedInstancePreservesLowLevelSpawnTransportAndKeepsForegroundPidAsLauncherOnly(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);
        $instance->setMeta('spawn_transport', 'processer_create_foreground');

        $this->invokePrivateWithArgs($orchestrator, 'markSpawnedInstance', [
            $instance,
            10.0,
            10.5,
            43210,
            'providers_batch_create',
            2,
        ]);

        self::assertSame('processer_create_foreground', $instance->getMeta('spawn_transport'));
        self::assertSame('providers_batch_create', $instance->getMeta('spawn_strategy'));
        self::assertSame(0, $instance->pid);
        self::assertSame(0, $instance->getRootPid());
        self::assertSame(43210, $instance->getLauncherPid());
        self::assertSame(0, $instance->getMeta('service_pid'));
        self::assertSame(0, $instance->getMeta('root_pid'));
        self::assertSame(43210, $instance->getMeta('tracking_pid'));
    }

    public function testMarkSpawnedInstanceDoesNotDemoteAlreadyReadyIpcInstance(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            epoch: 9,
            launchId: 'session-fast-ready',
            pid: 962834,
            port: 24302,
            state: ServiceInstance::STATE_READY,
            startedAt: 1234.0,
            ipcClientId: 469,
        );
        $instance->setMeta('spawn_transport', 'processer_create');
        $instance->setMeta('ready_received_at', 1234.5);
        $orchestrator->getRegistry()->addInstance($instance);

        $this->invokePrivateWithArgs($orchestrator, 'markSpawnedInstance', [
            $instance,
            10.0,
            12.0,
            962833,
            'providers_batch_create',
            3,
        ]);

        self::assertSame(ServiceInstance::STATE_READY, $instance->state);
        self::assertSame(469, $instance->ipcClientId);
        self::assertSame(962834, $instance->pid);
        self::assertSame(962833, $instance->getRootPid());
        self::assertSame(962833, $instance->getLauncherPid());
        self::assertSame(962834, $instance->getMeta('service_pid'));
        self::assertSame(962833, $instance->getMeta('root_pid'));
        self::assertSame(962833, $instance->getMeta('tracking_pid'));
        self::assertSame(962833, $instance->getMeta('spawn_pid_returned'));
        self::assertSame(1234.0, $instance->startedAt);
    }

    public function testRegisterInstanceIpcSwitchesForegroundTrackingPidToRuntimeServicePid(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            launchId: 'worker-launch',
            state: ServiceInstance::STATE_STARTING,
        );
        $instance->setMeta('spawn_transport', 'processer_create_foreground');
        $this->invokePrivateWithArgs($orchestrator, 'applySpawnedProcessTree', [$instance, 4100]);
        $instance->setMeta('process_name', 'weline-wls-worker-test-1');

        self::assertTrue($this->invokePrivateWithArgs($orchestrator, 'registerInstanceIpc', [
            $instance,
            77,
            4200,
            1,
            0,
            'worker-launch',
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
        ]));

        self::assertSame(4200, $instance->pid);
        self::assertSame(4200, $instance->getRootPid());
        self::assertSame(4100, $instance->getLauncherPid());
        self::assertSame(4200, $instance->getMeta('service_pid'));
        self::assertSame(4200, $instance->getMeta('root_pid'));
        self::assertSame(4200, $instance->getMeta('tracking_pid'));
    }

    public function testStartProvidersBatchRegistersDispatcherPlaceholderBeforeBatchCreate(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $batchRegistrySnapshot = [];
            /** @var list<string> */
            public array $spawnAuthorizationOrder = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->spawnAuthorizationOrder[] = 'batch_create';
                $dispatcher = $this->getRegistry()->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
                $this->batchRegistrySnapshot = [
                    'command_keys' => \array_keys($commands),
                    'dispatcher_command' => (string)($commands[ControlMessage::ROLE_DISPATCHER . '#1']['command'] ?? ''),
                    'dispatcher_state' => $dispatcher?->state,
                    'dispatcher_process_pid' => $dispatcher?->pid,
                    'dispatcher_launch_id' => $dispatcher?->launchId,
                ];

                return [
                    ControlMessage::ROLE_DISPATCHER . '#1' => 5101,
                ];
            }

            protected function refreshMasterLeaseAfterBlockingSpawn(): void
            {
                $this->spawnAuthorizationOrder[] = 'master_lease_refresh';
            }

            protected function authorizeSpawnedInstanceCredentials(array $instances, array $pids): void
            {
                unset($instances, $pids);
                $this->spawnAuthorizationOrder[] = 'credential_authorization';
            }
        };

        $context = new ServiceContext(
            instanceName: 'phase-one-placeholder-batch-' . \bin2hex(\random_bytes(4)),
            epoch: 12,
            controlPort: 37985,
            masterPid: 424246,
            host: '127.0.0.1',
            mainPort: 18444,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'https://127.0.0.1:18444',
                ],
            ],
            httpRedirectPort: 18081,
            workerCount: 0,
            workerBasePort: 28184,
            workerPort: 28184,
        );
        $context = $this->persistTestContext($context);
        $this->writePrivate($orchestrator, 'context', $context);

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [[
            new DispatcherProvider(),
        ], $context]);

        self::assertSame([
            ControlMessage::ROLE_DISPATCHER . '#1',
        ], $orchestrator->batchRegistrySnapshot['command_keys'] ?? null);
        self::assertSame(ServiceInstance::STATE_STARTING, $orchestrator->batchRegistrySnapshot['dispatcher_state'] ?? null);
        self::assertSame(0, $orchestrator->batchRegistrySnapshot['dispatcher_process_pid'] ?? null);
        self::assertNotEmpty($orchestrator->batchRegistrySnapshot['dispatcher_launch_id'] ?? null);
        self::assertStringContainsString('--slot-id=', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertStringContainsString('dispatcher#1', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertStringContainsString('--lease-id=', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertStringContainsString('--slot-generation=', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertSame([
            'batch_create',
            'master_lease_refresh',
            'credential_authorization',
        ], $orchestrator->spawnAuthorizationOrder);
        $dispatcher = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ControlMessage::ROLE_DISPATCHER, $dispatcher->role);
    }

    public function testWaitForWorkerCriticalInfraReadyFailsWhenSessionServerStaysDegraded(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());

        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->writePrivate($orchestrator, 'infraDegraded', [
            ControlMessage::ROLE_SESSION_SERVER => true,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            state: ServiceInstance::STATE_FAILED,
            port: 19970,
        ));

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForWorkerCriticalInfraReady', ['reload worker', 0.0]);

        self::assertFalse($ready);
    }

    public function testWaitForWorkerCriticalInfraReadyReturnsAfterSessionServerRecovers(): void
    {
        $server = new class extends MasterControlServer {
            public ?\Closure $pollHook = null;

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                if ($this->pollHook !== null) {
                    $hook = $this->pollHook;
                    $this->pollHook = null;
                    $hook();
                }

                return 0;
            }

            public function clientExists(int $clientId): bool
            {
                return \in_array($clientId, [77, 88], true);
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->writePrivate($orchestrator, 'infraDegraded', [
            ControlMessage::ROLE_SESSION_SERVER => true,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            state: ServiceInstance::STATE_STARTING,
            port: 19970,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MEMORY_SERVER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 88,
            port: 19971,
        ));

        $server->pollHook = function () use ($registry, $orchestrator): void {
            $session = $registry->getInstance(ControlMessage::ROLE_SESSION_SERVER, 1);
            self::assertInstanceOf(ServiceInstance::class, $session);
            $session->state = ServiceInstance::STATE_READY;
            $session->ipcClientId = 77;
            $registry->updateInstance($session);

            // 妯℃嫙 IPC 浜嬩欢椹卞姩鏇存柊 infraDegraded
            $this->writePrivate($orchestrator, 'infraDegraded', [
                ControlMessage::ROLE_SESSION_SERVER => false,
                ControlMessage::ROLE_MEMORY_SERVER => false,
            ]);
        };

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForWorkerCriticalInfraReady', ['reload worker', 0.5]);

        self::assertTrue($ready);
    }

    public function testGetWorkerRestartBatchesUsesSingleBatchInForceMode(): void
    {
        $orchestrator = new ServiceOrchestrator();

        $normalBatches = $this->invokePrivateWithArgs($orchestrator, 'getWorkerRestartBatches', [[1, 2, 3, 4], false]);
        self::assertSame([[1, 2], [3, 4]], $normalBatches);

        $forceBatches = $this->invokePrivateWithArgs($orchestrator, 'getWorkerRestartBatches', [[1, 2, 3, 4], true]);
        self::assertSame([[1, 2, 3, 4]], $forceBatches);
    }

    /**
     * Failed slots that still own a live startup PID must keep the slot blocked
     * until the resurrection queue explicitly decides otherwise.
     */
    public function testFilterStartableInstanceIdsSkipsFailedWorkerSlotWhenQueuedStartupPidStillAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: \getmypid(),
            port: 18081,
            state: ServiceInstance::STATE_FAILED,
            startedAt: (\hrtime(true) / 1_000_000_000) - 10.0,
            metadata: [
                'resurrection_queued_from_state' => ServiceInstance::STATE_STARTING,
            ],
        ));

        $startable = $this->invokePrivateWithArgs(
            $orchestrator,
            'filterStartableInstanceIds',
            [ControlMessage::ROLE_WORKER, [1]]
        );

        self::assertSame([], $startable);
        self::assertInstanceOf(ServiceInstance::class, $registry->getInstance(ControlMessage::ROLE_WORKER, 1));
    }

    public function testSlotOccupancyTreatsServicePidWithoutWrapperAsOccupied(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: \getmypid(),
            port: 0,
            state: ServiceInstance::STATE_FAILED,
            startedAt: (\hrtime(true) / 1_000_000_000) - 30.0,
        );

        $occupancy = $this->invokePrivateWithArgs($orchestrator, 'inspectSlotOccupancy', [$instance]);

        self::assertFalse((bool)($occupancy['hasIpc'] ?? true));
        self::assertTrue((bool)($occupancy['hasPidOrTree'] ?? false));
        self::assertTrue((bool)($occupancy['pidAlive'] ?? false));
        self::assertTrue((bool)($occupancy['occupied'] ?? false));
    }

    public function testSlotOccupancyTreatsValidatedPortOwnerWithoutPidAsOccupied(): void
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, $errstr ?: 'failed to bind test port');
        $address = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr((string)\strrchr($address, ':'), 1);
        self::assertGreaterThan(0, $port);

        $originalPortIndex = Processer::readPortIndex();
        $portIndex = $originalPortIndex;
        $portIndex[(string)$port] = MasterProcess::buildScopedProcessName('weline-wls-worker', 'test', 1);
        Processer::writePortIndex($portIndex);

        try {
            $orchestrator = new ServiceOrchestrator();
            $instance = new ServiceInstance(
                role: ControlMessage::ROLE_WORKER,
                instanceId: 1,
                pid: 0,
                port: $port,
                state: ServiceInstance::STATE_FAILED,
                startedAt: (\hrtime(true) / 1_000_000_000) - 30.0,
            );

            $occupancy = $this->invokePrivateWithArgs($orchestrator, 'inspectSlotOccupancy', [$instance]);

            self::assertFalse((bool)($occupancy['hasIpc'] ?? true));
            self::assertFalse((bool)($occupancy['hasPidOrTree'] ?? true));
            self::assertTrue((bool)($occupancy['hasPortOwner'] ?? false));
            self::assertTrue((bool)($occupancy['occupied'] ?? false));
        } finally {
            Processer::writePortIndex($originalPortIndex);
            @\fclose($server);
        }
    }

    public function testSlotOccupancyReleasesLostLeaseWhenNoIpcPidOrPortOwnerExists(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: 0,
            port: 0,
            state: ServiceInstance::STATE_FAILED,
            startedAt: (\hrtime(true) / 1_000_000_000) - 30.0,
        );

        $occupancy = $this->invokePrivateWithArgs($orchestrator, 'inspectSlotOccupancy', [$instance]);

        self::assertFalse((bool)($occupancy['hasIpc'] ?? true));
        self::assertFalse((bool)($occupancy['hasPidOrTree'] ?? true));
        self::assertFalse((bool)($occupancy['hasPortOwner'] ?? true));
        self::assertFalse((bool)($occupancy['occupied'] ?? true));
    }

    public function testBatchStartAssignsMonotonicSlotGenerationIndependentFromEpoch(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array<string|int, array{command:string,block:bool,foreground:bool}>> */
            public array $capturedCommands = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands[] = $commands;

                return ['1' => 0];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        $context = $this->createWorkerInfraContext()->withEpoch(50);
        $this->writePrivate($orchestrator, 'context', $context);
        $provider = new WorkerProvider();

        $first = $this->invokePrivateWithArgs($orchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
        self::assertCount(1, $first);
        self::assertInstanceOf(ServiceInstance::class, $first[0]);
        self::assertSame(1, (int)$first[0]->getMeta('generation'));
        self::assertStringContainsString('--slot-generation=', $orchestrator->capturedCommands[0]['1']['command']);

        $orchestrator->getRegistry()->removeInstance(ControlMessage::ROLE_WORKER, 1);
        $second = $this->invokePrivateWithArgs($orchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
        self::assertCount(1, $second);
        self::assertInstanceOf(ServiceInstance::class, $second[0]);
        self::assertSame(2, (int)$second[0]->getMeta('generation'));
        self::assertMatchesRegularExpression('/--slot-generation=(?:\'|")?2(?:\'|")?/', $orchestrator->capturedCommands[1]['1']['command']);
    }

    public function testBatchStartPersistsSlotGenerationAcrossOrchestratorInstances(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = 'slot-generation-persist-' . \str_replace('.', '', \uniqid('', true));
        $file = $manager->getInstanceFile($instanceName);
        $manager->saveInstance($instanceName, [
            'startup_phase' => 'starting',
            'control_port' => 19981,
            'master_pid' => 1234,
        ]);

        try {
            $context = $this->createWorkerInfraContextForInstance($instanceName);
            $provider = new WorkerProvider();

            $firstOrchestrator = new class extends ServiceOrchestrator {
                protected function batchCreateProcesses(array $commands): array
                {
                    return ['1' => 0];
                }
            };
            $this->writePrivate($firstOrchestrator, 'context', $context);
            $this->writePrivate($firstOrchestrator, 'running', true);
            $first = $this->invokePrivateWithArgs($firstOrchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
            self::assertCount(1, $first);
            self::assertSame(1, (int)$first[0]->getMeta('generation'));

            $secondOrchestrator = new class extends ServiceOrchestrator {
                protected function batchCreateProcesses(array $commands): array
                {
                    return ['1' => 0];
                }
            };
            $this->writePrivate($secondOrchestrator, 'context', $context);
            $this->writePrivate($secondOrchestrator, 'running', true);
            $second = $this->invokePrivateWithArgs($secondOrchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
            self::assertCount(1, $second);
            self::assertSame(2, (int)$second[0]->getMeta('generation'));

            $persisted = $manager->getRawInstanceData($instanceName);
            self::assertIsArray($persisted);
            self::assertSame(2, (int)($persisted['slot_generations']['worker#1'] ?? 0));
        } finally {
            @\unlink($file);
            @\unlink($file . '.lock');
        }
    }

    public function testWorkerBatchStartUsesEmergencyPortWhenConfiguredPortIsBlocked(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array<string|int, array{command:string,block:bool,foreground:bool}>> */
            public array $capturedCommands = [];
            /** @var list<array{role:string,instanceId:int,port:int,reason:string}> */
            public array $cleanupRequests = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands[] = $commands;

                return ['1' => 0];
            }

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                return $port !== 18081;
            }

            protected function canUseEmergencyDynamicPort(string $role, int $configuredPort, ServiceContext $context): bool
            {
                return $role === ControlMessage::ROLE_WORKER && $configuredPort === 18081;
            }

            protected function allocateEmergencyDynamicPort(string $role, int $instanceId, int $configuredPort, ServiceContext $context): int
            {
                return 28081;
            }

            protected function scheduleEmergencyPortCleanup(string $role, int $instanceId, int $configuredPort, string $reason, int $attempt = 1): void
            {
                $this->cleanupRequests[] = [
                    'role' => $role,
                    'instanceId' => $instanceId,
                    'port' => $configuredPort,
                    'reason' => $reason,
                ];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        $provider = new WorkerProvider();
        $context = new ServiceContext(
            instanceName: 'emergency-port-test-' . \bin2hex(\random_bytes(4)),
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
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
                    'public_origin' => 'http://127.0.0.1:8080',
                ],
            ],
            workerCount: 1,
            workerBasePort: 18080,
            workerPort: 18081,
        );
        $context = $this->persistTestContext($context);
        $this->writePrivate($orchestrator, 'context', $context);

        $started = $this->invokePrivateWithArgs($orchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);

        self::assertCount(1, $started);
        self::assertInstanceOf(ServiceInstance::class, $started[0]);
        self::assertSame(28081, $started[0]->port);
        self::assertSame(18081, (int)$started[0]->getMeta('configured_port'));
        self::assertSame(28081, (int)$started[0]->getMeta('emergency_dynamic_port'));
        self::assertStringContainsString('28081', $orchestrator->capturedCommands[0]['1']['command']);
        self::assertSame([[
            'role' => ControlMessage::ROLE_WORKER,
            'instanceId' => 1,
            'port' => 18081,
            'reason' => 'batch_start',
        ]], $orchestrator->cleanupRequests);
    }

    public function testPhaseOneWorkerBatchStartReprobesPortsAndUsesEmergencyPort(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<string, array{command:string,block:bool,foreground:bool}> */
            public array $capturedCommands = [];
            /** @var list<array{role:string,instanceId:int,port:int,reason:string}> */
            public array $cleanupRequests = [];
            /** @var list<array{role:string,port:int}> */
            public array $prepareCalls = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands = $commands;

                return ['worker#1' => 0];
            }

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                $this->prepareCalls[] = ['role' => $role, 'port' => $port];

                return !($role === ControlMessage::ROLE_WORKER && $port === 18081);
            }

            protected function canUseEmergencyDynamicPort(string $role, int $configuredPort, ServiceContext $context): bool
            {
                return $role === ControlMessage::ROLE_WORKER && $configuredPort === 18081;
            }

            protected function allocateEmergencyDynamicPort(string $role, int $instanceId, int $configuredPort, ServiceContext $context): int
            {
                return 28081;
            }

            protected function scheduleEmergencyPortCleanup(string $role, int $instanceId, int $configuredPort, string $reason, int $attempt = 1): void
            {
                $this->cleanupRequests[] = [
                    'role' => $role,
                    'instanceId' => $instanceId,
                    'port' => $configuredPort,
                    'reason' => $reason,
                ];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        $context = new ServiceContext(
            instanceName: 'phase-one-emergency-port-test-' . \bin2hex(\random_bytes(4)),
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
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
                    'public_origin' => 'http://127.0.0.1:8080',
                ],
            ],
            workerCount: 1,
            workerBasePort: 18080,
            workerPort: 18081,
        );
        $context = $this->persistTestContext($context);
        $this->writePrivate($orchestrator, 'context', $context);

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [
            [new WorkerProvider()],
            $context,
        ]);

        self::assertIsArray($result);
        self::assertInstanceOf(ServiceInstance::class, $result[ControlMessage::ROLE_WORKER][0] ?? null);
        self::assertSame([[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 18081,
        ]], $orchestrator->prepareCalls);
        self::assertSame(28081, $result[ControlMessage::ROLE_WORKER][0]->port);
        self::assertSame(18081, (int)$result[ControlMessage::ROLE_WORKER][0]->getMeta('configured_port'));
        self::assertArrayHasKey('worker#1', $orchestrator->capturedCommands);
        self::assertStringContainsString('28081', $orchestrator->capturedCommands['worker#1']['command']);
        self::assertSame([[
            'role' => ControlMessage::ROLE_WORKER,
            'instanceId' => 1,
            'port' => 18081,
            'reason' => 'providers_batch_start',
        ]], $orchestrator->cleanupRequests);
    }

    public function testPhaseOneMaintenanceBatchPreflightsPortAndSkipsBlockedSlot(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<string, array{command:string,block:bool,foreground:bool}> */
            public array $capturedCommands = [];
            /** @var list<array{role:string,port:int}> */
            public array $preflightPorts = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands = $commands;

                return [];
            }

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                $this->preflightPorts[] = [
                    'role' => $role,
                    'port' => $port,
                ];

                return $role !== ControlMessage::ROLE_MAINTENANCE;
            }
        };
        $this->writePrivate($orchestrator, 'running', true);

        $provider = new MaintenanceWorkerProvider();
        $provider->enable(1);
        $context = new ServiceContext(
            instanceName: 'phase-one-maintenance-port-test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            workerCount: 3,
            workerBasePort: 18080,
            workerPort: 18081,
        );
        $context = $this->persistTestContext($context);
        $this->writePrivate($orchestrator, 'context', $context);
        $expectedPort = $provider->getPort(1, $context);

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [
            [$provider],
            $context,
        ]);

        self::assertSame([], $result);
        self::assertSame([], $orchestrator->capturedCommands);
        self::assertSame([[
            'role' => ControlMessage::ROLE_MAINTENANCE,
            'port' => $expectedPort,
        ]], $orchestrator->preflightPorts);
    }

    public function testStartupAcceptanceFailFastReportsFailedWorkerSlot(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'failed-worker',
            port: 0,
            state: ServiceInstance::STATE_FAILED,
        ));

        $reason = $this->invokePrivateWithArgs($orchestrator, 'detectStartupAcceptanceFatalFailure', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 1,
                'minReady' => 1,
            ],
        ], $context, 5.1]);

        self::assertSame('worker#1 failed before READY', $reason);
    }

    public function testEmergencyPortCleanupKeepsRetryTaskSchedulable(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public function exposeScheduleEmergencyPortCleanup(int $attempt = 1): void
            {
                $this->scheduleEmergencyPortCleanup(ControlMessage::ROLE_WORKER, 1, 18081, 'test', $attempt);
            }
        };

        $orchestrator->exposeScheduleEmergencyPortCleanup(1);
        $orchestrator->exposeScheduleEmergencyPortCleanup(1);
        $orchestrator->exposeScheduleEmergencyPortCleanup(2);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        self::assertIsArray($tasks);
        self::assertArrayHasKey('emergency_port_cleanup:worker:1:18081:1', $tasks);
        self::assertArrayHasKey('emergency_port_cleanup:worker:1:18081:2', $tasks);
        self::assertCount(2, \array_filter(
            \array_keys($tasks),
            static fn(string $key): bool => \str_starts_with($key, 'emergency_port_cleanup:worker:1:18081:')
        ));
    }

    public function testControlClientUsesRuntimeSlotGenerationInsteadOfEpoch(): void
    {
        $oldArgv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = [
            'worker.php',
            '--slot-id=worker#2',
            '--lease-id=lease-worker-2',
            '--slot-generation=7',
        ];
        try {
            $client = new ControlClient();
            self::assertFalse($client->register(
                ControlMessage::ROLE_WORKER,
                12002,
                18082,
                2,
                50,
                'launch-ignored'
            ));

            $registerInfo = $this->readPrivate($client, 'registerInfo');
            self::assertIsArray($registerInfo);
            self::assertSame('worker#2', $registerInfo['slot_id'] ?? null);
            self::assertSame('lease-worker-2', $registerInfo['lease_id'] ?? null);
            self::assertSame(7, $registerInfo['generation'] ?? null);
        } finally {
            if ($oldArgv === null) {
                unset($GLOBALS['argv']);
            } else {
                $GLOBALS['argv'] = $oldArgv;
            }
        }
    }

    public function testProcessResurrectQueueDefersWorkerRecoveryWhileQueuedStartupPidStillAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_WORKER)) {
            $registry->registerProvider(new WorkerProvider());
        }

        $context = $this->createWorkerInfraContext();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-queued-startup',
            pid: \getmypid(),
            port: 18081,
            state: ServiceInstance::STATE_FAILED,
            startedAt: (\hrtime(true) / 1_000_000_000) - 10.0,
            metadata: [
                'resurrection_queued_from_state' => ServiceInstance::STATE_STARTING,
            ],
        );
        $registry->addInstance($worker);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            $worker->getKey() => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => (\hrtime(true) / 1_000_000_000) - 1.0,
                'delayed' => true,
                'pid' => \getmypid(),
                'port' => 18081,
                'previousState' => ServiceInstance::STATE_STARTING,
            ],
        ]);
        $scheduledBefore = (float)$this->readPrivate($orchestrator, 'resurrectQueue')['worker:1']['scheduledAt'];

        $this->invokePrivate($orchestrator, 'processResurrectQueue');

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);
        self::assertGreaterThan($scheduledBefore, $queue['worker:1']['scheduledAt']);

        $currentWorker = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $currentWorker);
        self::assertSame(\getmypid(), $currentWorker->pid);
        self::assertSame(ServiceInstance::STATE_FAILED, $currentWorker->state);
    }

    /**
     * 启动预设维护 + 第一阶段 Dispatcher/maintenance 同批拉起（单入口 startProvidersBatch）。
     * 无业务 Worker 时 Dispatcher READY 应收到 SET_ROUTE_TABLE（维护端口）。
     */
    public function testStartupMaintenancePresetPhaseOneBatchAndDispatcherMaintenancePool(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<list<string>> */
            public array $phaseOneRoleBatches = [];

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->phaseOneRoleBatches[] = array_values(array_map(
                    static fn ($p) => $p->getRole(),
                    $providers
                ));
                $registry = $this->getRegistry();
                $result = [];
                foreach ($providers as $provider) {
                    $role = $provider->getRole();
                    $n = $provider->getInstanceCount($context);
                    $result[$role] = [];
                    for ($i = 1; $i <= $n; $i++) {
                        $port = $provider->getPort($i, $context);
                        $ipcId = $role === ControlMessage::ROLE_DISPATCHER ? 201 : null;
                        $inst = new ServiceInstance(
                            role: $role,
                            instanceId: $i,
                            epoch: $context->epoch,
                            launchId: 'test-launch',
                            port: $port,
                            state: ServiceInstance::STATE_READY,
                            startedAt: (\hrtime(true) / 1_000_000_000),
                            ipcClientId: $ipcId,
                        );
                        $registry->addInstance($inst);
                        $provider->onStarted($inst);
                        $result[$role][] = $inst;
                    }
                }

                return $result;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->registerProvider(new class extends WorkerProvider {
            public function getInstanceCount(ServiceContext $context): int
            {
                return 0;
            }
        });

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-pool-' . \bin2hex(\random_bytes(4)),
            epoch: 7,
            controlPort: 37981,
            masterPid: 424242,
            host: '127.0.0.1',
            mainPort: 18088,
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
                    'public_origin' => 'http://127.0.0.1:18088',
                    'worker' => ['count' => 0],
                ],
            ],
            workerCount: 0,
            workerBasePort: 28180,
            workerPort: 28180,
        );
        $context = $this->persistTestContext($context);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', false);
        $this->writePrivate($orchestrator, 'runtimePolicyPublishedDigest', self::TEST_POLICY_DIGEST);
        $this->writePrivate($orchestrator, 'containerRegistryDigest', self::TEST_CONTAINER_DIGEST);

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertSame(1, ($this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_MAINTENANCE] ?? null));
        $maintProvider = $registry->getProvider(ControlMessage::ROLE_MAINTENANCE);
        self::assertInstanceOf(MaintenanceWorkerProvider::class, $maintProvider);
        self::assertTrue($maintProvider->isEnabled($context));

        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        self::assertSame([[ControlMessage::ROLE_DISPATCHER, ControlMessage::ROLE_MAINTENANCE]], $orchestrator->phaseOneRoleBatches);

        $maintenancePorts = [];
        foreach ($registry->getInstancesByRole(ControlMessage::ROLE_MAINTENANCE) as $m) {
            if ($m->port !== null && $m->port > 0) {
                $maintenancePorts[] = (int) $m->port;
            }
        }
        \sort($maintenancePorts, SORT_NUMERIC);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'test-launch',
            'port' => $context->mainPort,
            'role' => ControlMessage::ROLE_DISPATCHER,
            'policy_digest' => self::TEST_POLICY_DIGEST,
        ], 201]);

        $poolSent = null;
        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $poolSent = $decoded;
            }
        }

        self::assertIsArray($poolSent);
        self::assertSame(ControlMessage::TYPE_SET_ROUTE_TABLE, $poolSent['type']);
        self::assertSame($maintenancePorts, $poolSent['ports'] ?? null);

    }

    public function testStartupPhaseOneBatchesDispatcherAndMaintenanceWithoutRetiredRedirectWorker(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<list<string>> */
            public array $phaseOneRoleBatches = [];

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                unset($context);
                $this->phaseOneRoleBatches[] = array_values(array_map(
                    static fn ($p) => $p->getRole(),
                    $providers
                ));

                return [];
            }

            protected function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
            {
                unset($startupAcceptance, $context);
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new HttpRedirectProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->registerProvider(new class extends WorkerProvider {
            public function getInstanceCount(ServiceContext $context): int
            {
                return 0;
            }
        });

        $context = new ServiceContext(
            instanceName: 'ai-u-phase-one-all-together',
            epoch: 8,
            controlPort: 37984,
            masterPid: 424245,
            host: '127.0.0.1',
            mainPort: 18443,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'worker' => ['count' => 0],
                ],
            ],
            httpRedirectPort: 18080,
            workerCount: 0,
            workerBasePort: 28183,
            workerPort: 28183,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);
        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        self::assertSame([[
            ControlMessage::ROLE_DISPATCHER,
            ControlMessage::ROLE_MAINTENANCE,
        ]], $orchestrator->phaseOneRoleBatches);
    }

    /**
     * Worker 与 Dispatcher/维护进程在同一次 startProvidersBatch 中拉起，
     * 随后在 waitForStartupAcceptance 中等待维护端就绪门槛。
     */
    public function testWorkersLaunchBeforeStartupAcceptanceWaitsForPhaseOneReadiness(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<string> */
            public array $events = [];

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->events[] = 'concurrent_batch';
                $result = [];
                foreach ($providers as $provider) {
                    $role = $provider->getRole();
                    $count = $provider->getInstanceCount($context);
                    $result[$role] = [];
                    for ($i = 1; $i <= $count; $i++) {
                        $instance = new ServiceInstance(
                            role: $role,
                            instanceId: $i,
                            epoch: $context->epoch,
                            launchId: 'phase-one-launch',
                            port: $provider->getPort($i, $context),
                            state: ServiceInstance::STATE_READY,
                            startedAt: (\hrtime(true) / 1_000_000_000),
                            ipcClientId: $role === ControlMessage::ROLE_DISPATCHER ? 401 : null,
                        );
                        $this->getRegistry()->addInstance($instance);
                        $result[$role][] = $instance;
                    }
                }

                return $result;
            }

            protected function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
            {
                unset($context);
                $this->events[] = 'wait:' . \implode(',', \array_keys($startupAcceptance));
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->registerProvider(new class extends WorkerProvider {
            public function getInstanceCount(ServiceContext $context): int
            {
                return 2;
            }
        });

        $context = new ServiceContext(
            instanceName: 'ai-u-concurrent-start',
            epoch: 21,
            controlPort: 37990,
            masterPid: 424250,
            host: '127.0.0.1',
            mainPort: 18444,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            workerCount: 2,
            workerBasePort: 28190,
            workerPort: 28190,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'controlServer', new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        });

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);
        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        self::assertSame('concurrent_batch', $orchestrator->events[0] ?? null);
        self::assertSame('wait:dispatcher,worker', $orchestrator->events[1] ?? null);
        self::assertCount(2, $orchestrator->events);
    }

    public function testSharedServicesAreExcludedFromLocalStartupBatch(): void
    {
        $events = new \ArrayObject();

        $orchestrator = new class($events) extends ServiceOrchestrator {
            public function __construct(
                private readonly \ArrayObject $events
            ) {
                parent::__construct();
            }

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $roles = [];
                foreach ($providers as $provider) {
                    $roles[] = $provider->getRole();
                }
                $this->events->append('phase_one_batch_roles:' . \implode(',', $roles));
                $result = [];
                foreach ($providers as $provider) {
                    $role = $provider->getRole();
                    $count = $provider->getInstanceCount($context);
                    $result[$role] = [];
                    for ($i = 1; $i <= $count; $i++) {
                        $instance = new ServiceInstance(
                            role: $role,
                            instanceId: $i,
                            epoch: $context->epoch,
                            launchId: 'phase-one-test',
                            port: $provider->getPort($i, $context),
                            state: ServiceInstance::STATE_READY,
                            startedAt: (\hrtime(true) / 1_000_000_000),
                            ipcClientId: $role === ControlMessage::ROLE_DISPATCHER ? 301 : null,
                        );
                        $this->getRegistry()->addInstance($instance);
                        $result[$role][] = $instance;
                    }
                }

                return $result;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        ));

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        $eventList = \iterator_to_array($events, false);
        self::assertNotEmpty($eventList);
        $batchEvent = $eventList[0] ?? '';
        self::assertStringNotContainsString(ControlMessage::ROLE_SESSION_SERVER, $batchEvent);
        self::assertStringNotContainsString(ControlMessage::ROLE_MEMORY_SERVER, $batchEvent);
        self::assertStringContainsString(ControlMessage::ROLE_DISPATCHER, $batchEvent);
    }

    /**
     * 鍏变韩鏈嶅姟鍚姩澶辫触涓嶉樆濉炲叾浠栧瓙鏈嶅姟鎵归噺鎷夎捣锛圥rovider 妯″紡涓嬬敱 Orchestrator 寮傛閲嶈瘯锛夈€?
     */
    public function testStartAllChildServicesBodyDoesNotThrowWhenSharedServiceProviderFails(): void
    {
        $events = new \ArrayObject();

        $orchestrator = new class($events) extends ServiceOrchestrator {
            public function __construct(
                private readonly \ArrayObject $events
            ) {
                parent::__construct();
            }

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->events->append('phase_one_batch_started');

                return [];
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        ));

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'controlServer', new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        });

        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        $eventList = \iterator_to_array($events, false);
        self::assertContains('phase_one_batch_started', $eventList);
    }

    /**
     * Dispatcher 先于维护 Worker READY 时，首次的 Worker 池下发因尚无 READY 维护 Worker 而无法落地；
     * 待维护进程上报 READY 后，应补发 SET_ROUTE_TABLE 完成池注入。
     */
    public function testMaintenanceReadyAfterDispatcherRefreshesRouteTable(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-late-ready',
            epoch: 11,
            controlPort: 37982,
            masterPid: 424243,
            host: '127.0.0.1',
            mainPort: 18089,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            workerCount: 0,
            workerBasePort: 28181,
            workerPort: 28181,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);
        $this->writePrivate($orchestrator, 'runtimePolicyPublishedDigest', self::TEST_POLICY_DIGEST);
        $this->writePrivate($orchestrator, 'containerRegistryDigest', self::TEST_CONTAINER_DIGEST);

        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'late-maint',
            port: $context->mainPort,
            state: ServiceInstance::STATE_REGISTERED,
            ipcClientId: 201,
        ));

        $maintPort = 29333;
        $maintenance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'late-maint',
            port: $maintPort,
            state: ServiceInstance::STATE_STARTING,
            ipcClientId: 202,
        );
        $maintenance->setMeta('slot_id', 'maintenance#1');
        $maintenance->setMeta('lease_id', 'late-maint');
        $maintenance->setMeta('generation', 1);
        $registry->addInstance($maintenance);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'late-maint',
            'port' => $context->mainPort,
            'role' => 'dispatcher',
            'policy_digest' => self::TEST_POLICY_DIGEST,
        ], 201]);

        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded)
                && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && \in_array($maintPort, \array_map('intval', $decoded['ports'] ?? []), true)) {
                self::fail('维护 Worker 尚未 READY 时不应下发 SET_ROUTE_TABLE');
            }
        }

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'late-maint',
            'msg_id' => 'late-maint',
            'slot_id' => 'maintenance#1',
            'lease_id' => 'late-maint',
            'generation' => 1,
            'port' => $maintPort,
            'role' => ControlMessage::ROLE_MAINTENANCE,
            ...$this->readyCapabilityPayload($context, ControlMessage::ROLE_MAINTENANCE),
        ], 202]);

        $poolSent = null;
        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $poolSent = $decoded;
            }
        }

        self::assertIsArray($poolSent);
        self::assertSame([$maintPort], $poolSent['ports'] ?? null);
    }

    public function testDuplicateReadyFromSameClientIsIdempotent(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);
        $this->writePrivate($orchestrator, 'runtimePolicyPublishedDigest', self::TEST_POLICY_DIGEST);
        $this->writePrivate($orchestrator, 'containerRegistryDigest', self::TEST_CONTAINER_DIGEST);

        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dup-ready',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        );
        $registry->addInstance($dispatcher);

        $maintenance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dup-ready',
            port: 29339,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 202,
        );
        $maintenance->setMeta('worker_id', 1);
        $maintenance->setMeta('slot_id', 'maintenance#1');
        $maintenance->setMeta('lease_id', 'dup-ready');
        $maintenance->setMeta('generation', 1);
        $maintenance->setMeta('ready_at', (\hrtime(true) / 1_000_000_000) - 1.0);
        $registry->addInstance($maintenance);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'dup-ready',
            'msg_id' => 'dup-ready',
            'slot_id' => 'maintenance#1',
            'lease_id' => 'dup-ready',
            'generation' => 1,
            'port' => 29339,
            'role' => ControlMessage::ROLE_MAINTENANCE,
            ...$this->readyCapabilityPayload($context, ControlMessage::ROLE_MAINTENANCE),
        ], 202]);

        $setPoolMessages = 0;
        $ackMessages = 0;
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $setPoolMessages++;
            }
            if ($entry['clientId'] === 202 && ($decoded['type'] ?? '') === ControlMessage::TYPE_ACK_READY) {
                $ackMessages++;
            }
        }

        self::assertSame(0, $setPoolMessages, 'duplicate READY should not repush maintenance worker pool');
        self::assertSame(1, $ackMessages, 'duplicate READY should still receive ACK_READY');
    }

    public function testAuthenticatedDuplicateWorkerReadySkipsCapabilityAndRouteSideEffects(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);
        $this->writePrivate($orchestrator, 'runtimePolicyPublishedDigest', self::TEST_POLICY_DIGEST);
        $this->writePrivate($orchestrator, 'containerRegistryDigest', self::TEST_CONTAINER_DIGEST);

        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'duplicate-dispatcher',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        );
        $registry->addInstance($dispatcher);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'duplicate-worker',
            port: 29340,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 202,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'duplicate-worker');
        $worker->setMeta('generation', 1);
        $worker->setMeta('ready_at', (\hrtime(true) / 1_000_000_000) - 1.0);
        $registry->addInstance($worker);

        // An already admitted child may retransmit only its authenticated
        // READY identity after losing ACK_READY. Capability/homepage/listener
        // evidence was consumed by the first admission and must not be replayed.
        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'duplicate-worker',
            'msg_id' => 'duplicate-worker',
            'slot_id' => 'worker#1',
            'lease_id' => 'duplicate-worker',
            'generation' => 1,
            'port' => 29340,
            'role' => ControlMessage::ROLE_WORKER,
        ], 202]);

        $messageTypes = [];
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded)) {
                $messageTypes[] = (string)($decoded['type'] ?? '');
            }
        }

        self::assertSame([], $mockControl->closed);
        self::assertSame([ControlMessage::TYPE_ACK_READY], $messageTypes);
        self::assertSame(ServiceInstance::STATE_READY, $worker->state);
    }

    public function testExpiredPendingWorkerReadyCanBeReplacedByNewWorkerReady(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'runtimePolicyPublishedDigest', self::TEST_POLICY_DIGEST);
        $this->writePrivate($orchestrator, 'containerRegistryDigest', self::TEST_CONTAINER_DIGEST);

        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-ready',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        );
        $registry->addInstance($dispatcher);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'old-worker-launch',
            pid: 1001,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        );
        $worker->setProcessTreePids(1001);
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('ready_at', (\hrtime(true) / 1_000_000_000) - 4.0);
        $worker->setMeta('ready_received_at', (\hrtime(true) / 1_000_000_000) - 4.0);
        $worker->setMeta('dispatcher_pool_confirmed_at', null);
        $worker->setMeta('lease_state', 'registered');
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleRegister', [[
            'role' => ControlMessage::ROLE_WORKER,
            'pid' => 2002,
            'port' => 28001,
            'worker_id' => 1,
            'epoch' => $context->epoch,
            'launch_id' => 'new-worker-launch',
        ], 302]);

        $replaced = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $replaced);
        self::assertSame(302, $replaced->ipcClientId);
        self::assertSame('new-worker-launch', $replaced->launchId);
        self::assertSame(ServiceInstance::STATE_REGISTERED, $replaced->state);
        self::assertNull($replaced->getMeta('ready_at'));
        self::assertSame([301], $mockControl->closed);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'role' => ControlMessage::ROLE_WORKER,
            'worker_id' => 1,
            'port' => 28001,
            'epoch' => $context->epoch,
            'launch_id' => 'new-worker-launch',
            'msg_id' => 'new-worker-launch',
            ...$this->readyCapabilityPayload($context, ControlMessage::ROLE_WORKER),
        ], 302]);

        $held = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $held);
        self::assertSame(
            ServiceInstance::STATE_REGISTERED,
            $held->state,
            'replacement READY must remain behind the runtime policy PREPARE barrier'
        );
        $pendingReady = $this->readPrivate($orchestrator, 'runtimePolicyPendingReady');
        self::assertArrayHasKey(302, $pendingReady);

        $policyTransition = $this->readPrivate($orchestrator, 'runtimePolicyTransition');
        self::assertIsArray($policyTransition);
        $this->invokePrivateWithArgs($orchestrator, 'handleRuntimePolicyPreparedAck', [[
            'digest' => (string)$policyTransition['digest'],
            'success' => true,
            'capabilities' => ['policy_accept_gate'],
        ], 302]);

        $ready = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $ready);
        self::assertSame(ServiceInstance::STATE_READY, $ready->state);
        self::assertNotNull($ready->getMeta('ready_at'));
        self::assertSame('new-worker-launch', $ready->launchId);

        $setPoolMessages = 0;
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $setPoolMessages++;
                self::assertContains(28001, $decoded['ports'] ?? []);
            }
        }
        self::assertGreaterThanOrEqual(1, $setPoolMessages);
    }

    public function testUnmatchedWorkerRegisterReceivesSelfTerminateReject(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'handleRegister', [[
            'role' => ControlMessage::ROLE_WORKER,
            'pid' => 0,
            'port' => 28099,
            'worker_id' => 9,
            'epoch' => $context->epoch,
            'launch_id' => 'stray-worker-launch',
            'msg_id' => 'stray-ready',
        ], 909]);

        self::assertSame([909], $mockControl->closed);
        self::assertCount(1, $mockControl->sent);
        $decoded = \json_decode(\rtrim($mockControl->sent[0]['message'], "\n"), true);
        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_READY_ACK, $decoded['type'] ?? null);
        self::assertFalse((bool)($decoded['accepted'] ?? true));
        self::assertSame('no_matching_slot', $decoded['reason'] ?? null);
        self::assertSame(9, $decoded['worker_id'] ?? null);
        self::assertSame(28099, $decoded['port'] ?? null);
        self::assertSame('stray-ready', $decoded['msg_id'] ?? null);
    }

    public function testUnmatchedDispatcherRegisterReceivesRejectInsteadOfPidTermination(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'handleRegister', [[
            'role' => ControlMessage::ROLE_DISPATCHER,
            'pid' => 0,
            'port' => 28100,
            'worker_id' => 0,
            'epoch' => $context->epoch,
            'launch_id' => 'stray-dispatcher-launch',
            'msg_id' => 'stray-dispatcher-ready',
        ], 910]);

        self::assertSame([910], $mockControl->closed);
        self::assertCount(1, $mockControl->sent);
        $decoded = \json_decode(\rtrim($mockControl->sent[0]['message'], "\n"), true);
        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_READY_ACK, $decoded['type'] ?? null);
        self::assertFalse((bool)($decoded['accepted'] ?? true));
        self::assertSame('no_matching_slot', $decoded['reason'] ?? null);
        self::assertSame('stray-dispatcher-ready', $decoded['msg_id'] ?? null);
    }

    public function testForcedTerminationLeaseRequiresCredentialBoundProcessBirth(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            launchId: 'dispatcher-launch',
            pid: 41001,
            rootPid: 41002,
            launcherPid: 41002,
        );
        $instance->setMeta('process_name', 'weline-wls-dispatcher-default-ptest');
        $instance->setMeta('child_credential_id', \str_repeat('c', 64));
        $instance->setMeta('child_credential_pid', 41001);
        $instance->setMeta('child_process_birth', \str_repeat('d', 64));
        $instance->setMeta('child_pid_namespace_id', '');

        self::assertNull($this->invokePrivateWithArgs(
            $orchestrator,
            'buildCredentialBoundTerminationLease',
            [$instance],
        ));

        $instance->setMeta('child_credential_pid', 41002);
        $lease = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildCredentialBoundTerminationLease',
            [$instance],
        );
        self::assertIsArray($lease);
        self::assertSame(41002, $lease['pid'] ?? 0);
        self::assertSame(\str_repeat('d', 64), $lease['process_birth'] ?? '');
        self::assertSame('dispatcher-launch', $lease['launch_id'] ?? '');
    }

    public function testAdvisoryPortInspectionCannotAuthorizePidCleanup(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $port = Processer::findAvailablePort(29500, 29999);
        self::assertGreaterThan(0, $port);

        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'releaseCurrentInstancePortOwner',
            [
                ControlMessage::ROLE_DISPATCHER,
                $port,
                [
                    'pid' => 2_000_000_003,
                    'pid_running' => true,
                    'is_weline' => true,
                    'pname' => 'weline-wls-dispatcher-default-ptest',
                    'scope' => MasterProcess::getProjectScopeToken(),
                ],
            ],
        ));
    }

    public function testGatewayBackendPublicationSkipsEarlierInvalidAdoption(): void
    {
        $first = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_BACKEND,
            instanceId: 1,
            launchId: 'first-launch',
            state: ServiceInstance::STATE_READY,
        );
        $second = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_BACKEND,
            instanceId: 2,
            launchId: 'second-launch',
            state: ServiceInstance::STATE_READY,
        );

        $selected = $this->invokePrivateWithArgs(
            new ServiceOrchestrator(),
            'gatewayJoinBackendPublicationInstance',
            [
                [$first, $second],
                static fn (ServiceInstance $instance): bool => $instance->instanceId === 2,
            ],
        );

        self::assertSame($second, $selected);
    }

    public function testPrimaryHostLeaseActivationOccursAfterEveryFinalReadyGate(): void
    {
        $method = new \ReflectionMethod(ServiceOrchestrator::class, 'handleReady');
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $http3Gate = \strpos($source, '$this->holdLinuxHttp3ReadyForActivation(');
        $namespaceGate = \strpos($source, '$finalNamespaceRejection =');
        $publicLeaseActivation = \strpos($source, '$this->confirmPublicEdgeLease(');
        $initialBackendActivation = \strpos(
            $source,
            '$this->confirmGatewayInitialBackendLease(',
        );

        self::assertIsInt($http3Gate);
        self::assertIsInt($namespaceGate);
        self::assertIsInt($publicLeaseActivation);
        self::assertIsInt($initialBackendActivation);
        self::assertGreaterThan($http3Gate, $publicLeaseActivation);
        self::assertGreaterThan($namespaceGate, $publicLeaseActivation);
        self::assertGreaterThan($http3Gate, $initialBackendActivation);
        self::assertGreaterThan($namespaceGate, $initialBackendActivation);
    }

    public function testHomepageFailOpenAuditUsesInitializedWorkerReadinessProof(): void
    {
        $method = new \ReflectionMethod(ServiceOrchestrator::class, 'handleReady');
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $homepageProof = \strpos($source, '$homepageProcessFpcReady =');
        $failOpenAudit = \strpos($source, 'Worker READY admitted via homepage fail-open');
        $workerRejection = \strpos($source, "if (\$readyRejection !== '')", (int)$homepageProof);

        self::assertIsInt($homepageProof);
        self::assertIsInt($failOpenAudit);
        self::assertIsInt($workerRejection);
        self::assertGreaterThan($homepageProof, $failOpenAudit);
        self::assertLessThan($workerRejection, $failOpenAudit);
    }

    public function testLeasedSharedPortRegisterIsRejectedOnlyOnceByExactSlot(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];
                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }

            public function clientExists(int $clientId): bool
            {
                return false;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $registry = $orchestrator->getRegistry();
        foreach ([1, 2] as $slot) {
            $backend = new ServiceInstance(
                role: ControlMessage::ROLE_GATEWAY_BACKEND,
                instanceId: $slot,
                epoch: $context->epoch,
                launchId: 'gateway-current-' . $slot,
                port: 24542,
                state: ServiceInstance::STATE_READY,
            );
            $backend->setMeta('slot_id', 'gateway_backend#' . $slot);
            $backend->setMeta('lease_id', 'gateway-current-' . $slot);
            $backend->setMeta('generation', 10 + $slot);
            $registry->addInstance($backend);
        }

        $this->invokePrivateWithArgs($orchestrator, 'handleRegister', [[
            'role' => ControlMessage::ROLE_GATEWAY_BACKEND,
            'pid' => 9999,
            'port' => 24542,
            'worker_id' => 2,
            'instance_id' => 2,
            'epoch' => $context->epoch,
            'launch_id' => 'gateway-stale-2',
            'slot_id' => 'gateway_backend#2',
            'lease_id' => 'gateway-stale-2',
            'generation' => 10,
            'msg_id' => 'gateway-stale-2',
        ], 902]);

        self::assertSame([902], $mockControl->closed);
        self::assertCount(1, $mockControl->sent);
        $decoded = \json_decode(\rtrim($mockControl->sent[0]['message'], "\n"), true);
        self::assertIsArray($decoded);
        self::assertSame('missing_or_stale_lease', $decoded['reason'] ?? null);
        self::assertNull(
            $registry->getInstance(ControlMessage::ROLE_GATEWAY_BACKEND, 2)?->ipcClientId,
        );
    }

    public function testStaleWorkerPoolAckCannotConfirmNewLeaseGeneration(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease-new',
            pid: 3101,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease-new');
        $worker->setMeta('generation', 2);
        $worker->setMeta('lease_state', 'ready_accepted');
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 28001,
            'in_pool' => true,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-lease-old',
            'generation' => 1,
        ], 401]);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNull($current->getMeta('dispatcher_pool_confirmed_at'));
        self::assertSame([], $mockControl->sent);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 28001,
            'in_pool' => true,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-lease-new',
            'generation' => 2,
        ], 401]);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNotNull($current->getMeta('dispatcher_pool_confirmed_at'));
        self::assertSame('dispatcher_active', $current->getMeta('lease_state'));
        self::assertCount(0, $mockControl->sent);
    }

    public function testWorkerRegisterWithoutCurrentLeaseIsRejectedForLeasedSlot(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease',
            pid: 0,
            port: 28001,
            state: ServiceInstance::STATE_STARTING,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease');
        $worker->setMeta('generation', 3);

        $accepted = $this->invokePrivateWithArgs($orchestrator, 'registerInstanceIpc', [
            $worker,
            302,
            2302,
            1,
            $context->epoch,
            'worker-lease',
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
            '',
            '',
            0,
        ]);

        self::assertFalse($accepted);
        self::assertSame([302], $mockControl->closed);
        self::assertCount(1, $mockControl->sent);

        $decoded = \json_decode(\rtrim($mockControl->sent[0]['message'], "\n"), true);
        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_READY_ACK, $decoded['type'] ?? null);
        self::assertFalse((bool)($decoded['accepted'] ?? true));
        self::assertSame('missing_or_stale_lease', $decoded['reason'] ?? null);
        self::assertSame(1, $decoded['worker_id'] ?? null);
        self::assertSame(28001, $decoded['port'] ?? null);
    }

    public function testWorkerPoolRejectNotifiesWorkerBeforeSelfHealRetry(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease-new',
            pid: 3101,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease-new');
        $worker->setMeta('generation', 2);
        $worker->setMeta('lease_state', 'ready_accepted');
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 28001,
            'in_pool' => false,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-lease-new',
            'generation' => 2,
            'msg_id' => 'pool-reject-1',
        ], 401]);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNotNull($current->getMeta('dispatcher_pool_rejected_at'));
        self::assertSame(1, $current->getMeta('dispatcher_pool_reject_count'));
        self::assertCount(0, $mockControl->sent);
    }

    public function testReadyWorkerIpcDisconnectRemovesDispatcherPoolBeforeResurrection(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_WORKER)) {
            $registry->registerProvider(new WorkerProvider());
        }
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-lease',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        ));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease',
            pid: 0,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
            startedAt: (\hrtime(true) / 1_000_000_000) - 30.0,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease');
        $worker->setMeta('generation', 1);
        $worker->setMeta('lease_state', 'in_pool');
        $worker->setMeta('dispatcher_pool_confirmed_at', (\hrtime(true) / 1_000_000_000) - 1.0);
        $registry->addInstance($worker);

        $orchestrator->handleIpcDisconnect(301, [
            'role' => ControlMessage::ROLE_WORKER,
            'state' => ServiceInstance::STATE_READY,
        ], $mockControl);

        $routeTable = null;
        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 401) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $routeTable = $decoded;
                break;
            }
        }

        self::assertIsArray($routeTable);
        self::assertSame([], $routeTable['ports'] ?? null);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNull($current->ipcClientId);
        self::assertSame(ServiceInstance::STATE_FAILED, $current->state);
        self::assertSame('disconnected_grace', $current->getMeta('lease_state'));
        self::assertArrayHasKey('worker:1', $this->readPrivate($orchestrator, 'resurrectQueue'));
    }

    public function testAutoStartMaintenanceModeUsesRuntimeWorkerCount(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-runtime-count',
            epoch: 12,
            controlPort: 37983,
            masterPid: 424244,
            host: '127.0.0.1',
            mainPort: 18090,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            workerCount: 6,
            workerBasePort: 28182,
            workerPort: 28182,
        );

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'maintenanceSticky'));
        self::assertSame(1, ($this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_MAINTENANCE] ?? null));
    }

    public function testPerformHealthChecksDoesNotPromoteStartingMaintenanceWithOnlyIpcBindingToReady(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            port: 29333,
            state: ServiceInstance::STATE_STARTING,
            startedAt: (\hrtime(true) / 1_000_000_000),
            ipcClientId: 901,
        ));

        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        $this->invokePrivate($orchestrator, 'performHealthChecks');

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(ServiceInstance::STATE_STARTING, $instance->state);
    }

    public function testHandleIpcDisconnectCleansInactiveMaintenanceWorkerWithoutFullRestart(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 0,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 902,
        ));

        $orchestrator->handleIpcDisconnect(902, [], $this->createMock(MasterControlServer::class));

        self::assertNull($registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1));
        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'fullRestartRequested'));
    }

    public function testHandleIpcDisconnectSchedulesLocalRecoveryForActiveMaintenanceWorker(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 0,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 903,
            startedAt: (\hrtime(true) / 1_000_000_000) - 20.0,
        ));

        $orchestrator->handleIpcDisconnect(903, [], $this->createMock(MasterControlServer::class));

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertNull($instance->ipcClientId);
        self::assertSame(ServiceInstance::STATE_FAILED, $instance->state);
        self::assertSame(1, $instance->restarts);
        self::assertArrayHasKey('maintenance:1', $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'fullRestartRequested'));
    }

    public function testHandleIpcDisconnectDelaysLocalRecoveryWhenWrapperRootStillRunning(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 999999,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 904,
            startedAt: (\hrtime(true) / 1_000_000_000) - 20.0,
        );
        $instance->setProcessTreePids(999999, \getmypid(), \getmypid());
        $registry->addInstance($instance);

        $orchestrator->handleIpcDisconnect(904, [], $this->createMock(MasterControlServer::class));

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertNull($instance->ipcClientId);
        self::assertSame(ServiceInstance::STATE_FAILED, $instance->state);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('maintenance:1', $queue);
        self::assertGreaterThan(0.0, (float) ($queue['maintenance:1']['restartDelay'] ?? 0.0));
        self::assertTrue((bool) ($queue['maintenance:1']['delayed'] ?? false));
        self::assertSame(\getmypid(), (int) ($queue['maintenance:1']['tracking_pid'] ?? 0));
    }

    public function testHealthCheckRestartOrEscalateSchedulesLocalRecoveryForActiveMaintenanceWorker(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 0,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            startedAt: (\hrtime(true) / 1_000_000_000) - 20.0,
        ));

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);

        $this->invokePrivateWithArgs($orchestrator, 'healthCheckRestartOrEscalate', [
            $instance,
            'dead_without_ipc:maintenance#1',
        ]);

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(ServiceInstance::STATE_FAILED, $instance->state);
        self::assertSame(1, $instance->restarts);
        self::assertArrayHasKey('maintenance:1', $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'fullRestartRequested'));
    }

    public function testMasterSelfAuditRecyclesReadyDispatcherWhenIpcClientSlotIsStale(): void
    {
        $server = new class extends MasterControlServer {
            public array $existingClientIds = [];

            public function getPort(): int
            {
                return 19981;
            }

            public function clientExists(int $clientId): bool
            {
                return \in_array($clientId, $this->existingClientIds, true);
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                unset($role, $port);

                return true;
            }

            protected function batchCreateProcesses(array $commands): array
            {
                unset($commands);

                return ['1' => 0];
            }
        };

        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $oldDispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-stale-ipc',
            pid: 43210,
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 999,
            startedAt: (\hrtime(true) / 1_000_000_000) - 120.0,
        );
        $registry->addInstance($oldDispatcher);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_DISPATCHER => 1,
        ]);

        $readyBefore = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);
        $this->invokePrivate($orchestrator, 'performMasterSelfAudit');
        $readyAfter = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);

        self::assertSame(0, $readyBefore);
        self::assertSame(0, $readyAfter);
        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->ipcClientId);
        self::assertNotSame('dispatcher-stale-ipc', $dispatcher->launchId);
    }

    public function testMasterSelfAuditKeepsReadyDispatcherWhenWrapperRootStillAlive(): void
    {
        $server = new class extends MasterControlServer {
            public array $existingClientIds = [];

            public function getPort(): int
            {
                return 19981;
            }

            public function clientExists(int $clientId): bool
            {
                return \in_array($clientId, $this->existingClientIds, true);
            }
        };

        $orchestrator = new ServiceOrchestrator();

        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-wrapper-alive',
            pid: 999999,
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 1001,
            startedAt: (\hrtime(true) / 1_000_000_000) - 120.0,
        );
        $dispatcher->setProcessTreePids(999999, \getmypid(), \getmypid());
        $registry->addInstance($dispatcher);

        $server->existingClientIds = [1001];
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_DISPATCHER => 1,
        ]);

        $readyBefore = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);
        $this->invokePrivate($orchestrator, 'performMasterSelfAudit');
        $readyAfter = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);

        self::assertSame(1, $readyBefore);
        self::assertSame(1, $readyAfter);

        $dispatcherAfter = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcherAfter);
        self::assertSame('dispatcher-wrapper-alive', $dispatcherAfter->launchId);
        self::assertSame(ServiceInstance::STATE_READY, $dispatcherAfter->state);
        self::assertSame(1001, $dispatcherAfter->ipcClientId);
        self::assertSame(\getmypid(), $dispatcherAfter->getRootPid());
    }

    public function testShouldLaunchForegroundAllowsWindowsFrontendChildProcessesDuringBootstrapWhenFlagsSet(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return true;
            }
        };
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);

        $context = new ServiceContext(
            instanceName: 'frontend-bootstrap',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'http://127.0.0.1:8080',
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );

        $dispatchForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $context]
        );
        $workerForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_WORKER, $context]
        );

        self::assertTrue($dispatchForeground);
        self::assertTrue($workerForeground);
    }

    public function testShouldLaunchForegroundAllowsWindowsFrontendChildProcessesAfterBootstrapWhenFlagsSet(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return true;
            }
        };
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        $context = new ServiceContext(
            instanceName: 'frontend-after-bootstrap',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'http://127.0.0.1:8080',
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );

        $dispatchForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $context]
        );
        $workerForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_WORKER, $context]
        );

        self::assertTrue($dispatchForeground);
        self::assertTrue($workerForeground);
    }

    public function testShouldLaunchForegroundKeepsUnixFrontendBootstrapChildrenDetached(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return false;
            }
        };
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);

        $context = $this->createFrontendContext([
            'frontend_non_worker_unix' => true,
        ]);

        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_SESSION_SERVER, $context]
        ));
        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_MEMORY_SERVER, $context]
        ));
        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $context]
        ));
    }

    public function testShouldLaunchForegroundRequiresUnixNonWorkerOptInAfterBootstrap(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return false;
            }
        };
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $this->createFrontendContext()]
        ));
        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $this->createFrontendContext([
                'frontend_non_worker_unix' => true,
            ])]
        ));
    }

    public function testBuildWindowsDetachedPhpArgvForBackgroundCommandIncludesEpochLaunchIdAndName(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 7,
            launchId: 'worker-1-launch',
            port: 19001,
            state: ServiceInstance::STATE_STARTING,
        );
        $command = new \Weline\Server\Service\Contract\ServiceCommand(
            script: 'app/code/Weline/Server/bin/worker.php',
            arguments: ['127.0.0.1', '19001', '1', 'test-instance'],
            processName: 'weline-wls-worker-test-instance-1',
        );

        $argv = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildWindowsDetachedPhpArgvForCommand',
            [$command, $instance, $command->getProcessName()]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertNotSame([], $argv);
            self::assertSame(PHP_BINARY, $argv[0]);
            self::assertContains('--epoch=7', $argv);
            self::assertContains('--launch-id=worker-1-launch', $argv);
            self::assertContains('--name=weline-wls-worker-test-instance-1', $argv);
        } else {
            self::assertSame([], $argv);
        }
    }

    public function testBuildWindowsDetachedPhpArgvForBootstrapWorkerYieldsToFrontendWindowFlags(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);

        $context = new ServiceContext(
            instanceName: 'frontend-bootstrap-argv',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'http://127.0.0.1:8080',
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
        $this->writePrivate($orchestrator, 'context', $context);

        $provider = new WorkerProvider();
        $command = $provider->buildCommand(1, $context);
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-bootstrap-launch',
            port: 18081,
            state: ServiceInstance::STATE_STARTING,
        );

        $argv = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildWindowsDetachedPhpArgvForCommand',
            [$command, $instance, $command->getProcessName()]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertSame([], $argv);
            return;
        }

        self::assertSame([], $argv);
    }

    public function testPureWlsDispatcherProviderBindsConfiguredAccessHost(): void
    {
        $provider = new DispatcherProvider();
        $context = new ServiceContext(
            instanceName: 'dispatcher-bind-host',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: 'p11005ce4.weline.test',
            mainPort: 9981,
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
                    'host' => 'p11005ce4.weline.test',
                ],
            ],
            workerCount: 4,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame('p11005ce4.weline.test', $command->arguments[0] ?? null);
    }

    public function testPureWlsDispatcherProviderIgnoresLegacyPrivateBindHostOverride(): void
    {
        $provider = new DispatcherProvider();
        $context = new ServiceContext(
            instanceName: 'dispatcher-bind-host',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: 'p11005ce4.weline.test',
            mainPort: 9981,
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
                    'host' => 'p11005ce4.weline.test',
                    'dispatcher' => [
                        'bind_host' => '127.0.0.1',
                    ],
                ],
            ],
            workerCount: 4,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame('p11005ce4.weline.test', $command->arguments[0] ?? null);
    }

    public function testGatewayDispatcherUsesSameBackendTokenAsWorkers(): void
    {
        $instanceName = 'gateway-dispatcher-token';
        $provider = new DispatcherProvider();
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => [
                        'adapter' => 'nginx',
                        'mode' => 'gateway',
                        'scope' => 'host_gateway',
                    ],
                    'gateway' => [
                        'protocol' => 'wls-edge/2',
                        'project_uuid' => '9f7bbff7-271a-4bdf-bdf1-7c655d419700',
                        'epoch' => '0123456789abcdef0123456789abcdef',
                        'instance_generation' => 23,
                        'launch_id' => \str_repeat('d', 32),
                        'backend_capability_launch' => self::gatewayCapabilityLaunch(
                            23,
                            \str_repeat('d', 32),
                        ),
                    ],
                    'http' => [
                        'protocols' => ['h1'],
                        'preferred' => 'h1',
                        'tls_session_resumption' => false,
                        'alt_svc' => false,
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        try {
            $command = $provider->buildCommand(1, $context);

            self::assertContains(
                '--gateway-backend-token-file=' . GatewayBackendIngressTokenStore::tokenFile($instanceName),
                $command->arguments,
            );
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/D',
                \trim((string)\file_get_contents(GatewayBackendIngressTokenStore::tokenFile($instanceName))),
            );
        } finally {
            self::cleanupGatewayBackendTokenState($instanceName);
        }
    }

    public function testGatewayWorkerCarriesImmutableBackendIdentity(): void
    {
        $instanceName = 'gateway-worker-identity';
        $provider = new WorkerProvider();
        $projectUuid = '9f7bbff7-271a-4bdf-bdf1-7c655d419700';
        $instanceLaunchId = \str_repeat('b', 32);
        $listenerLeaseId = \str_repeat('e', 32);
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'public_origin' => 'https://gateway-worker-identity.weline.test',
                    'edge' => [
                        'adapter' => 'nginx',
                        'mode' => 'gateway',
                        'scope' => 'host_gateway',
                    ],
                    'gateway' => [
                        'protocol' => 'wls-edge/2',
                        'project_uuid' => $projectUuid,
                        'epoch' => '0123456789abcdef0123456789abcdef',
                        'instance_generation' => 17,
                        'launch_id' => $instanceLaunchId,
                        'backend_lease' => [
                            'schema_version' => 6,
                            'bind_host' => '127.0.0.1',
                            'port' => 9981,
                            'lease_id' => $listenerLeaseId,
                        ],
                        'startup_listener_handoff' => [
                            'schema_version' => 1,
                            'transport' => 'posix_inherited_fd',
                            'continuous_ownership' => true,
                            'fd' => 3,
                            'bind_host' => '127.0.0.1',
                            'port' => 9981,
                            'lease_id' => $listenerLeaseId,
                            'launch_id' => $instanceLaunchId,
                        ],
                        'backend_capability_launch' => self::gatewayCapabilityLaunch(
                            17,
                            $instanceLaunchId,
                        ),
                    ],
                    'http' => [
                        'protocols' => ['h1'],
                        'preferred' => 'h1',
                        'tls_session_resumption' => false,
                        'alt_svc' => false,
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        try {
            $command = $provider->buildCommand(1, $context);

            self::assertContains(
                '--gateway-backend-token-file=' . GatewayBackendIngressTokenStore::tokenFile($instanceName),
                $command->arguments,
            );
            self::assertContains('--gateway-project-uuid=' . $projectUuid, $command->arguments);
            self::assertContains('--gateway-instance-generation=17', $command->arguments);
            self::assertContains(
                '--gateway-instance-launch-id=' . $instanceLaunchId,
                $command->arguments,
            );
            self::assertSame('24314', $command->arguments[1] ?? null);
            self::assertContains('--gateway-listener-host=127.0.0.1', $command->arguments);
            self::assertContains('--gateway-listener-port=9981', $command->arguments);
            self::assertContains(
                '--gateway-host-lease-id=' . $listenerLeaseId,
                $command->arguments,
            );
            self::assertContains('--gateway-session-capability=isolated', $command->arguments);
            self::assertContains(
                '--gateway-session-capability-evidence-digest=' . \str_repeat('0', 64),
                $command->arguments,
            );
        } finally {
            self::cleanupGatewayBackendTokenState($instanceName);
        }
    }

    public function testDirectGatewayWorkerAttestsItsOwnExactSharedListener(): void
    {
        $instanceName = 'gateway-direct-worker-identity';
        $projectUuid = 'c5359315-4e36-48d3-8ec5-9fa2d9e16b26';
        $instanceLaunchId = \str_repeat('a', 32);
        $listenerLeaseId = \str_repeat('f', 32);
        $runtimeSelection = RuntimeSelection::fromArray([
            'requested_topology' => 'direct',
            'effective_topology' => 'direct',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => 'shared_fd',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test direct runtime selection',
        ]);
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 9984,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: $runtimeSelection,
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'public_origin' => 'https://gateway-direct-worker-identity.weline.test',
                    'edge' => [
                        'adapter' => 'nginx',
                        'mode' => 'gateway',
                        'scope' => 'host_gateway',
                    ],
                    'gateway' => [
                        'protocol' => 'wls-edge/2',
                        'project_uuid' => $projectUuid,
                        'epoch' => '0123456789abcdef0123456789abcdef',
                        'instance_generation' => 18,
                        'launch_id' => $instanceLaunchId,
                        'backend_lease' => [
                            'schema_version' => 6,
                            'bind_host' => '127.0.0.1',
                            'port' => 9984,
                            'lease_id' => $listenerLeaseId,
                        ],
                        'startup_listener_handoff' => [
                            'schema_version' => 1,
                            'transport' => 'posix_inherited_fd',
                            'continuous_ownership' => true,
                            'fd' => 3,
                            'bind_host' => '127.0.0.1',
                            'port' => 9984,
                            'lease_id' => $listenerLeaseId,
                            'launch_id' => $instanceLaunchId,
                        ],
                        'backend_capability_launch' => self::gatewayCapabilityLaunch(
                            18,
                            $instanceLaunchId,
                        ),
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: 24313,
            workerPort: 9984,
        );

        try {
            $command = (new WorkerProvider())->buildCommand(1, $context);

            self::assertSame('9984', $command->arguments[1] ?? null);
            self::assertContains('--listen-fd=3', $command->arguments);
            self::assertContains('--gateway-listener-host=127.0.0.1', $command->arguments);
            self::assertContains('--gateway-listener-port=9984', $command->arguments);
            self::assertContains(
                '--gateway-host-lease-id=' . $listenerLeaseId,
                $command->arguments,
            );
            self::assertSame(
                1,
                \count(\array_filter(
                    $command->arguments,
                    static fn (string $argument): bool => \str_starts_with(
                        $argument,
                        '--gateway-host-lease-id=',
                    ),
                )),
            );
        } finally {
            self::cleanupGatewayBackendTokenState($instanceName);
        }
    }

    public function testGatewayMaintenanceWorkerCarriesImmutableBackendIdentity(): void
    {
        $instanceName = 'gateway-maintenance-identity';
        $provider = new MaintenanceWorkerProvider();
        $provider->enable(1);
        $projectUuid = 'd2e1fba3-91de-46f4-9538-b39259a89596';
        $instanceLaunchId = \str_repeat('c', 32);
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 9982,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'public_origin' => 'https://gateway-maintenance-identity.weline.test',
                    'edge' => [
                        'adapter' => 'nginx',
                        'mode' => 'gateway',
                        'scope' => 'host_gateway',
                    ],
                    'gateway' => [
                        'protocol' => 'wls-edge/2',
                        'project_uuid' => $projectUuid,
                        'epoch' => '0123456789abcdef0123456789abcdef',
                        'instance_generation' => 19,
                        'launch_id' => $instanceLaunchId,
                        'backend_capability_launch' => self::gatewayCapabilityLaunch(
                            19,
                            $instanceLaunchId,
                        ),
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: 24314,
            workerPort: 24314,
        );

        try {
            $command = $provider->buildCommand(1, $context);

            self::assertContains(
                '--gateway-backend-token-file=' . GatewayBackendIngressTokenStore::tokenFile($instanceName),
                $command->arguments,
            );
            self::assertContains('--gateway-project-uuid=' . $projectUuid, $command->arguments);
            self::assertContains('--gateway-instance-generation=19', $command->arguments);
            self::assertContains(
                '--gateway-instance-launch-id=' . $instanceLaunchId,
                $command->arguments,
            );
            self::assertContains('--gateway-session-capability=isolated', $command->arguments);
            self::assertContains(
                '--gateway-session-capability-evidence-digest=' . \str_repeat('0', 64),
                $command->arguments,
            );
        } finally {
            self::cleanupGatewayBackendTokenState($instanceName);
        }
    }

    public function testHttpRedirectProviderIsRetired(): void
    {
        $provider = new HttpRedirectProvider();
        $context = new ServiceContext(
            instanceName: 'redirect-bind-host',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: 'p11005ce4.weline.test',
            mainPort: 443,
            sslEnabled: true,
            sslCert: '/tmp/cert.pem',
            sslKey: '/tmp/key.pem',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'host' => 'p11005ce4.weline.test',
                ],
            ],
            httpRedirectPort: 80,
            workerCount: 4,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WLS HTTP redirect provider is retired');
        $provider->buildCommand(1, $context);
    }

    public function testBuildWindowsDetachedPhpArgvForPostBootstrapWorkerYieldsToFrontendWindowFlags(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        $context = new ServiceContext(
            instanceName: 'frontend-post-bootstrap-argv',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'http://127.0.0.1:8080',
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
        $this->writePrivate($orchestrator, 'context', $context);

        $provider = new WorkerProvider();
        $command = $provider->buildCommand(1, $context);
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-post-bootstrap-launch',
            port: 18081,
            state: ServiceInstance::STATE_STARTING,
        );

        $argv = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildWindowsDetachedPhpArgvForCommand',
            [$command, $instance, $command->getProcessName()]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertSame([], $argv);
            return;
        }

        self::assertSame([], $argv);
    }

    public function testDrainControlPlaneAfterStartupStepPollsUntilIdle(): void
    {
        $pollCalls = 0;
        $server = new class($pollCalls) extends MasterControlServer {
            public function __construct(private int &$pollCalls)
            {
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;

                return $this->pollCalls < 3 ? 1 : 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'drainControlPlaneAfterStartupStep', [8, 1]);

        self::assertSame(4, $pollCalls);
    }

    public function testConcurrentStartupDrainUsesShorterFrontendBudgetOnWindows(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = new ServiceContext(
            instanceName: 'startup-drain',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 443,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: true,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]]
        );

        $budget = $this->invokePrivateWithArgs($orchestrator, 'resolveConcurrentStartupDrainMinDurationUsec', [$context]);

        $expected = (\defined('IS_WIN') && IS_WIN) ? 2_500_000 : 750_000;
        self::assertSame($expected, $budget);
    }

    public function testConfiguredMaintenanceDoesNotAutoDisableWhenWorkerBecomesReady(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-sticky',
            epoch: 13,
            controlPort: 37984,
            masterPid: 424245,
            host: '127.0.0.1',
            mainPort: 18091,
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
                ],
                'system' => [
                    'maintenance' => true,
                ],
            ],
            workerCount: 2,
            workerBasePort: 28184,
            workerPort: 28184,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceSticky'));

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'sticky-worker',
            port: 28185,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        ));

        self::assertFalse($orchestrator->checkAndDisableMaintenanceIfReady());
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceSticky'));
    }

    public function testStringFalseMaintenanceConfigDoesNotCreateStickyStartupMaintenance(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-string-false',
            epoch: 15,
            controlPort: 37986,
            masterPid: 424247,
            host: '127.0.0.1',
            mainPort: 18093,
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
                ],
                'system' => [
                    'maintenance' => 'false',
                ],
            ],
            workerCount: 2,
            workerBasePort: 28188,
            workerPort: 28188,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'maintenanceSticky'));

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-string-false',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-string-false-1',
            port: 28189,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'worker-string-false-2',
            port: 28190,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 402,
        ));

        self::assertTrue($orchestrator->checkAndDisableMaintenanceIfReady());
        self::assertFalse($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'maintenanceSticky'));

        $workerRouteMessages = [];
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER) {
                $workerRouteMessages[] = $decoded;
            }
        }

        self::assertCount(1, $workerRouteMessages);
        self::assertSame([28189, 28190], $workerRouteMessages[0]['ports'] ?? null);
    }

    public function testConfiguredMaintenanceDoesNotAutoDisableWhenAllWorkersBecomeReady(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-sticky-all-ready',
            epoch: 14,
            controlPort: 37985,
            masterPid: 424246,
            host: '127.0.0.1',
            mainPort: 18092,
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
                ],
                'system' => [
                    'maintenance' => true,
                ],
            ],
            workerCount: 2,
            workerBasePort: 28186,
            workerPort: 28186,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'sticky-all-ready-1',
            port: 28187,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'sticky-all-ready-2',
            port: 28188,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 402,
        ));

        self::assertFalse($orchestrator->checkAndDisableMaintenanceIfReady());
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceSticky'));
    }

    public function testWorkerReadyDoesNotPublishBusinessWorkerWhileMaintenanceModeActive(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);
        $this->writePrivate($orchestrator, 'maintenanceSticky', false);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
            ControlMessage::ROLE_MAINTENANCE => 1,
        ]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-ready-maint',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-under-maintenance',
            port: 18080,
            state: ServiceInstance::STATE_STARTING,
            startedAt: (\hrtime(true) / 1_000_000_000) - 1.0,
            ipcClientId: 301,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'worker-under-maintenance',
            'port' => 18080,
            'role' => ControlMessage::ROLE_WORKER,
        ], 301]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));

        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }

            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded)
                && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER
                && \in_array(18080, \array_map('intval', $decoded['ports'] ?? []), true)) {
                self::fail('维护模式仍在激活时，业务 Worker READY 不应立即发布给 Dispatcher');
            }
        }
    }

    public function testNotifyDispatcherRemoveWorkerInvalidatesDispatcherPoolSignature(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '16895,16896');

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: 1,
            launchId: 'dispatcher-remove-signature',
            port: 443,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'notifyDispatcherRemoveWorker', [16895]);

        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));

        $messages = \array_map(
            static fn(array $entry): array => (array) \json_decode(\rtrim($entry['message'], "\n"), true),
            $mockControl->sent
        );
        self::assertContains(ControlMessage::TYPE_SET_ROUTE_TABLE, \array_column($messages, 'type'));
    }

    public function testDuplicateWorkerReadyResynchronizesDispatcherPoolAfterRemove(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', false);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '18080,18081');

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-duplicate-ready-sync',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));

        $workerOne = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-dup-ready-1',
            port: 18080,
            state: ServiceInstance::STATE_READY,
            startedAt: (\hrtime(true) / 1_000_000_000) - 1.0,
            ipcClientId: 301,
        );
        $workerOne->setMeta('worker_id', 1);
        $workerOne->setMeta('ready_at', (\hrtime(true) / 1_000_000_000) - 1.0);
        $registry->addInstance($workerOne);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'worker-dup-ready-2',
            port: 18081,
            state: ServiceInstance::STATE_READY,
            startedAt: (\hrtime(true) / 1_000_000_000) - 1.0,
            ipcClientId: 302,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'notifyDispatcherRemoveWorker', [18080]);
        self::assertSame('18080,18081', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'worker-dup-ready-1',
            'port' => 18080,
            'role' => ControlMessage::ROLE_WORKER,
        ], 301]);

        $poolMessages = [];
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER) {
                $poolMessages[] = $decoded;
            }
        }

        self::assertCount(1, $poolMessages);
        self::assertSame([18080, 18081], $poolMessages[0]['ports'] ?? null);
        self::assertSame('18080,18081', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));
    }

    public function testWorkerPoolAckRejectedTriggersPoolResyncSelfHealing(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', false);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '18080');

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-pool-ack-rejected',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-pool-ack-rejected',
            port: 18080,
            state: ServiceInstance::STATE_READY,
            startedAt: (\hrtime(true) / 1_000_000_000) - 1.0,
            ipcClientId: 301,
        );
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 18080,
            'in_pool' => false,
        ], 201]);

        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));
        self::assertGreaterThan(
            0,
            (int) ($registry->getInstance(ControlMessage::ROLE_WORKER, 1)?->getMeta('dispatcher_pool_reject_count') ?? 0)
        );

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        $poolMessages = [];
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER) {
                $poolMessages[] = $decoded;
            }
        }

        self::assertNotSame([], $poolMessages);
        $lastPoolMessage = $poolMessages[\array_key_last($poolMessages)];
        self::assertSame([18080], $lastPoolMessage['ports'] ?? []);
    }

    public function testGuardResurrectQueueTasksCancelsStalledPeriodicResurrectQueueTask(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->invokePrivate($orchestrator, 'initializeMainLoopFiberScheduler');
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => (\hrtime(true) / 1_000_000_000) - 1.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 18080,
            ],
        ]);

        $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleMainLoopTask', [
            'periodic:resurrect_queue',
            'resurrect_queue',
            static function (): void {
                \Weline\Server\Scheduler\SchedulerSystem::yieldDelay(60000);
            },
        ]);
        self::assertTrue($scheduled);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        $tasks['periodic:resurrect_queue']['startedAt'] = (\hrtime(true) / 1_000_000_000) - 30.0;
        $this->writePrivate($orchestrator, 'mainLoopTasks', $tasks);

        $now = (\hrtime(true) / 1_000_000_000);
        $this->invokePrivateWithArgs($orchestrator, 'guardResurrectQueueTasks', [$now]);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        self::assertArrayNotHasKey('periodic:resurrect_queue', $tasks);

        $scheduler = $this->readPrivate($orchestrator, 'mainLoopFiberScheduler');
        self::assertNotNull($scheduler);
        self::assertSame(0, $scheduler->getActiveFiberCount());
        self::assertFalse($scheduler->hasPendingTimers());
    }

    public function testGuardResurrectQueueTasksRequeuesStalledResurrectLaunchTask(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->invokePrivate($orchestrator, 'initializeMainLoopFiberScheduler');
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            launchId: 'guarded-worker-lease',
            state: ServiceInstance::STATE_FAILED,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'guarded-worker-lease');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);

        $now = (\hrtime(true) / 1_000_000_000);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => $now - 5.0,
                'delayed' => true,
                'pid' => 0,
                'port' => 18080,
                'slot_id' => 'worker#1',
                'lease_id' => 'guarded-worker-lease',
                'generation' => 1,
                'launching' => true,
                'launchingAt' => $now - 40.0,
            ],
        ]);

        $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleMainLoopTask', [
            'resurrect_launch:worker:1',
            'resurrect_launch',
            static function (): void {
                \Weline\Server\Scheduler\SchedulerSystem::yieldDelay(60000);
            },
        ]);
        self::assertTrue($scheduled);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        $tasks['resurrect_launch:worker:1']['startedAt'] = $now - 40.0;
        $this->writePrivate($orchestrator, 'mainLoopTasks', $tasks);

        $this->invokePrivateWithArgs($orchestrator, 'guardResurrectQueueTasks', [$now]);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        self::assertArrayNotHasKey('resurrect_launch:worker:1', $tasks);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);
        self::assertArrayNotHasKey('launching', $queue['worker:1']);
        self::assertArrayNotHasKey('launchingAt', $queue['worker:1']);
        self::assertSame(1.0, $queue['worker:1']['restartDelay']);
        self::assertGreaterThan($now, $queue['worker:1']['scheduledAt']);
        self::assertTrue((bool) $queue['worker:1']['delayed']);

        $scheduler = $this->readPrivate($orchestrator, 'mainLoopFiberScheduler');
        self::assertNotNull($scheduler);
        self::assertSame(0, $scheduler->getActiveFiberCount());
        self::assertFalse($scheduler->hasPendingTimers());
    }

    public function testResurrectQueueMainLoopTaskIsNotScheduledWhenQueueIsEmpty(): void
    {
        $orchestrator = new ServiceOrchestrator();

        $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleResurrectQueueMainLoopTaskIfDue', [
            (\hrtime(true) / 1_000_000_000),
        ]);

        self::assertFalse($scheduled);
        self::assertSame([], $this->readPrivate($orchestrator, 'mainLoopTasks'));
        self::assertNull($this->readPrivate($orchestrator, 'mainLoopFiberScheduler'));
    }

    public function testResurrectQueueMainLoopTaskIsNotScheduledForOnlyFutureWork(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $now = (\hrtime(true) / 1_000_000_000);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 30.0,
                'scheduledAt' => $now + 30.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 18080,
            ],
        ]);

        $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleResurrectQueueMainLoopTaskIfDue', [$now]);

        self::assertFalse($scheduled);
        self::assertSame([], $this->readPrivate($orchestrator, 'mainLoopTasks'));
        self::assertNull($this->readPrivate($orchestrator, 'mainLoopFiberScheduler'));
    }

    public function testResurrectQueueMainLoopTaskIsScheduledForDueWork(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $now = (\hrtime(true) / 1_000_000_000);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => $now - 1.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 18080,
            ],
        ]);

        try {
            $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleResurrectQueueMainLoopTaskIfDue', [$now]);

            self::assertTrue($scheduled);
            $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
            self::assertArrayHasKey('periodic:resurrect_queue', $tasks);
        } finally {
            $this->invokePrivate($orchestrator, 'resetMainLoopFiberScheduler');
        }
    }

    public function testMaintenanceResurrectQueueCleanupStillRunsWhenMaintenanceModeIsOff(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $now = (\hrtime(true) / 1_000_000_000);
        $this->writePrivate($orchestrator, 'maintenanceMode', false);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'maintenance:1' => [
                'role' => ControlMessage::ROLE_MAINTENANCE,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 30.0,
                'scheduledAt' => $now + 30.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 19080,
            ],
        ]);

        self::assertTrue($this->invokePrivateWithArgs($orchestrator, 'hasDueResurrectQueueWork', [$now]));

        $this->invokePrivate($orchestrator, 'processResurrectQueue');

        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
    }

    public function testWorkerEmergencyFenceBlocksCompetingRecoveryWriters(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_WORKER)) {
            $registry->registerProvider(new WorkerProvider());
        }

        $context = $this->createWorkerInfraContext();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-emergency-frozen-generation',
            pid: 0,
            port: 18081,
            state: ServiceInstance::STATE_FAILED,
            startedAt: (\hrtime(true) / 1_000_000_000) - 30.0,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-emergency-frozen-generation');
        $worker->setMeta('generation', 1);
        $registry->addInstance($worker);

        $queued = [
            'role' => ControlMessage::ROLE_WORKER,
            'instanceId' => 1,
            'maxRestarts' => 10,
            'restartDelay' => 0.0,
            'scheduledAt' => (\hrtime(true) / 1_000_000_000) - 1.0,
            'delayed' => false,
            'pid' => 0,
            'port' => 18081,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-emergency-frozen-generation',
            'generation' => 1,
        ];
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 1,
        ]);
        $this->writePrivate($orchestrator, 'resurrectQueue', ['worker:1' => $queued]);
        $this->writePrivate($orchestrator, 'workerEmergencyRestartInProgress', true);

        $this->invokePrivate($orchestrator, 'processResurrectQueue');
        $this->invokePrivateWithArgs($orchestrator, 'reconcileRoleSlotGaps', [
            ControlMessage::ROLE_WORKER,
        ]);

        self::assertSame(['worker:1' => $queued], $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertSame($worker, $registry->getInstance(ControlMessage::ROLE_WORKER, 1));

        $this->writePrivate($orchestrator, 'resurrectQueue', []);
        $this->invokePrivateWithArgs($orchestrator, 'scheduleResurrectionWithDelay', [
            $worker,
            0.0,
        ]);

        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertSame($worker, $registry->getInstance(ControlMessage::ROLE_WORKER, 1));
    }

    public function testWorkerEmergencyFenceBlocksDesiredStateDuplicateLaunch(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_WORKER)) {
            $registry->registerProvider(new WorkerProvider());
        }

        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 1,
        ]);
        $this->writePrivate($orchestrator, 'workerEmergencyRestartInProgress', true);

        $this->invokePrivate($orchestrator, 'reconcileDesiredState');

        self::assertNull($registry->getInstance(ControlMessage::ROLE_WORKER, 1));
    }

    public function testLaunchingResurrectQueueEntryIsGuardedButDoesNotScheduleDuplicatePeriodicTask(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->invokePrivate($orchestrator, 'initializeMainLoopFiberScheduler');
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            launchId: 'guarded-worker-lease',
            state: ServiceInstance::STATE_FAILED,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'guarded-worker-lease');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);

        $now = (\hrtime(true) / 1_000_000_000);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => $now - 5.0,
                'delayed' => true,
                'pid' => 0,
                'port' => 18080,
                'slot_id' => 'worker#1',
                'lease_id' => 'guarded-worker-lease',
                'generation' => 1,
                'launching' => true,
                'launchingAt' => $now - 40.0,
            ],
        ]);

        try {
            $scheduledLaunch = $this->invokePrivateWithArgs($orchestrator, 'scheduleMainLoopTask', [
                'resurrect_launch:worker:1',
                'resurrect_launch',
                static function (): void {
                    \Weline\Server\Scheduler\SchedulerSystem::yieldDelay(60000);
                },
            ]);
            self::assertTrue($scheduledLaunch);

            $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
            $tasks['resurrect_launch:worker:1']['startedAt'] = $now - 40.0;
            $this->writePrivate($orchestrator, 'mainLoopTasks', $tasks);

            $scheduledPeriodic = $this->invokePrivateWithArgs(
                $orchestrator,
                'scheduleResurrectQueueMainLoopTaskIfDue',
                [$now]
            );

            self::assertFalse($scheduledPeriodic);
            $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
            self::assertArrayNotHasKey('resurrect_launch:worker:1', $tasks);
            self::assertArrayNotHasKey('periodic:resurrect_queue', $tasks);

            $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
            self::assertArrayHasKey('worker:1', $queue);
            self::assertArrayNotHasKey('launching', $queue['worker:1']);
            self::assertArrayNotHasKey('launchingAt', $queue['worker:1']);
            self::assertGreaterThan($now, $queue['worker:1']['scheduledAt']);
            self::assertTrue((bool) $queue['worker:1']['delayed']);
        } finally {
            $this->invokePrivate($orchestrator, 'resetMainLoopFiberScheduler');
        }
    }

    public function testRecoverFromDispatcherAlertQueuesWorkerResurrectionWhenDispatcherReportsAllWorkersUnavailable(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new class extends WorkerProvider {
            public function isEnabled(ServiceContext $context): bool
            {
                return false;
            }
        });
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 1,
        ]);

        $controlServer = $this->createMock(\Weline\Server\Service\Control\ControlPlaneServerInterface::class);
        $controlServer->method('clientExists')->willReturn(false);
        $this->writePrivate($orchestrator, 'controlServer', $controlServer);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-alert-worker-1',
            state: ServiceInstance::STATE_FAILED,
            pid: 4567,
            port: 18080,
            startedAt: (\hrtime(true) / 1_000_000_000) - 60.0,
            ipcClientId: 321,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'dispatcher-alert-worker-1');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);

        $decision = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'all_workers_unavailable',
            [
                'business_pool' => [16895, 16896],
                'maintenance_candidates' => [16995],
                'maintenance_port' => 0,
            ],
        ]);

        self::assertTrue($decision['eligible']);
        self::assertTrue($decision['recovery_dispatched']);
        self::assertSame('dispatcher_alert_recovery', $decision['reason']);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);

        $worker = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertNull($worker->ipcClientId);
    }

    public function testRecoverFromDispatcherAlertQueuesSpecificFailedWorkerPortEvenWhenIpcIsAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new class extends WorkerProvider {
            public function isEnabled(ServiceContext $context): bool
            {
                return false;
            }
        });
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '18080,18081');

        $controlServer = $this->createMock(\Weline\Server\Service\Control\ControlPlaneServerInterface::class);
        $controlServer->method('clientExists')->willReturn(true);
        $controlServer->expects(self::once())->method('closeClient')->with(321);
        $this->writePrivate($orchestrator, 'controlServer', $controlServer);

        $failedWorker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-health-failed',
            state: ServiceInstance::STATE_FAILED,
            pid: 0,
            port: 18080,
            startedAt: (\hrtime(true) / 1_000_000_000) - 60.0,
            ipcClientId: 321,
        );
        $failedWorker->setMeta('slot_id', 'worker#1');
        $failedWorker->setMeta('lease_id', 'worker-health-failed');
        $failedWorker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($failedWorker);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'worker-health-ok',
            state: ServiceInstance::STATE_READY,
            pid: 0,
            port: 18081,
            startedAt: (\hrtime(true) / 1_000_000_000) - 60.0,
            ipcClientId: 322,
        ));

        $decision = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'worker_health_probe_failed',
            [
                'business_pool' => [18081],
                'failed_ports' => [18080],
                'failed_reasons' => [18080 => 'HTTP/1.1 503 Service Unavailable'],
            ],
        ]);

        self::assertTrue($decision['eligible']);
        self::assertTrue($decision['recovery_dispatched']);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);
        self::assertArrayNotHasKey('worker:2', $queue);
        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));

        $worker = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertNull($worker->ipcClientId);
        self::assertSame('HTTP/1.1 503 Service Unavailable', $worker->getMeta('dispatcher_health_failed_reason'));
    }

    public function testRecoverFromDispatcherAlertIsThrottledDuringStorm(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'running', true);

        $first = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'all_workers_unavailable',
            [
                'business_pool' => [16895, 16896],
                'maintenance_candidates' => [16995],
                'maintenance_port' => 0,
            ],
        ]);
        $second = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'all_workers_unavailable',
            [
                'business_pool' => [16895, 16896],
                'maintenance_candidates' => [16995],
                'maintenance_port' => 0,
            ],
        ]);

        self::assertTrue($first['eligible']);
        self::assertTrue($first['recovery_dispatched']);
        self::assertTrue($second['eligible']);
        self::assertFalse($second['recovery_dispatched']);
        self::assertSame('dispatcher_alert_cooldown', $second['reason']);
    }

    public function testWindowsDispatcherAcceptsOnlyItsRetainedStartupListenerHandoff(): void
    {
        $instanceName = 'ut-windows-startup-listener';
        $port = 29522;
        $leaseId = \str_repeat('2', 32);
        $launchId = \str_repeat('a', 32);
        $intentDigest = \str_repeat('d', 64);
        $intent = [
            'schema_version' => 1,
            'transport' => WindowsListenerHandoff::TRANSPORT,
            'continuous_ownership' => true,
            'handoff_id' => \str_repeat('1', 32),
            'lease_id' => $leaseId,
            'instance' => $instanceName,
            'wls_instance' => $instanceName,
            'bind_host' => '127.0.0.1',
            'port' => $port,
            'launch_id' => $launchId,
            'master_path' => 'C:\\wls-test\\master.json',
            'intent_digest' => $intentDigest,
        ];
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 29521,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: $port,
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
                    'gateway' => [
                        'launch_id' => $launchId,
                        'public_lease' => [
                            'schema_version' => 6,
                            'instance' => $instanceName,
                            'port' => $port,
                            'lease_id' => $leaseId,
                            'bind_host' => '127.0.0.1',
                        ],
                        'startup_listener_handoff' => $intent,
                    ],
                ],
            ],
            workerCount: 1,
            workerBasePort: $port,
            workerPort: $port,
        );
        $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);

        $handoffReflection = new \ReflectionClass(WindowsListenerHandoff::class);
        $sourcesProperty = $handoffReflection->getProperty('masterSources');
        $primaryProperty = $handoffReflection->getProperty('primaryIntentDigest');
        $originalSources = $sourcesProperty->getValue();
        $originalPrimary = $primaryProperty->getValue();
        $sourcesProperty->setValue(null, [
            $intentDigest => [
                'socket' => $socket,
                'stream' => null,
                'intent' => $intent,
            ],
        ]);
        $primaryProperty->setValue(null, $intentDigest);

        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return true;
            }
        };
        $this->writePrivate($orchestrator, 'context', $context);

        try {
            $this->invokePrivateWithArgs(
                $orchestrator,
                'ensureDirectSharedListenerForRole',
                [ControlMessage::ROLE_DISPATCHER, $context],
            );
            self::addToAssertionCount(1);
            self::assertTrue($this->invokePrivateWithArgs(
                $orchestrator,
                'isMasterOwnedSharedListenerPort',
                [ControlMessage::ROLE_DISPATCHER, $port],
            ));

            $dispatcherName = 'weline-wls-dispatcher-' . $instanceName . '-p39912e55';
            $dispatcher = new ServiceInstance(
                role: ControlMessage::ROLE_DISPATCHER,
                instanceId: 1,
                epoch: 1,
                launchId: $launchId,
                pid: 7372,
                port: $port,
                state: ServiceInstance::STATE_STARTING,
            );
            $dispatcher->setMeta('process_name', $dispatcherName);
            $bootstrapOwnedInspect = [
                'in_use' => true,
                'pid' => 10256,
                'pid_running' => true,
                'is_weline' => false,
                'state' => 'foreign',
                'pname' => '--name=' . $dispatcherName,
                'kernel_listener_pid' => 10256,
                'kernel_listener_pname' => 'php.exe bin\\w server:start ' . $instanceName,
                'port_index_advisory_pname' => '--name=' . $dispatcherName,
            ];
            self::assertTrue(
                $this->invokePrivateWithArgs(
                    $orchestrator,
                    'isLaunchPortOwnedByInstance',
                    [$dispatcher, $bootstrapOwnedInspect],
                ),
                'The exact retained listener handoff must outrank Windows bootstrap-PID attribution.',
            );

            $sourcesProperty->setValue(null, []);
            self::assertFalse(
                $this->invokePrivateWithArgs(
                    $orchestrator,
                    'isLaunchPortOwnedByInstance',
                    [$dispatcher, $bootstrapOwnedInspect],
                ),
                'A bootstrap-attributed listener without the exact retained source must remain foreign.',
            );
            try {
                $this->invokePrivateWithArgs(
                    $orchestrator,
                    'ensureDirectSharedListenerForRole',
                    [ControlMessage::ROLE_DISPATCHER, $context],
                );
                self::fail('Windows startup must reject a listener handoff that the Master no longer owns.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Windows Dispatcher startup requires the Master-owned listener source copy.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $sourcesProperty->setValue(null, $originalSources);
            $primaryProperty->setValue(null, $originalPrimary);
            @\socket_close($socket);
        }
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

    private function createFrontendContext(array $orchestratorConfig = []): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'frontend-unix',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'orchestrator' => $orchestratorConfig,
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
    }

    private function createWorkerInfraContext(?string $instanceName = null, array $serverConfig = []): ServiceContext
    {
        $instanceName ??= 'ut-orchestrator-' . \bin2hex(\random_bytes(6));
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                ...($serverConfig !== [] ? ['server' => $serverConfig] : []),
                'session' => ['server_port' => 19970],
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'public_origin' => 'http://127.0.0.1:8080',
                    'session' => [
                        'port' => 19970,
                        'token_file_name' => 'session_server.token',
                        'wls_server' => [
                            'port' => 19970,
                            'token_file_name' => 'session_server.token',
                        ],
                    ],
                    'memory_service' => [
                        'enabled' => true,
                        'port' => 19971,
                        'token_file_name' => 'memory_server.token',
                    ],
                    'runtime' => [
                        'container_registry_digest' => self::TEST_CONTAINER_DIGEST,
                    ],
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );

        return $this->persistTestContext($context);
    }

    private function createWorkerInfraContextForInstance(string $instanceName): ServiceContext
    {
        return $this->createWorkerInfraContext($instanceName);
    }

    private function persistTestContext(ServiceContext $context): ServiceContext
    {
        $protocolSelection = HttpProtocolSelection::fromConfig(
            [
                'edge' => ['adapter' => 'wls'],
                'http' => \is_array($context->envConfig['wls']['http'] ?? null)
                    ? $context->envConfig['wls']['http']
                    : [],
            ],
            $context->sslEnabled,
        );
        $publicOrigin = PureWlsPublicOrigin::fromHostAndPort(
            $context->publicHost ?? $context->host,
            $context->mainPort,
            $context->sslEnabled,
        );
        $manager = new ServerInstanceManager();
        $manager->saveInstance($context->instanceName, [
            'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
            'instance_name' => $context->instanceName,
            'runtime_selection' => $context->runtimeSelection->toArray(),
            'edge_adapter' => 'wls',
            'public_origin' => $publicOrigin,
            'http_protocol_selection' => $protocolSelection->toArray(),
            'host' => $context->host,
            'public_host' => $context->publicHost,
            'port' => $context->mainPort,
            'main_port' => $context->mainPort,
            'control_port' => $context->controlPort,
            'master_pid' => $context->masterPid,
            'count' => $context->getWorkerCount(),
            'worker_port' => $context->getWorkerPort(),
            'worker_base_port' => $context->getWorkerBasePort(),
            'ssl_enabled' => $context->sslEnabled,
            'ssl_cert' => $context->sslCert,
            'ssl_key' => $context->sslKey,
            'http_redirect_port' => $context->httpRedirectPort,
            'startup_phase' => 'starting',
        ]);
        $this->registerFileCleanup($manager->getInstanceFile($context->instanceName));

        return $context;
    }

    /** @return array<string, mixed> */
    private function readyCapabilityPayload(ServiceContext $context, string $role): array
    {
        $topology = $context->getEffectiveTopology()->value;
        $direct = $topology === 'direct';

        return [
            'readiness_protocol_version' => WorkerReadinessState::READINESS_PROTOCOL_VERSION,
            'readiness_capabilities' => [
                WorkerReadinessState::CAPABILITY_DYNAMIC_FIRST_RENDER_PROOF,
                WorkerReadinessState::CAPABILITY_COMPILED_CONTAINER_DIGEST,
            ],
            'topology' => $topology,
            'policy_digest' => self::TEST_POLICY_DIGEST,
            'container_registry_digest' => self::TEST_CONTAINER_DIGEST,
            'warmup_state' => $role === ControlMessage::ROLE_MAINTENANCE ? 'ready' : 'hot',
            'homepage_fpc' => [
                'hit' => true,
                'fpc_status' => 'HIT',
                'source' => 'process',
                'full_uri' => 'http://example.test/',
                'http_status' => 200,
            ],
            'dynamic_first_render' => [
                'ready' => true,
                'host' => 'example.test',
                'path' => '/',
                'status_code' => 200,
                'body_length' => 1024,
                'elapsed_ms' => 5.0,
                'target_ms' => 70.0,
                'attempts' => 1,
                'fpc_status' => 'MISS',
                'cache' => 'bypass',
                'reason' => 'rendered',
            ],
            'listen_capabilities' => [
                'bound' => true,
                'reuseport' => $direct,
                'mode' => $direct ? 'reuseport' : 'single',
                'event_loop' => 'select',
                'ssl_engine' => 'stream',
            ],
        ];
    }

    /**
     * PHPUnit CLI 涓?Runtime::isWls() 甯镐负 false锛孲chedulerWaitObserver 涓嶄細娉ㄥ唽 yield 瀹氭椂鍣紝
     * 鎸傝捣鐨?stop_all Fiber 闇€鎵嬪姩 resume 鎵嶈兘鎵ц闂寘鍐呯殑 stopAll()銆?
     */
    private function drainOrchestratorMainLoopTasks(ServiceOrchestrator $orchestrator): void
    {
        $prop = $this->findProperty($orchestrator, 'mainLoopTasks');
        $prop->setAccessible(true);
        /** @var array<string, array{fiber:\Fiber, label:string, startedAt:float}> $tasks */
        $tasks = $prop->getValue($orchestrator);
        foreach ($tasks as $entry) {
            $fiber = $entry['fiber'] ?? null;
            if ($fiber instanceof \Fiber && $fiber->isSuspended()) {
                $fiber->resume();
            }
        }
        for ($i = 0; $i < 16; $i++) {
            $this->invokePrivate($orchestrator, 'tickMainLoopTasks');
        }
    }

    private function registerFileCleanup(string $file): void
    {
        \register_shutdown_function(static function () use ($file): void {
            if (\is_file($file)) {
                @\unlink($file);
            }
            if (\is_file($file . '.lock')) {
                @\unlink($file . '.lock');
            }
        });
    }

    /** @return array<string,mixed> */
    private static function gatewayCapabilityLaunch(
        int $instanceGeneration,
        string $launchId,
    ): array {
        $evidence = [
            'schema' => 'wls-session-capability/1',
            'reason' => 'unit-isolated',
        ];
        return [
            'schema' => \Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityResolver::LAUNCH_SNAPSHOT_SCHEMA,
            'instance_generation' => $instanceGeneration,
            'launch_id' => $launchId,
            'mode' => 'isolated',
            'evidence' => $evidence,
            'evidence_digest' => \hash(
                'sha256',
                \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($evidence),
            ),
        ];
    }

    public function testAdoptDeferredChildStartupAllowsClearingNativeServingManifestRecovery(): void
    {
        $digest = \str_repeat('a', 64);
        $baseEnv = [
            'wls' => [
                'edge' => ['adapter' => 'wls'],
                'gateway' => [
                    'instance_generation' => 7,
                    'mode' => 'wls',
                    NativeServingManifestStartupRecovery::CONFIG_KEY => [
                        'schema' => NativeServingManifestStartupRecovery::SCHEMA,
                        'instance_id' => 'ut-deferred-fence',
                        'generation' => 3,
                        'digest' => $digest,
                    ],
                ],
            ],
        ];
        $before = new ServiceContext(
            instanceName: 'ut-deferred-fence',
            epoch: 1,
            controlPort: 19991,
            masterPid: 4242,
            host: '127.0.0.1',
            mainPort: 9510,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: false,
            envConfig: $baseEnv,
            workerCount: 1,
            workerBasePort: 9510,
            workerPort: 9510,
            controlToken: 'token-before',
            masterLeaseFile: '/tmp/ut-master.lease',
            masterToken: 'lease-token',
        );
        $afterEnv = $baseEnv;
        unset($afterEnv['wls']['gateway'][NativeServingManifestStartupRecovery::CONFIG_KEY]);
        $afterEnv['wls']['serving_manifest_path'] = '/tmp/ut-serving-manifest.json';
        $afterEnv['wls']['serving_manifest_generation'] = 3;
        $afterEnv['wls']['serving_manifest_digest'] = $digest;
        $afterEnv['wls']['serving_instance_generation'] = 7;
        $afterEnv['wls']['serving_certificate_trust_profile'] = 'test';
        $after = new ServiceContext(
            instanceName: 'ut-deferred-fence',
            epoch: 1,
            controlPort: 19991,
            masterPid: 4242,
            host: '127.0.0.1',
            mainPort: 9510,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: false,
            envConfig: $afterEnv,
            workerCount: 1,
            workerBasePort: 9510,
            workerPort: 9510,
            controlToken: 'token-before',
            masterLeaseFile: '/tmp/ut-master.lease',
            masterToken: 'lease-token',
        );

        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $before);
        $this->invokePrivateWithArgs($orchestrator, 'adoptDeferredChildStartupContext', [$after]);

        $adopted = $this->readPrivate($orchestrator, 'context');
        self::assertInstanceOf(ServiceContext::class, $adopted);
        self::assertSame(3, (int)$adopted->getConfig('wls.serving_manifest_generation', 0));
        self::assertSame(
            'test',
            $adopted->getConfig('wls.serving_certificate_trust_profile', ''),
        );
        self::assertArrayNotHasKey(
            NativeServingManifestStartupRecovery::CONFIG_KEY,
            \is_array($adopted->envConfig['wls']['gateway'] ?? null)
                ? $adopted->envConfig['wls']['gateway']
                : [],
        );
    }

    public function testAdoptDeferredChildStartupRejectsNonFenceEnvDrift(): void
    {
        $before = new ServiceContext(
            instanceName: 'ut-deferred-fence-drift',
            epoch: 1,
            controlPort: 19992,
            masterPid: 4243,
            host: '127.0.0.1',
            mainPort: 9511,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'worker_count' => 2,
                ],
            ],
            workerCount: 2,
            workerBasePort: 9511,
            workerPort: 9511,
            controlToken: 'token-drift',
            masterLeaseFile: '/tmp/ut-master-drift.lease',
            masterToken: 'lease-token-drift',
        );
        $after = new ServiceContext(
            instanceName: 'ut-deferred-fence-drift',
            epoch: 1,
            controlPort: 19992,
            masterPid: 4243,
            host: '127.0.0.1',
            mainPort: 9511,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: self::runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'worker_count' => 4,
                ],
            ],
            workerCount: 2,
            workerBasePort: 9511,
            workerPort: 9511,
            controlToken: 'token-drift',
            masterLeaseFile: '/tmp/ut-master-drift.lease',
            masterToken: 'lease-token-drift',
        );

        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $before);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Deferred child startup configuration changed outside the serving manifest fence.',
        );
        $this->invokePrivateWithArgs($orchestrator, 'adoptDeferredChildStartupContext', [$after]);
    }

    public function testWindowsCredentialAuthorizationRecoversOnlyAnExactPublishedChildPid(): void
    {
        $startedAt = \time();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: 7,
            launchId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            startedAt: $startedAt,
        );
        $dispatcher->setMeta('process_name', 'weline-wls-dispatcher-pid-recovery');
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 7,
            launchId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            startedAt: $startedAt,
        );
        $worker->setMeta('process_name', 'weline-wls-worker-pid-recovery');
        $maintenance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            epoch: 7,
            launchId: 'cccccccccccccccccccccccccccccccc',
            startedAt: $startedAt,
        );
        $maintenance->setMeta('process_name', 'weline-wls-maintenance-pid-recovery');

        $orchestrator = new class($startedAt) extends ServiceOrchestrator {
            /** @var list<string> */
            public array $leaseReads = [];
            /** @var list<int> */
            public array $birthCaptures = [];

            public function __construct(private readonly int $leaseTime)
            {
            }

            /** @param array<string|int,ServiceInstance> $instances */
            public function recover(array $instances, array $pids): array
            {
                return $this->recoverPublishedWindowsChildPids($instances, $pids);
            }

            protected function isWindowsRuntime(): bool
            {
                return true;
            }

            protected function readPublishedWindowsChildLease(string $expectedPname): array
            {
                $this->leaseReads[] = $expectedPname;
                $isDispatcher = \str_contains($expectedPname, 'dispatcher');

                return [
                    'pid' => $isDispatcher ? 4242 : 5252,
                    'time' => $this->leaseTime,
                    'pname' => $expectedPname,
                    'pname_key' => $expectedPname,
                    'process_name' => \substr($expectedPname, \strlen('--name=')),
                    'launch_id' => $isDispatcher
                        ? 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
                        : 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                    'epoch' => 7,
                ];
            }

            protected function probePublishedWindowsChildPids(array $requests): array
            {
                $probes = [];
                foreach ($requests as $key => $request) {
                    $pid = (int)($request['pid'] ?? 0);
                    $probes[$key] = [
                        'pid' => $pid,
                        'state' => Processer::PROCESS_STATE_UNKNOWN,
                        'reason' => $pid === 4242
                            ? 'live_identity_unavailable'
                            : 'live_identity_mismatch',
                    ];
                }

                return $probes;
            }

            protected function capturePublishedWindowsChildProcessIdentity(int $pid): array
            {
                $this->birthCaptures[] = $pid;

                return $pid === 4242
                    ? [
                        'birth' => \str_repeat('d', 64),
                        'pid_namespace_id' => '',
                    ]
                    : [];
            }
        };

        self::assertSame([
            'dispatcher#1' => 4242,
            'worker#1' => 0,
            'maintenance#1' => 6363,
        ], $orchestrator->recover([
            'dispatcher#1' => $dispatcher,
            'worker#1' => $worker,
            'maintenance#1' => $maintenance,
        ], [
            'dispatcher#1' => 0,
            'worker#1' => 0,
            'maintenance#1' => 6363,
        ]));
        self::assertSame([
            '--name=weline-wls-dispatcher-pid-recovery',
            '--name=weline-wls-worker-pid-recovery',
        ], $orchestrator->leaseReads);
        self::assertSame([4242], $orchestrator->birthCaptures);
    }

    public function testWindowsCredentialAuthorizationWaitsForALateExactManagedLease(): void
    {
        $startedAt = \time();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 7,
            launchId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            startedAt: $startedAt,
        );
        $worker->setMeta('process_name', 'weline-wls-worker-late-pid-recovery');

        $orchestrator = new class($startedAt) extends ServiceOrchestrator {
            public int $leaseReads = 0;
            public int $waits = 0;

            public function __construct(private readonly int $leaseTime)
            {
            }

            /** @param array<string|int,ServiceInstance> $instances */
            public function recover(array $instances, array $pids): array
            {
                return $this->recoverPublishedWindowsChildPids($instances, $pids);
            }

            protected function isWindowsRuntime(): bool
            {
                return true;
            }

            protected function publishedWindowsChildPidRecoveryDeadline(): float
            {
                return 10.0;
            }

            protected function publishedWindowsChildPidRecoveryNow(): float
            {
                return (float)$this->leaseReads;
            }

            protected function waitForPublishedWindowsChildPidRecovery(): void
            {
                ++$this->waits;
            }

            protected function readPublishedWindowsChildLease(string $expectedPname): array
            {
                ++$this->leaseReads;
                if ($this->leaseReads < 3) {
                    return [];
                }

                return [
                    'pid' => 4242,
                    'time' => $this->leaseTime,
                    'pname' => $expectedPname,
                    'pname_key' => $expectedPname,
                    'process_name' => \substr($expectedPname, \strlen('--name=')),
                    'launch_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                    'epoch' => 7,
                ];
            }

            protected function probePublishedWindowsChildPids(array $requests): array
            {
                return [
                    'worker#1' => [
                        'pid' => 4242,
                        'state' => Processer::PROCESS_STATE_UNKNOWN,
                        'reason' => 'live_identity_unavailable',
                    ],
                ];
            }

            protected function capturePublishedWindowsChildProcessIdentity(int $pid): array
            {
                return $pid === 4242
                    ? [
                        'birth' => \str_repeat('d', 64),
                        'pid_namespace_id' => '',
                    ]
                    : [];
            }
        };

        self::assertSame([
            'worker#1' => 4242,
        ], $orchestrator->recover([
            'worker#1' => $worker,
        ], [
            'worker#1' => 0,
        ]));
        self::assertSame(3, $orchestrator->leaseReads);
        self::assertSame(2, $orchestrator->waits);
    }

    private static function cleanupGatewayBackendTokenState(string $instanceName): void
    {
        $tokenFile = GatewayBackendIngressTokenStore::tokenFile($instanceName);
        @\unlink($tokenFile);
        @\unlink(\dirname($tokenFile) . DIRECTORY_SEPARATOR . '.state.lock');
        @\rmdir(\dirname($tokenFile));
    }

    private function invokePrivate(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    private function invokePrivateWithArgs(object $object, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function readPrivateBool(object $object, string $property): bool
    {
        return (bool) $this->readPrivate($object, $property);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = $this->findProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = $this->findProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function stripAnsi(string $value): string
    {
        return (string) \preg_replace('/\e\[[\d;]*m/', '', $value);
    }

    private function displayWidth(string $value): int
    {
        return \mb_strwidth($value, 'UTF-8');
    }

    private function findProperty(object $object, string $property): \ReflectionProperty
    {
        $reflection = new \ReflectionClass($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                return $reflection->getProperty($property);
            }
            $reflection = $reflection->getParentClass();
        }

        throw new \ReflectionException(\sprintf('Property %s::%s does not exist', $object::class, $property));
    }
}
