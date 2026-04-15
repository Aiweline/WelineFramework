<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\ChildControl;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\MasterOrphanGuard;
use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\Supervisor\Client\SupervisorChildClient;
use Weline\Server\Supervisor\Endpoint\ControlEndpoint;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;
use Weline\Server\Supervisor\Lease\LeaseRegistry;
use Weline\Server\Supervisor\Supervisor;
use Weline\Server\Supervisor\SupervisorRuntime;
use Weline\Server\Supervisor\SupervisorServer;

final class SubprocessControlKernelTest extends TestCase
{
    public function testResolveControlPortFromInstanceFile(): void
    {
        $instanceName = 'ut-kernel-port';
        $instanceDir = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances';
        $instanceFile = $instanceDir . DIRECTORY_SEPARATOR . $instanceName . '.json';
        if (!\is_dir($instanceDir)) {
            @\mkdir($instanceDir, 0777, true);
        }

        \file_put_contents($instanceFile, \json_encode([
            'control_port' => 19091,
            'updated_at' => \time(),
        ]));
        try {
            $resolved = SubprocessControlKernel::resolveControlPort($instanceName, 0);
            $this->assertSame(19091, $resolved);
            $this->assertSame(18888, SubprocessControlKernel::resolveControlPort($instanceName, 18888));
        } finally {
            @\unlink($instanceFile);
        }
    }

    public function testMasterOrphanGuardShortCircuit(): void
    {
        $guard = new MasterOrphanGuard();

        $this->assertFalse($guard->shouldExit(0, false, false, 'UT'));
        $this->assertFalse($guard->shouldExit(1234, false, true, 'UT'));
    }

    public function testResolveReadyDelayMillisecondsUsesRoleSpecificEnv(): void
    {
        \putenv('WLS_E2E_WORKER_READY_DELAY_MS=4500');
        \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS=1200');
        try {
            $this->assertSame(4500, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_WORKER));
            $this->assertSame(1200, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_MAINTENANCE));
            $this->assertSame(0, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_DISPATCHER));
        } finally {
            \putenv('WLS_E2E_WORKER_READY_DELAY_MS');
            \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS');
        }
    }

    public function testResolveServicePortFromInstanceFile(): void
    {
        $instanceName = 'ut-kernel-service-port';
        $instanceDir = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances';
        $instanceFile = $instanceDir . DIRECTORY_SEPARATOR . $instanceName . '.json';
        if (!\is_dir($instanceDir)) {
            @\mkdir($instanceDir, 0777, true);
        }

        \file_put_contents($instanceFile, \json_encode([
            'session_port' => 19970,
            'memory_port' => 19971,
            'session_service_updated_at' => \time(),
            'memory_service_updated_at' => \time(),
        ]));
        try {
            $this->assertSame(19970, SubprocessControlKernel::resolveServicePort($instanceName, 'session_port', 1));
            $this->assertSame(19971, SubprocessControlKernel::resolveServicePort($instanceName, 'memory_port', 1));
            $this->assertSame(0, SubprocessControlKernel::resolveServicePort($instanceName, 'missing_port', 0));
        } finally {
            @\unlink($instanceFile);
        }
    }

    public function testResolveReadyDelayMillisecondsClampsInvalidValues(): void
    {
        \putenv('WLS_E2E_WORKER_READY_DELAY_MS=-5');
        \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS=999999');
        try {
            $this->assertSame(0, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_WORKER));
            $this->assertSame(60000, SubprocessControlKernel::resolveReadyDelayMilliseconds(ControlMessage::ROLE_MAINTENANCE));
        } finally {
            \putenv('WLS_E2E_WORKER_READY_DELAY_MS');
            \putenv('WLS_E2E_MAINTENANCE_READY_DELAY_MS');
        }
    }

    public function testConnectAndRegisterFailureStillAllowsLaterReconnect(): void
    {
        $probeServer = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($probeServer, (string) $errstr);
        $probeName = \stream_socket_get_name($probeServer, false);
        self::assertIsString($probeName);
        $parts = \explode(':', $probeName);
        $port = (int) \end($parts);
        @\fclose($probeServer);

        $identity = new ChildProcessIdentity(
            ControlMessage::ROLE_WORKER,
            \getmypid(),
            19091,
            1,
            7,
            'ut-reconnect-launch'
        );
        $handler = new class implements RoleControlHandlerInterface {
            public function onMessage(array $message, SubprocessControlKernel $kernel): void
            {
            }

            public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
            {
            }
        };

        $kernel = new SubprocessControlKernel($identity, $handler, 'UT-Kernel', false, 'ut-instance');

        self::assertFalse($kernel->connectAndRegister($port));
        self::assertNotNull($kernel->getClient(), 'kernel should keep client state for later reconnect');

        $server = \stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        self::assertIsResource($server, (string) $errstr);

        try {
            self::assertTrue($kernel->reconnect());

            $conn = \stream_socket_accept($server, 1.0);
            self::assertIsResource($conn);
            \stream_set_timeout($conn, 1);
            \usleep(100000);
            $payload = (string) \stream_get_contents($conn);
            @\fclose($conn);

            self::assertStringContainsString('"type":"register"', $payload);
            self::assertStringContainsString('"type":"ready"', $payload);
            self::assertTrue($kernel->isConnected());
        } finally {
            @\fclose($server);
        }
    }

    public function testKernelCanConnectViaSupervisorClientFactory(): void
    {
        $runtime = new SupervisorRuntime(
            instanceName: 'ut-instance',
            channelId: 'channel-ut-instance',
            endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
            supervisor: new Supervisor(new LeaseRegistry(
                static fn(string $slotId, int $generation): string => "{$slotId}-lease-{$generation}"
            )),
        );
        $server = new SupervisorServer($runtime);
        $endpoint = $server->start(ControlEndpoint::tcp('127.0.0.1', 0));

        $identity = new ChildProcessIdentity(
            ControlMessage::ROLE_WORKER,
            \getmypid(),
            19091,
            1,
            7,
            'ut-supervisor-launch'
        );
        $handler = new class implements RoleControlHandlerInterface {
            public function onMessage(array $message, SubprocessControlKernel $kernel): void
            {
            }

            public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
            {
            }
        };

        $kernel = new SubprocessControlKernel(
            identity: $identity,
            handler: $handler,
            selfTag: 'UT-Supervisor-Kernel',
            verboseLog: false,
            instanceCode: 'ut-instance',
            clientFactory: static function (SubprocessControlKernel $kernel) use ($endpoint, $server): SupervisorChildClient {
                unset($kernel);
                return new SupervisorChildClient(
                    instanceName: 'ut-instance',
                    channelId: 'channel-ut-instance',
                    endpointResolver: new ControlEndpointResolver(BP, 27000, 1000),
                    endpoint: $endpoint,
                    progressCallback: static function () use ($server): void {
                        $server->poll(0, 10000);
                    },
                );
            }
        );

        try {
            self::assertTrue($kernel->connectAndRegister(0));
            self::assertNotNull($kernel->getClient());
            self::assertTrue($kernel->isConnected());

            $slotSnapshot = $runtime->slotSnapshot();
            self::assertSame(2, $slotSnapshot['version']);
            self::assertCount(1, $slotSnapshot['slots']);
            self::assertSame('worker#1', $slotSnapshot['slots'][0]['slot_id']);
            self::assertSame('ready', $slotSnapshot['slots'][0]['state']);

            $poolSnapshot = $runtime->workerPoolSnapshot();
            self::assertSame(1, $poolSnapshot['version']);
            self::assertCount(1, $poolSnapshot['workers']);
            self::assertSame('worker#1', $poolSnapshot['workers'][0]['slot_id']);
        } finally {
            $kernel->close();
            $server->close();
        }
    }

    public function testKernelChoosesSupervisorClientWhenFeatureFlagEnabled(): void
    {
        \putenv('WLS_SUPERVISOR_ENABLED=1');
        \putenv('WLS_SUPERVISOR_CHANNEL=channel-ut-instance');
        \putenv('WLS_SUPERVISOR_BASE_PATH=' . BP);

        $identity = new ChildProcessIdentity(
            ControlMessage::ROLE_WORKER,
            \getmypid(),
            19091,
            1,
            7,
            'ut-supervisor-env'
        );
        $handler = new class implements RoleControlHandlerInterface {
            public function onMessage(array $message, SubprocessControlKernel $kernel): void
            {
            }

            public function onDisconnect(bool $receivedShutdown, SubprocessControlKernel $kernel): void
            {
            }
        };

        try {
            $kernel = new SubprocessControlKernel($identity, $handler, 'UT-Kernel', false, 'ut-instance');

            $reflection = new \ReflectionMethod($kernel, 'createClient');
            $reflection->setAccessible(true);
            $client = $reflection->invoke($kernel);

            self::assertInstanceOf(SupervisorChildClient::class, $client);
        } finally {
            \putenv('WLS_SUPERVISOR_ENABLED');
            \putenv('WLS_SUPERVISOR_CHANNEL');
            \putenv('WLS_SUPERVISOR_BASE_PATH');
        }
    }

    public function testChildEntryScriptsLoadFrameworkBootstrapBeforeResolvingControlPort(): void
    {
        $scripts = [
            'worker.php',
            'worker_ssl.php',
            'dispatcher.php',
            'http_redirect_worker.php',
        ];

        foreach ($scripts as $script) {
            $path = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $script;
            $source = \file_get_contents($path);

            self::assertNotFalse($source, "failed to read {$script}");

            $bpPos = \strpos($source, "\\define('BP', \$bp);");
            $autoloadPos = \strpos($source, "require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';");
            $resolvePos = \strpos($source, 'SubprocessControlKernel::resolveControlPort');

            self::assertNotFalse($bpPos, "{$script} should define BP before resolving control port");
            self::assertNotFalse($autoloadPos, "{$script} should load app/autoload.php");
            self::assertNotFalse($resolvePos, "{$script} should resolve the control port");

            self::assertLessThan($resolvePos, $bpPos, "{$script} should define BP before resolveControlPort");
            self::assertLessThan($resolvePos, $autoloadPos, "{$script} should load app/autoload.php before resolveControlPort");
        }
    }

    public function testWorkerEntryScriptsInitializeIpcShutdownStateBeforeOrphanGuardChecks(): void
    {
        foreach (['worker.php', 'worker_ssl.php'] as $script) {
            $path = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $script;
            $source = \file_get_contents($path);

            self::assertNotFalse($source, "failed to read {$script}");

            $shutdownInitPos = \strpos($source, '$ipcReceivedShutdown = false;');
            $guardPos = \strpos($source, '$orphanGuard->shouldExit(');

            self::assertNotFalse($shutdownInitPos, "{$script} should initialize \$ipcReceivedShutdown");
            self::assertNotFalse($guardPos, "{$script} should check orphan guard");
            self::assertLessThan($guardPos, $shutdownInitPos, "{$script} should initialize \$ipcReceivedShutdown before orphan guard checks");
        }
    }
}

