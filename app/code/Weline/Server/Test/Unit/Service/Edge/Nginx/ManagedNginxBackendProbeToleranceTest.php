<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * 回归锁：托管 Nginx 发布前的 loopback WLS 后端探针不得把「慢但活」的后端判死。
 *
 * 线上证据（changanhanfu 2026-10-27）：实例 READY（8 worker + dispatcher 9981 就绪）后，
 * 探针仍报 `managed nginx upstream did not prove a healthy loopback WLS backend`
 * 并把整代实例回收。同机实测经 Dispatcher 转发的 `/_wls/health` 延迟达 0.1–10s+，
 * 而探针旧读超时只有 1.0s、总预算 25s。
 */
final class ManagedNginxBackendProbeToleranceTest extends TestCase
{
    private const INSTANCE_NAME = 'tolerance-instance';

    public function testProbeBudgetCoversLoadedColdStart(): void
    {
        $reflection = new \ReflectionClass(ManagedNginxService::class);

        self::assertGreaterThanOrEqual(
            5.0,
            (float)$reflection->getConstant('BACKEND_PROBE_READ_TIMEOUT_SECONDS'),
            'Loopback health reads must tolerate >1s latency measured on a loaded host.',
        );
        self::assertGreaterThanOrEqual(
            2.0,
            (float)$reflection->getConstant('BACKEND_PROBE_CONNECT_TIMEOUT_SECONDS'),
            'Loopback connects must tolerate a busy dispatcher accept queue.',
        );
        self::assertGreaterThanOrEqual(
            60.0,
            (float)$reflection->getConstant('BACKEND_PROBE_WAIT_BUDGET_SECONDS'),
            'The readiness wait budget must cover a loaded cold start.',
        );
    }

    public function testSlowButAliveLoopbackBackendIsAccepted(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork is required to host the delayed loopback fixture.');
        }
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, 'Loopback fixture listen failed: ' . $errstr);
        $bound = (string)\stream_socket_get_name($server, false);
        $separator = \strrpos($bound, ':');
        self::assertIsInt($separator);
        $port = (int)\substr($bound, $separator + 1);
        self::assertGreaterThan(0, $port);

        $pid = \pcntl_fork();
        self::assertIsInt($pid);
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Fixture: answer every accepted health probe only after 2.5s, like a
            // dispatcher queued behind real traffic on a saturated host.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $connection = @\stream_socket_accept($server, 10.0);
                if ($connection === false) {
                    break;
                }
                @\fread($connection, 8192);
                \usleep(2_500_000);
                $body = (string)\json_encode([
                    'status' => 'healthy',
                    'instance' => self::INSTANCE_NAME,
                    'worker_id' => 1,
                    'port' => $port,
                ]);
                @\fwrite(
                    $connection,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                    . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
                );
                @\fclose($connection);
            }
            exit(0);
        }

        try {
            $identity = [
                'topology' => 'direct',
                'listener_mode' => 'direct',
                'upstream_port' => $port,
                'upstream_ports' => [$port],
                'worker_port' => $port,
                'worker_count' => 1,
            ];
            $accepted = (new \ReflectionMethod(
                ManagedNginxService::class,
                'probeWlsBackend',
            ))->invoke(
                new ManagedNginxService(),
                '127.0.0.1',
                $port,
                self::INSTANCE_NAME,
                $identity,
            );
            self::assertTrue(
                $accepted,
                'A healthy loopback backend that answers within 2.5s must not be judged dead.',
            );
        } finally {
            @\posix_kill($pid, \SIGKILL);
            @\pcntl_waitpid($pid, $status);
            @\fclose($server);
        }
    }

    public function testHealthIdentityMismatchIsStillRejected(): void
    {
        $method = new \ReflectionMethod(
            ManagedNginxService::class,
            'healthMatchesBackendIdentity',
        );
        $service = new ManagedNginxService();
        $identity = [
            'topology' => 'direct',
            'listener_mode' => 'direct',
            'upstream_port' => 9981,
            'upstream_ports' => [9981],
            'worker_port' => 9981,
            'worker_count' => 1,
        ];
        $healthy = [
            'status' => 'healthy',
            'instance' => 'default',
            'worker_id' => 1,
            'port' => 9981,
        ];

        self::assertTrue($method->invoke($service, $healthy, 'default', $identity));
        self::assertFalse($method->invoke(
            $service,
            ['port' => 9999] + $healthy,
            'default',
            $identity,
        ));
        self::assertFalse($method->invoke(
            $service,
            ['status' => 'degraded'] + $healthy,
            'default',
            $identity,
        ));
        self::assertFalse($method->invoke($service, $healthy, 'another-instance', $identity));
    }
}
