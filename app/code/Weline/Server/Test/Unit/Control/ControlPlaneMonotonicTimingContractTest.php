<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;

final class ControlPlaneMonotonicTimingContractTest extends TestCase
{
    /** @return iterable<string,string> */
    public static function durationConsumerProvider(): iterable
    {
        $server = BP . 'app/code/Weline/Server/';

        yield 'control client reconnect and flush' => [$server . 'IPC/ControlClient.php'];
        yield 'master control throttle and flush' => [$server . 'IPC/MasterControlServer.php'];
        yield 'IPC gateway reads and writes' => [$server . 'Service/Control/IpcControlGateway.php'];
        yield 'broadcast dispatch budget' => [$server . 'Service/Control/BroadcastControlDispatchService.php'];
        yield 'hybrid flush budget' => [$server . 'Service/Control/HybridControlPlaneServer.php'];
        yield 'child endpoint wait' => [$server . 'IPC/ChildControl/SubprocessControlKernel.php'];
        yield 'master resurrection grace' => [$server . 'IPC/MasterResurrector.php'];
        yield 'worker scaling and health' => [$server . 'Service/WorkerScaler.php'];
    }

    /** @dataProvider durationConsumerProvider */
    public function testRuntimeDurationConsumersNeverUseWallClock(string $path): void
    {
        $source = \file_get_contents($path);

        self::assertIsString($source);
        self::assertStringNotContainsString('\\microtime(true)', $source, $path);
        self::assertMatchesRegularExpression(
            '/(?:hrtime\s*\(\s*true\s*\)|monotonicSeconds\s*\()/',
            $source,
            $path,
        );
    }

    public function testAllWorkerTransportsReplyUsingTheWholePingEnvelope(): void
    {
        foreach (['worker.php', 'worker_ssl.php', 'worker_ssl_event.php'] as $worker) {
            $path = BP . 'app/code/Weline/Server/bin/' . $worker;
            $source = \file_get_contents($path);
            self::assertIsString($source);
            self::assertStringContainsString('ControlMessage::pongForPing($msg,', $source, $path);
        }
    }

    public function testControlWritesAndHybridFlushShareTheirDeclaredBudgets(): void
    {
        $gatewayPath = BP . 'app/code/Weline/Server/Service/Control/IpcControlGateway.php';
        $gateway = \file_get_contents($gatewayPath);
        self::assertIsString($gateway);
        self::assertGreaterThanOrEqual(
            4,
            \substr_count($gateway, 'writeCommandFully('),
            'parallel, serial and bounded control writes must all use the monotonic full-write helper',
        );

        $hybridPath = BP . 'app/code/Weline/Server/Service/Control/HybridControlPlaneServer.php';
        $hybrid = \file_get_contents($hybridPath);
        self::assertIsString($hybrid);
        $methodStart = \strpos($hybrid, 'public function flushPendingWrites');
        $methodEnd = \strpos($hybrid, 'public function close()', $methodStart ?: 0);
        self::assertIsInt($methodStart);
        self::assertIsInt($methodEnd);
        $method = \substr($hybrid, $methodStart, $methodEnd - $methodStart);
        self::assertLessThan(
            \strpos($method, '$this->controlServer->flushPendingWrites'),
            \strpos($method, '$deadline = ControlMessage::monotonicSeconds()'),
            'the shared deadline must start before the first data-plane flush',
        );
    }

    public function testReconnectThrottleExplicitlyRejectsFutureClockState(): void
    {
        $source = \file_get_contents(BP . 'app/code/Weline/Server/IPC/ControlClient.php');
        self::assertIsString($source);
        self::assertStringContainsString('$this->lastReconnectTime <= $now', $source);
    }
}
