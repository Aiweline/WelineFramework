<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayHostManagerTrustTest extends TestCase
{
    public function testStaleDrainBuildsOneExactSameTenantFence(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceName = 'stale-shop';
        $status = self::staleOwnStatus($projectUuid, $instanceName);
        $credential = ['project_uuid' => $projectUuid];
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'staleDrainFenceFromAuthenticatedStatus',
        );

        self::assertSame([
            'project_uuid' => $projectUuid,
            'gateway_epoch' => \str_repeat('a', 32),
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'instance_id' => $instanceName,
            'instance_generation' => 7,
            'master_epoch' => 11,
            'launch_id' => \str_repeat('c', 32),
        ], $method->invoke(null, $credential, $status, $instanceName));

        $status['instances'][0]['status'] = 'DRAINING';
        $status['active_routes'][0]['status'] = 'DRAINING';
        $status['desired_routes'][0]['status'] = 'DRAINING';
        self::assertSame(
            $instanceName,
            $method->invoke(null, $credential, $status, $instanceName)['instance_id'],
        );
    }

    public function testStaleDrainAllowsAnActiveAggregateOwnedByAnotherExactInstance(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceName = 'stale-shop';
        $status = self::staleOwnStatus($projectUuid, $instanceName);
        $activeInstance = [
            'instance_id' => 'active-shop',
            'status' => 'ACTIVE',
            'generation' => 8,
            'digest' => \str_repeat('e', 64),
            'master_epoch' => 12,
            'launch_id' => \str_repeat('f', 32),
        ];
        $status['instances'][] = $activeInstance;
        foreach (['active_routes', 'desired_routes'] as $collection) {
            $status[$collection][0]['status'] = 'ACTIVE';
            $status[$collection][0]['instance_id'] = 'active-shop';
            $status[$collection][0]['backend_instances'] = [[
                'instance_id' => 'active-shop',
                'backends' => [['host' => '127.0.0.1', 'port' => 28081, 'weight' => 1]],
                'backend_identity' => [
                    'project_uuid' => $projectUuid,
                    'instance_id' => 'active-shop',
                    'generation' => 8,
                    'master_epoch' => 12,
                    'launch_id' => \str_repeat('f', 32),
                ],
            ]];
        }
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'staleDrainFenceFromAuthenticatedStatus',
        );

        $fence = $method->invoke(
            null,
            ['project_uuid' => $projectUuid],
            $status,
            $instanceName,
        );

        self::assertSame($instanceName, $fence['instance_id']);
        self::assertSame(7, $fence['instance_generation']);
        self::assertSame(11, $fence['master_epoch']);
        self::assertSame(\str_repeat('c', 32), $fence['launch_id']);
    }

    /** @dataProvider unsafeStaleDrainStatusProvider */
    public function testStaleDrainRejectsAnyUnfencedOrLiveRouteState(
        \Closure $mutate,
    ): void {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceName = 'stale-shop';
        $status = self::staleOwnStatus($projectUuid, $instanceName);
        $credential = ['project_uuid' => $projectUuid];
        [$credential, $status] = $mutate($credential, $status);
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'staleDrainFenceFromAuthenticatedStatus',
        );

        $this->expectException(\RuntimeException::class);
        $method->invoke(null, $credential, $status, $instanceName);
    }

    /** @return iterable<string,array{0:\Closure}> */
    public static function unsafeStaleDrainStatusProvider(): iterable
    {
        yield 'cross tenant status' => [static function (array $credential, array $status): array {
            $status['project_uuid'] = '123e4567-e89b-42d3-a456-426614174098';
            return [$credential, $status];
        }];
        yield 'active instance' => [static function (array $credential, array $status): array {
            $status['instances'][0]['status'] = 'ACTIVE';
            return [$credential, $status];
        }];
        yield 'removed instance' => [static function (array $credential, array $status): array {
            $status['instances'][0]['status'] = 'REMOVED';
            return [$credential, $status];
        }];
        yield 'duplicate instance' => [static function (array $credential, array $status): array {
            $status['instances'][] = $status['instances'][0];
            return [$credential, $status];
        }];
        yield 'route generation mismatch' => [static function (array $credential, array $status): array {
            $status['desired_routes'][0]['route_generation'] = 6;
            return [$credential, $status];
        }];
        yield 'stale target selected as active backend' => [static function (
            array $credential,
            array $status,
        ): array {
            foreach (['active_routes', 'desired_routes'] as $collection) {
                $status[$collection][0]['status'] = 'ACTIVE';
                $status[$collection][0]['instance_id'] = 'stale-shop';
                $status[$collection][0]['backend_instances'] = [[
                    'instance_id' => 'stale-shop',
                    'backend_identity' => [
                        'project_uuid' => $credential['project_uuid'],
                        'instance_id' => 'stale-shop',
                        'generation' => 7,
                        'master_epoch' => 11,
                        'launch_id' => \str_repeat('c', 32),
                    ],
                ]];
            }
            return [$credential, $status];
        }];
        yield 'removed desired route' => [static function (array $credential, array $status): array {
            $status['desired_routes'][0]['status'] = 'REMOVED';
            return [$credential, $status];
        }];
        yield 'cross tenant active route' => [static function (array $credential, array $status): array {
            $status['active_routes'][0]['project_uuid']
                = '123e4567-e89b-42d3-a456-426614174098';
            return [$credential, $status];
        }];
        yield 'no registered routes' => [static function (array $credential, array $status): array {
            $status['active_routes'] = [];
            $status['desired_routes'] = [];
            return [$credential, $status];
        }];
        yield 'inexact publication' => [static function (array $credential, array $status): array {
            $status['publication_exact'] = false;
            return [$credential, $status];
        }];
    }

    public function testStaleDrainPublicApiNeverLoadsAnExpiredLeaseReceipt(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'drainStaleRegistration',
        );
        self::assertSame(4, $method->getNumberOfParameters());
        self::assertSame('waitForConnections', $method->getParameters()[2]->getName());
        self::assertSame('deadlineMonotonic', $method->getParameters()[3]->getName());
        $source = self::methodSource($method);
        self::assertStringNotContainsString('loadLeaseReceipt', $source);
        self::assertStringContainsString(
            '$status = $this->status(5.0, $deadlineMonotonic);',
            $source,
        );
        self::assertStringContainsString(
            'staleDrainFenceFromAuthenticatedStatus',
            $source,
        );
        self::assertStringContainsString(
            'self::leaseReceiptFromPublication($published)',
            $source,
        );
        self::assertStringContainsString('$this->persistLeaseReceipt(', $source);
        self::assertStringContainsString(
            'self::committedDrainRemainingSeconds(',
            $source,
        );
        self::assertStringContainsString('$this->awaitInstanceDrain(', $source);
        self::assertMatchesRegularExpression(
            '/idempotentProjectMutation\([\s\S]*?\$deadlineMonotonic,\s*\)/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/awaitPublication\([\s\S]*?\$deadlineMonotonic,\s*\)/',
            $source,
        );
    }

    public function testStaleDrainWaitFailsClosedBeforeForcedUnregister(): void
    {
        $source = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'awaitInstanceDrain',
        ));
        $failClosed = \strpos($source, 'if ($failClosedOnTimeout)');
        $forcedUnregister = \strpos(
            $source,
            '$unregistered = $this->unregister(',
        );
        self::assertIsInt($failClosed);
        self::assertIsInt($forcedUnregister);
        self::assertLessThan($forcedUnregister, $failClosed);
        self::assertStringContainsString(
            'self::drainCountersComplete($lastCounters)',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/heartbeat\([\s\S]*?\$instanceName,[\s\S]*?\$deadline,\s*\)/',
            $source,
        );
    }

    public function testCommittedDrainRetryUsesOnlyItsExistingDeadline(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'committedDrainRemainingSeconds',
        );

        self::assertSame(10.0, $method->invoke(null, [
            'drain_seconds' => 300,
            'drain_until' => 1_010,
        ], 1_000));
        self::assertSame(300.0, $method->invoke(null, [
            'drain_seconds' => 300,
            'drain_until' => 1_500,
        ], 1_000));

        $this->expectException(\RuntimeException::class);
        $method->invoke(null, [
            'drain_seconds' => 300,
            'drain_until' => 1_000,
        ], 1_000);
    }

    public function testDrainCompletionRequiresH2SseAndWebSocketCountersToBeZero(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'drainCountersComplete',
        );
        $zero = [
            'counters_known' => true,
            'active_requests' => 0,
            'long_lived_connections' => 0,
            'sse_connections' => 0,
            'websocket_connections' => 0,
            'http2_connections' => 0,
        ];
        self::assertTrue($method->invoke(null, $zero));
        self::assertFalse($method->invoke(null, [
            ...$zero,
            'counters_known' => false,
        ]));
        foreach ([
            'active_requests',
            'long_lived_connections',
            'sse_connections',
            'websocket_connections',
            'http2_connections',
        ] as $counter) {
            self::assertFalse(
                $method->invoke(null, [...$zero, $counter => 1]),
                $counter,
            );
        }
        self::assertFalse($method->invoke(null, [
            ...$zero,
            'http2_connections' => '0',
        ]));
        self::assertFalse($method->invoke(
            null,
            \array_diff_key($zero, ['http2_connections' => true]),
        ));
        self::assertFalse($method->invoke(null, [
            ...$zero,
            'long_lived_connections' => 0,
            'sse_connections' => 1,
        ]));
    }

    public function testRenewAndReceiptValidationExposeOneAbsoluteDeadline(): void
    {
        $renew = new \ReflectionMethod(GatewayHostManager::class, 'renew');
        self::assertSame(4, $renew->getNumberOfParameters());
        self::assertSame(
            'deadlineMonotonic',
            $renew->getParameters()[3]->getName(),
        );
        $renewSource = self::methodSource($renew);
        self::assertStringContainsString(
            '$status = $this->status(5.0, $deadlineMonotonic);',
            $renewSource,
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count(
                $renewSource,
                '$this->operationLockWaitTimeout($deadlineMonotonic, 300.0)',
            ),
        );
        self::assertMatchesRegularExpression(
            '/idempotentProjectMutation\([\s\S]*?\$deadlineMonotonic,\s*\)/',
            $renewSource,
        );
        self::assertMatchesRegularExpression(
            '/awaitPublication\([\s\S]*?\$deadlineMonotonic,\s*\)/',
            $renewSource,
        );
        self::assertMatchesRegularExpression(
            '/persistLeaseReceipt\([\s\S]*?\$deadlineMonotonic,\s*\)/',
            $renewSource,
        );

        $receipt = new \ReflectionMethod(
            GatewayHostManager::class,
            'validatedLeaseReceiptForInstance',
        );
        self::assertSame(2, $receipt->getNumberOfParameters());
        $receiptSource = self::methodSource($receipt);
        self::assertStringContainsString(
            '$deadlineMonotonic = $this->boundedOperationDeadline(',
            $receiptSource,
        );
        self::assertStringContainsString(
            '$status = $this->status(1.0, $deadlineMonotonic);',
            $receiptSource,
        );
    }

    public function testHeartbeatRejectsAnExpiredBudgetBeforeReadingReceiptState(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deadline was exhausted');

        (new GatewayHostManager())->heartbeat(
            'default',
            [],
            \hrtime(true) / 1_000_000_000 - 1.0,
        );
    }

    public function testOnlyAnExactWlsTwoControlEnvelopeIsAccepted(): void
    {
        $trusted = self::trustedControl();
        self::assertTrue(GatewayHostManager::controlPlaneAcceptsRegistration($trusted));

        foreach ([
            'legacy protocol' => ['protocol' => 'wls-edge/1'],
            'legacy implementation' => ['implementation_level' => 'wls-1.x'],
            'untrusted security profile' => ['security_profile' => 'prototype'],
            'non-integer protocol minimum' => ['protocol_min' => '2'],
            'invalid protocol minimum' => ['protocol_min' => 0],
            'non-integer protocol maximum' => ['protocol_max' => '2'],
            'non-integer HTTP port' => ['public_http' => '80'],
            'non-integer HTTPS port' => ['public_https' => 443.0],
            'shared HTTP and HTTPS port' => ['public_https' => 80],
            'missing release readiness' => ['release_ready' => false],
            'invalid host boot identity' => ['host_boot_id' => 'previous-boot'],
        ] as $label => $override) {
            self::assertFalse(
                GatewayHostManager::controlPlaneAcceptsRegistration([
                    ...$trusted,
                    ...$override,
                ]),
                $label,
            );
        }
    }

    public function testAdministrativeReadinessRequiresTheCompleteCurrentHostEnvelope(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'hostStatusIsTrustedReady',
        );
        $trusted = [
            ...self::trustedControl(),
            'ready' => true,
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'active_slot' => 'A',
            'runtime_generation' => \str_repeat('c', 64),
        ];
        self::assertTrue($method->invoke(null, $trusted));

        foreach ([
            'not ready' => ['ready' => false],
            'control degraded' => ['control_plane_ready' => false],
            'untrusted release' => ['release_ready' => false],
            'broker unavailable' => ['broker_ready' => false],
            'supervisor unavailable' => ['supervisor_ready' => false],
            'legacy protocol' => ['protocol' => 'wls-edge/1'],
            'legacy implementation' => ['implementation_level' => 'wls-1.x'],
            'untrusted security profile' => ['security_profile' => 'prototype'],
            'stale boot' => ['host_boot_id' => \str_repeat('f', 64)],
            'invalid active slot' => ['active_slot' => 'C'],
            'invalid runtime generation' => ['runtime_generation' => 'legacy'],
        ] as $label => $override) {
            self::assertFalse(
                $method->invoke(null, [...$trusted, ...$override]),
                $label,
            );
        }
    }

    public function testUpgradeReadinessRequiresTheExactSlotAndRuntimeGeneration(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'hostStatusMatchesRuntimeIdentity',
        );
        $generation = \str_repeat('c', 64);
        $trusted = [
            ...self::trustedControl(),
            'ready' => true,
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'active_slot' => 'B',
            'runtime_generation' => $generation,
        ];

        self::assertTrue($method->invoke(null, $trusted, 'B', $generation));
        self::assertFalse($method->invoke(null, $trusted, 'A', $generation));
        self::assertFalse($method->invoke(
            null,
            $trusted,
            'B',
            \str_repeat('d', 64),
        ));
        self::assertFalse($method->invoke(
            null,
            [...$trusted, 'release_ready' => false],
            'B',
            $generation,
        ));
    }

    public function testLatePortRaceRequiresStructuredCurrentRuntimeProof(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'hostStatusIsTrustedPortTaken',
        );
        $generation = \str_repeat('c', 64);
        $trusted = [
            ...self::trustedControl(),
            'ready' => false,
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'active_slot' => 'B',
            'runtime_generation' => $generation,
            'state' => 'PORT_TAKEN',
            'recovery' => ['stage' => 'PORT_TAKEN'],
            'data_plane' => [
                'ok' => true,
                'running' => false,
            ],
            // Free-form diagnostics are deliberately outside the authority
            // boundary used to dismantle a newly installed platform service.
            'reason' => 'arbitrary text',
        ];

        self::assertTrue($method->invoke(null, $trusted, 'B', $generation));

        foreach ([
            'ready contradiction' => ['ready' => true],
            'control plane unavailable' => ['control_plane_ready' => false],
            'stale host boot' => ['host_boot_id' => \str_repeat('f', 64)],
            'different active slot' => ['active_slot' => 'A'],
            'different runtime generation' => [
                'runtime_generation' => \str_repeat('d', 64),
            ],
            'generic health failure' => ['state' => 'DATA_PLANE_DOWN'],
            'generic recovery stage' => [
                'recovery' => ['stage' => 'FAST_RESTART'],
            ],
            'unproven data-plane identity' => [
                'data_plane' => ['ok' => false, 'running' => false],
            ],
            'owned data plane still running' => [
                'data_plane' => ['ok' => true, 'running' => true],
            ],
        ] as $label => $override) {
            self::assertFalse(
                $method->invoke(
                    null,
                    [...$trusted, ...$override],
                    'B',
                    $generation,
                ),
                $label,
            );
        }

        self::assertFalse($method->invoke(null, [
            ...$trusted,
            'state' => 'DATA_PLANE_DOWN',
            'recovery' => ['stage' => 'FAST_RESTART'],
            'reason' => 'PORT_TAKEN',
        ], 'B', $generation));

        $install = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'install',
        ));
        $promotion = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'activateLegacyPromotion',
        ));
        self::assertStringContainsString(
            'self::hostStatusIsTrustedPortTaken(',
            $install,
        );
        self::assertStringContainsString(
            'rollbackInitialActivationAfterReadinessFailure(',
            $install,
        );
        self::assertStringContainsString(
            'self::hostStatusIsTrustedPortTaken(',
            $promotion,
        );
        self::assertStringContainsString(
            'failLegacyPromotionForTrustedPortTaken(',
            $promotion,
        );
    }

    public function testRebootstrapSealsTheRetainedBackupBeforeStartAuthorizationIsConsumed(): void
    {
        $source = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'startRebootstrapGeneration',
        ));
        $quiescent = \strpos(
            $source,
            '$this->assertRebootstrapQuiescent(',
        );
        $seal = \strpos(
            $source,
            '$this->platform->secureRebootstrapBackup(',
        );
        $closure = \strpos(
            $source,
            '$this->packages->assertRebootstrapPreStartClosure(',
        );
        $consumeIntent = \strpos(
            $source,
            '$cleared = $this->clearAdminStoppedIntent(',
        );
        $start = \strpos($source, '$this->platform->start(');

        self::assertIsInt($quiescent);
        self::assertIsInt($seal);
        self::assertIsInt($closure);
        self::assertIsInt($consumeIntent);
        self::assertIsInt($start);
        self::assertTrue(
            $quiescent < $seal
                && $seal < $closure
                && $closure < $consumeIntent
                && $consumeIntent < $start,
            'The stopped old generation must be proven, sealed and closed before its intent is consumed.',
        );
        self::assertMatchesRegularExpression(
            '/secureRebootstrapBackup\(\s*\$nonce,\s*\$forwardDeadline,?\s*\)/',
            $source,
        );
    }

    public function testRebootstrapCapacityIsHeldBeforeStopAndReleasedBeforeMutation(): void
    {
        $forward = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'rebootstrap',
        ));
        $rollback = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'performRebootstrapRollback',
        ));
        $rollbackRelease = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'releaseRebootstrapCapacityForRollback',
        ));

        $ensure = \strpos(
            $forward,
            '->ensureRebootstrapCapacityReserve(',
        );
        $verify = \strpos(
            $forward,
            '->verifyRebootstrapCapacityReserveHeld(',
            \is_int($ensure) ? $ensure : 0,
        );
        $publicStop = \strpos(
            $forward,
            '$this->client->request(\'stop\'',
        );
        $forwardRelease = \strpos(
            $forward,
            '->releaseRebootstrapCapacityReserve(',
        );
        $publish = \strpos(
            $forward,
            '$this->packages->publishRebootstrapGeneration(',
            \is_int($forwardRelease) ? $forwardRelease : 0,
        );
        self::assertIsInt($ensure);
        self::assertIsInt($verify);
        self::assertIsInt($publicStop);
        self::assertIsInt($forwardRelease);
        self::assertIsInt($publish);
        self::assertTrue(
            $ensure < $verify
                && $verify < $publicStop
                && $forwardRelease < $publish,
            'Forward rebootstrap must hold capacity before public stop and release it before generation publication.',
        );

        $rollbackReleaseCall = \strpos(
            $rollback,
            '$this->releaseRebootstrapCapacityForRollback(',
        );
        $beginRollback = \strpos(
            $rollback,
            '$this->packages->beginRebootstrapRollback(',
        );
        $platformStop = \strpos(
            $rollbackRelease,
            '$this->platform->stop(',
        );
        $quiescent = \strpos(
            $rollbackRelease,
            '$this->assertRebootstrapQuiescent(',
        );
        $release = \strpos(
            $rollbackRelease,
            '->releaseRebootstrapCapacityReserve(',
        );
        self::assertIsInt($rollbackReleaseCall);
        self::assertIsInt($beginRollback);
        self::assertIsInt($platformStop);
        self::assertIsInt($quiescent);
        self::assertIsInt($release);
        self::assertTrue(
            $rollbackReleaseCall < $beginRollback
                && $platformStop < $quiescent
                && $quiescent < $release,
            'Rollback must prove the old generation quiescent, release capacity, then persist ROLLING_BACK.',
        );
    }

    public function testRebootstrapRollbackConsumesItsExactStopFenceAndRestartsTheRestoredPlatform(): void
    {
        $rollback = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'performRebootstrapRollback',
        ));
        $startGeneration = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'startRebootstrapRollbackGeneration',
        ));
        $authorizeStart = \strpos(
            $rollback,
            "'ROLLBACK_START_AUTHORIZED'",
        );
        $startStateMachine = \strpos(
            $rollback,
            '$this->startRebootstrapRollbackGeneration(',
        );
        $terminal = \strpos(
            $rollback,
            '$this->packages->completeRebootstrapRollback(',
        );
        $cleanup = \strpos(
            $rollback,
            '$this->packages->assertNoActiveRebootstrap(',
        );
        $closure = \strpos(
            $startGeneration,
            '$this->packages->assertRebootstrapRollbackPreStartClosure(',
        );
        $consumeIntent = \strpos(
            $startGeneration,
            '$cleared = $this->clearAdminStoppedIntent(',
        );
        $start = \strpos($startGeneration, '$this->platform->start(');
        $observe = \strpos(
            $startGeneration,
            "'ROLLBACK_OBSERVING'",
            \is_int($start) ? $start : 0,
        );

        self::assertIsInt($authorizeStart);
        self::assertIsInt($startStateMachine);
        self::assertIsInt($terminal);
        self::assertIsInt($cleanup);
        self::assertIsInt($closure);
        self::assertIsInt($consumeIntent);
        self::assertIsInt($start);
        self::assertIsInt($observe);
        self::assertTrue(
            $authorizeStart < $startStateMachine
                && $startStateMachine < $terminal
                && $terminal < $cleanup,
            'Rollback start must remain journaled until the restored generation has started and the terminal transaction can be cleaned up.',
        );
        self::assertTrue(
            $closure < $consumeIntent
                && $consumeIntent < $start
                && $start < $observe,
            'The exact stop fence must be consumed only after closure and before the restored generation starts and enters observation.',
        );
        self::assertMatchesRegularExpression(
            '/\$cleared\s*=\s*\$this->clearAdminStoppedIntent\([\s\S]*?'
                . '!\\\\hash_equals\(\$expectedIntent,\s*\$cleared\)/',
            $startGeneration,
        );
        self::assertMatchesRegularExpression(
            '/\$this->platform->start\(\s*\(string\)\$snapshot\[\'kind\'\],'
                . '\s*\$forwardDeadline,?\s*\)/',
            $startGeneration,
        );
    }

    public function testForwardRebootstrapSealsBackupBeforeStartAuthorization(): void
    {
        $forward = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'rebootstrap',
        ));
        $branchStart = \strpos(
            $forward,
            "if (\\hash_equals('PLATFORM_REFRESHED', \$phase))",
        );
        $nextBranch = \strpos(
            $forward,
            "if (\\hash_equals('START_AUTHORIZED', \$phase))",
            \is_int($branchStart) ? $branchStart : 0,
        );
        self::assertIsInt($branchStart);
        self::assertIsInt($nextBranch);
        $branch = \substr($forward, $branchStart, $nextBranch - $branchStart);
        $seal = \strpos(
            $branch,
            '$this->platform->secureRebootstrapBackup(',
        );
        $fault = \strpos(
            $branch,
            'forward:after-backup-sealed-before-start-authorization',
        );
        $authorize = \strpos(
            $branch,
            '$this->packages->advanceRebootstrapPhase(',
        );
        self::assertIsInt($seal);
        self::assertIsInt($fault);
        self::assertIsInt($authorize);
        self::assertTrue(
            $seal < $fault && $fault < $authorize,
            'The retained generation must be sealed and crash-replayable before START_AUTHORIZED is written.',
        );
    }

    public function testRollbackObservationFailureResetsAndCompensatesBeforeReplay(): void
    {
        $rollback = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'performRebootstrapRollback',
        ));
        $compensation = self::methodSource(new \ReflectionMethod(
            GatewayHostManager::class,
            'compensateFailedGatewayStart',
        ));
        $reserve = \strpos(
            $rollback,
            'operationDeadlineWithRequiredCompensation(',
        );
        $observe = \strpos($rollback, '$this->awaitRebootstrapRollbackIdentity(');
        $intent = \strpos(
            $rollback,
            '$expectedIntent ??= $this->packages',
        );
        $postStartTry = \strrpos(
            \substr($rollback, 0, $intent),
            'try {',
        );
        $startRollback = \strpos(
            $rollback,
            '$this->startRebootstrapRollbackGeneration(',
        );
        $failureHandler = \substr($rollback, $observe);
        self::assertIsString($failureHandler);
        $reset = \strpos(
            $failureHandler,
            '$this->packages->retryRebootstrapRollbackObservation(',
        );
        $resetDeadline = \strpos(
            $failureHandler,
            '$resetDeadline = $this',
        );
        $compensate = \strpos(
            $failureHandler,
            '$this->compensateFailedGatewayStart(',
        );
        self::assertIsInt($reserve);
        self::assertIsInt($observe);
        self::assertIsInt($reset);
        self::assertIsInt($resetDeadline);
        self::assertIsInt($intent);
        self::assertIsInt($postStartTry);
        self::assertIsInt($startRollback);
        self::assertIsInt($compensate);
        self::assertTrue(
            $reserve < $observe
                && $postStartTry < $startRollback
                && $startRollback < $intent
                && $intent < $observe
                && $resetDeadline < $reset
                && $reset < $compensate,
            'A resumed rollback must acquire its fence inside the post-start fail-closed region, reserve compensation time, then stop even if journal reset fails.',
        );
        self::assertStringContainsString(
            'SERVICE_START_COMPENSATION_RESERVE_SECONDS',
            \substr(
                $failureHandler,
                $resetDeadline,
                $reset - $resetDeadline,
            ),
        );
        self::assertStringContainsString(
            '$expectedIntent !== null,',
            \substr($failureHandler, $compensate, 320),
            'Rollback observation compensation must require an exact fence only when acquisition succeeded.',
        );
        self::assertStringContainsString(
            '$expectedIntent,',
            \substr($rollback, $startRollback, $intent - $startRollback),
            'A fresh rollback start must carry its already-verified fence into observation.',
        );
        self::assertStringContainsString(
            'reset to ROLLING_BACK for bounded replay',
            $rollback,
        );
        self::assertStringContainsString(
            'SERVICE_START_COMPENSATION_RESERVE_SECONDS',
            $rollback,
        );

        $stop = \strpos($compensation, '$this->platform->stop(');
        $restore = \strpos($compensation, '$this->restoreAdminStoppedIntent(');
        $proof = \strpos($compensation, '$this->platform->persistentStoppedProof(');
        $intentProof = \strpos(
            $compensation,
            '$this->readVerifiedAdminStoppedIntent(',
        );
        self::assertIsInt($stop);
        self::assertIsInt($restore);
        self::assertIsInt($proof);
        self::assertIsInt($intentProof);
        self::assertTrue(
            $stop < $restore && $restore < $proof && $proof < $intentProof,
            'Rollback-observation compensation must stop the platform, restore the exact ADMIN_STOPPED fence, and prove both before replay.',
        );
        self::assertStringContainsString(
            '$requireExactIntent',
            $compensation,
        );
        self::assertStringContainsString(
            '!\\hash_equals($expectedIntent, $observedIntent)',
            $compensation,
        );
    }

    public function testRebootstrapTrustRotationBindsOnlyANewAuthenticatedEpoch(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'assertRebootstrapObservedEpoch',
        );
        $manager = new GatewayHostManager();
        $runtimeGeneration = \str_repeat('c', 64);
        $oldEpoch = \str_repeat('a', 32);
        $newEpoch = \str_repeat('d', 32);
        $status = [
            ...self::trustedControl(),
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'active_slot' => 'A',
            'runtime_generation' => $runtimeGeneration,
            'epoch' => $newEpoch,
        ];
        $transaction = [
            'runtime_generation' => $runtimeGeneration,
            'old_gateway_epoch' => $oldEpoch,
            'new_gateway_epoch' => '',
            'trust_rotation' => true,
        ];

        self::assertSame(
            $newEpoch,
            $method->invoke($manager, $transaction, $status),
        );

        try {
            $method->invoke(
                $manager,
                $transaction,
                [...$status, 'epoch' => $oldEpoch],
            );
            self::fail('A CA rotation must not reuse the old gateway epoch.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'violates its trust-generation contract',
                $exception->getMessage(),
            );
        }

        try {
            $method->invoke(
                $manager,
                [...$transaction, 'new_gateway_epoch' => $newEpoch],
                [...$status, 'epoch' => \str_repeat('e', 32)],
            );
            self::fail('Recovery must not rebind an already observed epoch.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'violates its trust-generation contract',
                $exception->getMessage(),
            );
        }
    }

    public function testLauncherOnlyRebootstrapRequiresTheOldEpochToRemainExact(): void
    {
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'assertRebootstrapObservedEpoch',
        );
        $manager = new GatewayHostManager();
        $runtimeGeneration = \str_repeat('c', 64);
        $oldEpoch = \str_repeat('a', 32);
        $status = [
            ...self::trustedControl(),
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'active_slot' => 'A',
            'runtime_generation' => $runtimeGeneration,
            'epoch' => $oldEpoch,
        ];
        $transaction = [
            'runtime_generation' => $runtimeGeneration,
            'old_gateway_epoch' => $oldEpoch,
            'new_gateway_epoch' => $oldEpoch,
            'trust_rotation' => false,
        ];

        self::assertSame(
            $oldEpoch,
            $method->invoke($manager, $transaction, $status),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('violates its trust-generation contract');
        $method->invoke(
            $manager,
            $transaction,
            [...$status, 'epoch' => \str_repeat('d', 32)],
        );
    }

    /** @return array<string,mixed> */
    private static function trustedControl(): array
    {
        return [
            'ok' => true,
            'ready' => false,
            'control_plane_ready' => true,
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'protocol' => GatewayPaths::PROTOCOL,
            'implementation_level' => GatewayPaths::IMPLEMENTATION_LEVEL,
            'security_profile' => GatewayPaths::SECURITY_PROFILE,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'epoch' => \str_repeat('a', 32),
            'host_boot_id' => \str_repeat('b', 64),
            'public_http' => 80,
            'public_https' => 443,
        ];
    }

    /** @return array<string,mixed> */
    private static function staleOwnStatus(
        string $projectUuid,
        string $instanceName,
    ): array {
        $route = [
            'route_id' => \str_repeat('d', 32),
            'project_uuid' => $projectUuid,
            'instance_id' => '',
            'status' => 'STALE',
            'route_generation' => 5,
            'backend_instances' => [],
        ];
        return [
            ...self::trustedControl(),
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'publication_exact' => true,
            'project_uuid' => $projectUuid,
            'project_generation' => 9,
            'instances' => [[
                'instance_id' => $instanceName,
                'status' => 'STALE',
                'generation' => 7,
                'digest' => \str_repeat('b', 64),
                'master_epoch' => 11,
                'launch_id' => \str_repeat('c', 32),
            ]],
            'active_routes' => [$route],
            'desired_routes' => [$route],
        ];
    }

    private static function methodSource(\ReflectionMethod $method): string
    {
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
