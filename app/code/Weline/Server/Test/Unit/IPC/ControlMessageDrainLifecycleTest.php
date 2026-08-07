<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

final class ControlMessageDrainLifecycleTest extends TestCase
{
    public function testLongLivedConnectionsWaitUntilTheHardDeadline(): void
    {
        self::assertSame(
            ControlMessage::DRAIN_ACTION_WAIT,
            ControlMessage::drainLifecycleDecision(
                elapsedSeconds: 9.5,
                softDeadlineSeconds: 9.0,
                hardDeadlineSeconds: 10.0,
                connectionCount: 2,
                activeRequests: 2,
                pendingApplicationWork: 2,
                longLivedConnections: 2,
                http2Connections: 0,
            ),
        );
    }

    public function testHttp2PeerFinWaitIsForcedAtTheHardDeadline(): void
    {
        self::assertSame(
            ControlMessage::DRAIN_ACTION_FORCE,
            ControlMessage::drainLifecycleDecision(
                elapsedSeconds: 10.0,
                softDeadlineSeconds: 9.0,
                hardDeadlineSeconds: 10.0,
                connectionCount: 1,
                activeRequests: 0,
                pendingApplicationWork: 0,
                longLivedConnections: 0,
                http2Connections: 1,
            ),
        );
    }

    public function testLongLivedConnectionsAreForcedOnlyAtTheHardDeadline(): void
    {
        self::assertSame(
            ControlMessage::DRAIN_ACTION_FORCE,
            ControlMessage::drainLifecycleDecision(
                elapsedSeconds: 10.0,
                softDeadlineSeconds: 9.0,
                hardDeadlineSeconds: 10.0,
                connectionCount: 2,
                activeRequests: 2,
                pendingApplicationWork: 2,
                longLivedConnections: 2,
                http2Connections: 0,
            ),
        );
    }

    public function testOnlyIdleTransportConnectionsCloseAtTheSoftDeadline(): void
    {
        self::assertSame(
            ControlMessage::DRAIN_ACTION_CLOSE_IDLE,
            ControlMessage::drainLifecycleDecision(
                elapsedSeconds: 9.0,
                softDeadlineSeconds: 9.0,
                hardDeadlineSeconds: 10.0,
                connectionCount: 3,
                activeRequests: 0,
                pendingApplicationWork: 0,
                longLivedConnections: 0,
                http2Connections: 0,
            ),
        );
    }

    public function testEmptyDrainCompletesBeforeAnyDeadline(): void
    {
        self::assertSame(
            ControlMessage::DRAIN_ACTION_COMPLETE,
            ControlMessage::drainLifecycleDecision(
                elapsedSeconds: 0.1,
                softDeadlineSeconds: 9.0,
                hardDeadlineSeconds: 10.0,
                connectionCount: 0,
                activeRequests: 0,
                pendingApplicationWork: 0,
                longLivedConnections: 0,
                http2Connections: 0,
            ),
        );
    }

    public function testDrainingCompleteCarriesVersionedForcedTerminationCounts(): void
    {
        $report = ControlMessage::drainCompletionReport(
            outcome: ControlMessage::DRAIN_OUTCOME_FORCED,
            elapsedSeconds: 10.125,
            softDeadlineSeconds: 9.0,
            hardDeadlineSeconds: 10.0,
            observed: [
                'connections' => 3,
                'active_requests' => 2,
                'long_lived_connections' => 2,
                'sse_connections' => 1,
                'websocket_connections' => 1,
                'http2_connections' => 1,
            ],
            terminated: [
                'connections' => 3,
                'active_requests' => 2,
                'long_lived_connections' => 2,
                'sse_connections' => 1,
                'websocket_connections' => 1,
                'http2_connections' => 1,
            ],
        );

        $message = ControlMessage::decode(ControlMessage::drainingComplete(
            workerId: 7,
            port: 9507,
            msgId: 'drain-7',
            reason: 'hard_deadline',
            drainReport: $report,
        ));

        self::assertSame('wls-drain-report/1', $message['drain']['schema']);
        self::assertSame(ControlMessage::DRAIN_OUTCOME_FORCED, $message['drain']['outcome']);
        self::assertTrue($message['drain']['forced']);
        self::assertSame(10125, $message['drain']['elapsed_ms']);
        self::assertSame(9000, $message['drain']['soft_deadline_ms']);
        self::assertSame(10000, $message['drain']['hard_deadline_ms']);
        self::assertSame(3, $message['drain']['observed']['connections']);
        self::assertSame(2, $message['drain']['terminated']['active_requests']);
        self::assertSame(1, $message['drain']['terminated']['http2_connections']);
    }

    public function testDrainDeadlinesReserveABoundedHardCloseWindow(): void
    {
        self::assertSame(
            ['soft' => 9.0, 'hard' => 10.0],
            ControlMessage::drainDeadlines(10.0),
        );
        self::assertSame(
            ['soft' => 1.0, 'hard' => 1.0],
            ControlMessage::drainDeadlines(1.0),
        );
    }

    public function testLegacyDrainingCompleteEnvelopeRemainsCompatible(): void
    {
        $message = ControlMessage::decode(ControlMessage::drainingComplete(
            workerId: 3,
            port: 9503,
            msgId: 'legacy-drain',
            reason: 'natural',
        ));

        self::assertSame(ControlMessage::TYPE_DRAINING_COMPLETE, $message['type']);
        self::assertSame(3, $message['worker_id']);
        self::assertSame(9503, $message['port']);
        self::assertArrayNotHasKey('drain', $message);
    }
}
