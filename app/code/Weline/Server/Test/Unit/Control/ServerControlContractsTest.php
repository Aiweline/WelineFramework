<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerControlContractsPass().
 */
final class ServerControlContractsTest extends TestCase
{
    public function testAllServerControlContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ControlPlaneMonotonicTiming::testAllWorkerTransportsReplyUsingTheWholePingEnvelope' => function () {
            $this->assert_ControlPlaneMonotonicTiming_testAllWorkerTransportsReplyUsingTheWholePingEnvelope();
        },
            'ControlPlaneMonotonicTiming::testControlWritesAndHybridFlushShareTheirDeclaredBudgets' => function () {
            $this->assert_ControlPlaneMonotonicTiming_testControlWritesAndHybridFlushShareTheirDeclaredBudgets();
        },
            'ControlPlaneMonotonicTiming::testReconnectThrottleExplicitlyRejectsFutureClockState' => function () {
            $this->assert_ControlPlaneMonotonicTiming_testReconnectThrottleExplicitlyRejectsFutureClockState();
        },
        ];
    }

/** @return iterable<string,string> */
private function durationConsumerProvider_ControlPlaneMonotonicTiming(): iterable
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

private function assert_ControlPlaneMonotonicTiming_testAllWorkerTransportsReplyUsingTheWholePingEnvelope(): void
{
    foreach (['worker.php', 'worker_ssl.php', 'worker_ssl_event.php'] as $worker) {
        $path = BP . 'app/code/Weline/Server/bin/' . $worker;
        $source = \file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('ControlMessage::pongForPing($msg,', $source, $path);
    }
}

private function assert_ControlPlaneMonotonicTiming_testControlWritesAndHybridFlushShareTheirDeclaredBudgets(): void
{
    $gatewayPath = BP . 'app/code/Weline/Server/Service/Control/IpcControlGateway.php';
    $gateway = \file_get_contents($gatewayPath);
    self::assertIsString($gateway);
    self::assertGreaterThanOrEqual(4, \substr_count($gateway, 'writeCommandFully('), 'parallel, serial and bounded control writes must all use the monotonic full-write helper');
    $hybridPath = BP . 'app/code/Weline/Server/Service/Control/HybridControlPlaneServer.php';
    $hybrid = \file_get_contents($hybridPath);
    self::assertIsString($hybrid);
    $methodStart = \strpos($hybrid, 'public function flushPendingWrites');
    $methodEnd = \strpos($hybrid, 'public function close()', $methodStart ?: 0);
    self::assertIsInt($methodStart);
    self::assertIsInt($methodEnd);
    $method = \substr($hybrid, $methodStart, $methodEnd - $methodStart);
    self::assertLessThan(\strpos($method, '$this->controlServer->flushPendingWrites'), \strpos($method, '$deadline = ControlMessage::monotonicSeconds()'), 'the shared deadline must start before the first data-plane flush');
}

private function assert_ControlPlaneMonotonicTiming_testReconnectThrottleExplicitlyRejectsFutureClockState(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Server/IPC/ControlClient.php');
    self::assertIsString($source);
    self::assertStringContainsString('$this->lastReconnectTime <= $now', $source);
}
}
