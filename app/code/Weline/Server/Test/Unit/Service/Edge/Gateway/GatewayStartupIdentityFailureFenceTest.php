<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayStartupIdentityFailureFenceTest extends TestCase
{
    private const BOOT_ID =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function setUpBeforeClass(): void
    {
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
    }

    public function testFirstAdoptionIdentityFailureFencesOnlyExactTenantTuple(): void
    {
        $badRouteId = \str_repeat('1', 32);
        $goodRouteId = \str_repeat('2', 32);
        $bad = $this->route(
            $badRouteId,
            '123e4567-e89b-42d3-a456-426614174201',
            'bad-instance',
            29101,
            'b',
        );
        $standby = $bad['instances']['bad-instance'];
        $standby['instance_id'] = 'bad-standby';
        $standby['backends'][0]['port'] = 29111;
        $standby['backend_identity']['instance_id'] = 'bad-standby';
        $standby['backend_identity']['launch_id'] = \str_repeat('9', 32);
        $standby['backend_identity']['listener_lease_id'] = \str_repeat('9', 32);
        $standby['backend_identity']['public_digest'] = \str_repeat('9', 64);
        $standby['launch_id'] = \str_repeat('9', 32);
        $bad['instances']['bad-standby'] = $standby;
        $good = $this->route(
            $goodRouteId,
            '123e4567-e89b-42d3-a456-426614174202',
            'good-instance',
            29102,
            'c',
        );
        $controller = $this->controller([$badRouteId => $bad, $goodRouteId => $good]);
        $this->recordProbe($controller, $bad, false, 'backend_identity');
        $this->recordProbe($controller, $good, true, '');

        $proofs = $this->property($controller, 'publicRouteProbeResults')->getValue(
            $controller,
        );
        $badProof = $proofs[$badRouteId];
        self::assertSame(7, $badProof['generation']);
        self::assertSame($badRouteId, $badProof['route_id']);
        self::assertSame($bad['project_uuid'], $badProof['project_uuid']);
        self::assertSame(3, $badProof['route_generation']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $badProof['backend_identity_tuple_digest'],
        );
        self::assertSame(
            $badProof['route_digest'],
            $this->invoke($controller, 'routeRoutingDigest', [$bad]),
        );
        self::assertSame(
            $badProof['backend_identity_tuple_digest'],
            $this->invoke($controller, 'routeBackendIdentityTupleDigest', [$bad]),
        );
        self::assertSame(self::BOOT_ID, $badProof['boot_id']);
        self::assertFalse($badProof['isolation']);
        self::assertFalse($badProof['success']);
        self::assertSame('backend_identity', $badProof['failure_kind']);
        $age = (float)$this->invoke($controller, 'monotonicNow')
            - (float)$badProof['observed_monotonic'];
        self::assertGreaterThanOrEqual(0.0, $age);
        self::assertLessThanOrEqual(60.0, $age);
        self::assertSame($badRouteId, $bad['route_id']);
        self::assertSame($badProof['project_uuid'], $bad['project_uuid']);
        $checks = [
            'status' => \hash_equals('ACTIVE', (string)$bad['status']),
            'success' => ($badProof['success'] ?? true) === false,
            'isolation' => ($badProof['isolation'] ?? true) === false,
            'boot' => \hash_equals(
                (string)$this->property($controller, 'hostBootId')->getValue($controller),
                (string)$badProof['boot_id'],
            ),
            'generation' => (int)$badProof['generation'] === 7,
            'route_id' => \hash_equals($badRouteId, (string)$badProof['route_id']),
            'project' => \hash_equals(
                (string)$bad['project_uuid'],
                (string)$badProof['project_uuid'],
            ),
            'route_generation' => (int)$bad['route_generation']
                === (int)$badProof['route_generation'],
            'route_digest' => \hash_equals(
                (string)$this->invoke($controller, 'routeRoutingDigest', [$bad]),
                (string)$badProof['route_digest'],
            ),
            'tuple_digest' => \hash_equals(
                (string)$this->invoke(
                    $controller,
                    'routeBackendIdentityTupleDigest',
                    [$bad],
                ),
                (string)$badProof['backend_identity_tuple_digest'],
            ),
            'age' => $age >= 0.0 && $age <= 60.0,
        ];
        self::assertNotContains(false, $checks, \json_encode($checks));
        self::assertTrue($this->invoke(
            $controller,
            'identityFailureProofMatchesRoute',
            [$badProof, $bad, 7],
        ));
        self::assertTrue($this->invoke(
            $controller,
            'publishedRouteMatchesCurrentDesired',
            [$bad, $bad],
        ));

        $assessment = $this->invoke(
            $controller,
            'startupIdentityFailureFenceAssessment',
            [7],
        );
        self::assertSame(1, $assessment['identity_failure_count']);
        self::assertSame(0, $assessment['stale_count']);
        self::assertCount(1, $assessment['bindings']);
        self::assertSame($badRouteId, $assessment['bindings'][0]['route_id']);

        $beforeGood = $this->state($controller)['routes'][$goodRouteId];
        $fenced = $this->invoke(
            $controller,
            'applyStartupIdentityFailureFenceBindings',
            [$assessment['bindings']],
        );
        self::assertSame([$badRouteId], $fenced);
        $after = $this->state($controller);
        self::assertSame('PENDING_BACKEND', $after['routes'][$badRouteId]['status']);
        self::assertSame([], $after['routes'][$badRouteId]['backends']);
        self::assertSame([], $after['routes'][$badRouteId]['backend_instances']);
        self::assertFalse(
            $after['routes'][$badRouteId]['instances']['bad-instance']['backend_healthy'],
        );
        self::assertFalse(
            $after['routes'][$badRouteId]['instances']['bad-standby']['backend_healthy'],
            'A same-route standby must not silently replace an identity-failed tuple.',
        );
        self::assertSame(
            'identity',
            $after['routes'][$badRouteId]['instances']['bad-instance'][
                'last_backend_probe_failure_kind'
            ],
        );
        self::assertSame($beforeGood, $after['routes'][$goodRouteId]);
    }

    public function testGenerationOrBackendTupleRaceDiscardsProofWithoutMutation(): void
    {
        $routeId = \str_repeat('3', 32);
        $route = $this->route(
            $routeId,
            '123e4567-e89b-42d3-a456-426614174203',
            'racing-instance',
            29103,
            'd',
        );
        $controller = $this->controller([$routeId => $route]);
        $this->recordProbe($controller, $route, false, 'backend_identity');
        $state = $this->state($controller);
        $state['active_config_generation'] = 8;
        $this->setState($controller, $state);
        $generationRace = $this->invoke(
            $controller,
            'startupIdentityFailureFenceAssessment',
            [7],
        );
        self::assertSame(1, $generationRace['identity_failure_count']);
        self::assertSame(1, $generationRace['stale_count']);
        self::assertSame([], $generationRace['bindings']);
        self::assertSame('ACTIVE', $this->state($controller)['routes'][$routeId]['status']);

        $controller = $this->controller([$routeId => $route]);
        $this->recordProbe($controller, $route, false, 'backend_identity');
        $state = $this->state($controller);
        $state['routes'][$routeId]['instances']['racing-instance'][
            'backend_identity'
        ]['launch_id'] = \str_repeat('e', 32);
        $this->setState($controller, $state);
        $tupleRace = $this->invoke(
            $controller,
            'startupIdentityFailureFenceAssessment',
            [7],
        );
        self::assertSame(1, $tupleRace['identity_failure_count']);
        self::assertSame(1, $tupleRace['stale_count']);
        self::assertSame([], $tupleRace['bindings']);
        self::assertTrue(
            $this->state($controller)['routes'][$routeId]['instances'][
                'racing-instance'
            ]['backend_healthy'],
        );
    }

    public function testPublicationFailureStateRetainsFenceAndForcesGlobalFailClosed(): void
    {
        $badRouteId = \str_repeat('4', 32);
        $goodRouteId = \str_repeat('5', 32);
        $bad = $this->route(
            $badRouteId,
            '123e4567-e89b-42d3-a456-426614174204',
            'failed-publication',
            29104,
            'f',
        );
        $good = $this->route(
            $goodRouteId,
            '123e4567-e89b-42d3-a456-426614174205',
            'unrelated-tenant',
            29105,
            '1',
        );
        $controller = $this->controller([$badRouteId => $bad, $goodRouteId => $good]);
        $this->recordProbe($controller, $bad, false, 'backend_identity');
        $assessment = $this->invoke(
            $controller,
            'startupIdentityFailureFenceAssessment',
            [7],
        );
        $beforeGood = $this->state($controller)['routes'][$goodRouteId];

        $fenced = $this->invoke(
            $controller,
            'retainStartupIdentityFailureFenceState',
            [$assessment['bindings'], 'injected publication failure'],
        );
        self::assertSame([$badRouteId], $fenced);
        $after = $this->state($controller);
        self::assertSame(8, $after['generation']);
        self::assertFalse($after['ready']);
        self::assertTrue($after['isolation_mode']);
        self::assertSame('SECURITY_MUTATION_FAILED_CLOSED', $after['health_state']);
        self::assertSame(
            'ROUTE_IDENTITY_FENCE_FAILED_CLOSED',
            $after['recovery']['stage'],
        );
        self::assertSame('PENDING_BACKEND', $after['routes'][$badRouteId]['status']);
        self::assertSame($beforeGood, $after['routes'][$goodRouteId]);
        self::assertTrue($this->property($controller, 'configDirty')->getValue($controller));
        self::assertSame(
            [$badRouteId],
            $this->invoke(
                $controller,
                'retainStartupIdentityFailureFenceState',
                [$assessment['bindings'], 'idempotent retry'],
            ),
        );
        self::assertSame(
            8,
            $this->state($controller)['generation'],
            'Retrying a durable fence must not manufacture another generation.',
        );

        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php',
        );
        $fenceSource = $this->methodSource(
            $source,
            'fenceStartupBackendIdentityFailures',
            'publicRouteProbeSweepProofsHealthy',
        );
        self::assertStringContainsString(
            '$this->failClosedStartupIdentityFailureFence(',
            $fenceSource,
        );
        $failureSource = $this->methodSource(
            $source,
            'failClosedStartupIdentityFailureFence',
            'retainStartupIdentityFailureFenceState',
        );
        $stop = \strpos($failureSource, '$this->forceStopSecurityDataPlane()');
        $restart = \strpos($failureSource, '$this->requestServiceTreeRestart($reason)');
        $persist = \strpos($failureSource, '$this->persistState()');
        self::assertIsInt($stop);
        self::assertIsInt($restart);
        self::assertIsInt($persist);
        self::assertLessThan($restart, $stop);
        self::assertLessThan($persist, $stop);
    }

    public function testFailClosedRollbackNeverRestoresPreviousActiveRouteAcrossDeferrals(): void
    {
        $routeId = \str_repeat('6', 32);
        $goodRouteId = \str_repeat('7', 32);
        $active = $this->route(
            $routeId,
            '123e4567-e89b-42d3-a456-426614174206',
            'irreversible-instance',
            29106,
            '2',
        );
        $good = $this->route(
            $goodRouteId,
            '123e4567-e89b-42d3-a456-426614174207',
            'unrelated-instance',
            29107,
            '3',
        );

        foreach ([
            'public_tcp_deferral' => 'DESIRED',
            'lkg_rollback_hold' => 'PREPARED',
            'broker_action_pending' => 'PENDING_PUBLICATION',
            'crash_after_fail_closed_persist' => 'ACTIVATING',
        ] as $scenario => $phase) {
            $controller = $this->controller([
                $routeId => $active,
                $goodRouteId => $good,
            ]);
            $this->recordProbe($controller, $active, false, 'backend_identity');
            $assessment = $this->invoke(
                $controller,
                'startupIdentityFailureFenceAssessment',
                [7],
            );
            $bindings = $assessment['bindings'];
            self::assertCount(1, $bindings, $scenario);
            $fenced = $this->invoke(
                $controller,
                'retainStartupIdentityFailureFenceState',
                [$bindings, $scenario],
            );
            self::assertSame([$routeId], $fenced, $scenario);

            $publication = $this->routeIdentityPublication(
                $controller,
                $bindings,
                $phase,
                [$routeId => $active, $goodRouteId => $good],
            );
            $this->property($controller, 'publication')->setValue(
                $controller,
                $publication,
            );
            $beforeRollbackGood = $this->state($controller)['routes'][$goodRouteId];
            $failClosed = $this->invoke(
                $controller,
                'applyPublicationRollbackDesiredState',
                [$publication['previous'], $scenario],
            );

            self::assertTrue($failClosed, $scenario);
            $after = $this->state($controller);
            self::assertSame(8, $after['generation'], $scenario);
            self::assertSame(
                'PENDING_BACKEND',
                $after['routes'][$routeId]['status'],
                $scenario,
            );
            self::assertSame([], $after['routes'][$routeId]['backends'], $scenario);
            self::assertSame(
                [],
                $after['routes'][$routeId]['backend_instances'],
                $scenario,
            );
            self::assertSame(
                $beforeRollbackGood,
                $after['routes'][$goodRouteId],
                $scenario,
            );
            self::assertSame('ACTIVE', $after['active_routes'][$routeId]['status']);
            self::assertFalse($after['ready'], $scenario);
            self::assertTrue($after['isolation_mode'], $scenario);
            self::assertSame(
                'ROUTE_IDENTITY_FENCE_FAILED_CLOSED',
                $after['recovery']['stage'],
                $scenario,
            );
            self::assertTrue(
                $this->property($controller, 'configDirty')->getValue($controller),
                $scenario,
            );
        }
    }

    public function testDurableBindingReconstructsFenceAfterMarkerOnlyCrash(): void
    {
        $routeId = \str_repeat('8', 32);
        $goodRouteId = \str_repeat('9', 32);
        $active = $this->route(
            $routeId,
            '123e4567-e89b-42d3-a456-426614174208',
            'marker-crash-instance',
            29108,
            '4',
        );
        $good = $this->route(
            $goodRouteId,
            '123e4567-e89b-42d3-a456-426614174209',
            'marker-crash-neighbor',
            29109,
            '5',
        );
        $controller = $this->controller([
            $routeId => $active,
            $goodRouteId => $good,
        ]);
        $this->recordProbe($controller, $active, false, 'backend_identity');
        $assessment = $this->invoke(
            $controller,
            'startupIdentityFailureFenceAssessment',
            [7],
        );
        $bindings = $assessment['bindings'];
        $publication = $this->routeIdentityPublication(
            $controller,
            $bindings,
            'DESIRED',
            [$routeId => $active, $goodRouteId => $good],
        );
        $this->property($controller, 'publication')->setValue(
            $controller,
            $publication,
        );

        self::assertSame(
            $bindings,
            $this->invoke(
                $controller,
                'publicationRouteIdentityFenceBindings',
            ),
        );
        self::assertSame('ACTIVE', $this->state($controller)['routes'][$routeId]['status']);
        $beforeGood = $this->state($controller)['routes'][$goodRouteId];
        $fenced = $this->invoke(
            $controller,
            'retainStartupIdentityFailureFenceState',
            [$bindings, 'marker-only crash'],
        );
        self::assertSame([$routeId], $fenced);
        $after = $this->state($controller);
        self::assertSame(8, $after['generation']);
        self::assertSame('PENDING_BACKEND', $after['routes'][$routeId]['status']);
        self::assertSame($beforeGood, $after['routes'][$goodRouteId]);

        $tampered = $publication;
        $tampered['route_identity_fence_bindings'][0]['route_generation'] = 4;
        $this->property($controller, 'publication')->setValue($controller, $tampered);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binding digest is invalid');
        $this->invoke($controller, 'publicationRouteIdentityFenceBindings');
    }

    public function testSourceOrdersDurableMarkerBeforeMutationAndRecoveryBeforeDeferral(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php',
        );
        $fenceSource = $this->methodSource(
            $source,
            'fenceStartupBackendIdentityFailures',
            'publicRouteProbeSweepProofsHealthy',
        );
        $marker = \strpos(
            $fenceSource,
            '$this->markPublicationFailClosedRouteIdentity($bindings)',
        );
        $mutation = \strpos(
            $fenceSource,
            '$this->applyStartupIdentityFailureFenceBindings($bindings)',
        );
        $generation = \strpos($fenceSource, '$this->bumpGeneration(');
        self::assertIsInt($marker);
        self::assertIsInt($mutation);
        self::assertIsInt($generation);
        self::assertLessThan($mutation, $marker);
        self::assertLessThan($generation, $mutation);

        $reconcile = $this->methodSource(
            $source,
            'reconcileInterruptedPublication',
            'publishIfDirty',
        );
        $recover = \strpos(
            $reconcile,
            '$this->recoverInterruptedRouteIdentityFencePublication()',
        );
        $portDeferral = \strpos(
            $reconcile,
            '$this->publicTcpStartDeferralActive()',
        );
        $brokerPending = \strpos(
            $reconcile,
            '$this->brokerDrivenActionPending()',
        );
        self::assertIsInt($recover);
        self::assertIsInt($portDeferral);
        self::assertIsInt($brokerPending);
        self::assertLessThan($portDeferral, $recover);
        self::assertLessThan($brokerPending, $recover);

        $termination = $this->methodSource(
            $source,
            'terminateRouteIdentityFencePublication',
            'recoverInterruptedRouteIdentityFencePublication',
        );
        self::assertStringContainsString(
            '$this->rollbackRoutingMutation($reason)',
            $termination,
        );
        self::assertStringContainsString(
            "\$this->publication['phase'] = 'FAILED_CLOSED'",
            $termination,
        );
        self::assertStringContainsString('$this->completePublication()', $termination);
    }

    public function testTransportFailureKeepsThreeProbeThresholdWhileIdentityFailsOnce(): void
    {
        $controller = $this->controller([]);
        $transport = ['backend_healthy' => true];
        self::assertTrue($this->applyProbe($controller, $transport, false, 'transport'));
        self::assertTrue($transport['backend_healthy']);
        self::assertTrue($this->applyProbe($controller, $transport, false, 'transport'));
        self::assertTrue($transport['backend_healthy']);
        self::assertFalse($this->applyProbe($controller, $transport, false, 'transport'));
        self::assertFalse($transport['backend_healthy']);
        self::assertSame(3, $transport['backend_probe_failures']);

        $identity = ['backend_healthy' => true];
        self::assertFalse($this->applyProbe($controller, $identity, false, 'identity'));
        self::assertFalse($identity['backend_healthy']);
        self::assertSame(1, $identity['backend_probe_failures']);
    }

    /** @param array<string,array<string,mixed>> $routes */
    private function controller(array $routes): \WlsEdgeGatewayController
    {
        $reflection = new \ReflectionClass(\WlsEdgeGatewayController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $this->property($controller, 'hostBootId')->setValue($controller, self::BOOT_ID);
        $this->setState($controller, [
            'generation' => 7,
            'active_config_generation' => 7,
            'active_routes' => $routes,
            'routes' => $routes,
            'ready' => true,
            'isolation_mode' => false,
            'security_ledger_valid' => true,
            'security' => ['tombstones' => []],
            'recovery' => ['stage' => 'NONE', 'last_failure' => ''],
        ]);
        return $controller;
    }

    /** @return array<string,mixed> */
    private function route(
        string $routeId,
        string $projectUuid,
        string $instanceId,
        int $port,
        string $seed,
    ): array {
        $backend = ['host' => '127.0.0.1', 'port' => $port, 'weight' => 1];
        $identity = [
            'schema' => 'wls-backend-listener-identity/2',
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceId,
            'generation' => 4,
            'master_epoch' => 2,
            'launch_id' => \str_repeat($seed, 32),
            'listener_lease_id' => \str_repeat($seed, 32),
            'edge_capability_digest' => \str_repeat($seed, 64),
            'public_digest' => \str_repeat($seed, 64),
        ];
        $instance = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'master_epoch' => 2,
            'launch_id' => \str_repeat($seed, 32),
            'status' => 'ACTIVE',
            'backend_healthy' => true,
            'backends' => [$backend],
            'backend_identity' => $identity,
            'last_heartbeat' => \time(),
            'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
            'lease_boot_id' => self::BOOT_ID,
        ];
        return [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => $instanceId . '.example.test',
            'status' => 'ACTIVE',
            'route_generation' => 3,
            'force_https' => true,
            'force_root_to_www' => false,
            'certificate' => [
                'valid' => true,
                'state' => 'active',
                'generation' => 1,
                'source_digest' => \hash('sha256', 'source-' . $seed),
                'snapshot_digest' => \hash('sha256', 'snapshot-' . $seed),
            ],
            'instances' => [$instanceId => $instance],
            'preferred_instance_id' => $instanceId,
            'instance_id' => $instanceId,
            'backends' => [$backend],
            'backend_identity' => $identity,
            'backend_instances' => [
                $instanceId => [
                    'instance_id' => $instanceId,
                    'backends' => [$backend],
                    'backend_identity' => $identity,
                ],
            ],
            'distribution_mode' => 'single',
        ];
    }

    /** @param array<string,mixed> $route */
    private function recordProbe(
        \WlsEdgeGatewayController $controller,
        array $route,
        bool $success,
        string $failureKind,
    ): void {
        $this->invoke(
            $controller,
            'recordPublicRouteProbeResult',
            [$route, $success, false, $failureKind],
        );
    }

    /** @param array<string,mixed> $instance */
    private function applyProbe(
        \WlsEdgeGatewayController $controller,
        array &$instance,
        bool $healthy,
        string $failureKind,
    ): bool {
        $method = new \ReflectionMethod($controller, 'applyBackendProbeResult');
        $arguments = [&$instance, $healthy, $failureKind];
        return (bool)$method->invokeArgs($controller, $arguments);
    }

    /**
     * @param list<array<string,mixed>> $bindings
     * @param array<string,array<string,mixed>> $previousRoutes
     * @return array<string,mixed>
     */
    private function routeIdentityPublication(
        \WlsEdgeGatewayController $controller,
        array $bindings,
        string $phase,
        array $previousRoutes,
    ): array {
        $normalized = $this->invoke(
            $controller,
            'normalizePublicationRouteIdentityFenceBindings',
            [$bindings],
        );
        return [
            'schema_version' => 1,
            'transaction_id' => \str_repeat('a', 32),
            'operation' => 'startup-backend-identity-fence',
            'phase' => $phase,
            'previous' => [
                'generation' => 7,
                'projects' => [],
                'instances' => [],
                'routes' => $previousRoutes,
                'acme_challenges' => [],
                'acme_generations' => [],
                'isolation_mode' => false,
                'active_config_generation' => 7,
                'active_routes' => $previousRoutes,
                'active_config_digest' => \str_repeat('b', 64),
                'pending_lkg_generation' => 7,
                'pending_lkg_since' => 1,
                'pending_lkg_since_monotonic' => 1.0,
                'pending_lkg_boot_id' => self::BOOT_ID,
                'pending_lkg_config_digest' => \str_repeat('c', 64),
                'pending_lkg_routes_digest' => \str_repeat('d', 64),
                'config_dirty' => false,
            ],
            'irrevocable_security' => false,
            'fail_closed_route_identity' => true,
            'route_identity_fence_bindings' => $normalized,
            'route_identity_fence_digest' => \hash(
                'sha256',
                (string)$this->invoke($controller, 'canonicalJson', [$normalized]),
            ),
            'operation_ids' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function state(\WlsEdgeGatewayController $controller): array
    {
        return $this->property($controller, 'state')->getValue($controller);
    }

    /** @param array<string,mixed> $state */
    private function setState(
        \WlsEdgeGatewayController $controller,
        array $state,
    ): void {
        $this->property($controller, 'state')->setValue($controller, $state);
    }

    private function property(object $object, string $name): \ReflectionProperty
    {
        return new \ReflectionProperty($object, $name);
    }

    private function invoke(
        object $object,
        string $method,
        array $arguments = [],
    ): mixed {
        return (new \ReflectionMethod($object, $method))->invokeArgs(
            $object,
            $arguments,
        );
    }

    private function methodSource(
        string $source,
        string $method,
        string $nextMethod,
    ): string {
        $start = \strpos($source, 'private function ' . $method . '(');
        $end = \strpos($source, 'private function ' . $nextMethod . '(', (int)$start + 1);
        self::assertIsInt($start, 'Missing method ' . $method);
        self::assertIsInt($end, 'Missing method ' . $nextMethod);
        return \substr($source, $start, $end - $start);
    }
}
