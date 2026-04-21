<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

class ServiceInfoRuntimeStatusTest extends TestCase
{
    public function testRunningStateUsesPidAsSourceOfTruthWhenPidExists(): void
    {
        $socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, 'failed to create test socket: ' . $errstr);

        $address = (string)\stream_socket_get_name($socket, false);
        $parts = \explode(':', $address);
        $port = (int)($parts[1] ?? 0);
        $this->assertGreaterThan(0, $port);

        // 即使端口被占用，只要 PID 不存在，也必须判定为未运行（避免跨实例端口误判）。
        $service = new ServiceInfo(
            role: 'worker',
            displayName: 'HTTP Worker',
            instanceId: 1,
            pid: 999999,
            port: $port,
            state: ServiceInstance::STATE_READY,
        );

        $this->assertFalse($service->isRunning());
        \fclose($socket);
    }

    public function testRunningStateFallsBackToPortWhenPidIsMissing(): void
    {
        $socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, 'failed to create test socket: ' . $errstr);

        $address = (string)\stream_socket_get_name($socket, false);
        $parts = \explode(':', $address);
        $port = (int)($parts[1] ?? 0);
        $this->assertGreaterThan(0, $port);

        $service = new ServiceInfo(
            role: 'session_server',
            displayName: 'Session Server',
            instanceId: 1,
            pid: 0,
            port: $port,
            state: ServiceInstance::STATE_READY,
        );

        $this->assertTrue($service->isRunning());
        \fclose($socket);
    }

    public function testRunningStatePrefersTrackedRootPidWhenChildPidIsStale(): void
    {
        $service = new ServiceInfo(
            role: 'worker',
            displayName: 'HTTP Worker',
            instanceId: 1,
            pid: 999999,
            port: null,
            state: ServiceInstance::STATE_READY,
            rootPid: \getmypid(),
            launcherPid: \getmypid(),
        );

        $this->assertTrue($service->isRunning());
        $this->assertSame(\getmypid(), $service->getTrackingPid());
    }
}
