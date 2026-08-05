<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityResolver;

final class GatewayBackendCapabilityResolverTest extends TestCase
{
    public function testLaunchSnapshotFreezesCapabilityAndBindsGenerationAndLaunch(): void
    {
        $resolver = new GatewayBackendCapabilityResolver();
        $capability = $resolver->resolve([
            'gateway' => [
                'instance_generation' => 11,
                'backend_capability' => 'stateless',
                'backend_capability_source' => 'runtime_config',
                'backend_capability_generation' => 11,
            ],
        ]);
        $launchId = \str_repeat('a', 32);
        $snapshot = $resolver->createLaunchSnapshot($capability, 11, $launchId);

        self::assertSame(
            $capability,
            $resolver->capabilityFromLaunchSnapshot([
                'gateway' => [
                    'instance_generation' => 11,
                    'launch_id' => $launchId,
                    'backend_capability_launch' => $snapshot,
                ],
            ]),
        );
    }

    public function testLaunchSnapshotRejectsGenerationDriftInsteadOfReprobing(): void
    {
        $resolver = new GatewayBackendCapabilityResolver();
        $capability = $resolver->resolve([
            'gateway' => [
                'instance_generation' => 11,
                'backend_capability' => 'stateless',
                'backend_capability_source' => 'runtime_config',
                'backend_capability_generation' => 11,
            ],
        ]);
        $launchId = \str_repeat('b', 32);
        $snapshot = $resolver->createLaunchSnapshot($capability, 11, $launchId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or stale');
        $resolver->capabilityFromLaunchSnapshot([
            'gateway' => [
                'instance_generation' => 12,
                'launch_id' => $launchId,
                'backend_capability_launch' => $snapshot,
            ],
        ]);
    }

    public function testWorkerAttestationDoesNotReadCapabilityAnswersFromRequestHeaders(): void
    {
        $worker = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/bin/worker.php',
        );

        self::assertStringContainsString(
            'WLS_WORKER_GATEWAY_SESSION_CAPABILITY',
            $worker,
        );
        self::assertStringContainsString(
            'WLS_WORKER_GATEWAY_SESSION_CAPABILITY_EVIDENCE_DIGEST',
            $worker,
        );
        self::assertStringNotContainsString('x-wls-attest-session-capability', $worker);
        self::assertStringNotContainsString('x-wls-attest-evidence-digest', $worker);
    }

    public function testManagedWlsSessionRequiresAuthenticatedLiveRuntimeProof(): void
    {
        $probeCalls = 0;
        $resolver = new GatewayBackendCapabilityResolver(
            configProvider: static fn (): array => [
                'session' => ['default' => 'file', 'wls_managed' => true],
            ],
            healthProbe: static function (array $runtime) use (&$probeCalls): bool {
                $probeCalls++;
                TestCase::assertSame('127.0.0.1', $runtime['host']);
                TestCase::assertSame(20970, $runtime['port']);
                TestCase::assertSame('session_server.20970.token', $runtime['token_file_name']);
                return true;
            },
        );

        $result = $resolver->resolve($this->validEndpoint());

        self::assertSame(1, $probeCalls);
        self::assertSame('shared_session', $result['mode']);
        self::assertSame('wls-session-capability/1', $result['evidence']['schema']);
        self::assertSame('wls', $result['evidence']['storage']);
        self::assertSame('healthy', $result['evidence']['probe']);
        self::assertSame('authenticated_session_runtime', $result['evidence']['reason']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $result['evidence_digest']);
        $encoded = \json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('session_server.20970.token', $encoded);
        self::assertStringNotContainsString('super-secret', $encoded);
    }

    public function testExplicitRuntimeStatelessDeclarationBypassesSessionProbe(): void
    {
        $probeCalls = 0;
        $resolver = new GatewayBackendCapabilityResolver(
            configProvider: static fn (): array => [
                'session' => ['default' => 'file', 'wls_managed' => true],
            ],
            healthProbe: static function (array $runtime) use (&$probeCalls): bool {
                $probeCalls++;
                return true;
            },
        );
        $endpoint = $this->validEndpoint();
        $endpoint['gateway'] = [
            'instance_generation' => 11,
            'backend_capability' => 'stateless',
            'backend_capability_source' => 'runtime_config',
            'backend_capability_generation' => 11,
        ];

        $result = $resolver->resolve($endpoint);

        self::assertSame(0, $probeCalls);
        self::assertSame('stateless', $result['mode']);
        self::assertSame('wls-stateless-capability/1', $result['evidence']['schema']);
        self::assertSame(11, $result['evidence']['instance_generation']);
        self::assertSame('declared_stateless_runtime', $result['evidence']['reason']);
    }

    public function testProjectDesiredCapabilityIgnoresInstanceLocalStatelessGeneration(): void
    {
        $resolver = new GatewayBackendCapabilityResolver();
        $first = $resolver->resolve([
            'gateway' => [
                'instance_generation' => 11,
                'backend_capability' => 'stateless',
                'backend_capability_source' => 'runtime_config',
                'backend_capability_generation' => 11,
            ],
        ]);
        $second = $resolver->resolve([
            'gateway' => [
                'instance_generation' => 12,
                'backend_capability' => 'stateless',
                'backend_capability_source' => 'runtime_config',
                'backend_capability_generation' => 12,
            ],
        ]);

        self::assertNotSame($first['evidence_digest'], $second['evidence_digest']);
        self::assertSame(
            ['policy' => 'runtime_attested'],
            $resolver->projectDesiredState($first),
        );
        self::assertSame(
            ['policy' => 'runtime_attested'],
            $resolver->projectDesiredState($second),
        );
    }

    public function testProjectDesiredPolicyIgnoresSharedAndIsolatedRuntimeObservations(): void
    {
        $resolver = new GatewayBackendCapabilityResolver(
            configProvider: static fn (): array => [
                'session' => ['default' => 'wls', 'wls_managed' => true],
            ],
            healthProbe: static fn (array $runtime): bool => true,
        );
        $shared = $resolver->resolve($this->validEndpoint());

        self::assertSame(
            ['policy' => 'runtime_attested'],
            $resolver->projectDesiredState($shared),
        );
        self::assertSame(
            ['policy' => 'runtime_attested'],
            $resolver->projectDesiredState([
                'mode' => 'isolated',
                'evidence' => [],
                'evidence_digest' => '',
            ]),
            'Project desired generation must not depend on an instance observation.',
        );
    }

    public function testIsolatedDiagnosticChangesDoNotChangeRegistrationIdentity(): void
    {
        $resolver = new GatewayBackendCapabilityResolver();
        $first = [
            'mode' => 'isolated',
            'evidence' => ['schema' => 'wls-session-capability/1', 'reason' => 'unhealthy'],
        ];
        $first['evidence_digest'] = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                $first['evidence'],
            ),
        );
        $second = $first;
        $second['evidence']['reason'] = 'shared_session_recovery_pending';
        $second['evidence_digest'] = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                $second['evidence'],
            ),
        );

        self::assertNotSame($first['evidence_digest'], $second['evidence_digest']);
        self::assertSame(
            ['session_capability' => 'isolated'],
            $resolver->instanceIdentityState($first),
        );
        self::assertSame(
            ['session_capability' => 'isolated'],
            $resolver->instanceIdentityState($second),
        );
    }

    public function testFailedAuthenticatedProbeFailsClosedToIsolated(): void
    {
        $resolver = new GatewayBackendCapabilityResolver(
            configProvider: static fn (): array => [
                'session' => ['default' => 'wls', 'wls_managed' => true],
            ],
            healthProbe: static fn (array $runtime): bool => false,
        );

        $result = $resolver->resolve($this->validEndpoint());

        self::assertSame('isolated', $result['mode']);
        self::assertSame('unhealthy', $result['evidence']['probe']);
        self::assertSame('session_runtime_unhealthy', $result['evidence']['reason']);
    }

    public function testUnmanagedFileStorageCannotBeUpgradedByEndpointHint(): void
    {
        $probeCalls = 0;
        $resolver = new GatewayBackendCapabilityResolver(
            configProvider: static fn (): array => [
                'session' => ['default' => 'file', 'wls_managed' => false],
            ],
            healthProbe: static function (array $runtime) use (&$probeCalls): bool {
                $probeCalls++;
                return true;
            },
        );
        $endpoint = $this->validEndpoint();
        $endpoint['gateway']['backend_capability'] = 'stateless';

        $result = $resolver->resolve($endpoint);

        self::assertSame(0, $probeCalls);
        self::assertSame('isolated', $result['mode']);
        self::assertSame(
            'stateless_runtime_declaration_invalid',
            $result['evidence']['reason'],
        );
    }

    public function testUnregisteredOrNonLoopbackRuntimeIsRejectedBeforeProbe(): void
    {
        $probeCalls = 0;
        $resolver = new GatewayBackendCapabilityResolver(
            configProvider: static fn (): array => [
                'session' => ['default' => 'file', 'wls_managed' => true],
            ],
            healthProbe: static function (array $runtime) use (&$probeCalls): bool {
                $probeCalls++;
                return true;
            },
        );
        $unregistered = $this->validEndpoint();
        $unregistered['shared_state']['session']['registered'] = false;
        $remote = $this->validEndpoint();
        $remote['shared_state']['session']['host'] = '192.0.2.10';

        $first = $resolver->resolve($unregistered);
        $second = $resolver->resolve($remote);

        self::assertSame(0, $probeCalls);
        self::assertSame('isolated', $first['mode']);
        self::assertSame('session_runtime_not_registered', $first['evidence']['reason']);
        self::assertSame('isolated', $second['mode']);
        self::assertSame('session_runtime_not_loopback', $second['evidence']['reason']);
    }

    /** @return array<string,mixed> */
    private function validEndpoint(): array
    {
        return [
            'shared_state' => [
                'session' => [
                    'role' => 'session_server',
                    'host' => '127.0.0.1',
                    'port' => 20970,
                    'token_file_name' => 'session_server.20970.token',
                    'healthy_at' => '2026-07-29T00:00:00+00:00',
                    'shared_service' => true,
                    'registered' => true,
                ],
            ],
            'gateway' => [
                'backend_capability' => 'super-secret-forged-hint',
            ],
        ];
    }
}
