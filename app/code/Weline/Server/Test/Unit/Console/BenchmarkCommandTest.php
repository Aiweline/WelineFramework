<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Benchmark;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Runtime\RuntimeSelection;

final class BenchmarkCommandTest extends TestCase
{
    public function testResolveBenchmarkPathDefaultsToHealthEndpoint(): void
    {
        $command = $this->createCommand();

        self::assertSame('/_wls/health', $command->resolvePath([]));
    }

    public function testResolveBenchmarkPathKeepsExplicitBusinessPath(): void
    {
        $command = $this->createCommand();

        self::assertSame('/', $command->resolvePath(['path' => '/']));
    }

    public function testResolveBenchmarkPathRepairsGitBashConvertedPath(): void
    {
        $command = $this->createCommand();

        self::assertSame('/_wls/health', $command->resolvePath(['path' => 'C:/Program Files/Git/_wls/health']));
    }

    public function testBenchmarkReportPathIncludesTargetSlugAndAvoidsOverwrite(): void
    {
        $command = $this->createCommand();
        $reportDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-benchmark-test-' . \bin2hex(\random_bytes(4));
        \mkdir($reportDir, 0777, true);

        try {
            $firstPath = $command->reportFilePath($reportDir, 'http://127.0.0.1:21400/__bench/framework', 1800000000.123456);
            \file_put_contents($firstPath, '{}');
            $secondPath = $command->reportFilePath($reportDir, 'http://127.0.0.1:21400/__bench/framework', 1800000000.123456);

            self::assertStringContainsString('_123456_bench-framework_pid', $firstPath);
            self::assertStringEndsWith('_1.json', $secondPath);
            self::assertNotSame($firstPath, $secondPath);
        } finally {
            foreach (\glob($reportDir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($reportDir);
        }
    }

    public function testRuntimeDesiredWorkerCountOverridesPersistedRestartConfiguration(): void
    {
        $command = $this->createCommand();

        self::assertSame(8, $command->selectWorkers(
            configuredWorkers: 1,
            persistedWorkers: 1,
            runtimeStats: [
                'desired_workers' => 8,
                'workers' => 8,
            ],
        ));
        self::assertSame(8, $command->selectWorkers(
            configuredWorkers: 1,
            persistedWorkers: 1,
            runtimeStats: [
                'desired_workers' => 8,
                'workers' => 3,
            ],
        ), 'desired state must remain the readiness threshold while scale-up is incomplete');
    }

    public function testGatewayCarrierCountOverridesReleasedNativeWorkers(): void
    {
        $command = $this->createCommand();

        self::assertSame(8, $command->resolveWorkers([
            'instance' => 'gateway-benchmark',
            'worker_count' => 3,
            'benchmark_carrier_role' => 'gateway_backend',
            'benchmark_expected_carriers' => 8,
        ]));
    }

    public function testHostGatewayTargetUsesSignedProjectRouteInsteadOfManagedNginx(): void
    {
        $status = $this->gatewayStatus();
        $command = $this->createGatewayCommand($status);
        $target = $command->buildTarget('gateway-benchmark', $this->gatewayEndpoint());

        self::assertIsArray($target);
        self::assertSame('127.0.0.1', $target['host']);
        self::assertSame('shop.example.test', $target['authority_host']);
        self::assertSame(443, $target['port']);
        self::assertTrue($target['ssl']);
        self::assertSame('host_gateway_public', $target['target_endpoint_role']);
        self::assertSame('worker', $target['benchmark_carrier_role']);
        self::assertSame(2, $target['benchmark_expected_carriers']);
        self::assertSame(
            ['http/2', 'http/1.1'],
            $target['host_gateway']['public_protocols'],
        );
        self::assertSame([], $target['managed_nginx']);

        $target['target_attribution'] = 'explicit_instance';
        $context = $command->buildContext($target);
        self::assertTrue($context['endpoint_policy_bound']);
        self::assertTrue($context['host_gateway_identity_required']);
        self::assertFalse($context['managed_nginx_generation_required']);
        self::assertSame('http/2', $context['http_default_effective']);
        self::assertSame('worker', $context['benchmark_carrier_role']);
        self::assertSame(2, $context['benchmark_expected_carriers']);
        self::assertTrue($command->gatewayStillMatches($context));

        $status['epoch'] = \str_repeat('f', 32);
        $command->status = $status;
        self::assertFalse($command->gatewayStillMatches($context));
    }

    public function testHostGatewayTargetRejectsRouteOwnedByAnotherPreferredInstance(): void
    {
        $status = $this->gatewayStatus();
        $status['active_routes'][0]['preferred_instance_id'] = 'other-instance';
        $command = $this->createGatewayCommand($status);

        self::assertNull(
            $command->buildTarget('gateway-benchmark', $this->gatewayEndpoint()),
        );
    }

    public function testHostGatewayTargetRejectsDuplicateInstanceIdentity(): void
    {
        $status = $this->gatewayStatus();
        $status['instances'][] = $status['instances'][0];
        $command = $this->createGatewayCommand($status);

        self::assertNull(
            $command->buildTarget('gateway-benchmark', $this->gatewayEndpoint()),
        );
    }

    public function testHostGatewayTargetRejectsAmbiguousActiveRouteIdentity(): void
    {
        $status = $this->gatewayStatus();
        $status['active_routes'][] = [
            ...$status['active_routes'][0],
            'route_id' => \str_repeat('e', 32),
        ];
        $command = $this->createGatewayCommand($status);

        self::assertNull(
            $command->buildTarget('gateway-benchmark', $this->gatewayEndpoint()),
        );
    }

    public function testRecoveredHostGatewayOverridesPersistedPureWlsAdapter(): void
    {
        $endpoint = $this->gatewayEndpoint();
        $endpoint['edge_adapter'] = 'wls';
        $endpoint['gateway']['requested_mode'] = 'auto';
        $endpoint['gateway']['join_backend'] = ['desired_count' => 2];
        $command = $this->createGatewayCommand($this->gatewayStatus());

        $target = $command->buildTarget('gateway-benchmark', $endpoint);

        self::assertIsArray($target);
        self::assertSame('host_gateway_public', $target['target_endpoint_role']);
        self::assertSame(443, $target['port']);
        self::assertTrue($target['ssl']);
        self::assertSame('wls', $target['edge_adapter']);
        self::assertSame('gateway_backend', $target['benchmark_carrier_role']);
        self::assertSame(2, $target['benchmark_expected_carriers']);
    }

    public function testGatewayFallbackUsesItsLeasedHttpsPort(): void
    {
        $endpoint = $this->gatewayEndpoint();
        $endpoint['edge_adapter'] = 'wls';
        $endpoint['ssl_enabled'] = true;
        $endpoint['gateway']['mode'] = 'wls';
        $endpoint['gateway']['fallback_state'] = 'DEGRADED_WLS';
        $endpoint['gateway']['public_https'] = 27673;
        $command = $this->createGatewayCommand($this->gatewayStatus());

        $target = $command->buildTarget('gateway-benchmark', $endpoint);

        self::assertIsArray($target);
        self::assertSame('gateway_fallback_public', $target['target_endpoint_role']);
        self::assertSame(27673, $target['port']);
        self::assertTrue($target['ssl']);
    }

    public function testGatewayFallbackConsumesProjectedHttpScheme(): void
    {
        $endpoint = $this->gatewayEndpoint();
        $endpoint['edge_adapter'] = 'wls';
        $endpoint['ssl_enabled'] = false;
        $endpoint['gateway']['mode'] = 'wls';
        $endpoint['gateway']['fallback_state'] = 'DEGRADED_WLS';
        $endpoint['gateway']['public_https'] = 27674;
        $command = $this->createGatewayCommand($this->gatewayStatus());

        $target = $command->buildTarget('gateway-benchmark', $endpoint);

        self::assertIsArray($target);
        self::assertSame('gateway_fallback_public', $target['target_endpoint_role']);
        self::assertSame(27674, $target['port']);
        self::assertFalse($target['ssl']);
    }

    public function testWildcardOnlyFallbackRefusesToBenchmarkBindIpAsTlsAuthority(): void
    {
        $command = new class extends Benchmark {
            public function __construct()
            {
                $this->__init();
            }

            protected function runtimeGatewayIsServing(array $endpoint): bool
            {
                return false;
            }

            protected function runtimeFallbackServingEndpoint(array $endpoint): ?array
            {
                return null;
            }

            protected function runtimeFallbackServingObservation(array $endpoint): ?array
            {
                return [
                    'bind_host' => '127.0.0.1',
                    'bind_endpoint' => '127.0.0.1:27675',
                    'connect_host' => '127.0.0.1',
                    'authority_host' => null,
                    'route_domains' => ['*.example.test'],
                    'limitations' => ['hostname_and_sni_required'],
                    'port' => 27675,
                    'https' => true,
                ];
            }

            public function buildTarget(string $name, array $endpoint): ?array
            {
                return (new \ReflectionMethod(Benchmark::class, 'buildInstanceTarget'))
                    ->invoke($this, $name, $endpoint);
            }
        };
        $endpoint = $this->gatewayEndpoint();
        $endpoint['edge_adapter'] = 'wls';

        self::assertNull($command->buildTarget('gateway-benchmark', $endpoint));
    }

    public function testExplicitPureWlsRejectsPersistedOriginWithoutLiveLeaseProjection(): void
    {
        $endpoint = $this->gatewayEndpoint();
        $endpoint['edge_adapter'] = 'wls';
        $endpoint['ssl_enabled'] = true;
        $endpoint['public_origin'] = 'https://shop.example.test:29613';
        $endpoint['gateway']['mode'] = 'wls';
        $endpoint['gateway']['requested_mode'] = 'wls';
        $command = $this->createGatewayCommand($this->gatewayStatus());

        self::assertNull($command->buildTarget('gateway-benchmark', $endpoint));
    }

    public function testExpectedReloadRequiresSameMasterAndChangedHealthyWorkerFingerprint(): void
    {
        $command = $this->createCommand();
        $context = $this->qualityGateContext('changed');

        $gate = $command->evaluateGate($context);

        self::assertTrue($gate['passed']);
        self::assertTrue($gate['checks']['worker_runtime_stability']['passed']);
        self::assertSame(
            'same_master_authoritative_ipc_fingerprint_change',
            $gate['checks']['worker_runtime_stability']['actual']['comparison_mode'],
        );
    }

    public function testExpectedReloadFailsWhenWorkerFingerprintDoesNotChange(): void
    {
        $command = $this->createCommand();
        $context = $this->qualityGateContext('changed');
        $context['worker_runtime_after']['ready_fingerprint'] =
            $context['worker_runtime_before']['ready_fingerprint'];

        $gate = $command->evaluateGate($context);

        self::assertFalse($gate['passed']);
        self::assertContains('worker_runtime_did_not_change', $gate['failure_reasons']);
    }

    public function testExpectedReloadFailsClosedWhenTargetHasNoAuthoritativeRuntime(): void
    {
        $command = $this->createCommand();
        $context = $this->qualityGateContext('changed');
        $context['worker_runtime_before'] = [
            'required' => false,
            'captured' => false,
            'healthy' => null,
        ];
        $context['worker_runtime_after'] = $context['worker_runtime_before'];

        $gate = $command->evaluateGate($context);

        self::assertFalse($gate['passed']);
        self::assertTrue($gate['checks']['worker_runtime_stability']['evaluated']);
        self::assertContains('worker_runtime_not_ready', $gate['failure_reasons']);
    }

    public function testDefaultBenchmarkStillRequiresStableWorkerFingerprint(): void
    {
        $command = $this->createCommand();

        $gate = $command->evaluateGate($this->qualityGateContext('stable'));

        self::assertFalse($gate['passed']);
        self::assertContains('worker_runtime_changed', $gate['failure_reasons']);
    }

    public function testAuthenticatedInstanceIpcKeepsBenchmarkAttributionWhenProcessTitleHidesLaunchId(): void
    {
        $command = new class extends Benchmark {
            public array $status = [];

            public function __construct()
            {
                $this->__init();
            }

            public function masterRunning(ServerInstanceInfo $info, string $instanceName): bool
            {
                return $this->isBenchmarkMasterRunning($info, $instanceName);
            }

            protected function readBenchmarkMasterStatus(string $instanceName): array
            {
                return $this->status;
            }
        };
        $info = new ServerInstanceInfo(
            name: 'benchmark-ipc',
            masterPid: 999999999,
            controlPort: 26001,
            host: '127.0.0.1',
            port: 26002,
            sslEnabled: true,
            runtimeSelection: RuntimeSelection::fromArray([
                'requested_topology' => 'direct',
                'effective_topology' => 'direct',
                'topology_source' => 'test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'stream',
                'listener_mode' => PHP_OS_FAMILY === 'Windows' ? 'worker_ports' : 'shared_fd',
                'policy_compatible' => true,
                'reason_codes' => ['test'],
                'reason' => 'test fixture',
            ]),
            workerCount: 2,
            workerBasePort: 26002,
            httpRedirectPort: 0,
            startedAt: '',
            startedTimestamp: 0,
            services: [],
        );

        $command->status = [
            'success' => true,
            'instance' => 'benchmark-ipc',
            'data' => ['running' => true],
        ];
        self::assertTrue($command->masterRunning($info, 'benchmark-ipc'));

        $command->status['instance'] = 'another-instance';
        self::assertFalse($command->masterRunning($info, 'benchmark-ipc'));

        $command->status = ['success' => false];
        self::assertFalse($command->masterRunning($info, 'benchmark-ipc'));
    }

    private function createCommand(): object
    {
        return new class extends Benchmark {
            public function __construct()
            {
                $this->__init();
            }

            public function resolvePath(array $args): string
            {
                return $this->resolveBenchmarkPath($args);
            }

            public function reportFilePath(string $reportDir, string $targetUrl, float $now): string
            {
                return $this->buildReportFilePath($reportDir, $targetUrl, $now);
            }

            public function selectWorkers(
                int $configuredWorkers,
                int $persistedWorkers,
                array $runtimeStats,
            ): int {
                return $this->selectRuntimeWorkerCount(
                    $configuredWorkers,
                    $persistedWorkers,
                    $runtimeStats,
                );
            }

            public function resolveWorkers(array $serverConfig): int
            {
                return $this->resolveRuntimeWorkerCount($serverConfig);
            }

            public function evaluateGate(array $context): array
            {
                $method = new \ReflectionMethod(Benchmark::class, 'evaluateQualityGate');

                return $method->invoke(
                    $this,
                    [1.0],
                    0,
                    1.0,
                    1,
                    [1.0],
                    ['evaluated' => false],
                    $context,
                );
            }
        };
    }

    private function qualityGateContext(string $workerRuntimeExpectation): array
    {
        $snapshot = [
            'required' => true,
            'captured' => true,
            'healthy' => true,
            'identity_authoritative' => true,
            'lease_complete' => true,
            'master_pid' => 1001,
            'ready_workers' => 1,
            'ready_fingerprint' => [
                'worker#1' => [
                    'pid' => 2001,
                    'lease_id' => 'lease-before',
                    'generation' => 1,
                ],
            ],
        ];
        $after = $snapshot;
        $after['ready_fingerprint']['worker#1'] = [
            'pid' => 2002,
            'lease_id' => 'lease-after',
            'generation' => 2,
        ];

        return [
            'quality_gate_thresholds' => [],
            'benchmark_success_count' => 1,
            'physical_connection_target_valid' => true,
            'http_version_requested' => '1.1',
            'http_version_effective' => '1.1',
            'http_version_hits' => ['1.1' => 1],
            'worker_runtime_expectation' => $workerRuntimeExpectation,
            'worker_runtime_before' => $snapshot,
            'worker_runtime_after' => $after,
            'keep_alive' => false,
        ];
    }

    private function createGatewayCommand(array $status): object
    {
        return new class($status) extends Benchmark {
            /** @var array<string,mixed> */
            public array $status;

            public function __construct(array $status)
            {
                $this->status = $status;
                $this->__init();
            }

            protected function readHostGatewayStatus(): array
            {
                return $this->status;
            }

            protected function runtimeGatewayIsServing(array $endpoint): bool
            {
                return (string)($endpoint['gateway']['mode'] ?? '') === 'gateway';
            }

            protected function runtimeFallbackServingEndpoint(array $endpoint): ?array
            {
                $gateway = \is_array($endpoint['gateway'] ?? null)
                    ? $endpoint['gateway']
                    : [];
                if ((string)($gateway['fallback_state'] ?? '') !== 'DEGRADED_WLS') {
                    return null;
                }
                $authorityHost = (string)($endpoint['public_host'] ?? 'shop.example.test');
                $port = (int)($gateway['public_https'] ?? 0);
                $https = (bool)($endpoint['ssl_enabled'] ?? false);
                return [
                    'origin' => ($https ? 'https://' : 'http://')
                        . $authorityHost . ':' . $port,
                    'bind_host' => '127.0.0.1',
                    'connect_host' => '127.0.0.1',
                    'authority_host' => $authorityHost,
                    'port' => $port,
                    'https' => $https,
                ];
            }

            protected function probeHostGatewayPublicProtocols(
                string $connectHost,
                string $authorityHost,
                int $httpsPort,
                array $observation,
            ): array {
                return ['http/2', 'http/1.1'];
            }

            public function buildTarget(string $name, array $endpoint): ?array
            {
                $method = new \ReflectionMethod(Benchmark::class, 'buildInstanceTarget');
                return $method->invoke($this, $name, $endpoint);
            }

            public function buildContext(array $target): array
            {
                $method = new \ReflectionMethod(Benchmark::class, 'buildBenchmarkContext');
                return $method->invoke($this, $target, 10, 100, false, true, 'auto', 'auto');
            }

            public function gatewayStillMatches(array $context): bool
            {
                $method = new \ReflectionMethod(
                    Benchmark::class,
                    'hostGatewayStillMatchesBenchmarkContext',
                );
                return $method->invoke($this, $context);
            }
        };
    }

    /** @return array<string,mixed> */
    private function gatewayEndpoint(): array
    {
        return [
            'schema_version' => 4,
            'runtime_selection' => [
                'requested_topology' => 'dispatcher',
                'effective_topology' => 'dispatcher',
                'topology_source' => 'test',
                'os_family' => PHP_OS_FAMILY,
                'event_loop_driver' => 'select',
                'ssl_engine' => 'disabled',
                'listener_mode' => 'single',
                'policy_compatible' => true,
                'reason_codes' => ['test'],
                'reason' => 'test fixture',
            ],
            'host' => '127.0.0.1',
            'public_host' => 'shop.example.test',
            'port' => 29613,
            'main_port' => 29613,
            'count' => 2,
            'ssl_enabled' => false,
            'edge_adapter' => 'nginx',
            'http_protocol_selection' => [
                'protocols' => ['h1'],
                'preferred' => 'h1',
                'edge' => 'disabled',
                'tls_session_resumption' => false,
                'alt_svc' => false,
            ],
            'gateway' => [
                'mode' => 'gateway',
                'protocol' => 'wls-edge/2',
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174001',
                'instance_id' => 'gateway-benchmark',
                'instance_generation' => 27,
                'launch_id' => \str_repeat('b', 32),
                'epoch' => \str_repeat('a', 32),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function gatewayStatus(): array
    {
        return [
            'ok' => true,
            'ready' => true,
            'project_ready' => true,
            'publication_exact' => true,
            'state' => 'ACTIVE',
            'protocol' => 'wls-edge/2',
            'protocol_min' => 2,
            'protocol_max' => 2,
            'implementation_level' => 'wls-2.0',
            'security_profile' => 'native-broker-v1',
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'epoch' => \str_repeat('a', 32),
            'generation' => 43,
            'active_config_generation' => 43,
            'active_config_digest' => \str_repeat('6', 64),
            'data_plane' => ['running' => true],
            'public_http' => 80,
            'public_https' => 443,
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174001',
            'project_generation' => 27,
            'request_digest' => \str_repeat('7', 64),
            'non_certificate_desired_digest' => \str_repeat('8', 64),
            'instances' => [[
                'instance_id' => 'gateway-benchmark',
                'status' => 'ACTIVE',
                'generation' => 27,
                'launch_id' => \str_repeat('b', 32),
                'master_epoch' => 5,
            ]],
            'active_routes' => [[
                'route_id' => \str_repeat('c', 32),
                'route_generation' => 9,
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174001',
                'domain' => 'shop.example.test',
                'status' => 'ACTIVE',
                'preferred_instance_id' => 'gateway-benchmark',
                'backend_identity' => [
                    'generation' => 27,
                    'launch_id' => \str_repeat('b', 32),
                    'master_epoch' => 5,
                ],
                'certificate' => [
                    'valid' => true,
                    'generation' => 3,
                    'snapshot_digest' => \str_repeat('d', 64),
                ],
            ]],
        ];
    }
}
