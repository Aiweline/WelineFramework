<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\ChildControl;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\MasterOrphanGuard;
use Weline\Server\IPC\ChildControl\RoleControlHandlerInterface;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;

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

        \file_put_contents($instanceFile, \json_encode(['control_port' => 19091]));
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
}

