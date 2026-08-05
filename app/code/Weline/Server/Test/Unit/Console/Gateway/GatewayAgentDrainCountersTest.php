<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;

final class GatewayAgentDrainCountersTest extends TestCase
{
    public function testDesiredStateWorkerUsesPidBoundManagedTaskCapability(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        $procOpen = \strpos($source, '$process = @\\proc_open(');
        $authorize = \strpos($source, '->authorizeTaskFromManagedParent(');

        self::assertIsInt($procOpen);
        self::assertIsInt($authorize);
        self::assertLessThan($authorize, $procOpen);
        self::assertStringContainsString('->revokeTaskFromManagedParent(', $source);
        self::assertStringContainsString(
            '$this->assertDesiredStateTaskAuthorized($taskGuard, \'result commit\');',
            $source,
        );
        self::assertStringContainsString(
            'Gateway desired-state worker --master-token is forbidden.',
            $source,
        );
        self::assertStringContainsString(
            'array{0:?SubprocessControlKernel,1:?ChildMasterGuard,2:string,3:string}',
            $source,
        );
        self::assertStringContainsString(
            '$parentSlotId = $this->stringArgument($args, \'slot-id\');',
            $source,
        );
        self::assertStringContainsString("'--slot-id=' . \$taskSlotId", $source);
        self::assertStringContainsString(
            'self::validDesiredStateTaskSlot($taskSlotId)',
            $source,
        );
        self::assertStringNotContainsString('DESIRED_STATE_TASK_SLOT', $source);
        self::assertStringContainsString(
            'DESIRED_STATE_LAUNCH_FAILURE_LOG_SECONDS',
            $source,
        );
    }

    public function testAuthenticatedDrainingLatchPrecedesBackendEnableAndReplaySideEffects(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        $latch = \strpos(
            $source,
            '$projectDraining = self::latchProjectInstanceDraining(',
        );
        $backendEnable = \strpos(
            $source,
            'ControlMessage::ACTION_GATEWAY_BACKEND_ENABLE',
        );

        self::assertIsInt($latch);
        self::assertIsInt($backendEnable);
        self::assertLessThan($backendEnable, $latch);
        self::assertStringContainsString(
            'if (!$projectDraining && $heartbeatDue)',
            $source,
        );
        self::assertStringContainsString(
            'if (!$projectDraining) {' . "\n"
                . '                    try {' . "\n"
                . '                        $certificateReplay',
            $source,
        );
    }

    public function testManagedProcessIdentityIsRedactedAndGenerationBound(): void
    {
        self::assertSame(
            '--name=weline-wls-gateway-agent-unit --launch-id=gateway_agent-1-deadbeef',
            Agent::managedProcessIdentity(
                'weline-wls-gateway-agent-unit',
                'gateway_agent-1-deadbeef',
            ),
        );
        self::assertSame('', Agent::managedProcessIdentity('', 'gateway_agent-1-deadbeef'));
        self::assertSame('', Agent::managedProcessIdentity(
            'weline-wls-gateway-agent-unit --master-token=secret',
            'gateway_agent-1-deadbeef',
        ));
        self::assertSame('', Agent::managedProcessIdentity(
            'weline-wls-gateway-agent-unit',
            'gateway_agent-1-deadbeef --master-token=secret',
        ));
    }

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

    public function testHeartbeatTransportFailureDoesNotTriggerRegistrationStorm(): void
    {
        self::assertFalse(Agent::heartbeatFailureRequiresRegistrationReplay(
            'WLS Gateway returned an empty response.',
        ));
        self::assertFalse(Agent::heartbeatFailureRequiresRegistrationReplay(
            new \RuntimeException('Gateway request rate limit exceeded; retry_after=1.'),
        ));
        self::assertTrue(Agent::heartbeatFailureRequiresRegistrationReplay(
            'Heartbeat project generation is stale or unknown.',
        ));
        self::assertTrue(Agent::heartbeatFailureRequiresRegistrationReplay(
            new \DomainException('Instance lease fencing identity is stale or unknown.'),
        ));
    }

    public function testPublicProbePreparationDoesNotRequireFreshControllerStatus(): void
    {
        self::assertTrue(Agent::canPreparePublicProbe(false, 'NOT_REQUIRED'));
        self::assertTrue(Agent::canPreparePublicProbe(true, 'ACTIVE'));
        self::assertFalse(Agent::canPreparePublicProbe(true, 'STARTING'));
        self::assertFalse(Agent::canPreparePublicProbe(true, 'STALE'));
    }

    public function testGatewayControlRecoveryDoesNotDependOnActivePublicRoutes(): void
    {
        $control = [
            'ok' => true,
            'ready' => false,
            'control_plane_ready' => true,
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'protocol' => 'wls-edge/2',
            'implementation_level' => 'wls-2.0',
            'security_profile' => 'native-broker-v1',
            'protocol_min' => 2,
            'protocol_max' => 2,
            'epoch' => \str_repeat('a', 32),
            'host_boot_id' => \str_repeat('b', 64),
            'public_http' => 80,
            'public_https' => 443,
        ];
        self::assertTrue(Agent::gatewayControlDiscoverable($control));
        self::assertFalse(Agent::gatewayControlDiscoverable(
            ['ready' => true, ...$control, 'broker_ready' => false],
        ));
        self::assertFalse(Agent::gatewayControlDiscoverable(
            [...$control, 'protocol' => 'wls-edge/1'],
        ));
        self::assertTrue(GatewayHostManager::controlPlaneAcceptsRegistration($control));
        self::assertFalse(GatewayHostManager::controlPlaneAcceptsRegistration(
            [...$control, 'supervisor_ready' => false],
        ));
    }

    public function testDiscoveryTrustRequiresExactActiveRoutePublication(): void
    {
        $active = [
            'authenticated' => true,
            'state' => 'ACTIVE',
            'active_route_ids' => [\str_repeat('a', 32)],
            'certificate_ready_route_count' => 1,
            'certificate_ready_unavailable_route_count' => 0,
        ];
        self::assertTrue(Agent::routePublicationProvesActive($active));
        self::assertFalse(Agent::routePublicationProvesActive([
            ...$active,
            'authenticated' => false,
        ]));
        self::assertFalse(Agent::routePublicationProvesActive([
            ...$active,
            'active_route_ids' => [],
        ]));
        self::assertFalse(Agent::routePublicationProvesActive([
            ...$active,
            'certificate_ready_route_count' => 2,
        ]));
    }

    public function testAuthenticatedDataPlaneDownCanUseOnlyValidLocalCertificatesForFallback(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174071';
        $instanceName = 'fallback-first-start';
        $boot = \str_repeat('b', 64);
        $domain = 'fallback-first-start.example.test';
        $routeId = \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32);
        $registration = [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'project_generation' => 3,
            'instance_generation' => 4,
            'instance_digest' => \str_repeat('c', 64),
            'master_epoch' => 5,
            'launch_id' => \str_repeat('d', 32),
            'request_digest' => \str_repeat('e', 64),
            'non_certificate_desired_digest' => \str_repeat('f', 64),
            'routes' => [[
                'route_id' => $routeId,
                'domain' => $domain,
                'certificate' => [
                    'pending' => false,
                    'generation' => 2,
                    'leaf_fingerprint_sha256' => \str_repeat('1', 64),
                    'source_digest' => \str_repeat('2', 64),
                ],
            ]],
        ];
        $status = [
            'ok' => true,
            'control_plane_ready' => true,
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'protocol' => 'wls-edge/2',
            'implementation_level' => 'wls-2.0',
            'security_profile' => 'native-broker-v1',
            'protocol_min' => 2,
            'protocol_max' => 2,
            'epoch' => \str_repeat('a', 32),
            'host_boot_id' => $boot,
            'public_http' => 80,
            'public_https' => 443,
            'project_uuid' => $projectUuid,
            'state' => 'DATA_PLANE_DOWN',
            'data_plane' => ['running' => false],
            'instances' => [],
        ];

        self::assertSame(1, Agent::localFallbackCertificateReadyRouteCount(
            $registration,
            $status,
            $projectUuid,
            $instanceName,
            $boot,
        ));
        self::assertSame(1, Agent::localFallbackCertificateReadyRouteCount(
            $registration,
            [...$status, 'data_plane' => ['running' => true]],
            $projectUuid,
            $instanceName,
            $boot,
        ));
        self::assertSame(0, Agent::localFallbackCertificateReadyRouteCount(
            $registration,
            [
                ...$status,
                'instances' => [[
                    'instance_id' => $instanceName,
                    'status' => 'DRAINING',
                    'generation' => 4,
                    'master_epoch' => 5,
                    'launch_id' => \str_repeat('d', 32),
                    'digest' => \str_repeat('c', 64),
                ]],
            ],
            $projectUuid,
            $instanceName,
            $boot,
        ));
        self::assertSame(0, Agent::localFallbackCertificateReadyRouteCount(
            [
                ...$registration,
                'routes' => [[
                    ...$registration['routes'][0],
                    'certificate' => [
                        ...$registration['routes'][0]['certificate'],
                        'pending' => true,
                    ],
                ]],
            ],
            $status,
            $projectUuid,
            $instanceName,
            $boot,
        ));
        foreach ([
            [...$status, 'state' => 'CONTROL_DEGRADED'],
            [...$status, 'data_plane' => ['running' => 'false']],
            [...$status, 'project_uuid' => '123e4567-e89b-42d3-a456-426614174072'],
            [...$status, 'host_boot_id' => \str_repeat('9', 64)],
        ] as $rejectedStatus) {
            self::assertSame(0, Agent::localFallbackCertificateReadyRouteCount(
                $registration,
                $rejectedStatus,
                $projectUuid,
                $instanceName,
                $boot,
            ));
        }
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
        $boot = \str_repeat('a', 64);
        $otherBoot = \str_repeat('b', 64);
        self::assertSame(0.0, Agent::restoreFallbackDrainStartedAt(
            [
                'state' => 'ACTIVE',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 700.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::restoreFallbackDrainStartedAt(
            ['state' => 'DRAINING'],
            1000.0,
            $boot,
        ));
        self::assertSame(880.0, Agent::restoreFallbackDrainStartedAt(
            [
                'state' => 'draining',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 880.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(700.0, Agent::restoreFallbackDrainStartedAt(
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 100.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::restoreFallbackDrainStartedAt(
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $otherBoot,
                'draining_monotonic' => 100.0,
                'draining_timestamp' => 1,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::restoreFallbackDrainStartedAt(
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 1001.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(910.0, Agent::reconcileFallbackDrainStartedAt(
            900.0,
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 910.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(920.0, Agent::reconcileFallbackDrainStartedAt(
            920.0,
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 910.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::reconcileFallbackDrainStartedAt(
            1000.0,
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $otherBoot,
                'draining_monotonic' => 100.0,
            ],
            1001.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::reconcileFallbackDrainStartedAt(
            1000.0,
            [
                'state' => 'DRAINING',
                'draining_host_boot_id' => $otherBoot,
                'draining_monotonic' => 100.0,
            ],
            1299.999,
            $boot,
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
                        'draining_host_boot_id' => $boot,
                        'draining_monotonic' => 100.0,
                    ],
                    1000.0,
                    $boot,
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

    public function testProjectDrainingFencesFallbackByProjectAndInstanceIdentity(): void
    {
        $currentProject = '11111111-1111-4111-8111-111111111111';
        $otherProject = '22222222-2222-4222-8222-222222222222';
        $generation = 7;
        $masterEpoch = 9;
        $launchId = \str_repeat('a', 32);
        $drainingStatus = [
            'project_uuid' => $currentProject,
            'instances' => [[
                'instance_id' => 'api',
                'status' => 'DRAINING',
                'generation' => $generation,
                'master_epoch' => $masterEpoch,
                'launch_id' => $launchId,
            ]],
        ];
        self::assertFalse(Agent::projectInstanceDraining(
            $drainingStatus,
            $currentProject,
            'worker',
            $generation,
            $masterEpoch,
            $launchId,
        ));
        self::assertFalse(Agent::projectInstanceDraining(
            [...$drainingStatus, 'project_uuid' => $otherProject],
            $currentProject,
            'api',
            $generation,
            $masterEpoch,
            $launchId,
        ));
        self::assertTrue(Agent::projectInstanceDraining(
            $drainingStatus,
            $currentProject,
            'api',
            $generation,
            $masterEpoch,
            $launchId,
        ));
        foreach ([
            ['generation' => $generation + 1],
            ['master_epoch' => $masterEpoch + 1],
            ['launch_id' => \str_repeat('b', 32)],
            ['status' => 'ACTIVE'],
        ] as $mismatch) {
            self::assertFalse(Agent::projectInstanceDraining(
                [
                    ...$drainingStatus,
                    'instances' => [[...$drainingStatus['instances'][0], ...$mismatch]],
                ],
                $currentProject,
                'api',
                $generation,
                $masterEpoch,
                $launchId,
            ));
        }
        self::assertFalse(Agent::projectInstanceDraining(
            [
                ...$drainingStatus,
                'instances' => [
                    $drainingStatus['instances'][0],
                    $drainingStatus['instances'][0],
                ],
            ],
            $currentProject,
            'api',
            $generation,
            $masterEpoch,
            $launchId,
        ));
        self::assertFalse(Agent::latchProjectInstanceDraining(
            false,
            ['ok' => false, ...$drainingStatus],
            $currentProject,
            'api',
            $generation,
            $masterEpoch,
            $launchId,
        ));
        self::assertTrue(Agent::latchProjectInstanceDraining(
            false,
            ['ok' => true, ...$drainingStatus],
            $currentProject,
            'api',
            $generation,
            $masterEpoch,
            $launchId,
        ));
        self::assertTrue(Agent::latchProjectInstanceDraining(
            true,
            ['ok' => true, 'routes' => [], 'instances' => []],
            $currentProject,
            'api',
        ));

        $draining = [
            'dataPlaneHealthy' => false,
            'fallbackEligible' => true,
            'controlAvailable' => true,
            'downSince' => 100.0,
            'activeSince' => 0.0,
            'fallbackDrainStartedAt' => 0.0,
            'lastFallbackCommandAt' => 0.0,
            'fallbackRequested' => false,
            'fallbackDrainRequested' => false,
            'projectDraining' => true,
        ];
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 1000.0, ...$draining],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN,
            Agent::decideFallbackLifecycleAction(...[
                'now' => 1000.0,
                ...$draining,
                'fallbackRequested' => true,
            ]),
        );
        $fallbackDraining = [
            ...$draining,
            'fallbackRequested' => true,
            'fallbackDrainRequested' => true,
            'fallbackDrainStartedAt' => 700.0,
        ];
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 999.999, ...$fallbackDraining],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE,
            Agent::decideFallbackLifecycleAction(...[
                'now' => 1000.0,
                ...$fallbackDraining,
            ]),
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
                'last_status_report_monotonic' => $reportedAt,
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
