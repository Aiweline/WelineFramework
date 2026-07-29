<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\IPC\ControlMessage;

final class GatewayAgentDrainCountersTest extends TestCase
{
    public function testHeartbeatReplaySignalIsStrictAndBackwardCompatible(): void
    {
        self::assertFalse(Agent::heartbeatRequiresRegistrationReplay([]));
        self::assertFalse(Agent::heartbeatRequiresRegistrationReplay([
            're_register_required' => 'true',
        ]));
        self::assertTrue(Agent::heartbeatRequiresRegistrationReplay([
            're_register_required' => true,
        ]));
    }

    public function testPublicationKeepaliveUsesHeartbeatIntervalAndStopsOnShutdown(): void
    {
        self::assertTrue(Agent::publicationHeartbeatDue(110.0, 100.0, false));
        self::assertFalse(Agent::publicationHeartbeatDue(109.999, 100.0, false));
        self::assertTrue(Agent::publicationHeartbeatDue(10.0, 0.0, false));
        self::assertFalse(Agent::publicationHeartbeatDue(110.0, 100.0, true));
    }

    public function testEmptyAcmeChallengeDigestStartsConverged(): void
    {
        self::assertSame(
            Agent::acmeChallengeDigest([]),
            Agent::initialAcmeChallengeDigest(),
        );
        self::assertNotSame(
            Agent::initialAcmeChallengeDigest(),
            Agent::acmeChallengeDigest([[
                'domain' => 'example.test',
                'token' => 'token',
                'key_authorization' => 'token.thumbprint',
                'expires_at' => 1234,
            ]]),
        );
    }

    public function testFallbackLeaseOnlyProvesSuccessAfterReadyPromotion(): void
    {
        self::assertFalse(Agent::fallbackLeaseProvesLive([]));
        self::assertFalse(Agent::fallbackLeaseProvesLive([
            'state' => 'RESERVED',
            'live' => true,
        ]));
        self::assertFalse(Agent::fallbackLeaseProvesLive([
            'state' => 'ACTIVE',
            'live' => false,
        ]));
        self::assertTrue(Agent::fallbackLeaseProvesLive([
            'state' => 'active',
            'live' => true,
        ]));
        self::assertTrue(Agent::fallbackLeaseProvesLive([
            'state' => 'DRAINING',
            'live' => true,
        ]));
    }

    public function testFallbackDrainTimestampSurvivesAgentSelfHealAndDeadWorker(): void
    {
        self::assertSame(0.0, Agent::restoreFallbackDrainStartedAt(
            ['state' => 'ACTIVE', 'draining_timestamp' => 700],
            1000.0,
            1000,
        ));
        self::assertSame(1000.0, Agent::restoreFallbackDrainStartedAt(
            ['state' => 'DRAINING'],
            1000.0,
            1000,
        ));
        self::assertSame(880.0, Agent::restoreFallbackDrainStartedAt(
            ['state' => 'draining', 'draining_timestamp' => 880],
            1000.0,
            1000,
        ));
        self::assertSame(700.0, Agent::restoreFallbackDrainStartedAt(
            ['state' => 'DRAINING', 'draining_timestamp' => 100],
            1000.0,
            1000,
        ));
        self::assertSame(910.0, Agent::reconcileFallbackDrainStartedAt(
            900.0,
            ['state' => 'DRAINING', 'draining_timestamp' => 910],
            1000.0,
            1000,
        ));
        self::assertSame(920.0, Agent::reconcileFallbackDrainStartedAt(
            920.0,
            ['state' => 'DRAINING', 'draining_timestamp' => 910],
            1000.0,
            1000,
        ));

        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE,
            Agent::decideFallbackLifecycleAction(
                now: 1000.0,
                dataPlaneHealthy: true,
                fallbackEligible: true,
                controlAvailable: true,
                downSince: 0.0,
                activeSince: 900.0,
                fallbackDrainStartedAt: Agent::restoreFallbackDrainStartedAt(
                    [
                        'state' => 'DRAINING',
                        'live' => false,
                        'draining_timestamp' => 100,
                    ],
                    1000.0,
                    1000,
                ),
                lastFallbackCommandAt: 0.0,
                fallbackRequested: true,
                fallbackDrainRequested: true,
            ),
        );
    }

    public function testFallbackControlPortOnlyEchoesTheHostLeaseRange(): void
    {
        self::assertSame(0, Agent::fallbackControlPort([]));
        self::assertSame(0, Agent::fallbackControlPort(['port' => 19999]));
        self::assertSame(20000, Agent::fallbackControlPort(['port' => '20000']));
        self::assertSame(27673, Agent::fallbackControlPort(['port' => 27673]));
        self::assertSame(29999, Agent::fallbackControlPort(['port' => 29999]));
        self::assertSame(0, Agent::fallbackControlPort(['port' => 30000]));
    }

    public function testAggregatesFreshIdentityMatchedWorkerReports(): void
    {
        $now = 1200.0;
        $result = $this->statusResult($now, [
            $this->worker(1, $now - 1.0, 2, 3, 1, 2),
            $this->worker(2, $now - 2.0, 4, 5, 2, 1),
        ]);

        self::assertSame([
            'version' => 1,
            'counters_known' => true,
            'worker_count' => 2,
            'reported_worker_count' => 2,
            'active_requests' => 6,
            'long_lived_connections' => 8,
            'sse_connections' => 3,
            'websocket_connections' => 3,
        ], Agent::aggregateMasterDrainCounters($result, $now));
    }

    public function testStaleOrPartialWorkerReportsFailClosedAsUnknown(): void
    {
        $now = 1200.0;
        $stale = $this->statusResult($now, [
            $this->worker(1, $now - 16.0, 1, 1, 0, 0),
            $this->worker(2, $now - 1.0, 1, 1, 0, 0),
        ]);
        $partial = $this->statusResult($now, [
            $this->worker(1, $now - 1.0, 1, 1, 0, 0),
        ], 2);

        self::assertFalse(Agent::aggregateMasterDrainCounters($stale, $now)['counters_known']);
        self::assertSame(
            [
                'version' => 1,
                'counters_known' => false,
                'worker_count' => 2,
                'reported_worker_count' => 1,
                'active_requests' => 0,
                'long_lived_connections' => 0,
                'sse_connections' => 0,
                'websocket_connections' => 0,
            ],
            Agent::aggregateMasterDrainCounters($partial, $now),
        );
    }

    public function testMismatchedWorkerLeaseIdentityFailsClosed(): void
    {
        $now = 1200.0;
        $worker = $this->worker(1, $now - 1.0, 0, 0, 0, 0);
        $worker['metadata']['last_status_report']['source_lease_id'] = 'forged-lease';
        $result = $this->statusResult($now, [$worker], 1);

        $aggregate = Agent::aggregateMasterDrainCounters($result, $now);

        self::assertFalse($aggregate['counters_known']);
        self::assertSame(0, $aggregate['active_requests']);
        self::assertSame(0, $aggregate['long_lived_connections']);
    }

    public function testFallbackLifecycleUsesExactNinetyThirtyAndThreeHundredSecondGates(): void
    {
        $base = [
            'dataPlaneHealthy' => false,
            'fallbackEligible' => true,
            'controlAvailable' => true,
            'downSince' => 100.0,
            'activeSince' => 0.0,
            'fallbackDrainStartedAt' => 0.0,
            'lastFallbackCommandAt' => 0.0,
            'fallbackRequested' => false,
            'fallbackDrainRequested' => false,
        ];
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 189.999, ...$base],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE,
            Agent::decideFallbackLifecycleAction(...['now' => 190.0, ...$base]),
        );

        $recovered = [
            ...$base,
            'dataPlaneHealthy' => true,
            'downSince' => 0.0,
            'activeSince' => 200.0,
            'fallbackRequested' => true,
        ];
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 229.999, ...$recovered],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN,
            Agent::decideFallbackLifecycleAction(...['now' => 230.0, ...$recovered]),
        );

        $draining = [
            ...$recovered,
            'fallbackDrainStartedAt' => 230.0,
            'fallbackDrainRequested' => true,
        ];
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 529.999, ...$draining],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE,
            Agent::decideFallbackLifecycleAction(...['now' => 530.0, ...$draining]),
        );
    }

    public function testFallbackNeverDrainsFromControlPlaneActiveStateAlone(): void
    {
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            now: 1000.0,
            dataPlaneHealthy: false,
            fallbackEligible: true,
            controlAvailable: true,
            downSince: 950.0,
            activeSince: 900.0,
            fallbackDrainStartedAt: 600.0,
            lastFallbackCommandAt: 990.0,
            fallbackRequested: true,
            fallbackDrainRequested: true,
        ));
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            now: 1000.0,
            dataPlaneHealthy: true,
            fallbackEligible: true,
            controlAvailable: false,
            downSince: 0.0,
            activeSince: 900.0,
            fallbackDrainStartedAt: 600.0,
            lastFallbackCommandAt: 0.0,
            fallbackRequested: true,
            fallbackDrainRequested: true,
        ));
    }

    public function testNativeDrainRetriesWithoutResettingTheDurableDeadline(): void
    {
        $base = [
            'dataPlaneHealthy' => true,
            'joinRequired' => true,
            'activeSince' => 100.0,
            'controlAvailable' => true,
            'promotionCommitted' => true,
        ];
        self::assertFalse(Agent::shouldRequestNativeDrain(
            ...[
                'now' => 200.0,
                'nativeEdgeState' => 'ACTIVE',
                'lastCommandAt' => 0.0,
                ...$base,
                'promotionCommitted' => false,
            ],
        ));
        self::assertFalse(Agent::shouldRequestNativeDrain(
            ...[
                'now' => 129.999,
                'nativeEdgeState' => 'ACTIVE',
                'lastCommandAt' => 0.0,
                ...$base,
            ],
        ));
        self::assertTrue(Agent::shouldRequestNativeDrain(
            ...[
                'now' => 130.0,
                'nativeEdgeState' => 'ACTIVE',
                'lastCommandAt' => 0.0,
                ...$base,
            ],
        ));
        self::assertFalse(Agent::shouldRequestNativeDrain(
            ...[
                'now' => 139.999,
                'nativeEdgeState' => 'DRAINING',
                'lastCommandAt' => 130.0,
                ...$base,
            ],
        ));
        self::assertTrue(Agent::shouldRequestNativeDrain(
            ...[
                'now' => 140.0,
                'nativeEdgeState' => 'DRAINING',
                'lastCommandAt' => 130.0,
                ...$base,
            ],
        ));
        self::assertFalse(Agent::shouldRequestNativeDrain(
            ...[
                'now' => 1000.0,
                'nativeEdgeState' => 'DRAINED',
                'lastCommandAt' => 130.0,
                ...$base,
            ],
        ));
    }

    /**
     * @param list<array<string,mixed>> $workers
     * @return array<string,mixed>
     */
    private function statusResult(float $now, array $workers, ?int $desired = null): array
    {
        unset($now);
        return [
            'success' => true,
            'data' => [
                'running' => true,
                'shutting_down' => false,
                'desired_state' => ['worker' => $desired ?? \count($workers)],
                'services' => [
                    'worker' => ['instances' => $workers],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function worker(
        int $workerId,
        float $reportedAt,
        int $activeRequests,
        int $longLived,
        int $sse,
        int $webSocket,
    ): array {
        $pid = 5000 + $workerId;
        $lease = 'worker-' . $workerId . '-lease';
        $generation = 10 + $workerId;
        return [
            'instance_id' => $workerId,
            'pid' => $pid,
            'state' => 'ready',
            'metadata' => [
                'lease_id' => $lease,
                'generation' => $generation,
                'last_status_report_at' => $reportedAt,
                'last_status_report' => [
                    'drain_counters_version' => 1,
                    'active_requests' => $activeRequests,
                    'long_lived_connections' => $longLived,
                    'sse_connections' => $sse,
                    'websocket_connections' => $webSocket,
                    'source_role' => 'worker',
                    'source_pid' => $pid,
                    'source_worker_id' => $workerId,
                    'source_lease_id' => $lease,
                    'source_generation' => $generation,
                ],
            ],
        ];
    }
}
