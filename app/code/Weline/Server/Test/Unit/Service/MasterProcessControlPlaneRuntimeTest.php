<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\Control\HybridControlPlaneServer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\HttpProtocolSelection;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;

final class MasterProcessControlPlaneRuntimeTest extends TestCase
{
    public function testFirstCtrlCRequestsDrainingStop(): void
    {
        $master = new MasterProcess();
        $orchestrator = new class extends ServiceOrchestrator {
            public array $requestStopCalls = [];

            public function requestStop(
                string $reason = 'shutdown',
                ?int $progressClientId = null,
                bool $exclusiveIpc = false,
                bool $skipDrain = false,
                string $msgId = ''
            ): bool {
                $this->requestStopCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                    'exclusiveIpc' => $exclusiveIpc,
                    'skipDrain' => $skipDrain,
                    'msgId' => $msgId,
                ];

                return true;
            }
        };

        $this->writePrivate($master, 'orchestrator', $orchestrator);

        $master->stopWithProgress('Ctrl+C (Windows)');

        self::assertSame([[
            'reason' => 'Ctrl+C (Windows)',
            'progressClientId' => null,
            'exclusiveIpc' => false,
            'skipDrain' => false,
            'msgId' => '',
        ]], $orchestrator->requestStopCalls);
    }

    public function testRepeatCtrlCForcesStopInsteadOfPendingNudge(): void
    {
        $master = new MasterProcess();
        $orchestrator = new class extends ServiceOrchestrator {
            public bool $repeatNudgeCalled = false;

            public function applyRepeatTerminationNudge(): bool
            {
                $this->repeatNudgeCalled = true;

                return true;
            }

            public function forceTerminateMasterAndChildren(string $reason = 'force'): void
            {
                throw new \RuntimeException('forced:' . $reason);
            }
        };

        $this->writePrivate($master, 'stopRequested', true);
        $this->writePrivate($master, 'orchestrator', $orchestrator);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forced:repeat_signal:Ctrl+C (Windows)');

        try {
            $this->invokePrivate(
                $master,
                'handleTerminationSignal',
                ['Ctrl+C (Windows)', 'ignored']
            );
        } finally {
            self::assertFalse($orchestrator->repeatNudgeCalled);
        }
    }

    public function testSaveMasterInfoPersistsHybridSupervisorRuntimeMetadata(): void
    {
        $instanceName = 'ut-master-runtime-' . \bin2hex(\random_bytes(4));
        $master = new MasterProcess();

        $controlServer = new MasterControlServer();
        self::assertTrue($controlServer->start('127.0.0.1', 0));
        $hybrid = new HybridControlPlaneServer(
            controlServer: $controlServer,
            endpointResolver: new ControlEndpointResolver(BP, 28600, 1000),
            supervisorEnabled: true,
            channelId: 'channel-' . $instanceName,
        );
        $hybrid->setExpectedInstanceCode($instanceName);
        $hybrid->onMessage(static function (): void {});
        $hybrid->onDisconnect(static function (): void {});
        self::assertTrue($hybrid->start('127.0.0.1', 0));

        $this->writePrivate($master, 'instanceName', $instanceName);
        $master->setRuntimeSelection(RuntimeSelection::fromArray([
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
        ]));
        $this->writePrivate($master, 'mainPort', 18080);
        $this->writePrivate($master, 'controlPort', $hybrid->getPort());
        $this->writePrivate($master, 'orchestrator', new class($hybrid) extends ServiceOrchestrator {
            public function __construct(private readonly HybridControlPlaneServer $server) {}
            public function getControlServer(): HybridControlPlaneServer
            {
                return $this->server;
            }
        });

        $httpProtocolSelection = HttpProtocolSelection::fromConfig([
            'http' => [
                'protocols' => [HttpProtocolSelection::HTTP_1],
                'preferred' => HttpProtocolSelection::HTTP_1,
                'protocol_edge' => HttpProtocolSelection::EDGE_DISABLED,
                'alt_svc' => false,
            ],
        ], true)->toArray();
        (new ServerInstanceManager())->saveInstance($instanceName, [
            'edge_adapter' => 'nginx',
            'http_protocol_selection' => $httpProtocolSelection,
            'protocol_edge_enabled' => false,
            'protocol_edge_binary' => '',
        ]);

        try {
            $master->saveMasterInfo('bootstrapping');
            $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
            self::assertFileExists($instanceFile);
            $data = \json_decode((string)\file_get_contents($instanceFile), true);
            self::assertIsArray($data);
            self::assertSame('hybrid', $data['control_plane_mode'] ?? null);
            self::assertTrue((bool)($data['supervisor_enabled'] ?? false));
            self::assertSame('channel-' . $instanceName, $data['supervisor_channel'] ?? null);
            self::assertIsString($data['supervisor_endpoint'] ?? null);
            self::assertNotSame('', $data['supervisor_endpoint'] ?? '');
            self::assertSame($hybrid->getPort(), $data['control_port'] ?? null);
            self::assertSame('nginx', $data['edge_adapter'] ?? null);
            self::assertSame($httpProtocolSelection, $data['http_protocol_selection'] ?? null);
            self::assertFalse((bool)($data['protocol_edge_enabled'] ?? true));
            self::assertSame('', $data['protocol_edge_binary'] ?? null);
        } finally {
            $hybrid->close();
            $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
            if (\is_file($instanceFile)) {
                @\unlink($instanceFile);
            }
        }
    }

    public function testGetMasterInfoReadsMasterEndpointMetadata(): void
    {
        $instanceName = 'ut-master-endpoint-' . \bin2hex(\random_bytes(4));
        $manager = new ServerInstanceManager();
        $instanceFile = $manager->getInstanceFile($instanceName);

        try {
            ServerInstanceManager::atomicWriteJsonStatic($instanceFile, [
                'lifecycle_state' => 'running',
                'startup_phase' => 'running',
                'master_enabled' => true,
                'master_pid' => 60284,
                'control_port' => 26895,
            ], 5);

            $info = MasterProcess::getMasterEndpoint($instanceName);

            self::assertIsArray($info);
            self::assertTrue((bool)($info['master_enabled'] ?? false));
            self::assertSame(60284, $info['master_pid'] ?? null);
            self::assertSame(26895, $info['control_port'] ?? null);
        } finally {
            if (\is_file($instanceFile)) {
                @\unlink($instanceFile);
            }
            if (\is_file($instanceFile . '.lock')) {
                @\unlink($instanceFile . '.lock');
            }
        }
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
