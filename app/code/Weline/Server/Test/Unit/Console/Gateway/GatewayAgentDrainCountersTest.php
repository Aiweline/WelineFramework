<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;

final class GatewayAgentDrainCountersTest extends TestCase
{
    public function testBootstrapReconnectAndHeartbeatShareCallerOwnedDeadlines(): void
    {
        $execute = new \ReflectionMethod(Agent::class, 'execute');
        $lines = \file($execute->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $execute->getStartLine() - 1,
            $execute->getEndLine() - $execute->getStartLine() + 1,
        ));

        $tickDeadline = \strpos(
            $source,
            '$tickDeadline = $now + self::TICK_WORK_DEADLINE_SECONDS;',
        );
        $tick = \strpos($source, '$kernel?->tick();');
        self::assertIsInt($tickDeadline);
        self::assertIsInt($tick);
        self::assertLessThan(
            $tick,
            $tickDeadline,
            'The tick deadline must exist before reconnect or other control work starts.',
        );
        self::assertStringContainsString(
            '$kernel->reconnect($tickDeadline);',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/masterDrainCounters\(\s*\$instanceName,\s*\$tickDeadline,\s*\)/',
            $source,
        );

        $drainCounters = new \ReflectionMethod(Agent::class, 'masterDrainCounters');
        $drainSource = \implode('', \array_slice(
            $lines,
            $drainCounters->getStartLine() - 1,
            $drainCounters->getEndLine() - $drainCounters->getStartLine() + 1,
        ));
        self::assertStringContainsString(
            'getStatusBeforeDeadline(',
            $drainSource,
        );
        self::assertStringContainsString('$deadlineMonotonic,', $drainSource);

        $connectMaster = new \ReflectionMethod(Agent::class, 'connectMaster');
        $connectSource = \implode('', \array_slice(
            $lines,
            $connectMaster->getStartLine() - 1,
            $connectMaster->getEndLine() - $connectMaster->getStartLine() + 1,
        ));
        self::assertStringContainsString(
            'deadlineMonotonic: $bootstrapDeadline',
            $connectSource,
        );
        self::assertMatchesRegularExpression(
            '/connectAndRegister\(\s*\$controlPort,\s*false,\s*'
                . '\$bootstrapDeadline,\s*\)/',
            $connectSource,
        );
        self::assertStringContainsString(
            '$kernel->sendReady($bootstrapDeadline)',
            $connectSource,
        );
    }

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

    public function testFallbackEnablePayloadIsBoundToExactNonEmptyManifest(): void
    {
        $digest = \str_repeat('a', 64);
        self::assertSame([
            'serving_manifest_generation' => 17,
            'serving_manifest_digest' => $digest,
            'serving_manifest_route_count' => 2,
        ], Agent::fallbackServingManifestExpectation([
            'generation' => 17,
            'digest' => $digest,
            'route_count' => 2,
            'payload' => [
                'routes' => [
                    ['route_id' => \str_repeat('1', 32)],
                    ['route_id' => \str_repeat('2', 32)],
                ],
            ],
        ]));
    }

    public function testFallbackEnablePayloadRejectsManifestWithoutActiveRoutes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ACTIVE routes');
        Agent::fallbackServingManifestExpectation([
            'generation' => 17,
            'digest' => \str_repeat('a', 64),
            'route_count' => 0,
            'payload' => ['routes' => []],
        ]);
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

    public function testPublicProbeExpectationDigestUsesTheProtocolCanonicalizer(): void
    {
        $method = new \ReflectionMethod(Agent::class, 'publicProbeExpectationDigest');
        $digest = $method->invoke(null, [
            'project_generation' => 1,
            'request_digest' => \str_repeat('a', 64),
            'non_certificate_desired_digest' => \str_repeat('b', 64),
            'routes' => [],
        ], [
            'active_route_ids' => [],
        ], [
            'active_config_generation' => 1,
            'active_config_digest' => \str_repeat('c', 64),
            'public_https' => 443,
        ]);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $digest);
    }

    public function testFallbackLeaseObservationUsesAnyLiveListenerOwner(): void
    {
        $method = new \ReflectionMethod(Agent::class, 'observeFallbackLease');
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString('->liveServingLeaseForAnyOwner(', $source);
        self::assertStringNotContainsString('->liveServingLease(', $source);
        self::assertStringContainsString(
            'operationDeadlineMonotonic: $deadlineMonotonic',
            $source,
        );

        $execute = new \ReflectionMethod(Agent::class, 'execute');
        $executeLines = \file($execute->getFileName());
        self::assertIsArray($executeLines);
        $executeSource = \implode('', \array_slice(
            $executeLines,
            $execute->getStartLine() - 1,
            $execute->getEndLine() - $execute->getStartLine() + 1,
        ));
        self::assertMatchesRegularExpression(
            '/observeFallbackLease\(\s*\$instanceName,\s*\$tickDeadline,\s*\)/',
            $executeSource,
        );
        self::assertStringNotContainsString(
            '$fallbackLeases = new GatewayPortLeaseAllocator()',
            $executeSource,
        );
        self::assertStringContainsString(
            '$bootstrapDeadline = $this->monotonicNow()',
            $executeSource,
        );
        self::assertStringContainsString(
            '$projectUuid = $builder->projectUuid($bootstrapDeadline)',
            $executeSource,
        );
        self::assertStringNotContainsString(
            '$projectUuid = $builder->projectUuid();',
            $executeSource,
        );
        self::assertMatchesRegularExpression(
            '/pendingReplay\(\s*0\.25,\s*\$tickDeadline,\s*\)/',
            $executeSource,
        );
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
                    'state' => 'active',
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'snapshots/controller-outage.crt',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'snapshots/controller-outage.key',
                    ],
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

    public function testControllerOutageUsesExactLocalCertificateRoutesToClassifyTheDataPlane(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174081';
        $instanceName = 'controller-outage-fallback';
        $domain = 'controller-outage-fallback.example.test';
        $routeId = \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32);
        $registration = [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'project_generation' => 7,
            'instance_generation' => 11,
            'instance_digest' => \str_repeat('a', 64),
            'master_epoch' => 13,
            'launch_id' => \str_repeat('b', 32),
            'request_digest' => \str_repeat('c', 64),
            'non_certificate_desired_digest' => \str_repeat('d', 64),
            'routes' => [[
                'route_id' => $routeId,
                'domain' => $domain,
                'certificate' => [
                    'state' => 'active',
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'snapshots/controller-outage.crt',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'snapshots/controller-outage.key',
                    ],
                    'pending' => false,
                    'generation' => 3,
                    'leaf_fingerprint_sha256' => \str_repeat('e', 64),
                    'source_digest' => \str_repeat('f', 64),
                ],
            ]],
        ];

        self::assertSame([$routeId], Agent::localFallbackCertificateReadyRouteIds(
            $registration,
            $projectUuid,
            $instanceName,
            11,
            13,
            \str_repeat('b', 32),
        ));
        self::assertSame([], Agent::localFallbackCertificateReadyRouteIds(
            $registration,
            $projectUuid,
            $instanceName,
            12,
            13,
            \str_repeat('b', 32),
        ));
        self::assertSame([], Agent::localFallbackCertificateReadyRouteIds(
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
            $projectUuid,
            $instanceName,
            11,
            13,
            \str_repeat('b', 32),
        ));

        self::assertSame([
            'data_plane_healthy' => true,
            'data_plane_outage' => false,
            'certificate_ready' => true,
        ], Agent::fallbackDataPlaneObservation(
            statusAuthenticated: false,
            servingStatusAuthenticated: false,
            projectServingReady: false,
            allCertificateReadyRoutesActive: false,
            routeActive: false,
            publicProbeHealthy: true,
            gatewayCoreDown: false,
            routePublicationFailed: false,
            certificateReadyRouteCount: 0,
            certificateReadyUnavailableRouteCount: 0,
            localCertificateReadyRouteCount: 1,
        ));
        self::assertSame([
            'data_plane_healthy' => false,
            'data_plane_outage' => true,
            'certificate_ready' => true,
        ], Agent::fallbackDataPlaneObservation(
            statusAuthenticated: false,
            servingStatusAuthenticated: false,
            projectServingReady: false,
            allCertificateReadyRoutesActive: false,
            routeActive: false,
            publicProbeHealthy: false,
            gatewayCoreDown: false,
            routePublicationFailed: false,
            certificateReadyRouteCount: 0,
            certificateReadyUnavailableRouteCount: 0,
            localCertificateReadyRouteCount: 1,
        ));
        self::assertSame([
            'data_plane_healthy' => false,
            'data_plane_outage' => false,
            'certificate_ready' => false,
        ], Agent::fallbackDataPlaneObservation(
            statusAuthenticated: false,
            servingStatusAuthenticated: false,
            projectServingReady: false,
            allCertificateReadyRoutesActive: false,
            routeActive: false,
            publicProbeHealthy: false,
            gatewayCoreDown: false,
            routePublicationFailed: false,
            certificateReadyRouteCount: 0,
            certificateReadyUnavailableRouteCount: 0,
            localCertificateReadyRouteCount: 0,
        ));
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
        self::assertFalse(Agent::fallbackLeaseProvesLive([
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
                'state' => 'DRAINING',
                'drain_acknowledged' => false,
                'draining_host_boot_id' => null,
                'draining_monotonic' => null,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(0.0, Agent::restoreFallbackDrainStartedAt(
            [
                'state' => 'ACTIVE',
                'draining_host_boot_id' => $boot,
                'draining_monotonic' => 700.0,
            ],
            1000.0,
            $boot,
        ));
        self::assertSame(0.0, Agent::restoreFallbackDrainStartedAt(
            ['state' => 'DRAINING'],
            1000.0,
            $boot,
        ));
        self::assertSame(880.0, Agent::restoreFallbackDrainStartedAt(
            $this->fallbackDrainAckObservation($boot, 880.0),
            1000.0,
            $boot,
        ));
        self::assertSame(700.0, Agent::restoreFallbackDrainStartedAt(
            $this->fallbackDrainAckObservation($boot, 100.0),
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::restoreFallbackDrainStartedAt(
            $this->fallbackDrainAckObservation($otherBoot, 100.0),
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::restoreFallbackDrainStartedAt(
            $this->fallbackDrainAckObservation($boot, 1001.0),
            1000.0,
            $boot,
        ));
        self::assertSame(910.0, Agent::reconcileFallbackDrainStartedAt(
            900.0,
            $this->fallbackDrainAckObservation($boot, 910.0),
            1000.0,
            $boot,
        ));
        self::assertSame(920.0, Agent::reconcileFallbackDrainStartedAt(
            920.0,
            $this->fallbackDrainAckObservation($boot, 910.0),
            1000.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::reconcileFallbackDrainStartedAt(
            1000.0,
            $this->fallbackDrainAckObservation($otherBoot, 100.0),
            1001.0,
            $boot,
        ));
        self::assertSame(1000.0, Agent::reconcileFallbackDrainStartedAt(
            1000.0,
            $this->fallbackDrainAckObservation($otherBoot, 100.0),
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
                    $this->fallbackDrainAckObservation($boot, 100.0),
                    1000.0,
                    $boot,
                ),
                lastFallbackCommandAt: 0.0,
                fallbackRequested: true,
                fallbackDrainRequested: true,
            ),
        );
    }

    public function testObservedSchemaSixDrainAckReachesTheReconcileClock(): void
    {
        $boot = \str_repeat('b', 64);
        $expected = $this->fallbackDrainAckObservation($boot, 880.0);
        $lease = [
            'schema_version' => $expected['schema_version'],
            'state' => $expected['state'],
            'listener_phase' => $expected['listener_phase'],
            'drain_acknowledged' => $expected['drain_acknowledged'],
            'listener_transition_action' => $expected['listener_transition_action'],
            'drain_transition_id' => $expected['drain_transition_id'],
            'listener_transition_digest' => $expected['listener_transition_digest'],
            'drain_action_digest' => $expected['drain_action_digest'],
            'transition_identity' => $expected['transition_identity'],
            'draining_timestamp' => $expected['draining_timestamp'],
            'draining_host_boot_id' => $expected['draining_host_boot_id'],
            'draining_monotonic' => $expected['draining_monotonic'],
            'host_boot_id' => $expected['host_boot_id'],
            'bind_host' => $expected['bind_host'],
            'port' => $expected['port'],
            'lease_id' => $expected['lease_id'],
            'instance' => $expected['lease_instance_id'],
            'project_uuid' => $expected['project_uuid'],
            'master_pid' => $expected['master_pid'],
            'launch_id' => $expected['worker_launch_id'],
            'confirmed_timestamp' => $expected['confirmed_timestamp'],
        ];
        $projection = new \ReflectionMethod(
            Agent::class,
            'projectFallbackLeaseObservation',
        );
        $observed = $projection->invoke(
            null,
            $lease,
            null,
            'site-gateway-fallback',
        );
        self::assertIsArray($observed);
        self::assertSame(
            GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED,
            $observed['listener_phase'],
        );
        self::assertSame(880.0, Agent::restoreFallbackDrainStartedAt(
            $observed,
            1000.0,
            $boot,
        ));

        $lease['listener_phase'] = GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_PREPARED;
        $lease['drain_acknowledged'] = false;
        $pending = $projection->invoke(
            null,
            $lease,
            null,
            'site-gateway-fallback',
        );
        self::assertSame(0.0, Agent::restoreFallbackDrainStartedAt(
            $pending,
            1000.0,
            $boot,
        ));
    }

    public function testFallbackDrainClockRejectsANonCanonicalAcknowledgementDigest(): void
    {
        $boot = \str_repeat('a', 64);
        $observation = $this->fallbackDrainAckObservation($boot, 880.0);
        $observation['listener_transition_digest'] = \str_repeat('f', 64);
        $observation['drain_action_digest'] = \str_repeat('f', 64);

        self::assertSame(0.0, Agent::restoreFallbackDrainStartedAt(
            $observation,
            1000.0,
            $boot,
        ));
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

    public function testAnOmittedPublicProbeCannotCreateOutageContinuityEvidence(): void
    {
        $digest = new \ReflectionMethod(Agent::class, 'gatewayOutageObservationDigest');
        $arguments = [
            true,
            false,
            false,
            false,
            false,
            false,
            \str_repeat('a', 64),
            [\str_repeat('1', 32)],
            443,
            '123e4567-e89b-42d3-a456-426614174099',
            'site',
            7,
            22000,
            9,
            \str_repeat('2', 32),
            11,
            \str_repeat('3', 64),
        ];

        self::assertSame('', $digest->invokeArgs(null, $arguments));
        $arguments[1] = true;
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)$digest->invokeArgs(null, $arguments),
        );
    }

    public function testAggregatesFreshIdentityMatchedWorkerReports(): void
    {
        $now = 1200.0;
        $result = $this->statusResult($now, [
            $this->worker(1, $now - 1.0, 2, 3, 1, 2, 2),
            $this->worker(2, $now - 2.0, 4, 5, 2, 1, 1),
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
            'http2_connections' => 3,
        ], Agent::aggregateMasterDrainCounters($result, $now));
    }

    public function testWorkersPublishHttp2CountsToMasterStatusAndDetailedHealth(): void
    {
        $agentFile = (string)(new \ReflectionClass(Agent::class))->getFileName();
        $moduleRoot = \dirname($agentFile, 4);
        $plainWorker = (string)\file_get_contents(
            $moduleRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker.php',
        );
        $sslWorker = (string)\file_get_contents(
            $moduleRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker_ssl.php',
        );

        self::assertStringContainsString("'http2_connections' => 0,", $plainWorker);
        self::assertStringContainsString(
            "'http2_connections' => \\max(0, \$http2ConnectionCount),",
            $plainWorker,
        );
        self::assertStringContainsString(
            '$http2StatusConnectionCount = wlsHttp2LiveConnectionCount(',
            $sslWorker,
        );
        self::assertStringContainsString(
            "'http2_connections' => \$http2StatusConnectionCount,",
            $sslWorker,
        );
        self::assertStringContainsString(
            "'http2_connections' => \\max(0, \$http2ConnectionCount),",
            $sslWorker,
        );
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
                'http2_connections' => 0,
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

    public function testMissingOrUntypedWorkerHttp2CounterFailsClosed(): void
    {
        $now = 1200.0;
        $missing = $this->worker(1, $now - 1.0, 0, 0, 0, 0);
        unset($missing['metadata']['last_status_report']['http2_connections']);
        $untyped = $this->worker(1, $now - 1.0, 0, 0, 0, 0);
        $untyped['metadata']['last_status_report']['http2_connections'] = '0';

        self::assertFalse(Agent::aggregateMasterDrainCounters(
            $this->statusResult($now, [$missing], 1),
            $now,
        )['counters_known']);
        self::assertFalse(Agent::aggregateMasterDrainCounters(
            $this->statusResult($now, [$untyped], 1),
            $now,
        )['counters_known']);
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

    public function testFallbackDrainDispatchIsNotAnAckAndRetriesOnlyAtHeartbeatCadence(): void
    {
        $recovered = [
            'dataPlaneHealthy' => true,
            'fallbackEligible' => true,
            'controlAvailable' => true,
            'downSince' => 0.0,
            'activeSince' => 100.0,
            'fallbackDrainStartedAt' => 0.0,
            'lastFallbackCommandAt' => 200.0,
            'fallbackRequested' => true,
            'fallbackDrainRequested' => false,
        ];
        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 209.999, ...$recovered],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN,
            Agent::decideFallbackLifecycleAction(...['now' => 210.0, ...$recovered]),
        );

        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        self::assertStringContainsString('$lastFallbackCommandAt = $now;', $source);
        self::assertStringContainsString('$fallbackDrainRequested = false;', $source);
        self::assertStringNotContainsString(
            '$fallbackDrainRequested = $kernel?->sendControlCommand(',
            $source,
        );
    }

    public function testFallbackFinalDisableRetriesOnlyAtHeartbeatCadence(): void
    {
        $drained = [
            'dataPlaneHealthy' => true,
            'fallbackEligible' => true,
            'controlAvailable' => true,
            'downSince' => 0.0,
            'activeSince' => 50.0,
            'fallbackDrainStartedAt' => 100.0,
            'lastFallbackCommandAt' => 395.0,
            'fallbackRequested' => true,
            'fallbackDrainRequested' => true,
        ];

        self::assertSame('', Agent::decideFallbackLifecycleAction(
            ...['now' => 404.999, ...$drained],
        ));
        self::assertSame(
            ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE,
            Agent::decideFallbackLifecycleAction(...['now' => 405.0, ...$drained]),
        );

        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($source, '$lastFallbackCommandAt = $now;'),
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

    public function testStartupFallbackUsesTheTickDeadlineAndExactManifestFence(): void
    {
        $method = new \ReflectionMethod(Agent::class, 'execute');
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $deadlineAt = \strpos(
            $source,
            '$tickDeadline = $now + self::TICK_WORK_DEADLINE_SECONDS',
        );
        $pendingAt = \strpos($source, 'if (\is_array($pendingStartupFallbackCommand))');
        $pollAt = \strpos($source, '$desiredStateResult = $this->pollDesiredStateJob(');
        self::assertIsInt($deadlineAt);
        self::assertIsInt($pendingAt);
        self::assertIsInt($pollAt);
        self::assertLessThan($pendingAt, $deadlineAt);
        $pendingSource = \substr($source, $pendingAt, $pollAt - $pendingAt);
        self::assertStringContainsString('->currentForFence([', $pendingSource);
        self::assertStringContainsString(
            'activeCertificateFenceForDomain(',
            $pendingSource,
        );
        self::assertStringNotContainsString('->active(', $pendingSource);
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($source, 'activeCertificateFenceForDomain('),
        );
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

    /** @return array<string,mixed> */
    private function fallbackDrainAckObservation(
        string $hostBootId,
        float $drainingMonotonic,
    ): array {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $leaseId = \str_repeat('1', 32);
        $workerLaunchId = \str_repeat('2', 32);
        $transitionId = \str_repeat('3', 32);
        $pidNamespaceId = PHP_OS_FAMILY === 'Linux'
            ? 'pid:[4026531836]'
            : '';
        $identity = [
            'schema' => 'wls-gateway-fallback-listener/1',
            'project_uuid' => $projectUuid,
            'wls_instance' => 'site',
            'role' => 'gateway_fallback',
            'slot_id' => 'gateway_fallback#1',
            'service_generation' => 7,
            'service_lease_id' => \str_repeat('5', 32),
            'worker_pid' => 22001,
            'worker_process_birth' => \str_repeat('6', 64),
            'worker_pid_namespace_id' => $pidNamespaceId,
            'worker_launch_id' => $workerLaunchId,
            'master_pid' => 22000,
            'master_epoch' => 9,
            'master_launch_id' => \str_repeat('7', 32),
            'master_process_birth' => \str_repeat('8', 64),
            'master_pid_namespace_id' => $pidNamespaceId,
            'port' => 24567,
            'host_lease_instance' => 'site-gateway-fallback',
            'host_lease_id' => $leaseId,
            'host_boot_id' => $hostBootId,
            'bind_host' => '127.0.0.1',
            'listener_proof_digest' => \str_repeat('9', 64),
            'listener_transport' => 'posix_inherited_fd',
            'listener_receipt_digest' => \str_repeat('a', 64),
        ];
        $actionDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );
        return [
            'schema_version' => GatewayPortLeaseAllocator::SCHEMA_VERSION,
            'state' => 'DRAINING',
            'live' => false,
            'listener_phase' => GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED,
            'drain_acknowledged' => true,
            'listener_transition_action' => 'DRAIN',
            'drain_transition_id' => $transitionId,
            'listener_transition_digest' => $actionDigest,
            'drain_action_digest' => $actionDigest,
            'transition_identity' => $identity,
            'draining_timestamp' => 1,
            'draining_host_boot_id' => $hostBootId,
            'draining_monotonic' => $drainingMonotonic,
            'host_boot_id' => $hostBootId,
            'bind_host' => '127.0.0.1',
            'port' => 24567,
            'lease_id' => $leaseId,
            'lease_instance_id' => 'site-gateway-fallback',
            'project_uuid' => $projectUuid,
            'master_pid' => 22000,
            'worker_launch_id' => $workerLaunchId,
            'confirmed_timestamp' => 1,
        ];
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
        int $http2 = 0,
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
                    'http2_connections' => $http2,
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
