<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

/**
 * Real high-port WLS 2.0 gateway acceptance.
 *
 * This test is deliberately opt-in: it starts a task-owned native Broker,
 * Controller, Nginx and loopback backends, then performs real TLS/ALPN and
 * process-recovery probes. It never binds 80/443 or touches a system service.
 */
final class GatewayDataPlaneRecoveryTest extends TestCase
{
    private const HTTP3_CURL = '/opt/homebrew/opt/curl/bin/curl';

    /** @var array<string,string|false> */
    private array $environment = [];
    /** @var array<string,resource> */
    private array $processes = [];
    /** @var list<int> */
    private array $backendPids = [];
    /** @var array<int,string> */
    private array $protectedPortOwners = [];
    private string $root = '';
    private string $home = '';
    private string $broker = '';
    private string $controller = '';
    private string $curl = '';
    private string $openssl = '';
    private string $nginxSeed = '';
    private int $httpPort = 0;
    private int $httpsPort = 0;
    private int $healthPort = 0;
    private bool $h3Enabled = false;
    private GatewayPaths $paths;

    protected function setUp(): void
    {
        if ((string)\getenv('WLS_RUN_GATEWAY_DATA_PLANE_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set WLS_RUN_GATEWAY_DATA_PLANE_INTEGRATION=1 for real gateway data-plane acceptance.',
            );
        }
        if ($this->name() === 'testCurrentSourceGatewayHandlesOneMillionTenantBoundHttp2Requests'
            && (string)\getenv('WLS_RUN_GATEWAY_MILLION_INTEGRATION') !== '1'
        ) {
            self::markTestSkipped(
                'Set WLS_RUN_GATEWAY_MILLION_INTEGRATION=1 for the exact 1,000,000-request gate.',
            );
        }
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('pcntl_fork')
            || !\function_exists('posix_kill')
            || !\function_exists('stream_socket_server')
        ) {
            self::markTestSkipped('The real data-plane harness currently requires a POSIX host.');
        }
        $this->curl = \is_executable(self::HTTP3_CURL)
            ? self::HTTP3_CURL
            : (string)$this->which('curl');
        if ($this->curl === '') {
            self::markTestSkipped('curl is required for real gateway protocol acceptance.');
        }
        $this->openssl = $this->findTlsAcceptanceOpenSsl();
        if ($this->openssl === '') {
            self::markTestSkipped(
                'OpenSSL with TLS 1.3 early-data support is required for TLS session acceptance.',
            );
        }
        $configuredNginx = \trim((string)\getenv('WLS_GATEWAY_TEST_NGINX'));
        $this->nginxSeed = $configuredNginx !== ''
            ? $configuredNginx
            : \dirname(__DIR__, 9)
                . '/var/server/nginx-validation-install-1304/sbin/nginx';
        if (!\is_executable($this->nginxSeed)) {
            self::markTestSkipped(
                'Set WLS_GATEWAY_TEST_NGINX to an executable Nginx with HTTP/2 and HTTP/3.',
            );
        }
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
            'WLS_GATEWAY_HEALTH_PORT',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }

        $temporaryRoot = (string)\realpath(\sys_get_temp_dir());
        self::assertNotSame('', $temporaryRoot);
        $temporaryRoot = \rtrim($temporaryRoot, '/\\');
        // Darwin limits Unix-domain socket paths to roughly 104 bytes.
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wdp-'
            . \bin2hex(\random_bytes(3));
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'home';
        self::assertTrue(\mkdir($this->root, 0700, true));
        $this->httpPort = $this->reservePort();
        $this->httpsPort = $this->reservePort();
        $this->healthPort = $this->reservePort();
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->home);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=' . $this->httpPort);
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=' . $this->httpsPort);
        \putenv('WLS_GATEWAY_HEALTH_PORT=' . $this->healthPort);
        $this->paths = new GatewayPaths();
        $this->home = $this->paths->home();
        $this->protectedPortOwners = $this->protectedPortOwners();

        $this->seedGatewayRuntime();
        $this->compileBroker();
        $this->startBroker();
        $this->startController('controller-primary');
        $this->waitForSocket($this->paths->controllerSocketFile(), 15.0);
        $this->waitForHttp(
            'http://127.0.0.1:' . $this->healthPort . '/__wls_gateway_health',
            200,
            20.0,
        );
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse(\array_keys($this->processes)) as $name) {
            $this->stopProcess($name);
        }
        $this->stopOwnedNginx();
        foreach ($this->backendPids as $pid) {
            if ($pid > 0 && @\posix_kill($pid, 0)) {
                @\posix_kill($pid, SIGTERM);
            }
        }
        foreach ($this->backendPids as $pid) {
            if ($pid > 0) {
                @\pcntl_waitpid($pid, $ignored);
            }
        }
        $this->assertProtectedPortOwnersUnchanged();
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testWallClockShimKeepsMonotonicClockIndependent(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('The wall-clock injection shim is macOS-specific.');
        }
        $shim = $this->compileWallClockShim();
        $script = 'echo json_encode(["wall" => time(), "mono" => hrtime(true)]);';
        $baseline = $this->runCommandWithEnvironment([PHP_BINARY, '-r', $script]);
        self::assertSame(0, $baseline['code'], $baseline['output']);
        $shifted = $this->runCommandWithEnvironment([
            PHP_BINARY,
            '-r',
            $script,
        ], [
            'DYLD_INSERT_LIBRARIES' => $shim,
            'DYLD_FORCE_FLAT_NAMESPACE' => '1',
            'WLS_TEST_WALL_OFFSET' => '3600',
        ]);
        self::assertSame(0, $shifted['code'], $shifted['output']);
        $baselineClocks = \json_decode($baseline['output'], true, 512, JSON_THROW_ON_ERROR);
        $shiftedClocks = \json_decode($shifted['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEqualsWithDelta(
            (int)$baselineClocks['wall'] + 3600,
            (int)$shiftedClocks['wall'],
            5,
        );
        self::assertLessThan(
            5_000_000_000,
            \abs((int)$shiftedClocks['mono'] - (int)$baselineClocks['mono']),
        );
    }

    public function testColdStartPublishesExpiredLeasesAs503BeforeOpeningPublicTraffic(): void
    {
        $status = $this->adminClient()->administratorStatus();
        self::assertTrue($status['ok']);
        $fixture = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-426614174190',
            'cold-start.wls.test',
            'cold-start-project-marker',
            'cold-start-a',
        );
        $client = $this->enroll($fixture);
        $registrations = $this->registerConcurrently([[
            $client,
            $fixture,
            (string)$status['payload']['epoch'],
        ]]);
        $this->waitForCommitted(
            $client,
            $fixture['project_uuid'],
            $registrations[0],
        );
        self::assertSame(200, $this->curlRoute($fixture['domain'])['http_code']);

        $this->stopProcess('controller-primary');
        $this->stopOwnedNginx();
        self::assertSame(0, $this->nginxPid(), 'Task-owned Nginx did not stop for cold-start testing.');
        $state = $this->readGatewayState();
        $expiredAt = \time() - 120;
        foreach ((array)($state['instances'] ?? []) as $projectUuid => $instances) {
            foreach ((array)$instances as $instanceId => $instance) {
                $state['instances'][$projectUuid][$instanceId]['last_heartbeat']
                    = $expiredAt;
                $state['instances'][$projectUuid][$instanceId]['last_heartbeat_monotonic']
                    = 1.0;
            }
        }
        foreach ((array)($state['routes'] ?? []) as $routeId => $route) {
            foreach ((array)($route['instances'] ?? []) as $instanceId => $instance) {
                $state['routes'][$routeId]['instances'][$instanceId]['last_heartbeat']
                    = $expiredAt;
                $state['routes'][$routeId]['instances'][$instanceId]['last_heartbeat_monotonic']
                    = 1.0;
            }
        }
        $this->writeGatewayState($state);

        $this->startController('controller-primary');
        $firstPublicResponse = [];
        $deadline = \microtime(true) + 20.0;
        do {
            $firstPublicResponse = $this->curlRoute($fixture['domain']);
            if ($firstPublicResponse['http_code'] !== 0) {
                break;
            }
            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        $startupState = $this->readGatewayState();
        self::assertSame(
            503,
            $firstPublicResponse['http_code'] ?? 0,
            'Expired persisted leases must never receive an ACTIVE startup window: '
                . \json_encode([
                    'response' => $firstPublicResponse,
                    'route_statuses' => \array_values(\array_map(
                        static fn (array $route): string => (string)($route['status'] ?? ''),
                        (array)($startupState['routes'] ?? []),
                    )),
                    'health_state' => (string)($startupState['health_state'] ?? ''),
                    'config_generation' => (int)($startupState['active_config_generation'] ?? 0),
                    'controller_log' => $this->processLog('controller-primary'),
                ], JSON_UNESCAPED_SLASHES),
        );
        self::assertSame(
            ['STALE'],
            \array_values(\array_map(
                static fn (array $route): string => (string)($route['status'] ?? ''),
                (array)($startupState['routes'] ?? []),
            )),
        );
        $this->waitForSocket($this->paths->controllerSocketFile(), 15.0);
        $recovered = $this->waitForAdminStatus(15.0);
        self::assertSame(
            0,
            $recovered['payload']['route_counts']['ACTIVE'] ?? 0,
            \json_encode($recovered['payload'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testCurrentSourceGatewayHandlesOneMillionTenantBoundHttp2Requests(): void
    {
        $h2load = $this->which('h2load');
        if ($h2load === '') {
            self::markTestSkipped('h2load is required for the exact Gateway million-request gate.');
        }

        $initialStatus = $this->adminClient()->administratorStatus();
        self::assertTrue($initialStatus['ok'], \json_encode($initialStatus));
        $gatewayEpoch = (string)($initialStatus['payload']['epoch'] ?? '');
        self::assertNotSame('', $gatewayEpoch);

        $alpha = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-4266141741a1',
            'million-alpha.wls.test',
            'million-alpha-project-marker',
            'million-alpha-a',
        );
        $beta = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-4266141741b2',
            'million-beta.wls.test',
            'million-beta-project-marker',
            'million-beta-a',
        );
        $alphaClient = $this->enroll($alpha);
        $betaClient = $this->enroll($beta);
        $registrations = $this->registerConcurrently([
            [$alphaClient, $alpha, $gatewayEpoch],
            [$betaClient, $beta, $gatewayEpoch],
        ]);
        $this->waitForCommitted($alphaClient, $alpha['project_uuid'], $registrations[0]);
        $this->waitForCommitted($betaClient, $beta['project_uuid'], $registrations[1]);

        $beforeStatus = $this->adminClient()->administratorStatus();
        self::assertTrue($beforeStatus['ok'], \json_encode($beforeStatus));
        self::assertSame(
            2,
            $beforeStatus['payload']['route_counts']['ACTIVE'] ?? 0,
            \json_encode($beforeStatus['payload'], JSON_UNESCAPED_SLASHES),
        );
        $nginxPidBefore = $this->nginxPid();
        self::assertGreaterThan(0, $nginxPidBefore);
        $routeGenerationBefore = (int)($beforeStatus['payload']['generation'] ?? 0);
        self::assertGreaterThan(0, $routeGenerationBefore);

        $this->assertMillionTenantRouteResponse($this->curlRoute($alpha['domain'], '--http2'), $alpha);
        $this->assertMillionTenantRouteResponse($this->curlRoute($beta['domain'], '--http2'), $beta);
        $alphaCounterBefore = $this->normalRequestCount($alpha);
        $betaCounterBefore = $this->normalRequestCount($beta);

        $alphaChunks = [];
        $betaChunks = [];
        $requestsPerChunk = 10_000;
        $chunksPerTenant = 50;
        $heartbeatDue = 0;
        $loadStarted = \hrtime(true);
        for ($chunk = 0; $chunk < $chunksPerTenant; $chunk++) {
            if (\hrtime(true) >= $heartbeatDue) {
                $this->refreshMillionTenantLease($alphaClient, $alpha, $gatewayEpoch);
                $this->refreshMillionTenantLease($betaClient, $beta, $gatewayEpoch);
                $heartbeatDue = \hrtime(true) + 8_000_000_000;
            }
            $alphaChunks[] = $this->runH2loadChunk(
                $h2load,
                (string)$alpha['domain'],
                $requestsPerChunk,
            );

            if (\hrtime(true) >= $heartbeatDue) {
                $this->refreshMillionTenantLease($alphaClient, $alpha, $gatewayEpoch);
                $this->refreshMillionTenantLease($betaClient, $beta, $gatewayEpoch);
                $heartbeatDue = \hrtime(true) + 8_000_000_000;
            }
            $betaChunks[] = $this->runH2loadChunk(
                $h2load,
                (string)$beta['domain'],
                $requestsPerChunk,
            );
            self::assertSame(
                $nginxPidBefore,
                $this->nginxPid(),
                'The shared Gateway Nginx PID changed during the million-request gate.',
            );
        }
        $loadDurationSeconds = (\hrtime(true) - $loadStarted) / 1_000_000_000;

        $alphaSummary = $this->summarizeH2loadChunks($alphaChunks);
        $betaSummary = $this->summarizeH2loadChunks($betaChunks);
        foreach ([$alphaSummary, $betaSummary] as $summary) {
            self::assertSame(500_000, $summary['requested']);
            self::assertSame(500_000, $summary['started']);
            self::assertSame(500_000, $summary['done']);
            self::assertSame(500_000, $summary['succeeded']);
            self::assertSame(500_000, $summary['status_2xx']);
            self::assertSame(0, $summary['failed']);
            self::assertSame(0, $summary['errored']);
            self::assertSame(0, $summary['timeout']);
            self::assertSame(0, $summary['status_3xx']);
            self::assertSame(0, $summary['status_4xx']);
            self::assertSame(0, $summary['status_5xx']);
        }
        self::assertSame(
            500_000,
            $this->normalRequestCount($alpha) - $alphaCounterBefore,
            'Alpha traffic did not remain bound to the exact alpha backend.',
        );
        self::assertSame(
            500_000,
            $this->normalRequestCount($beta) - $betaCounterBefore,
            'Beta traffic did not remain bound to the exact beta backend.',
        );

        $this->assertMillionTenantRouteResponse($this->curlRoute($alpha['domain'], '--http2'), $alpha);
        $this->assertMillionTenantRouteResponse($this->curlRoute($beta['domain'], '--http2'), $beta);
        $afterStatus = $this->adminClient()->administratorStatus();
        self::assertTrue($afterStatus['ok'], \json_encode($afterStatus));
        self::assertSame($gatewayEpoch, (string)($afterStatus['payload']['epoch'] ?? ''));
        self::assertSame(
            $routeGenerationBefore,
            (int)($afterStatus['payload']['generation'] ?? 0),
            'The active Gateway route generation changed during the million-request gate.',
        );
        self::assertSame(
            $nginxPidBefore,
            $this->nginxPid(),
            'The shared Gateway Nginx PID changed during the million-request gate.',
        );
        self::assertSame(
            2,
            $afterStatus['payload']['route_counts']['ACTIVE'] ?? 0,
            \json_encode($afterStatus['payload'], JSON_UNESCAPED_SLASHES),
        );

        $requested = $alphaSummary['requested'] + $betaSummary['requested'];
        $succeeded = $alphaSummary['succeeded'] + $betaSummary['succeeded'];
        $failures = $alphaSummary['failed'] + $betaSummary['failed']
            + $alphaSummary['errored'] + $betaSummary['errored']
            + $alphaSummary['timeout'] + $betaSummary['timeout']
            + $alphaSummary['status_3xx'] + $betaSummary['status_3xx']
            + $alphaSummary['status_4xx'] + $betaSummary['status_4xx']
            + $alphaSummary['status_5xx'] + $betaSummary['status_5xx'];
        $qualityGatePassed = $requested === 1_000_000
            && $succeeded === 1_000_000
            && $failures === 0
            && $this->nginxPid() === $nginxPidBefore
            && (string)($afterStatus['payload']['epoch'] ?? '') === $gatewayEpoch
            && (int)($afterStatus['payload']['generation'] ?? 0) === $routeGenerationBefore;
        self::assertTrue($qualityGatePassed);

        $serverRoot = \dirname(__DIR__, 5);
        $report = [
            'report_schema_version' => 1,
            'generated_at' => \gmdate(DATE_ATOM),
            'benchmark_target_surface' => 'public_edge',
            'target_endpoint_role' => 'current_source_test_gateway',
            'platform' => \PHP_OS_FAMILY,
            'requested_requests' => $requested,
            'requests' => $succeeded,
            'status_codes' => ['200' => $succeeded],
            'failures' => $failures,
            'http_version_hits' => ['2' => $succeeded],
            'duration_seconds' => \round($loadDurationSeconds, 6),
            'qps' => $loadDurationSeconds > 0.0
                ? \round($succeeded / $loadDurationSeconds, 3)
                : 0.0,
            'nginx_pid_before' => $nginxPidBefore,
            'nginx_pid_after' => $this->nginxPid(),
            'gateway_epoch_before' => $gatewayEpoch,
            'gateway_epoch_after' => (string)($afterStatus['payload']['epoch'] ?? ''),
            'route_generation_before' => $routeGenerationBefore,
            'route_generation_after' => (int)($afterStatus['payload']['generation'] ?? 0),
            'tenants' => [
                'alpha' => $alphaSummary + [
                    'domain' => $alpha['domain'],
                    'project_uuid' => $alpha['project_uuid'],
                    'backend_counter_delta' => $this->normalRequestCount($alpha)
                        - $alphaCounterBefore - 1,
                ],
                'beta' => $betaSummary + [
                    'domain' => $beta['domain'],
                    'project_uuid' => $beta['project_uuid'],
                    'backend_counter_delta' => $this->normalRequestCount($beta)
                        - $betaCounterBefore - 1,
                ],
            ],
            'current_source_sha256' => [
                'controller' => (string)\hash_file(
                    'sha256',
                    $serverRoot . '/bin/wls_gateway_controller.php',
                ),
                'broker_source' => (string)\hash_file(
                    'sha256',
                    $serverRoot . '/Service/Edge/Gateway/Native/posix/wls_gateway_broker.c',
                ),
                'broker_binary' => (string)\hash_file('sha256', $this->broker),
                'test' => (string)\hash_file('sha256', __FILE__),
                'nginx_binary' => (string)\hash_file(
                    'sha256',
                    $this->home . '/slots/A/bin/nginx',
                ),
                'nginx_config' => (string)\hash_file(
                    'sha256',
                    $this->home . '/runtime/conf/nginx.conf',
                ),
            ],
            'quality_gate' => [
                'passed' => $qualityGatePassed,
                'checks' => [
                    'exact_request_completion' => $requested === 1_000_000
                        && $succeeded === 1_000_000,
                    'zero_failures' => $failures === 0,
                    'all_http2' => $succeeded === 1_000_000,
                    'tenant_backend_binding' => true,
                    'nginx_pid_stable' => $this->nginxPid() === $nginxPidBefore,
                    'gateway_epoch_stable'
                        => (string)($afterStatus['payload']['epoch'] ?? '') === $gatewayEpoch,
                    'route_generation_stable'
                        => (int)($afterStatus['payload']['generation'] ?? 0)
                            === $routeGenerationBefore,
                ],
            ],
        ];
        $reportDirectory = \dirname(__DIR__, 9) . '/var/log/wls';
        if (!\is_dir($reportDirectory)) {
            self::assertTrue(\mkdir($reportDirectory, 0775, true));
        }
        $reportPath = $reportDirectory . '/benchmark_report_'
            . \gmdate('Ymd_His') . '_' . \bin2hex(\random_bytes(3))
            . '_gateway-current-source_pid' . $nginxPidBefore . '.json';
        $encodedReport = \json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        self::assertSame(\strlen($encodedReport), \file_put_contents($reportPath, $encodedReport));
        @\fwrite(\STDOUT, 'WLS_GATEWAY_MILLION_REPORT=' . $reportPath . "\n");
    }

    public function testMultiProjectProtocolsAndOwnedProcessRecovery(): void
    {
        $initialStatus = $this->adminClient()->administratorStatus();
        self::assertTrue($initialStatus['ok']);
        self::assertSame('wls-edge/2', $initialStatus['payload']['protocol']);
        self::assertSame($this->httpPort, $initialStatus['payload']['public_http']);
        self::assertSame($this->httpsPort, $initialStatus['payload']['public_https']);
        self::assertTrue(
            $initialStatus['payload']['data_plane']['running'],
            \json_encode($initialStatus['payload'], JSON_UNESCAPED_SLASHES),
        );
        $gatewayEpoch = (string)$initialStatus['payload']['epoch'];

        $alpha = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-426614174101',
            'alpha.wls.test',
            'alpha-project-marker',
            'alpha-a',
        );
        $alpha = $this->addWorkerBackend($alpha, 'worker-b');
        $beta = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-426614174102',
            'beta.wls.test',
            'beta-project-marker',
            'beta-a',
        );

        $alphaClient = $this->enroll($alpha);
        $betaClient = $this->enroll($beta);
        $registrations = $this->registerConcurrently([
            [$alphaClient, $alpha, $gatewayEpoch],
            [$betaClient, $beta, $gatewayEpoch],
        ]);
        $this->waitForCommitted($alphaClient, $alpha['project_uuid'], $registrations[0]);
        $this->waitForCommitted($betaClient, $beta['project_uuid'], $registrations[1]);

        $status = $this->adminClient()->administratorStatus();
        self::assertSame(
            2,
            $status['payload']['route_counts']['ACTIVE'] ?? 0,
            \json_encode($status['payload'], JSON_UNESCAPED_SLASHES),
        );
        self::assertIsBool($status['payload']['h3_enabled'] ?? null);
        $this->h3Enabled = (bool)$status['payload']['h3_enabled'];

        $acme = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-426614174104',
            'acme.wls.test',
            'acme-project-marker',
            'acme-a',
        );
        $acmeClient = $this->enroll($acme);
        $pendingCertificate = $this->registrationPayload($acme, $gatewayEpoch);
        $pendingSourceDigest = \hash(
            'sha256',
            "wls-pending-certificate\0" . $acme['domain'],
        );
        $pendingCertificate['routes'][0]['certificate'] = [
            'state' => 'pending',
            'valid' => false,
            'pending' => true,
            'cert' => [],
            'key' => [],
            'chain' => null,
            'source_digest' => $pendingSourceDigest,
            'trust_profile' => 'test',
            'provider' => 'none',
            'material_class' => 'none',
            'provenance_digest' => \hash(
                'sha256',
                "wls-inactive-certificate-provenance/1\0" . $acme['domain']
                    . "\0pending\0" . $pendingSourceDigest . "\0" . 0
                    . "\0test",
            ),
            'generation' => 0,
        ];
        $pendingCertificate = $this->sealRegistrationPayload($pendingCertificate);
        $pendingRegistration = $this->projectMutationWithRetry(
            $acmeClient,
            'register',
            $pendingCertificate,
        );
        self::assertTrue($pendingRegistration['ok'], \json_encode($pendingRegistration));
        $this->waitForCommitted(
            $acmeClient,
            $acme['project_uuid'],
            (string)$pendingRegistration['payload']['operation_id'],
        );
        $pendingStatus = $this->adminClient()->administratorStatus();
        self::assertSame(
            1,
            $pendingStatus['payload']['route_counts']['PENDING_CERTIFICATE'] ?? 0,
            \json_encode($pendingStatus['payload'], JSON_UNESCAPED_SLASHES),
        );
        $challengeToken = 'WLS_ACME_' . \bin2hex(\random_bytes(8));
        $keyAuthorization = $challengeToken . '.' . \str_repeat('A', 43);
        $challengeSync = $this->projectMutationWithRetry(
            $acmeClient,
            'acme-challenge-sync',
            [
            'project_uuid' => $acme['project_uuid'],
            'challenge_generation' => 1,
            'challenges' => [[
                'domain' => $acme['domain'],
                'token' => $challengeToken,
                'key_authorization' => $keyAuthorization,
                'expires_at' => \time() + 300,
            ]],
        ]);
        self::assertTrue($challengeSync['ok'], \json_encode($challengeSync));
        $this->waitForCommitted(
            $acmeClient,
            $acme['project_uuid'],
            (string)$challengeSync['payload']['operation_id'],
        );
        $challengePath = '/.well-known/acme-challenge/' . $challengeToken;
        $challenge = $this->curlHttp($acme['domain'], $challengePath);
        self::assertSame(0, $challenge['code'], $challenge['output']);
        self::assertSame(200, $challenge['http_code'], $challenge['output']);
        self::assertSame($keyAuthorization, \trim($challenge['body']));
        $unleasedChallenge = $this->curlHttp(
            $acme['domain'],
            '/.well-known/acme-challenge/not-authorized',
        );
        self::assertSame(404, $unleasedChallenge['http_code']);
        $pendingHttps = $this->curlRoute($acme['domain']);
        self::assertSame(421, $pendingHttps['http_code'], $pendingHttps['output']);
        self::assertStringNotContainsString('acme-project-marker', $pendingHttps['body']);

        ++$acme['project_generation'];
        $acme['project_digest'] = \hash(
            'sha256',
            $acme['project_uuid'] . ':project:' . $acme['project_generation'],
        );
        $certificateReady = $this->projectMutationWithRetry(
            $acmeClient,
            'register',
            $this->registrationPayload($acme, $gatewayEpoch),
        );
        self::assertTrue($certificateReady['ok'], \json_encode($certificateReady));
        $this->waitForCommitted(
            $acmeClient,
            $acme['project_uuid'],
            (string)$certificateReady['payload']['operation_id'],
        );
        $activeAcme = $this->curlRoute($acme['domain']);
        self::assertSame(200, $activeAcme['http_code'], $activeAcme['output']);
        self::assertStringContainsString('acme-project-marker', $activeAcme['body']);
        self::assertSame(
            \strtolower($acme['certificate_fingerprint']),
            \strtolower($this->peerCertificateFingerprint($acme['domain'])),
        );
        $challengeClear = $this->projectMutationWithRetry(
            $acmeClient,
            'acme-challenge-sync',
            [
            'project_uuid' => $acme['project_uuid'],
            'challenge_generation' => 2,
            'challenges' => [],
        ]);
        self::assertTrue($challengeClear['ok'], \json_encode($challengeClear));
        $this->waitForCommitted(
            $acmeClient,
            $acme['project_uuid'],
            (string)$challengeClear['payload']['operation_id'],
        );
        self::assertSame(
            308,
            $this->curlHttp($acme['domain'], $challengePath)['http_code'],
        );

        foreach ([
            [$alpha, 'alpha-project-marker'],
            [$beta, 'beta-project-marker'],
        ] as [$fixture, $marker]) {
            $h1 = $this->curlRoute($fixture['domain'], '--http1.1');
            self::assertSame(0, $h1['code'], $h1['output']);
            self::assertSame('1.1', $h1['http_version']);
            self::assertSame(200, $h1['http_code']);
            self::assertStringContainsString($marker, $h1['body']);

            $h2 = $this->curlRoute($fixture['domain'], '--http2');
            self::assertSame(0, $h2['code'], $h2['output']);
            self::assertSame('2', $h2['http_version']);
            self::assertSame(200, $h2['http_code']);
            self::assertStringContainsString($marker, $h2['body']);

            if ($this->h3IsCurrentlyEnabled() && $this->curlSupportsHttp3()) {
                $h3 = $this->waitForSuccessfulRoute(
                    $fixture['domain'],
                    '--http3-only',
                    15.0,
                );
                self::assertSame(0, $h3['code'], $h3['output']);
                self::assertSame('3', $h3['http_version']);
                self::assertSame(200, $h3['http_code']);
                self::assertStringContainsString($marker, $h3['body']);
            }
            self::assertSame(
                \strtolower($fixture['certificate_fingerprint']),
                \strtolower($this->peerCertificateFingerprint($fixture['domain'])),
            );
        }

        $alphaSession = $this->captureTls13Session($alpha['domain']);
        $alphaResumed = $this->resumeTls13Session(
            $alpha['domain'],
            $alphaSession['session_file'],
        );
        self::assertSame('New', $alphaResumed['session_state'], $alphaResumed['output']);
        self::assertStringContainsString('alpha-project-marker', $alphaResumed['output']);
        $crossTenantResume = $this->resumeTls13Session(
            $beta['domain'],
            $alphaSession['session_file'],
        );
        self::assertSame('New', $crossTenantResume['session_state'], $crossTenantResume['output']);
        self::assertStringContainsString('beta-project-marker', $crossTenantResume['output']);
        $this->assertTlsEarlyDataRejected($alpha, $alphaSession['session_file']);

        $betaSession = $this->captureTls13Session($beta['domain']);
        $betaResumed = $this->resumeTls13Session(
            $beta['domain'],
            $betaSession['session_file'],
        );
        self::assertSame('New', $betaResumed['session_state'], $betaResumed['output']);
        $previousBetaFingerprint = $beta['certificate_fingerprint'];
        $beta = $this->rotateFixtureCertificate($beta);
        $betaRotation = $this->projectMutationWithRetry(
            $betaClient,
            'register',
            $this->registrationPayload($beta, $gatewayEpoch),
        );
        self::assertTrue($betaRotation['ok'], \json_encode($betaRotation));
        $this->waitForCommitted(
            $betaClient,
            $beta['project_uuid'],
            (string)$betaRotation['payload']['operation_id'],
        );
        self::assertNotSame(
            \strtolower($previousBetaFingerprint),
            \strtolower($beta['certificate_fingerprint']),
        );
        self::assertSame(
            \strtolower($beta['certificate_fingerprint']),
            \strtolower($this->peerCertificateFingerprint($beta['domain'])),
        );
        $afterCertificateRotation = $this->resumeTls13Session(
            $beta['domain'],
            $betaSession['session_file'],
        );
        self::assertSame(
            'New',
            $afterCertificateRotation['session_state'],
            $afterCertificateRotation['output'],
        );
        self::assertStringContainsString(
            'beta-project-marker',
            $afterCertificateRotation['output'],
        );

        $unknownHttp = $this->curlHttp('unknown.wls.test');
        self::assertSame(404, $unknownHttp['http_code']);
        self::assertStringNotContainsString('alpha-project-marker', $unknownHttp['body']);
        self::assertStringNotContainsString('beta-project-marker', $unknownHttp['body']);
        $unknownTls = $this->curlRoute('unknown.wls.test', '--http1.1');
        self::assertSame(421, $unknownTls['http_code']);
        self::assertStringNotContainsString('alpha-project-marker', $unknownTls['body']);
        self::assertStringNotContainsString('beta-project-marker', $unknownTls['body']);
        $mismatchedHost = $this->curlRoute(
            $alpha['domain'],
            '--http1.1',
            ['Host: ' . $beta['domain']],
        );
        self::assertSame(421, $mismatchedHost['http_code']);
        self::assertNotSame(
            \strtolower($alpha['certificate_fingerprint']),
            \strtolower($beta['certificate_fingerprint']),
        );

        $wrongNonce = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-426614174103',
            'wrong-nonce.wls.test',
            'wrong-nonce-project-marker',
            'wrong-nonce-a',
            'wrong',
        );
        $wrongNonceClient = $this->enroll($wrongNonce);
        $wrongNonceRegistration = $this->projectMutationWithRetry(
            $wrongNonceClient,
            'register',
            $this->registrationPayload($wrongNonce, $gatewayEpoch),
        );
        self::assertFalse(
            $wrongNonceRegistration['ok'],
            \json_encode($wrongNonceRegistration),
        );
        self::assertSame(
            'rejected',
            $wrongNonceRegistration['error']['code'] ?? null,
        );
        self::assertSame(
            'Registration backend identity attestation was rejected.',
            $wrongNonceRegistration['error']['message'] ?? null,
        );
        $wrongNonceStatus = $this->adminClient()->administratorStatus();
        self::assertSame(
            0,
            $wrongNonceStatus['payload']['route_counts']['PENDING_BACKEND'] ?? 0,
            \json_encode($wrongNonceStatus['payload'], JSON_UNESCAPED_SLASHES),
        );
        $wrongNoncePublic = $this->curlRoute($wrongNonce['domain'], '--http1.1');
        self::assertSame(421, $wrongNoncePublic['http_code'], $wrongNoncePublic['output']);
        self::assertStringNotContainsString(
            'wrong-nonce-project-marker',
            $wrongNoncePublic['body'],
        );
        self::assertNotSame(
            \strtolower($wrongNonce['certificate_fingerprint']),
            \strtolower($this->peerCertificateFingerprint($wrongNonce['domain'])),
        );
        foreach ([$alpha, $beta] as $fixture) {
            self::assertSame(200, $this->curlRoute($fixture['domain'])['http_code']);
        }

        $alphaCountBefore = $this->normalRequestCount($alpha);
        $duplicateHost = $this->rawTlsRequest(
            $alpha['domain'],
            "GET / HTTP/1.1\r\n"
                . "Host: {$alpha['domain']}\r\n"
                . "Host: {$beta['domain']}\r\n"
                . "Connection: close\r\n\r\n",
        );
        self::assertSame(400, $this->responseStatus($duplicateHost), $duplicateHost);
        $ambiguousFraming = $this->rawTlsRequest(
            $alpha['domain'],
            "POST / HTTP/1.1\r\n"
                . "Host: {$alpha['domain']}\r\n"
                . "Content-Length: 4\r\n"
                . "Transfer-Encoding: chunked\r\n"
                . "Connection: close\r\n\r\n"
                . "0\r\n\r\n",
        );
        self::assertSame(400, $this->responseStatus($ambiguousFraming), $ambiguousFraming);
        self::assertSame($alphaCountBefore, $this->normalRequestCount($alpha));

        $webSocket = $this->rawTlsRequest(
            $alpha['domain'],
            "GET /socket HTTP/1.1\r\n"
                . "Host: {$alpha['domain']}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n",
        );
        self::assertSame(101, $this->responseStatus($webSocket), $webSocket);

        $nonIdempotentCountBefore = $this->requestCountAcrossBackends($alpha);
        $nonIdempotentBody = \str_repeat('non-idempotent-payload-', 256);
        $nonIdempotent = $this->rawTlsRequest(
            $alpha['domain'],
            "POST /__wls_abort_after_body HTTP/1.1\r\n"
                . "Host: {$alpha['domain']}\r\n"
                . "Content-Type: application/octet-stream\r\n"
                . 'Content-Length: ' . \strlen($nonIdempotentBody) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $nonIdempotentBody,
        );
        self::assertSame(502, $this->responseStatus($nonIdempotent), $nonIdempotent);
        self::assertSame(
            1,
            $this->requestCountAcrossBackends($alpha) - $nonIdempotentCountBefore,
            'A consumed non-idempotent request must never be replayed to another backend.',
        );

        $alphaStandby = $this->createAdditionalInstanceFixture(
            $alpha,
            'alpha-b',
            'alpha-project-marker',
        );
        $standbyRegistration = $this->projectMutationWithRetry(
            $alphaClient,
            'register',
            $this->registrationPayload($alphaStandby, $gatewayEpoch),
        );
        self::assertTrue($standbyRegistration['ok'], \json_encode($standbyRegistration));
        $standbyOperation = (string)($standbyRegistration['payload']['operation_id'] ?? '');
        if ($standbyOperation !== '') {
            $this->waitForCommitted(
                $alphaClient,
                $alpha['project_uuid'],
                $standbyOperation,
            );
        }
        $distributedInstances = [];
        for ($request = 0; $request < 64; ++$request) {
            $beforeDrain = $this->curlRoute($alpha['domain']);
            self::assertSame(200, $beforeDrain['http_code']);
            foreach (['alpha-a', 'alpha-b'] as $expectedInstance) {
                if (\str_contains(
                    (string)$beforeDrain['body'],
                    '"instance":"' . $expectedInstance . '"',
                )) {
                    $distributedInstances[$expectedInstance] = true;
                }
            }
        }
        $distributedInstanceIds = \array_keys($distributedInstances);
        \sort($distributedInstanceIds, SORT_STRING);
        self::assertSame(
            ['alpha-a', 'alpha-b'],
            $distributedInstanceIds,
            'A stateless project must distribute traffic only after both instances prove capability.',
        );
        $drain = $this->projectMutationWithRetry(
            $alphaClient,
            'drain',
            [
                'project_uuid' => $alpha['project_uuid'],
                'instance_id' => $alpha['instance_id'],
                'instance_generation' => $alpha['instance_generation'],
                'master_epoch' => $alpha['master_epoch'],
                'launch_id' => $alpha['launch_id'],
                'gateway_epoch' => $gatewayEpoch,
                'host_boot_id' => GatewayHostBootIdentity::current(),
                'drain_operation_id' => GatewayHostManager::drainOperationId(
                    $alpha,
                    $alpha['instance_id'],
                ),
                'seconds' => 300,
            ],
        );
        self::assertTrue($drain['ok'], \json_encode($drain));
        $this->waitForCommitted(
            $alphaClient,
            $alpha['project_uuid'],
            (string)$drain['payload']['operation_id'],
        );
        $this->stopBackend((int)$alpha['master_pid']);
        $afterDrain = $this->curlRoute($alpha['domain']);
        self::assertSame(200, $afterDrain['http_code']);
        self::assertStringContainsString('"instance":"alpha-b"', $afterDrain['body']);

        for ($cycle = 0; $cycle < 4; $cycle++) {
            foreach ([
                [$alphaClient, $alphaStandby],
                [$betaClient, $beta],
                [$acmeClient, $acme],
            ] as [$client, $fixture]) {
                $heartbeat = $this->projectMutationWithRetry(
                    $client,
                    'heartbeat',
                    [
                        'project_uuid' => $fixture['project_uuid'],
                        'project_generation' => $fixture['project_generation'],
                        'instance_id' => $fixture['instance_id'],
                        'instance_generation' => $fixture['instance_generation'],
                        'instance_digest' => (string)($this
                            ->registrationPayload($fixture, $gatewayEpoch)['instance_digest'] ?? ''),
                        'master_epoch' => $fixture['master_epoch'],
                        'launch_id' => $fixture['launch_id'],
                        'gateway_epoch' => $gatewayEpoch,
                        'host_boot_id' => GatewayHostBootIdentity::current(),
                    ],
                );
                self::assertTrue($heartbeat['ok'], \json_encode($heartbeat));
            }
            self::assertSame(200, $this->curlRoute($alpha['domain'])['http_code']);
            self::assertSame(200, $this->curlRoute($beta['domain'])['http_code']);
            if ($cycle < 3) {
                \sleep(10);
            }
        }
        $leaseStatus = $this->adminClient()->administratorStatus();
        self::assertSame(
            3,
            $leaseStatus['payload']['route_counts']['ACTIVE'] ?? 0,
            \json_encode($leaseStatus['payload'], JSON_UNESCAPED_SLASHES),
        );
        $steadyLeaseFixtures = [
            [$alphaClient, $alphaStandby],
            [$betaClient, $beta],
            [$acmeClient, $acme],
        ];
        $this->assertTenantBackendFailureDoesNotRestartGateway(
            $gatewayEpoch,
            $steadyLeaseFixtures,
        );

        $h3FaultInjection = $this->h3IsCurrentlyEnabled()
            && $this->curlSupportsHttp3();
        if ($h3FaultInjection || \PHP_OS_FAMILY === 'Darwin') {
            // The production Agent refreshes leases every 10 seconds. Mirror
            // that behavior before bounded Controller fault injection so the
            // harness tests protocol/clock isolation rather than fixture expiry.
            $leaseFixtures = $steadyLeaseFixtures;
            $leaseCheckpointBefore = $this->durableLeaseMonotonicByInstance();
            foreach ($leaseFixtures as [$client, $fixture]) {
                $heartbeat = $this->projectMutationWithRetry(
                    $client,
                    'heartbeat',
                    [
                        'project_uuid' => $fixture['project_uuid'],
                        'project_generation' => $fixture['project_generation'],
                        'instance_id' => $fixture['instance_id'],
                        'instance_generation' => $fixture['instance_generation'],
                        'instance_digest' => (string)($this
                            ->registrationPayload($fixture, $gatewayEpoch)['instance_digest'] ?? ''),
                        'master_epoch' => $fixture['master_epoch'],
                        'launch_id' => $fixture['launch_id'],
                        'gateway_epoch' => $gatewayEpoch,
                        'host_boot_id' => GatewayHostBootIdentity::current(),
                    ],
                );
                self::assertTrue($heartbeat['ok'], \json_encode($heartbeat));
            }
            $this->waitForDurableLeaseAdvance(
                \array_column($leaseFixtures, 1),
                $leaseCheckpointBefore,
                8.0,
            );
        }
        if ($h3FaultInjection) {
            $this->assertH3FailureIsolation($alpha['domain'], $beta['domain']);
        }

        if (\PHP_OS_FAMILY === 'Darwin') {
            $this->assertWallClockJumpLeaseStability([
                $alpha['domain'],
                $beta['domain'],
                $acme['domain'],
            ]);
        }

        $this->assertPublicationPersistenceBoundaryRecovery([
            $alpha['domain'],
            $beta['domain'],
            $acme['domain'],
        ]);

        $nginxPid = $this->nginxPid();
        $workerPids = $this->childPids($nginxPid);
        self::assertNotSame([], $workerPids);
        $this->stopProcess('controller-primary');
        self::assertTrue(@\posix_kill($nginxPid, 0));
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $probe = $this->curlRoute($attempt % 2 === 0 ? $alpha['domain'] : $beta['domain']);
            self::assertSame(200, $probe['http_code'], $probe['output']);
            self::assertStringContainsString(
                $attempt % 2 === 0 ? 'alpha-project-marker' : 'beta-project-marker',
                $probe['body'],
            );
        }
        $this->startController('controller-primary');
        $this->waitForSocket($this->paths->controllerSocketFile(), 15.0);
        $this->waitForAdminStatus(15.0);
        self::assertSame($nginxPid, $this->nginxPid());
        self::assertSame($workerPids, $this->childPids($nginxPid));
        self::assertSame(200, $this->curlRoute($alpha['domain'])['http_code']);

        $overlap = $this->startController('controller-overlap');
        $overlapResult = $this->waitForProcessExit('controller-overlap', 10.0);
        self::assertNotSame(0, $overlapResult['code'], $overlapResult['log']);
        self::assertStringContainsString(
            'Another WLS Gateway controller',
            $overlapResult['log'],
        );
        self::assertSame($nginxPid, $this->nginxPid());
        self::assertSame(200, $this->curlRoute($beta['domain'])['http_code']);
        unset($overlap);

        self::assertTrue(@\posix_kill($nginxPid, SIGTERM));
        $newNginxPid = $this->waitForNginxReplacement($nginxPid, 35.0);
        self::assertNotSame($nginxPid, $newNginxPid);
        self::assertGreaterThan(0, $newNginxPid);
        self::assertSame(200, $this->curlRoute($alpha['domain'])['http_code']);
        self::assertSame(200, $this->curlRoute($beta['domain'])['http_code']);
        if ($this->h3IsCurrentlyEnabled() && $this->curlSupportsHttp3()) {
            $recoveredH3 = $this->waitForSuccessfulRoute(
                $alpha['domain'],
                '--http3-only',
                15.0,
            );
            self::assertSame(0, $recoveredH3['code'], $recoveredH3['output']);
            self::assertSame('3', $recoveredH3['http_version']);
            self::assertSame(200, $recoveredH3['http_code']);
        }
        $this->assertProtectedPortOwnersUnchanged();
    }

    private function assertTenantBackendFailureDoesNotRestartGateway(
        string $gatewayEpoch,
        array $steadyLeaseFixtures,
    ): void {
        $fixture = $this->createProjectFixture(
            '123e4567-e89b-42d3-a456-426614174105',
            'backend-down.wls.test',
            'backend-down-project-marker',
            'backend-down-a',
        );
        $client = $this->enroll($fixture);
        $registration = $this->projectMutationWithRetry(
            $client,
            'register',
            $this->registrationPayload($fixture, $gatewayEpoch),
        );
        self::assertTrue($registration['ok'], \json_encode($registration));
        $this->waitForCommitted(
            $client,
            $fixture['project_uuid'],
            (string)$registration['payload']['operation_id'],
        );
        self::assertSame(200, $this->curlRoute($fixture['domain'])['http_code']);

        $nginxPid = $this->nginxPid();
        self::assertGreaterThan(0, $nginxPid);
        $this->stopBackend((int)$fixture['master_pid']);
        $heartbeatFixtures = [...$steadyLeaseFixtures, [$client, $fixture]];
        // Production backend inspection runs every 60 seconds. Keep every
        // exact lease alive while waiting through one complete interval so
        // the assertion cannot depend on the Controller's prior probe phase
        // or accidentally observe the separate 45-second lease expiry path.
        $deadline = \microtime(true) + 67.0;
        $heartbeatDue = \hrtime(true);
        $degradedObserved = false;
        do {
            if (\hrtime(true) >= $heartbeatDue) {
                foreach ($heartbeatFixtures as [$heartbeatClient, $heartbeatFixture]) {
                    $heartbeat = $this->projectMutationWithRetry(
                        $heartbeatClient,
                        'heartbeat',
                        [
                            'project_uuid' => $heartbeatFixture['project_uuid'],
                            'project_generation' => $heartbeatFixture['project_generation'],
                            'instance_id' => $heartbeatFixture['instance_id'],
                            'instance_generation' => $heartbeatFixture['instance_generation'],
                            'instance_digest' => (string)($this
                                ->registrationPayload($heartbeatFixture, $gatewayEpoch)
                                    ['instance_digest'] ?? ''),
                            'master_epoch' => $heartbeatFixture['master_epoch'],
                            'launch_id' => $heartbeatFixture['launch_id'],
                            'gateway_epoch' => $gatewayEpoch,
                            'host_boot_id' => GatewayHostBootIdentity::current(),
                        ],
                    );
                    self::assertTrue($heartbeat['ok'], \json_encode($heartbeat));
                }
                $heartbeatDue = \hrtime(true) + 8_000_000_000;
            }
            $status = $this->administratorStatusWithRateLimitRetry($deadline);
            self::assertTrue($status['ok'], \json_encode($status));
            self::assertTrue($status['payload']['data_plane']['running']);
            self::assertSame(
                $nginxPid,
                $this->nginxPid(),
                'A tenant backend failure must not restart the shared Nginx data plane.',
            );
            if (($status['payload']['state'] ?? '') === 'ROUTE_DEGRADED') {
                $degradedObserved = true;
            }
            self::assertSame(200, $this->curlRoute('alpha.wls.test')['http_code']);
            self::assertSame(200, $this->curlRoute('beta.wls.test')['http_code']);
            if ($degradedObserved) {
                break;
            }
            \usleep(250000);
        } while (\microtime(true) < $deadline);
        self::assertTrue(
            $degradedObserved,
            'The gateway did not distinguish the failed tenant route from a core data-plane outage.',
        );

        $unregister = $this->projectMutationWithRetry(
            $client,
            'unregister',
            [
                'project_uuid' => $fixture['project_uuid'],
                'instance_id' => $fixture['instance_id'],
                'instance_generation' => $fixture['instance_generation'],
                'master_epoch' => $fixture['master_epoch'],
                'launch_id' => $fixture['launch_id'],
                'gateway_epoch' => $gatewayEpoch,
                'host_boot_id' => GatewayHostBootIdentity::current(),
            ],
        );
        self::assertTrue($unregister['ok'], \json_encode($unregister));
        $operationId = (string)($unregister['payload']['operation_id'] ?? '');
        if ($operationId !== '') {
            $this->waitForCommitted(
                $client,
                $fixture['project_uuid'],
                $operationId,
            );
        }
        self::assertSame($nginxPid, $this->nginxPid());
    }

    /**
     * @return array<string,mixed>
     */
    private function createProjectFixture(
        string $projectUuid,
        string $domain,
        string $marker,
        string $instanceId,
        string $healthNonceMode = 'echo',
    ): array {
        $project = $this->root . DIRECTORY_SEPARATOR . 'project-'
            . \str_replace('.', '-', $domain);
        $certificateRoot = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl';
        $runtime = $project . DIRECTORY_SEPARATOR . 'var/server';
        self::assertTrue(\mkdir($certificateRoot, 0700, true));
        self::assertTrue(\mkdir($runtime, 0700, true));
        $certificate = $this->createCertificate($project, $domain, 'route');
        $certificateObject = \openssl_x509_read((string)\file_get_contents($certificate['cert']));
        self::assertNotFalse($certificateObject);
        $fingerprint = \openssl_x509_fingerprint($certificateObject, 'sha256');
        self::assertIsString($fingerprint);

        $backendPort = $this->reservePort();
        $edgeSecret = \bin2hex(\random_bytes(32));
        $launchId = \bin2hex(\random_bytes(16));
        $counterFile = $project . DIRECTORY_SEPARATOR . 'var/backend-count';
        $backendLog = $project . DIRECTORY_SEPARATOR . 'var/backend.log';
        self::assertSame(1, \file_put_contents($counterFile, "0"));
        self::assertSame(0, \file_put_contents($backendLog, ''));
        $backendPid = $this->startBackend(
            $backendPort,
            $projectUuid,
            $instanceId,
            $launchId,
            $edgeSecret,
            $marker,
            $counterFile,
            $backendLog,
            $healthNonceMode,
        );
        $endpointFile = $runtime . DIRECTORY_SEPARATOR . $instanceId . '.json';
        self::assertNotFalse(\file_put_contents(
            $endpointFile,
            \json_encode([
                'instance_name' => $instanceId,
                'main_port' => $backendPort,
                'master_pid' => $backendPid,
                'master_epoch' => 1,
                'gateway' => [
                    'project_uuid' => $projectUuid,
                    'instance_generation' => 1,
                    'launch_id' => $launchId,
                    'backend_capability' => 'stateless',
                    'backend_capability_source' => 'runtime_config',
                    'backend_capability_generation' => 1,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $certificate['cert'])
                . ':' . \hash_file('sha256', $certificate['key']) . ':',
        );
        return [
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'certificate_root' => $certificateRoot,
            'domain' => $domain,
            'marker' => $marker,
            'instance_id' => $instanceId,
            'instance_generation' => 1,
            'instance_digest' => \hash('sha256', $projectUuid . ':' . $instanceId . ':1'),
            'project_generation' => 1,
            'project_digest' => \hash('sha256', $projectUuid . ':project:1'),
            'launch_id' => $launchId,
            'master_epoch' => 1,
            'master_pid' => $backendPid,
            'backend_port' => $backendPort,
            'edge_secret' => $edgeSecret,
            'edge_digest' => \hash('sha256', $edgeSecret),
            'endpoint_file' => $endpointFile,
            'certificate' => $certificate,
            'certificate_source_digest' => $sourceDigest,
            'certificate_fingerprint' => $fingerprint,
            'certificate_relative_dir' => 'route',
            'certificate_generation' => 1,
            'counter_file' => $counterFile,
            'backend_log' => $backendLog,
            'route_id' => \substr(\hash('sha256', $projectUuid . ':' . $domain), 0, 32),
        ];
    }

    /**
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private function createAdditionalInstanceFixture(
        array $project,
        string $instanceId,
        string $marker,
    ): array {
        $fixture = $project;
        unset($fixture['extra_backends'], $fixture['counter_files']);
        $backendPort = $this->reservePort();
        $edgeSecret = \bin2hex(\random_bytes(32));
        $launchId = \bin2hex(\random_bytes(16));
        $counterFile = $project['project_root'] . DIRECTORY_SEPARATOR
            . 'var/backend-count-' . $instanceId;
        $backendLog = $project['project_root'] . DIRECTORY_SEPARATOR
            . 'var/backend-' . $instanceId . '.log';
        self::assertSame(1, \file_put_contents($counterFile, "0"));
        self::assertSame(0, \file_put_contents($backendLog, ''));
        $backendPid = $this->startBackend(
            $backendPort,
            (string)$project['project_uuid'],
            $instanceId,
            $launchId,
            $edgeSecret,
            $marker,
            $counterFile,
            $backendLog,
        );
        $endpointFile = $project['project_root'] . DIRECTORY_SEPARATOR
            . 'var/server/' . $instanceId . '.json';
        self::assertNotFalse(\file_put_contents(
            $endpointFile,
            \json_encode([
                'instance_name' => $instanceId,
                'main_port' => $backendPort,
                'master_pid' => $backendPid,
                'master_epoch' => 1,
                'gateway' => [
                    'project_uuid' => $project['project_uuid'],
                    'instance_generation' => 1,
                    'launch_id' => $launchId,
                    'backend_capability' => 'stateless',
                    'backend_capability_source' => 'runtime_config',
                    'backend_capability_generation' => 1,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $fixture['instance_id'] = $instanceId;
        $fixture['instance_generation'] = 1;
        $fixture['instance_digest'] = \hash(
            'sha256',
            $project['project_uuid'] . ':' . $instanceId . ':1',
        );
        $fixture['launch_id'] = $launchId;
        $fixture['master_epoch'] = 1;
        $fixture['master_pid'] = $backendPid;
        $fixture['backend_port'] = $backendPort;
        $fixture['edge_secret'] = $edgeSecret;
        $fixture['edge_digest'] = \hash('sha256', $edgeSecret);
        $fixture['endpoint_file'] = $endpointFile;
        $fixture['counter_file'] = $counterFile;
        $fixture['backend_log'] = $backendLog;
        return $fixture;
    }

    /**
     * @param array<string,mixed> $fixture
     * @return array<string,mixed>
     */
    private function addWorkerBackend(array $fixture, string $suffix): array
    {
        $backendPort = $this->reservePort();
        $counterFile = $fixture['project_root'] . DIRECTORY_SEPARATOR
            . 'var/backend-count-' . $fixture['instance_id'] . '-' . $suffix;
        $backendLog = $fixture['project_root'] . DIRECTORY_SEPARATOR
            . 'var/backend-' . $fixture['instance_id'] . '-' . $suffix . '.log';
        self::assertSame(1, \file_put_contents($counterFile, "0"));
        self::assertSame(0, \file_put_contents($backendLog, ''));
        $this->startBackend(
            $backendPort,
            (string)$fixture['project_uuid'],
            (string)$fixture['instance_id'],
            (string)$fixture['launch_id'],
            (string)$fixture['edge_secret'],
            (string)$fixture['marker'],
            $counterFile,
            $backendLog,
        );
        $fixture['extra_backends'][] = [
            'host' => '127.0.0.1',
            'port' => $backendPort,
            'weight' => 1,
        ];
        $fixture['counter_files'] = [
            (string)$fixture['counter_file'],
            $counterFile,
        ];
        return $fixture;
    }

    /**
     * @param array<string,mixed> $fixture
     * @return array<string,mixed>
     */
    private function rotateFixtureCertificate(array $fixture): array
    {
        $generation = (int)$fixture['certificate_generation'] + 1;
        $relativeDirectory = 'route-v' . $generation;
        $certificate = $this->createCertificate(
            (string)$fixture['project_root'],
            (string)$fixture['domain'],
            $relativeDirectory,
        );
        $certificateObject = \openssl_x509_read(
            (string)\file_get_contents($certificate['cert']),
        );
        self::assertNotFalse($certificateObject);
        $fingerprint = \openssl_x509_fingerprint($certificateObject, 'sha256');
        self::assertIsString($fingerprint);
        $fixture['certificate'] = $certificate;
        $fixture['certificate_fingerprint'] = $fingerprint;
        $fixture['certificate_relative_dir'] = $relativeDirectory;
        $fixture['certificate_generation'] = $generation;
        $fixture['certificate_source_digest'] = \hash(
            'sha256',
            \hash_file('sha256', $certificate['cert'])
                . ':' . \hash_file('sha256', $certificate['key']) . ':',
        );
        $fixture['project_generation'] = (int)$fixture['project_generation'] + 1;
        $fixture['project_digest'] = \hash(
            'sha256',
            $fixture['project_uuid'] . ':project:' . $fixture['project_generation'],
        );
        return $fixture;
    }

    /** @param array<string,mixed> $fixture */
    private function enroll(array $fixture): GatewayClient
    {
        $payload = [
            'project_uuid' => $fixture['project_uuid'],
            'project_root' => $fixture['project_root'],
            'certificate_roots' => [
                'project_ssl' => $fixture['certificate_root'],
            ],
            'allowed_domains' => [$fixture['domain']],
            'capabilities' => [
                'acme_http_01' => true,
                'stateless' => true,
                'shared_session' => false,
            ],
        ];
        $wireFacts = $payload;
        $wireFacts['project_root'] = (string)\realpath(
            (string)$payload['project_root'],
        );
        $wireFacts['certificate_roots']['project_ssl'] = (string)\realpath(
            (string)$payload['certificate_roots']['project_ssl'],
        );
        \ksort($wireFacts['certificate_roots'], SORT_STRING);
        \sort($wireFacts['allowed_domains'], SORT_STRING);
        \ksort($wireFacts['capabilities'], SORT_STRING);
        $payload['request_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($wireFacts),
        );
        $payload['idempotency_key'] = \substr(\hash(
            'sha256',
            $payload['project_uuid'] . ':enroll:' . $payload['request_digest'],
        ), 0, 40);
        try {
            $deadline = \microtime(true) + 20.0;
            do {
                $response = $this->adminClient()->request('enroll', $payload);
                if (($response['ok'] ?? false) === true) {
                    break;
                }
                $code = (string)($response['error']['code'] ?? '');
                $message = (string)($response['error']['message'] ?? '');
                $publicationDeferred = \hash_equals('rejected', $code)
                    && \hash_equals(
                        'Gateway publication is active; retry_after=1.',
                        $message,
                    );
                $rateDeferred = \hash_equals('rate_limited', $code)
                    && \hash_equals(
                        'Gateway request rate limit exceeded; retry_after=1.',
                        $message,
                    );
                if ((!$publicationDeferred && !$rateDeferred)
                    || \microtime(true) >= $deadline
                ) {
                    break;
                }
                \sleep(1);
            } while (true);
        } catch (\Throwable $throwable) {
            self::fail(
                $throwable->getMessage()
                    . "\nBroker: " . $this->processLog('broker')
                    . "\nController: " . $this->processLog('controller-primary')
                    . "\nBroker process: " . $this->processStatus('broker')
                    . "\nController process: " . $this->processStatus('controller-primary')
                    . "\nBroker registry: " . (string)@\file_get_contents(
                        $this->home . DIRECTORY_SEPARATOR . 'trust/broker-enrollments.tsv',
                    )
                    . "\nJournal: " . (string)@\file_get_contents(
                        $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl',
                    ),
            );
        }
        self::assertTrue($response['ok'], \json_encode($response));
        $credentials = new GatewayCredentialStore($this->paths, $fixture['project_root']);
        $credentials->install(
            (array)$response['payload']['credential'],
            (string)$fixture['project_uuid'],
        );
        return new GatewayClient($this->paths, 4.0, $credentials);
    }

    /**
     * @param list<array{0:GatewayClient,1:array<string,mixed>,2:string}> $entries
     * @return list<string>
     */
    private function registerConcurrently(array $entries): array
    {
        $resultFiles = [];
        $children = [];
        foreach ($entries as $index => [$client, $fixture, $gatewayEpoch]) {
            $resultFile = $this->root . DIRECTORY_SEPARATOR . 'registration-' . $index . '.json';
            $resultFiles[] = $resultFile;
            $pid = \pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                try {
                    $payload = $this->registrationPayload(
                        $fixture,
                        $gatewayEpoch,
                    );
                    $retryDeadline = \microtime(true) + 15.0;
                    do {
                        $response = $client->projectRequest(
                            'register',
                            $payload,
                        );
                        $publicationDeferred = ($response['ok'] ?? false) !== true
                            && \hash_equals(
                                'rejected',
                                (string)($response['error']['code'] ?? ''),
                            )
                            && \hash_equals(
                                'Gateway publication is active; retry_after=1.',
                                (string)($response['error']['message'] ?? ''),
                            );
                        if (!$publicationDeferred
                            || \microtime(true) >= $retryDeadline
                        ) {
                            break;
                        }
                        \sleep(1);
                    } while (true);
                    \file_put_contents(
                        $resultFile,
                        \json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    );
                    exit(0);
                } catch (\Throwable $throwable) {
                    \file_put_contents($resultFile, \json_encode([
                        'exception' => $throwable->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            self::assertSame($pid, \pcntl_waitpid($pid, $status));
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(
                0,
                \pcntl_wexitstatus($status),
                (string)@\file_get_contents($resultFiles[\array_search($pid, $children, true)]),
            );
        }
        $operationIds = [];
        foreach ($resultFiles as $file) {
            $response = \json_decode((string)\file_get_contents($file), true);
            self::assertIsArray($response);
            self::assertTrue($response['ok'] ?? false, \json_encode($response));
            $operationId = (string)($response['payload']['operation_id'] ?? '');
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $operationId);
            $operationIds[] = $operationId;
        }
        return $operationIds;
    }

    /** @param array<string,mixed> $fixture */
    private function registrationPayload(array $fixture, string $gatewayEpoch): array
    {
        $backends = [[
            'host' => '127.0.0.1',
            'port' => (int)$fixture['backend_port'],
            'weight' => 1,
        ]];
        foreach ((array)($fixture['extra_backends'] ?? []) as $backend) {
            $backends[] = [
                'host' => (string)($backend['host'] ?? ''),
                'port' => (int)($backend['port'] ?? 0),
                'weight' => (int)($backend['weight'] ?? 1),
            ];
        }
        $capabilityEvidence = [
            'schema' => 'wls-stateless-capability/1',
            'runtime_source' => 'project_endpoint',
            'runtime_declared' => true,
            'instance_generation' => (int)$fixture['instance_generation'],
            'reason' => 'declared_stateless_runtime',
        ];
        $identity = [
            'schema' => 'wls-backend-listener-identity/2',
            'project_uuid' => (string)$fixture['project_uuid'],
            'instance_id' => (string)$fixture['instance_id'],
            'generation' => (int)$fixture['instance_generation'],
            'master_pid' => (int)$fixture['master_pid'],
            'master_epoch' => (int)$fixture['master_epoch'],
            'launch_id' => (string)$fixture['launch_id'],
            'listener_lease_id' => \substr(\hash(
                'sha256',
                $fixture['project_uuid'] . "\0" . $fixture['instance_id'] . "\0"
                    . $fixture['instance_generation'] . "\0" . $fixture['launch_id'],
            ), 0, 32),
            'edge_capability_digest' => (string)$fixture['edge_digest'],
            'session_capability' => 'stateless',
            'session_capability_evidence' => $capabilityEvidence,
            'session_capability_evidence_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($capabilityEvidence),
            ),
            'edge_capability_secret' => (string)$fixture['edge_secret'],
        ];
        $publicIdentity = $identity;
        unset($publicIdentity['edge_capability_secret']);
        $identity['public_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($publicIdentity),
        );
        $identity['digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($identity),
        );
        $sourceDigest = (string)$fixture['certificate_source_digest'];
        $certificate = [
            'state' => 'active',
            'valid' => true,
            'pending' => false,
            'cert' => [
                'root_alias' => 'project_ssl',
                'relative_path' => $fixture['certificate_relative_dir']
                    . '/fullchain.pem',
            ],
            'key' => [
                'root_alias' => 'project_ssl',
                'relative_path' => $fixture['certificate_relative_dir']
                    . '/privkey.pem',
            ],
            'chain' => null,
            'source_digest' => $sourceDigest,
            'trust_profile' => 'test',
            'provider' => 'self_signed',
            'material_class' => 'self_signed',
            'provenance_digest' => \hash(
                'sha256',
                "wls-certificate-provenance/1\0" . $fixture['domain'] . "\0"
                    . $sourceDigest . "\0test\0self_signed\0self_signed",
            ),
            'generation' => (int)$fixture['certificate_generation'],
        ];
        return $this->sealRegistrationPayload([
            'project_uuid' => (string)$fixture['project_uuid'],
            'project_root' => (string)$fixture['project_root'],
            'certificate_trust_profile' => 'test',
            'instance_id' => (string)$fixture['instance_id'],
            'project_generation' => (int)$fixture['project_generation'],
            'master_epoch' => (int)$fixture['master_epoch'],
            'launch_id' => (string)$fixture['launch_id'],
            'gateway_epoch' => $gatewayEpoch,
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'routes' => [[
                'route_id' => \substr(\hash(
                    'sha256',
                    $fixture['project_uuid'] . "\0" . $fixture['domain'],
                ), 0, 32),
                'domain' => (string)$fixture['domain'],
                'force_https' => true,
                'force_root_to_www' => false,
                'root_to_www_target' => '',
                'backends' => $backends,
                'backend_identity' => $identity,
                'certificate' => $certificate,
            ]],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sealRegistrationPayload(array $payload): array
    {
        $routes = \array_values((array)($payload['routes'] ?? []));
        \usort(
            $routes,
            static fn (array $left, array $right): int =>
                (string)($left['domain'] ?? '') <=> (string)($right['domain'] ?? ''),
        );
        $projectRouteFacts = [];
        $policyRouteFacts = [];
        $backendSets = [];
        $identity = null;
        $trustProfile = null;
        foreach ($routes as $route) {
            $certificate = (array)($route['certificate'] ?? []);
            $routeTrustProfile = (string)($certificate['trust_profile'] ?? '');
            if ($trustProfile === null) {
                $trustProfile = $routeTrustProfile;
            } elseif (!\hash_equals($trustProfile, $routeTrustProfile)) {
                throw new \RuntimeException(
                    'The test registration must use one certificate trust profile.',
                );
            }
            $projectRouteFacts[] = [
                'route_id' => (string)($route['route_id'] ?? ''),
                'domain' => (string)($route['domain'] ?? ''),
                'certificate' => [
                    'state' => (string)($certificate['state'] ?? ''),
                    'source_digest' => (string)($certificate['source_digest'] ?? ''),
                    'trust_profile' => $routeTrustProfile,
                    'provider' => (string)($certificate['provider'] ?? ''),
                    'material_class' => (string)($certificate['material_class'] ?? ''),
                    'provenance_digest' => (string)($certificate['provenance_digest'] ?? ''),
                    'generation' => (int)($certificate['generation'] ?? 0),
                ],
                'force_https' => (bool)($route['force_https'] ?? true),
                'force_root_to_www' => (bool)($route['force_root_to_www'] ?? false),
                'root_to_www_target' => (string)($route['root_to_www_target'] ?? ''),
            ];
            $policyRouteFacts[] = [
                'route_id' => (string)($route['route_id'] ?? ''),
                'domain' => (string)($route['domain'] ?? ''),
                'force_https' => (bool)($route['force_https'] ?? true),
                'force_root_to_www' => (bool)($route['force_root_to_www'] ?? false),
                'root_to_www_target' => (string)($route['root_to_www_target'] ?? ''),
            ];
            $candidateIdentity = (array)($route['backend_identity'] ?? []);
            if ($identity === null) {
                $identity = $candidateIdentity;
            } elseif (!\hash_equals(
                GatewayClient::canonicalJson($identity),
                GatewayClient::canonicalJson($candidateIdentity),
            )) {
                throw new \RuntimeException(
                    'The test registration must use one backend identity.',
                );
            }
            $backendSets[] = \array_values((array)($route['backends'] ?? []));
        }
        if ($routes === [] || !\is_array($identity) || $identity === []) {
            throw new \RuntimeException('The test registration has no complete route closure.');
        }
        $projectUuid = (string)($payload['project_uuid'] ?? '');
        $projectRoot = (string)($payload['project_root'] ?? '');
        $instanceId = (string)($payload['instance_id'] ?? '');
        $instanceGeneration = (int)($identity['generation'] ?? 0);
        $requestDigest = \hash('sha256', GatewayClient::canonicalJson([
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'backend_capability' => ['policy' => 'runtime_attested'],
            'routes' => $projectRouteFacts,
        ]));
        $projectGeneration = (int)($payload['project_generation'] ?? 0);
        return \array_replace($payload, [
            'request_digest' => $requestDigest,
            'idempotency_key' => \substr(\hash(
                'sha256',
                $projectUuid . ':desired:' . $projectGeneration . ':' . $requestDigest,
            ), 0, 40),
            'instance_generation' => $instanceGeneration,
            'instance_digest' => \hash('sha256', GatewayClient::canonicalJson([
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceId,
                'instance_generation' => $instanceGeneration,
                'backend_identity' => $identity,
                'backends' => $backendSets,
            ])),
            'non_certificate_desired_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson([
                    'project_uuid' => $projectUuid,
                    'project_root' => $projectRoot,
                    'certificate_trust_profile' => $trustProfile,
                    'backend_capability' => ['policy' => 'runtime_attested'],
                    'routes' => $policyRouteFacts,
                ]),
            ),
            'routes' => $routes,
        ]);
    }
    private function waitForCommitted(
        GatewayClient $client,
        string $projectUuid,
        string $operationId,
    ): void {
        $deadline = \microtime(true) + 40.0;
        $last = [];
        do {
            $response = $client->projectRequest('operation-status', [
                'project_uuid' => $projectUuid,
                'operation_id' => $operationId,
            ]);
            if (($response['ok'] ?? false) !== true
                && \hash_equals(
                    'rate_limited',
                    (string)($response['error']['code'] ?? ''),
                )
                && \hash_equals(
                    'Gateway request rate limit exceeded; retry_after=1.',
                    (string)($response['error']['message'] ?? ''),
                )
                && \microtime(true) < $deadline
            ) {
                \sleep(1);
                continue;
            }
            self::assertTrue($response['ok'], \json_encode($response));
            $last = (array)$response['payload'];
            if (($last['state'] ?? '') === 'COMMITTED') {
                return;
            }
            if (($last['state'] ?? '') === 'FAILED') {
                self::fail(
                    'Gateway publication failed: ' . \json_encode($last)
                        . "\nController: " . $this->processLog('controller-primary')
                        . "\nNginx: " . (string)@\file_get_contents(
                            $this->home . DIRECTORY_SEPARATOR . 'runtime/logs/error.log',
                        )
                        . "\nJournal: " . (string)@\file_get_contents(
                            $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl',
                        )
                        . "\nConfig: " . (string)@\file_get_contents(
                            $this->home . DIRECTORY_SEPARATOR . 'runtime/conf/nginx.conf',
                        )
                        . "\nBackends: " . \implode("\n", \array_map(
                            static fn (string $file): string => $file . ': '
                                . (string)@\file_get_contents($file),
                            \glob($this->root . DIRECTORY_SEPARATOR
                                . 'project-*/var/backend.log') ?: [],
                        )),
                );
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);
        self::fail('Gateway operation did not commit: ' . \json_encode($last));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function projectMutationWithRetry(
        GatewayClient $client,
        string $operation,
        array $payload,
    ): array {
        $deadline = \microtime(true) + 20.0;
        do {
            $response = $client->projectRequest($operation, $payload);
            if (($response['ok'] ?? false) === true) {
                return $response;
            }
            $code = (string)($response['error']['code'] ?? '');
            $message = (string)($response['error']['message'] ?? '');
            $publicationDeferred = \hash_equals('rejected', $code)
                && \hash_equals(
                    'Gateway publication is active; retry_after=1.',
                    $message,
                );
            $rateDeferred = \hash_equals('rate_limited', $code)
                && \hash_equals(
                    'Gateway request rate limit exceeded; retry_after=1.',
                    $message,
                );
            $storageProbeDeferred = \hash_equals('rejected', $code)
                && \hash_equals(
                    'Gateway storage persistence proof is not fresh; '
                        . 'persistent mutation is fail-closed until maintenance refreshes it.',
                    $message,
                );
            if ((!$publicationDeferred && !$rateDeferred && !$storageProbeDeferred)
                || \microtime(true) >= $deadline
            ) {
                return $response;
            }
            \sleep(1);
        } while (true);
    }

    /** @return array<string,mixed> */
    private function administratorStatusWithRateLimitRetry(float $deadline): array
    {
        do {
            $response = $this->adminClient()->administratorStatus();
            if (($response['ok'] ?? false) === true) {
                return $response;
            }
            if (!\hash_equals(
                    'rate_limited',
                    (string)($response['error']['code'] ?? ''),
                )
                || !\hash_equals(
                    'Gateway request rate limit exceeded; retry_after=1.',
                    (string)($response['error']['message'] ?? ''),
                )
                || \microtime(true) >= $deadline
            ) {
                return $response;
            }
            \sleep(1);
        } while (true);
    }

    private function adminClient(): GatewayClient
    {
        return new GatewayClient($this->paths, 4.0);
    }

    /**
     * @param list<string> $headers
     * @return array{code:int,output:string,http_version:string,http_code:int,body:string}
     */
    private function h3IsCurrentlyEnabled(): bool
    {
        $status = $this->adminClient()->administratorStatus();
        self::assertTrue($status['ok'], \json_encode($status));
        self::assertIsBool($status['payload']['h3_enabled'] ?? null);
        $enabled = (bool)$status['payload']['h3_enabled'];
        if ($this->h3Enabled && !$enabled) {
            self::assertNotSame(
                '',
                \trim((string)($status['payload']['h3_reason'] ?? '')),
                'An advertised H3 capability may only disappear with an explicit downgrade reason.',
            );
        }
        $this->h3Enabled = $enabled;
        return $enabled;
    }

    /** @return array{code:int,http_code:int,http_version:string,body:string,output:string} */
    private function waitForSuccessfulRoute(
        string $domain,
        string $protocol,
        float $timeoutSeconds,
    ): array {
        $deadline = \microtime(true) + \max(0.1, $timeoutSeconds);
        $last = [];
        do {
            $last = $this->curlRoute($domain, $protocol);
            if (($last['code'] ?? -1) === 0
                && ($last['http_code'] ?? 0) === 200
            ) {
                return $last;
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);
        return $last;
    }

    private function curlRoute(
        string $domain,
        string $protocol = '--http1.1',
        array $headers = [],
    ): array {
        $bodyFile = $this->root . DIRECTORY_SEPARATOR . 'curl-body-'
            . \bin2hex(\random_bytes(4));
        $command = [
            $this->curl,
            '--silent',
            '--show-error',
            '--insecure',
            '--noproxy',
            '*',
            '--connect-timeout',
            '3',
            '--max-time',
            '8',
            $protocol,
            '--resolve',
            $domain . ':' . $this->httpsPort . ':127.0.0.1',
            '--output',
            $bodyFile,
            '--write-out',
            '%{http_version}' . "\t" . '%{http_code}',
        ];
        foreach ($headers as $header) {
            $command[] = '--header';
            $command[] = $header;
        }
        $command[] = 'https://' . $domain . ':' . $this->httpsPort . '/';
        $result = $this->runCommand($command);
        [$version, $code] = \array_pad(\explode("\t", \trim($result['output'])), 2, '');
        $body = (string)@\file_get_contents($bodyFile);
        @\unlink($bodyFile);
        return [
            'code' => $result['code'],
            'output' => $result['output'],
            'http_version' => $version,
            'http_code' => (int)$code,
            'body' => $body,
        ];
    }

    /** @return array{code:int,output:string,http_code:int,body:string} */
    private function curlHttp(string $domain, string $path = '/'): array
    {
        $bodyFile = $this->root . DIRECTORY_SEPARATOR . 'curl-http-body-'
            . \bin2hex(\random_bytes(4));
        $result = $this->runCommand([
            $this->curl,
            '--silent',
            '--show-error',
            '--noproxy',
            '*',
            '--connect-timeout',
            '3',
            '--max-time',
            '8',
            '--resolve',
            $domain . ':' . $this->httpPort . ':127.0.0.1',
            '--output',
            $bodyFile,
            '--write-out',
            '%{http_code}',
            'http://' . $domain . ':' . $this->httpPort . $path,
        ]);
        $body = (string)@\file_get_contents($bodyFile);
        @\unlink($bodyFile);
        return [
            'code' => $result['code'],
            'output' => $result['output'],
            'http_code' => (int)\trim($result['output']),
            'body' => $body,
        ];
    }

    private function rawTlsRequest(string $domain, string $request): string
    {
        $context = \stream_context_create(['ssl' => [
            'peer_name' => $domain,
            'SNI_enabled' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $socket = @\stream_socket_client(
            'tls://127.0.0.1:' . $this->httpsPort,
            $errno,
            $error,
            3.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        self::assertIsResource($socket, $error);
        \stream_set_timeout($socket, 3);
        self::assertSame(\strlen($request), \fwrite($socket, $request));
        $response = (string)\stream_get_contents($socket);
        \fclose($socket);
        return $response;
    }

    private function peerCertificateFingerprint(string $domain): string
    {
        $context = \stream_context_create(['ssl' => [
            'peer_name' => $domain,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $socket = @\stream_socket_client(
            'tls://127.0.0.1:' . $this->httpsPort,
            $errno,
            $error,
            3.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        self::assertIsResource($socket, $error);
        $parameters = \stream_context_get_params($socket);
        \fclose($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
        self::assertTrue(
            \is_resource($certificate) || $certificate instanceof \OpenSSLCertificate,
        );
        $fingerprint = \openssl_x509_fingerprint($certificate, 'sha256');
        self::assertIsString($fingerprint);
        return $fingerprint;
    }

    /**
     * @return array{session_file:string,session_state:string,output:string}
     */
    private function captureTls13Session(string $domain): array
    {
        $sessionFile = $this->root . DIRECTORY_SEPARATOR . 'tls-session-'
            . \bin2hex(\random_bytes(4)) . '.pem';
        $result = $this->runCommandWithInput([
            $this->openssl,
            's_client',
            '-connect',
            '127.0.0.1:' . $this->httpsPort,
            '-servername',
            $domain,
            '-tls1_3',
            '-ign_eof',
            '-sess_out',
            $sessionFile,
        ], $this->tlsHttpRequest($domain));
        self::assertSame(0, $result['code'], $result['output']);
        self::assertFileExists($sessionFile);
        self::assertGreaterThan(0, (int)\filesize($sessionFile));
        self::assertSame('New', $this->tlsSessionState($result['output']), $result['output']);
        return [
            'session_file' => $sessionFile,
            'session_state' => 'New',
            'output' => $result['output'],
        ];
    }

    /** @return array{code:int,session_state:string,output:string} */
    private function resumeTls13Session(string $domain, string $sessionFile): array
    {
        $result = $this->runCommandWithInput([
            $this->openssl,
            's_client',
            '-connect',
            '127.0.0.1:' . $this->httpsPort,
            '-servername',
            $domain,
            '-tls1_3',
            '-ign_eof',
            '-sess_in',
            $sessionFile,
        ], $this->tlsHttpRequest($domain));
        self::assertSame(0, $result['code'], $result['output']);
        return [
            'code' => $result['code'],
            'session_state' => $this->tlsSessionState($result['output']),
            'output' => $result['output'],
        ];
    }

    private function tlsHttpRequest(string $domain): string
    {
        return "GET / HTTP/1.1\r\nHost: {$domain}\r\nConnection: close\r\n\r\n";
    }

    private function tlsSessionState(string $output): string
    {
        self::assertSame(
            1,
            \preg_match('/^\s*Protocol\s*:\s*TLSv1\\.3\s*$/mi', $output),
            $output,
        );
        self::assertSame(
            1,
            // OpenSSL 3.6 may retain the historical TLSv1/SSLv3 summary
            // label while the authoritative Protocol field is TLSv1.3.
            \preg_match(
                '/^(New|Reused), (?:TLSv1\\.3|TLSv1\/SSLv3),/m',
                $output,
                $matches,
            ),
            $output,
        );
        return (string)$matches[1];
    }

    /** @param array<string,mixed> $fixture */
    private function assertTlsEarlyDataRejected(array $fixture, string $sessionFile): void
    {
        $earlyData = $this->root . DIRECTORY_SEPARATOR . 'tls-early-data-'
            . \bin2hex(\random_bytes(4));
        self::assertNotFalse(\file_put_contents(
            $earlyData,
            $this->tlsHttpRequest((string)$fixture['domain']),
        ));
        $before = $this->normalRequestCount($fixture);
        $result = $this->runCommandWithInput([
            $this->openssl,
            's_client',
            '-connect',
            '127.0.0.1:' . $this->httpsPort,
            '-servername',
            (string)$fixture['domain'],
            '-tls1_3',
            '-sess_in',
            $sessionFile,
            '-early_data',
            $earlyData,
        ], '');
        self::assertSame(0, $result['code'], $result['output']);
        self::assertSame(
            1,
            \preg_match('/Early data was (?:not sent|rejected)/', $result['output']),
            $result['output'],
        );
        self::assertSame($before, $this->normalRequestCount($fixture));
    }

    private function assertH3FailureIsolation(string $alphaDomain, string $betaDomain): void
    {
        $config = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf/nginx.conf';
        $original = (string)\file_get_contents($config);
        self::assertNotSame('', $original);
        $pattern = '/^\s*listen\s+[^;]*:' . $this->httpsPort
            . '\s+quic(?:\s+reuseport)?;\R/m';
        $withoutH3 = \preg_replace($pattern, '', $original, -1, $removed);
        self::assertIsString($withoutH3);
        self::assertGreaterThan(0, $removed);
        self::assertStringNotContainsString(' quic', $withoutH3);

        $this->stopProcess('controller-primary');
        try {
            $this->publishTaskOwnedNginxConfig($config, $withoutH3);
            $this->reloadTaskOwnedNginx($config);
            $deadline = \microtime(true) + 12.0;
            $h3 = [];
            do {
                $h3 = $this->curlRoute($alphaDomain, '--http3-only');
                if ($h3['code'] !== 0 || $h3['http_code'] === 0) {
                    break;
                }
                \usleep(100_000);
            } while (\microtime(true) < $deadline);
            self::assertNotSame(0, $h3['code'], \json_encode($h3));

            foreach ([$alphaDomain, $betaDomain] as $domain) {
                $h1 = $this->curlRoute($domain, '--http1.1');
                self::assertSame(0, $h1['code'], $h1['output']);
                self::assertSame('1.1', $h1['http_version']);
                self::assertSame(200, $h1['http_code']);
                $h2 = $this->curlRoute($domain, '--http2');
                self::assertSame(0, $h2['code'], $h2['output']);
                self::assertSame('2', $h2['http_version']);
                self::assertSame(200, $h2['http_code']);
            }
        } finally {
            $this->publishTaskOwnedNginxConfig($config, $original);
            $this->reloadTaskOwnedNginx($config);
            $this->startController('controller-primary');
            $this->waitForSocket($this->paths->controllerSocketFile(), 15.0);
            $this->waitForAdminStatus(15.0);
        }

        $deadline = \microtime(true) + 15.0;
        $restored = [];
        do {
            $restored = $this->curlRoute($alphaDomain, '--http3-only');
            if ($restored['code'] === 0
                && $restored['http_version'] === '3'
                && $restored['http_code'] === 200
            ) {
                break;
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);
        self::assertSame(0, $restored['code'], \json_encode($restored));
        self::assertSame('3', $restored['http_version']);
        self::assertSame(200, $restored['http_code']);
    }

    /**
     * @param list<string> $domains
     */
    private function assertWallClockJumpLeaseStability(array $domains): void
    {
        $shim = $this->compileWallClockShim();
        $baseline = $this->runCommandWithEnvironment([
            PHP_BINARY,
            '-r',
            'echo time();',
        ]);
        self::assertSame(0, $baseline['code'], $baseline['output']);
        $baselineTime = (int)\trim($baseline['output']);
        self::assertGreaterThan(0, $baselineTime);

        foreach ([3600, -3600] as $offset) {
            $injected = $this->runCommandWithEnvironment([
                PHP_BINARY,
                '-r',
                'echo time();',
            ], [
                'DYLD_INSERT_LIBRARIES' => $shim,
                'DYLD_FORCE_FLAT_NAMESPACE' => '1',
                'WLS_TEST_WALL_OFFSET' => (string)$offset,
            ]);
            self::assertSame(0, $injected['code'], $injected['output']);
            self::assertEqualsWithDelta(
                $baselineTime + $offset,
                (int)\trim($injected['output']),
                5,
                $injected['output'],
            );
        }

        $this->stopProcess('controller-primary');
        try {
            foreach ([3600, -3600] as $offset) {
                $name = $offset > 0 ? 'controller-clock-forward' : 'controller-clock-backward';
                $this->startController($name, [
                    'DYLD_INSERT_LIBRARIES' => $shim,
                    'DYLD_FORCE_FLAT_NAMESPACE' => '1',
                    'WLS_TEST_WALL_OFFSET' => (string)$offset,
                ]);
                $deadline = \hrtime(true) + 7_000_000_000;
                $iteration = 0;
                do {
                    $process = $this->processes[$name] ?? null;
                    self::assertIsResource($process);
                    $status = \proc_get_status($process);
                    self::assertTrue(
                        $status['running'] ?? false,
                        $this->processLog($name),
                    );
                    $domain = $domains[$iteration % \count($domains)];
                    $probe = $this->curlRoute($domain);
                    $diagnostic = ($probe['code'] ?? -1) === 0
                        && ($probe['http_code'] ?? 0) === 200
                            ? $probe['output']
                            : $this->wallClockLeaseFailureDiagnostic(
                                $name,
                                $offset,
                                $iteration,
                                $domain,
                                $probe,
                            );
                    self::assertSame(0, $probe['code'], $diagnostic);
                    self::assertSame(200, $probe['http_code'], $diagnostic);
                    $iteration++;
                    \usleep(150_000);
                } while (\hrtime(true) < $deadline);
                self::assertGreaterThanOrEqual(20, $iteration);
                $this->stopProcess($name);
            }
        } finally {
            $this->stopProcess('controller-clock-forward');
            $this->stopProcess('controller-clock-backward');
            $this->startController('controller-primary');
            $this->waitForAdminStatus(15.0);
        }

        $status = $this->adminClient()->administratorStatus();
        self::assertTrue($status['ok'], \json_encode($status));
        self::assertSame(
            \count($domains),
            $status['payload']['route_counts']['ACTIVE'] ?? 0,
            \json_encode($status['payload'], JSON_UNESCAPED_SLASHES),
        );
        foreach ($domains as $domain) {
            self::assertSame(200, $this->curlRoute($domain)['http_code']);
        }
    }

    /**
     * @param array<string,mixed> $probe
     */
    private function wallClockLeaseFailureDiagnostic(
        string $controller,
        int $offset,
        int $iteration,
        string $domain,
        array $probe,
    ): string {
        try {
            $status = $this->adminClient()->administratorStatus();
        } catch (\Throwable $throwable) {
            $status = ['diagnostic_error' => $throwable->getMessage()];
        }
        try {
            $state = $this->readGatewayState();
        } catch (\Throwable $throwable) {
            $state = ['diagnostic_error' => $throwable->getMessage()];
        }
        $routes = [];
        foreach ((array)($state['routes'] ?? []) as $routeId => $route) {
            if (!\is_array($route)) {
                continue;
            }
            $instances = [];
            foreach ((array)($route['instances'] ?? []) as $instanceId => $instance) {
                if (!\is_array($instance)) {
                    continue;
                }
                $instances[(string)$instanceId] = [
                    'status' => $instance['status'] ?? null,
                    'backend_healthy' => $instance['backend_healthy'] ?? null,
                    'last_heartbeat' => $instance['last_heartbeat'] ?? null,
                    'last_heartbeat_monotonic'
                        => $instance['last_heartbeat_monotonic'] ?? null,
                    'lease_boot_id' => $instance['lease_boot_id'] ?? null,
                ];
            }
            $routes[(string)$routeId] = [
                'domain' => $route['domain'] ?? null,
                'status' => $route['status'] ?? null,
                'instances' => $instances,
            ];
        }
        return (string)\json_encode([
            'controller' => $controller,
            'wall_offset' => $offset,
            'iteration' => $iteration,
            'domain' => $domain,
            'probe' => $probe,
            'admin' => $status,
            'state' => [
                'health_state' => $state['health_state'] ?? null,
                'ready' => $state['ready'] ?? null,
                'recovery' => $state['recovery'] ?? null,
                'generation' => $state['generation'] ?? null,
                'active_config_generation' => $state['active_config_generation'] ?? null,
                'routes' => $routes,
            ],
            'controller_log' => $this->processLog($controller),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,float> */
    private function durableLeaseMonotonicByInstance(): array
    {
        $readPayload = function (string $file): ?array {
            $raw = @\file_get_contents($file);
            if (!\is_string($raw) || $raw === '') {
                return null;
            }
            try {
                $envelope = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
            $payload = \is_array($envelope)
                ? ($envelope['payload'] ?? null)
                : null;
            $sha256 = \is_array($envelope)
                ? (string)($envelope['sha256'] ?? '')
                : '';
            return \is_array($payload)
                && \preg_match('/\A[a-f0-9]{64}\z/D', $sha256) === 1
                && \hash_equals($sha256, \hash('sha256', $this->canonicalJson($payload)))
                    ? $payload
                    : null;
        };
        $record = static function (
            array &$monotonic,
            string $projectUuid,
            string $instanceId,
            mixed $heartbeat,
        ): void {
            if ($projectUuid === ''
                || $instanceId === ''
                || (!\is_int($heartbeat) && !\is_float($heartbeat))
                || !\is_finite((float)$heartbeat)
                || (float)$heartbeat <= 0.0
            ) {
                return;
            }
            $key = $projectUuid . "\0" . $instanceId;
            $monotonic[$key] = \max(
                $monotonic[$key] ?? 0.0,
                (float)$heartbeat,
            );
        };
        $monotonic = [];
        $state = $readPayload(
            $this->home . DIRECTORY_SEPARATOR . 'state/gateway-state.json',
        );
        foreach ((array)($state['instances'] ?? []) as $projectUuid => $instances) {
            foreach ((array)$instances as $instanceId => $instance) {
                if (\is_array($instance)) {
                    $record(
                        $monotonic,
                        (string)$projectUuid,
                        (string)$instanceId,
                        $instance['last_heartbeat_monotonic'] ?? null,
                    );
                }
            }
        }
        $checkpoint = $readPayload(
            $this->home . DIRECTORY_SEPARATOR . 'state/lease-checkpoint.json',
        );
        foreach ((array)($checkpoint['instances'] ?? []) as $instance) {
            if (!\is_array($instance)) {
                continue;
            }
            $record(
                $monotonic,
                (string)($instance['project_uuid'] ?? ''),
                (string)($instance['instance_id'] ?? ''),
                $instance['last_heartbeat_monotonic'] ?? null,
            );
        }
        return $monotonic;
    }

    /**
     * @param list<array<string,mixed>> $fixtures
     * @param array<string,float> $before
     */
    private function waitForDurableLeaseAdvance(
        array $fixtures,
        array $before,
        float $timeoutSeconds,
    ): void {
        $deadline = \hrtime(true) + (int)\ceil($timeoutSeconds * 1_000_000_000);
        $current = [];
        do {
            $current = $this->durableLeaseMonotonicByInstance();
            $advanced = true;
            foreach ($fixtures as $fixture) {
                $key = (string)($fixture['project_uuid'] ?? '')
                    . "\0"
                    . (string)($fixture['instance_id'] ?? '');
                if (($current[$key] ?? 0.0) <= ($before[$key] ?? 0.0)) {
                    $advanced = false;
                    break;
                }
            }
            if ($advanced) {
                return;
            }
            \usleep(100_000);
        } while (\hrtime(true) < $deadline);

        self::fail(
            'Gateway durable lease authority did not advance before Controller restart: '
                . \json_encode($current, JSON_UNESCAPED_SLASHES)
                . "\nController: " . $this->processLog('controller-primary'),
        );
    }

    private function compileWallClockShim(): string
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'wall-clock-shim.c';
        $library = $this->root . DIRECTORY_SEPARATOR . 'wall-clock-shim.dylib';
        $code = <<<'C'
#include <stdint.h>
#include <stdlib.h>
#include <sys/time.h>
#include <time.h>

static long wls_wall_offset(void)
{
    const char *raw = getenv("WLS_TEST_WALL_OFFSET");
    return raw == NULL ? 0L : strtol(raw, NULL, 10);
}

static time_t wls_shifted_time(time_t *target)
{
    struct timespec now;
    if (clock_gettime(CLOCK_REALTIME, &now) != 0) {
        return (time_t)-1;
    }
    time_t shifted = now.tv_sec + (time_t)wls_wall_offset();
    if (target != NULL) {
        *target = shifted;
    }
    return shifted;
}

static int wls_shifted_gettimeofday(struct timeval *target, void *timezone)
{
    (void)timezone;
    struct timespec now;
    if (target == NULL || clock_gettime(CLOCK_REALTIME, &now) != 0) {
        return -1;
    }
    target->tv_sec = now.tv_sec + (time_t)wls_wall_offset();
    target->tv_usec = (suseconds_t)(now.tv_nsec / 1000);
    return 0;
}

#define DYLD_INTERPOSE(replacement, replacee)                                    \
    __attribute__((used)) static struct {                                        \
        const void *replacement_address;                                         \
        const void *replacee_address;                                             \
    } wls_interpose_##replacee __attribute__((section("__DATA,__interpose"))) = { \
        (const void *)(uintptr_t)&replacement,                                    \
        (const void *)(uintptr_t)&replacee                                        \
    };

DYLD_INTERPOSE(wls_shifted_time, time)
DYLD_INTERPOSE(wls_shifted_gettimeofday, gettimeofday)
C;
        self::assertNotFalse(\file_put_contents($source, $code, LOCK_EX));
        $compiled = $this->runCommand([
            'cc',
            '-dynamiclib',
            '-fPIC',
            '-Wall',
            '-Wextra',
            '-Werror',
            $source,
            '-o',
            $library,
        ]);
        self::assertSame(0, $compiled['code'], $compiled['output']);
        self::assertFileExists($library);
        return $library;
    }

    /**
     * @param list<string> $domains
     */
    private function assertPublicationPersistenceBoundaryRecovery(array $domains): void
    {
        $config = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf/nginx.conf';
        $publicationFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/publication-current.json';
        $masterPid = $this->nginxPid();
        self::assertGreaterThan(0, $masterPid);

        foreach ([
            'PENDING_PUBLICATION',
            'DESIRED',
            'PREPARED',
            'SHADOW_VERIFIED',
        ] as $phase) {
            $this->stopProcess('controller-primary');
            $state = $this->readGatewayState();
            $transactionId = \bin2hex(\random_bytes(16));
            $candidate = '';
            if (\in_array($phase, ['PREPARED', 'SHADOW_VERIFIED'], true)) {
                $candidate = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf/candidate-'
                    . (int)($state['active_config_generation'] ?? 0)
                    . '-' . $transactionId . '.conf';
                self::assertNotFalse(\copy($config, $candidate));
                self::assertTrue(\chmod($candidate, 0600));
            }
            $payload = $this->publicationPayload(
                $phase,
                $state,
                $candidate,
                $candidate === '' ? '' : (string)\hash_file('sha256', $candidate),
                '',
                $transactionId,
            );
            $this->writePublicationEnvelope($publicationFile, $payload);
            $this->startController('controller-primary');
            $status = $this->waitForPublicationIdle(20.0);

            self::assertFileDoesNotExist($publicationFile);
            if ($candidate !== '') {
                self::assertFileDoesNotExist($candidate);
            }
            self::assertSame($masterPid, $this->nginxPid());
            self::assertSame(
                \count($domains),
                $status['payload']['route_counts']['ACTIVE'] ?? 0,
                \json_encode($status['payload'], JSON_UNESCAPED_SLASHES),
            );
            foreach ($domains as $domain) {
                self::assertSame(200, $this->curlRoute($domain)['http_code']);
            }
        }

        $this->stopProcess('controller-primary');
        $state = $this->readGatewayState();
        $rollbackTransaction = \bin2hex(\random_bytes(16));
        $rollback = $config . '.rollback.' . $rollbackTransaction;
        self::assertNotFalse(\copy($config, $rollback));
        self::assertTrue(\chmod($rollback, 0600));
        $rollbackPayload = $this->publicationPayload(
            'ACTIVATING',
            $state,
            '',
            \hash('sha256', 'candidate-not-activated-' . $rollbackTransaction),
            $rollback,
            $rollbackTransaction,
        );
        $this->writePublicationEnvelope($publicationFile, $rollbackPayload);
        $this->startController('controller-primary');
        $this->waitForPublicationIdle(25.0);
        self::assertFileDoesNotExist($publicationFile);
        self::assertFileDoesNotExist(
            $rollback,
            'An interrupted pre-activation rollback artifact was leaked.',
        );
        self::assertSame($masterPid, $this->nginxPid());

        foreach (['ACTIVATING', 'COMMITTED'] as $phase) {
            $this->stopProcess('controller-primary');
            $state = $this->readGatewayState();
            $transaction = \bin2hex(\random_bytes(16));
            $rollback = $config . '.rollback.' . $transaction;
            self::assertNotFalse(\copy($config, $rollback));
            self::assertTrue(\chmod($rollback, 0600));
            $payload = $this->publicationPayload(
                $phase,
                $state,
                '',
                (string)\hash_file('sha256', $config),
                $rollback,
                $transaction,
            );
            $this->writePublicationEnvelope($publicationFile, $payload);
            $this->startController('controller-primary');
            $status = $this->waitForPublicationIdle(20.0);

            self::assertFileDoesNotExist($publicationFile);
            self::assertFileDoesNotExist(
                $rollback,
                'A committed publication rollback artifact was not archived.',
            );
            self::assertSame($masterPid, $this->nginxPid());
            self::assertSame(
                (int)$state['active_config_generation'],
                (int)$status['payload']['generation'],
            );
            foreach ($domains as $domain) {
                self::assertSame(200, $this->curlRoute($domain)['http_code']);
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function publicationPayload(
        string $phase,
        array $state,
        string $candidateFile = '',
        string $candidateDigest = '',
        string $rollbackFile = '',
        string $transactionId = '',
    ): array {
        return [
            'schema_version' => 1,
            'transaction_id' => $transactionId !== ''
                ? $transactionId
                : \bin2hex(\random_bytes(16)),
            'operation' => 'persistence-boundary-recovery-test',
            'phase' => $phase,
            'created_at' => \gmdate(DATE_ATOM),
            'previous' => [
                'generation' => (int)($state['generation'] ?? 0),
                'projects' => (array)($state['projects'] ?? []),
                'instances' => (array)($state['instances'] ?? []),
                'routes' => (array)($state['routes'] ?? []),
                'acme_challenges' => (array)($state['acme_challenges'] ?? []),
                'isolation_mode' => (bool)($state['isolation_mode'] ?? false),
                'active_config_generation'
                    => (int)($state['active_config_generation'] ?? 0),
                'pending_lkg_generation'
                    => (int)($state['pending_lkg_generation'] ?? 0),
                'pending_lkg_since' => (int)($state['pending_lkg_since'] ?? 0),
                'config_dirty' => false,
            ],
            'candidate_generation' => (int)($state['active_config_generation'] ?? 0),
            'candidate_file' => $candidateFile,
            'candidate_digest' => $candidateDigest,
            'rollback_file' => $rollbackFile,
            'irrevocable_security' => false,
            'operation_ids' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function readGatewayState(): array
    {
        $file = $this->home . DIRECTORY_SEPARATOR . 'state/gateway-state.json';
        $envelope = \json_decode(
            (string)\file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($envelope['payload'] ?? null);
        self::assertSame(
            (string)($envelope['sha256'] ?? ''),
            \hash('sha256', $this->canonicalJson($envelope['payload'])),
        );
        return $envelope['payload'];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeGatewayState(array $state): void
    {
        $encoded = \json_encode([
            'payload' => $state,
            'sha256' => \hash('sha256', $this->canonicalJson($state)),
        ], JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'state/gateway-state.json',
            $encoded,
            LOCK_EX,
        ));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writePublicationEnvelope(string $file, array $payload): void
    {
        $encoded = \json_encode([
            'payload' => $payload,
            'sha256' => \hash('sha256', $this->canonicalJson($payload)),
        ], JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR);
        $temporary = $file . '.test-' . \bin2hex(\random_bytes(4));
        self::assertNotFalse(\file_put_contents($temporary, $encoded, LOCK_EX));
        self::assertTrue(\chmod($temporary, 0600));
        self::assertTrue(\rename($temporary, $file));
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (\array_is_list($item)) {
                return \array_map($normalize, $item);
            }
            \ksort($item, SORT_STRING);
            foreach ($item as $key => $entry) {
                $item[$key] = $normalize($entry);
            }
            return $item;
        };
        return (string)\json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
    }

    private function publishTaskOwnedNginxConfig(string $config, string $contents): void
    {
        $temporary = $config . '.test-' . \bin2hex(\random_bytes(4));
        self::assertNotFalse(\file_put_contents($temporary, $contents, LOCK_EX));
        self::assertTrue(\chmod($temporary, 0600));
        self::assertTrue(\rename($temporary, $config));
    }

    private function reloadTaskOwnedNginx(string $config): void
    {
        $binary = $this->home . DIRECTORY_SEPARATOR . 'slots/A/bin/nginx';
        $tested = $this->runCommand([
            $binary,
            '-p',
            $this->home . DIRECTORY_SEPARATOR . 'runtime/',
            '-c',
            $config,
            '-t',
        ]);
        self::assertSame(0, $tested['code'], $tested['output']);
        $reloaded = $this->runCommand([
            $binary,
            '-p',
            $this->home . DIRECTORY_SEPARATOR . 'runtime/',
            '-c',
            $config,
            '-s',
            'reload',
        ]);
        self::assertSame(0, $reloaded['code'], $reloaded['output']);
    }

    private function responseStatus(string $response): int
    {
        return \preg_match('/\AHTTP\\/1\\.[01] ([0-9]{3}) /D', $response, $matches) === 1
            ? (int)$matches[1]
            : 0;
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array{code:int,http_code:int,http_version:string,body:string,output:string} $response
     */
    private function assertMillionTenantRouteResponse(array $response, array $fixture): void
    {
        self::assertSame(0, $response['code'], $response['output']);
        self::assertSame(200, $response['http_code'], $response['output']);
        self::assertSame('2', $response['http_version'], $response['output']);
        $body = \json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($fixture['marker'], $body['marker'] ?? null);
        self::assertSame($fixture['project_uuid'], $body['project_uuid'] ?? null);
        self::assertSame($fixture['instance_id'], $body['instance'] ?? null);
    }

    /** @param array<string,mixed> $fixture */
    private function refreshMillionTenantLease(
        GatewayClient $client,
        array $fixture,
        string $gatewayEpoch,
    ): void {
        $heartbeat = $this->projectMutationWithRetry(
            $client,
            'heartbeat',
            [
                'project_uuid' => $fixture['project_uuid'],
                'project_generation' => $fixture['project_generation'],
                'instance_id' => $fixture['instance_id'],
                'instance_generation' => $fixture['instance_generation'],
                'instance_digest' => (string)($this
                    ->registrationPayload($fixture, $gatewayEpoch)['instance_digest'] ?? ''),
                'master_epoch' => $fixture['master_epoch'],
                'launch_id' => $fixture['launch_id'],
                'gateway_epoch' => $gatewayEpoch,
                'host_boot_id' => GatewayHostBootIdentity::current(),
            ],
        );
        self::assertTrue($heartbeat['ok'], \json_encode($heartbeat));
    }

    /**
     * @return array{
     *   requested:int,
     *   started:int,
     *   done:int,
     *   succeeded:int,
     *   failed:int,
     *   errored:int,
     *   timeout:int,
     *   status_2xx:int,
     *   status_3xx:int,
     *   status_4xx:int,
     *   status_5xx:int,
     *   duration_seconds:float,
     *   qps:float,
     *   output_sha256:string
     * }
     */
    private function runH2loadChunk(string $h2load, string $domain, int $requests): array
    {
        $startedAt = \hrtime(true);
        $result = $this->runCommand([
            $h2load,
            '--requests=' . $requests,
            '--clients=4',
            '--threads=2',
            '--max-concurrent-streams=64',
            '--connection-active-timeout=300s',
            '--connection-inactivity-timeout=60s',
            '--alpn-list=h2',
            '--connect-to=127.0.0.1:' . $this->httpsPort,
            '--sni=' . $domain,
            '--header=accept-encoding: identity',
            'https://' . $domain . ':' . $this->httpsPort . '/',
        ]);
        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('Application protocol: h2', $result['output']);

        $integer = static fn (string $value): int => (int)\str_replace(',', '', $value);
        self::assertSame(1, \preg_match(
            '/requests:\s+([\d,]+)\s+total,\s+([\d,]+)\s+started,\s+'
                . '([\d,]+)\s+done,\s+([\d,]+)\s+succeeded,\s+'
                . '([\d,]+)\s+failed,\s+([\d,]+)\s+errored,\s+'
                . '([\d,]+)\s+timeout/',
            $result['output'],
            $requestMatches,
        ), $result['output']);
        self::assertSame(1, \preg_match(
            '/status codes:\s+([\d,]+)\s+2xx,\s+([\d,]+)\s+3xx,\s+'
                . '([\d,]+)\s+4xx,\s+([\d,]+)\s+5xx/',
            $result['output'],
            $statusMatches,
        ), $result['output']);

        return [
            'requested' => $requests,
            'started' => $integer($requestMatches[2]),
            'done' => $integer($requestMatches[3]),
            'succeeded' => $integer($requestMatches[4]),
            'failed' => $integer($requestMatches[5]),
            'errored' => $integer($requestMatches[6]),
            'timeout' => $integer($requestMatches[7]),
            'status_2xx' => $integer($statusMatches[1]),
            'status_3xx' => $integer($statusMatches[2]),
            'status_4xx' => $integer($statusMatches[3]),
            'status_5xx' => $integer($statusMatches[4]),
            'duration_seconds' => $durationSeconds,
            'qps' => $durationSeconds > 0.0 ? $requests / $durationSeconds : 0.0,
            'output_sha256' => \hash('sha256', $result['output']),
        ];
    }

    /**
     * @param list<array{
     *   requested:int,
     *   started:int,
     *   done:int,
     *   succeeded:int,
     *   failed:int,
     *   errored:int,
     *   timeout:int,
     *   status_2xx:int,
     *   status_3xx:int,
     *   status_4xx:int,
     *   status_5xx:int,
     *   duration_seconds:float,
     *   qps:float,
     *   output_sha256:string
     * }> $chunks
     * @return array{
     *   chunks:int,
     *   requested:int,
     *   started:int,
     *   done:int,
     *   succeeded:int,
     *   failed:int,
     *   errored:int,
     *   timeout:int,
     *   status_2xx:int,
     *   status_3xx:int,
     *   status_4xx:int,
     *   status_5xx:int,
     *   duration_seconds:float,
     *   qps:float,
     *   output_sha256:list<string>
     * }
     */
    private function summarizeH2loadChunks(array $chunks): array
    {
        $summary = [
            'chunks' => \count($chunks),
            'requested' => 0,
            'started' => 0,
            'done' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errored' => 0,
            'timeout' => 0,
            'status_2xx' => 0,
            'status_3xx' => 0,
            'status_4xx' => 0,
            'status_5xx' => 0,
            'duration_seconds' => 0.0,
            'qps' => 0.0,
            'output_sha256' => [],
        ];
        foreach ($chunks as $chunk) {
            foreach ([
                'requested',
                'started',
                'done',
                'succeeded',
                'failed',
                'errored',
                'timeout',
                'status_2xx',
                'status_3xx',
                'status_4xx',
                'status_5xx',
            ] as $field) {
                $summary[$field] += $chunk[$field];
            }
            $summary['duration_seconds'] += $chunk['duration_seconds'];
            $summary['output_sha256'][] = $chunk['output_sha256'];
        }
        $summary['qps'] = $summary['duration_seconds'] > 0.0
            ? $summary['succeeded'] / $summary['duration_seconds']
            : 0.0;
        $summary['duration_seconds'] = \round($summary['duration_seconds'], 6);
        $summary['qps'] = \round($summary['qps'], 3);
        return $summary;
    }

    /** @param array<string,mixed> $fixture */
    private function normalRequestCount(array $fixture): int
    {
        return (int)\trim((string)@\file_get_contents((string)$fixture['counter_file']));
    }

    /** @param array<string,mixed> $fixture */
    private function requestCountAcrossBackends(array $fixture): int
    {
        $files = (array)($fixture['counter_files'] ?? [$fixture['counter_file']]);
        return \array_sum(\array_map(
            static fn (string $file): int => (int)\trim(
                (string)@\file_get_contents($file),
            ),
            $files,
        ));
    }

    private function curlSupportsHttp3(): bool
    {
        $version = $this->runCommand([$this->curl, '--version']);
        return $version['code'] === 0 && \str_contains($version['output'], 'HTTP3');
    }

    private function seedGatewayRuntime(): void
    {
        $trust = $this->home . DIRECTORY_SEPARATOR . 'trust';
        $slot = $this->home . DIRECTORY_SEPARATOR . 'slots/A';
        $bin = $slot . DIRECTORY_SEPARATOR . 'bin';
        self::assertTrue(\mkdir($trust, 0700, true));
        self::assertTrue(\mkdir($bin, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            \bin2hex(\random_bytes(16)) . "\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex(\random_bytes(32)) . "\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "A\n",
        ));
        foreach (['host-id', 'admin.token', 'active-slot'] as $leaf) {
            self::assertTrue(\chmod($trust . DIRECTORY_SEPARATOR . $leaf, 0600));
        }
        $nginx = $bin . DIRECTORY_SEPARATOR . 'nginx';
        self::assertTrue(\copy($this->nginxSeed, $nginx));
        self::assertTrue(\chmod($nginx, 0700));
        $manifest = [
            'slot' => 'A',
            'test_mode' => true,
            'release_ready' => false,
            'implementation_level' => 'wls-2.0',
            'security_profile' => 'native-broker-v1',
            'runtime_generation' => \hash_file('sha256', $nginx),
            'listen_profile' => 'ipv4-only',
            'components' => [
                'bin/nginx' => [
                    'sha256' => \hash_file('sha256', $nginx),
                    'size' => \filesize($nginx),
                    'mode' => 0700,
                ],
            ],
        ];
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));
        self::assertTrue(\chmod($slot . DIRECTORY_SEPARATOR . 'manifest.json', 0600));

        $neutralSource = $this->createCertificate(
            $this->root,
            'wls-neutral.invalid',
            'neutral-tls',
        );
        self::assertTrue(\mkdir($this->paths->nginxPidDir(), 0700, true));
        $neutral = $this->paths->neutralTlsDir();
        self::assertTrue(\mkdir($neutral, 0700, true));
        foreach ([
            'cert' => 'neutral-cert.pem',
            'key' => 'neutral-key.pem',
        ] as $source => $leaf) {
            $target = $neutral . DIRECTORY_SEPARATOR . $leaf;
            self::assertTrue(\copy($neutralSource[$source], $target));
            self::assertTrue(\chmod($target, 0600));
        }
    }

    private function compileBroker(): void
    {
        $source = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native' . DIRECTORY_SEPARATOR . 'posix'
            . DIRECTORY_SEPARATOR . 'wls_gateway_broker.c';
        self::assertFileExists($source);
        $this->broker = $this->root . DIRECTORY_SEPARATOR . 'wls-gateway-broker';
        $sodiumCflags = $this->runCommand(['pkg-config', '--cflags', 'libsodium']);
        self::assertSame(0, $sodiumCflags['code'], $sodiumCflags['output']);
        $sodiumLdflags = $this->runCommand(['pkg-config', '--libs', 'libsodium']);
        self::assertSame(0, $sodiumLdflags['code'], $sodiumLdflags['output']);
        $compileFlags = \array_values(\array_filter(
            \preg_split('/\s+/', \trim($sodiumCflags['output'])) ?: [],
            static fn (string $flag): bool => $flag !== '',
        ));
        $linkFlags = \array_values(\array_filter(
            \preg_split('/\s+/', \trim($sodiumLdflags['output'])) ?: [],
            static fn (string $flag): bool => $flag !== '',
        ));
        $compiled = $this->runCommand([
            'cc',
            '-std=c11',
            \PHP_OS_FAMILY === 'Darwin' ? '-D_DARWIN_C_SOURCE' : '-D_GNU_SOURCE',
            '-Wall',
            '-Wextra',
            '-Werror',
            '-fstack-protector-strong',
            '-DWLS_NATIVE_TEST_HOOKS=1',
            ...$compileFlags,
            $source,
            ...$linkFlags,
            '-o',
            $this->broker,
        ]);
        self::assertSame(0, $compiled['code'], $compiled['output']);
        self::assertTrue(\is_executable($this->broker));
        $this->controller = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
        self::assertFileExists($this->controller);
    }

    private function startBroker(): void
    {
        $run = $this->home . DIRECTORY_SEPARATOR . 'runtime/run';
        $fencing = $this->home . DIRECTORY_SEPARATOR
            . 'trust/broker-fencing-token';
        self::assertTrue(\mkdir($run, 0700, true));
        $this->startProcess('broker', [
            $this->broker,
            '--serve',
            '--admin-socket',
            $run . DIRECTORY_SEPARATOR . 'admin.sock',
            '--project-socket',
            $run . DIRECTORY_SEPARATOR . 'project.sock',
            '--controller-socket',
            $run . DIRECTORY_SEPARATOR . 'controller.sock',
            '--lock-file',
            $run . DIRECTORY_SEPARATOR . 'broker.lock',
            '--fencing-file',
            $fencing,
            '--home',
            $this->home,
            '--active-slot',
            'A',
            '--runtime-generation',
            (string)\hash_file(
                'sha256',
                $this->home . DIRECTORY_SEPARATOR . 'slots/A/bin/nginx',
            ),
        ]);
        $this->waitForSocket($run . DIRECTORY_SEPARATOR . 'admin.sock', 10.0);
        $this->waitForSocket($run . DIRECTORY_SEPARATOR . 'project.sock', 10.0);
        $this->waitForFile($fencing, 10.0);
    }

    /** @return resource */
    private function startController(string $name, array $environment = [])
    {
        return $this->startProcess($name, [
            PHP_BINARY,
            $this->controller,
            '--home=' . $this->home,
            '--broker-internal=unix://' . $this->paths->controllerSocketFile(),
            '--broker-fencing-file=' . $this->home
                . DIRECTORY_SEPARATOR . 'trust/broker-fencing-token',
            '--host-boot-id=' . GatewayHostBootIdentity::current(),
        ], $environment);
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return resource
     */
    private function startProcess(string $name, array $command, array $environment = [])
    {
        $log = $this->root . DIRECTORY_SEPARATOR . $name . '.log';
        $processEnvironment = null;
        if ($environment !== []) {
            $current = \getenv();
            $processEnvironment = \array_replace(
                \is_array($current) ? $current : [],
                $environment,
            );
        }
        $process = \proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, null, $processEnvironment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $this->processes[$name] = $process;
        return $process;
    }

    private function stopProcess(string $name): void
    {
        $process = $this->processes[$name] ?? null;
        if (!\is_resource($process)) {
            return;
        }
        $status = \proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        if (($status['running'] ?? false) && $pid > 0) {
            @\posix_kill($pid, SIGTERM);
            $deadline = \microtime(true) + 5.0;
            do {
                \usleep(20_000);
                $status = \proc_get_status($process);
            } while (($status['running'] ?? false) && \microtime(true) < $deadline);
            if (($status['running'] ?? false) && $pid > 0) {
                @\posix_kill($pid, SIGKILL);
            }
        }
        @\proc_close($process);
        unset($this->processes[$name]);
    }

    private function stopBackend(int $pid): void
    {
        if ($pid > 0 && @\posix_kill($pid, 0)) {
            @\posix_kill($pid, SIGTERM);
        }
        if ($pid > 0) {
            @\pcntl_waitpid($pid, $ignored);
        }
        $this->backendPids = \array_values(\array_filter(
            $this->backendPids,
            static fn (int $tracked): bool => $tracked !== $pid,
        ));
    }

    /** @return array{code:int,log:string} */
    private function waitForProcessExit(string $name, float $timeout): array
    {
        $process = $this->processes[$name] ?? null;
        self::assertIsResource($process);
        $deadline = \microtime(true) + $timeout;
        $status = \proc_get_status($process);
        while (($status['running'] ?? false) && \microtime(true) < $deadline) {
            \usleep(50_000);
            $status = \proc_get_status($process);
        }
        self::assertFalse($status['running'] ?? true, $this->processLog($name));
        $exitCode = (int)($status['exitcode'] ?? -1);
        @\proc_close($process);
        unset($this->processes[$name]);
        return ['code' => $exitCode, 'log' => $this->processLog($name)];
    }

    private function processLog(string $name): string
    {
        return (string)@\file_get_contents(
            $this->root . DIRECTORY_SEPARATOR . $name . '.log',
        );
    }

    private function processStatus(string $name): string
    {
        $process = $this->processes[$name] ?? null;
        return \is_resource($process)
            ? \json_encode(\proc_get_status($process), JSON_UNESCAPED_SLASHES)
            : 'not tracked';
    }

    /** @return array<string,mixed> */
    private function waitForAdminStatus(float $timeout): array
    {
        $deadline = \microtime(true) + $timeout;
        $error = '';
        do {
            try {
                $response = $this->adminClient()->administratorStatus();
                if (($response['ok'] ?? false) === true) {
                    return $response;
                }
                $error = \json_encode($response);
            } catch (\Throwable $throwable) {
                $error = $throwable->getMessage();
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);
        self::fail('Gateway administrator channel did not recover: ' . $error);
    }

    /** @return array<string,mixed> */
    private function waitForPublicationIdle(float $timeout): array
    {
        $deadline = \microtime(true) + $timeout;
        $last = [];
        $error = '';
        do {
            try {
                $last = $this->adminClient()->administratorStatus();
                if (($last['ok'] ?? false) === true
                    && (string)($last['payload']['publication']['phase'] ?? '') === 'IDLE'
                ) {
                    return $last;
                }
                $error = \json_encode($last, JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $throwable) {
                $error = $throwable->getMessage();
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);
        self::fail(
            'Gateway publication did not recover to IDLE: ' . $error
                . ' Controller log: ' . $this->processLog('controller-primary'),
        );
    }

    private function waitForNginxReplacement(int $oldPid, float $timeout): int
    {
        $deadline = \microtime(true) + $timeout;
        $last = '';
        do {
            $pid = $this->nginxPid();
            if ($pid > 0 && $pid !== $oldPid && @\posix_kill($pid, 0)) {
                $probe = $this->curlRoute('alpha.wls.test');
                if ($probe['http_code'] === 200) {
                    return $pid;
                }
                $last = $probe['output'];
            }
            \usleep(200_000);
        } while (\microtime(true) < $deadline);
        self::fail(
            'Gateway Nginx was not recovered after the third probe. Last response: ' . $last
                . ' Controller log: ' . $this->processLog('controller-primary'),
        );
    }

    private function nginxPid(): int
    {
        $raw = \trim((string)@\file_get_contents(
            $this->paths->nginxPidFile(),
        ));
        return \ctype_digit($raw) ? (int)$raw : 0;
    }

    /** @return list<int> */
    private function childPids(int $parentPid): array
    {
        $result = $this->runCommand(['pgrep', '-P', (string)$parentPid]);
        if ($result['code'] !== 0 && \trim($result['output']) === '') {
            return [];
        }
        $pids = \array_values(\array_filter(\array_map(
            'intval',
            \preg_split('/\s+/', \trim($result['output'])) ?: [],
        )));
        \sort($pids, SORT_NUMERIC);
        return $pids;
    }

    private function stopOwnedNginx(): void
    {
        $pid = $this->nginxPid();
        if ($pid < 1 || !@\posix_kill($pid, 0)) {
            return;
        }
        $binary = $this->home . DIRECTORY_SEPARATOR . 'slots/A/bin/nginx';
        $command = $this->processCommand($pid);
        if (!\str_contains($command, $binary)) {
            return;
        }
        $config = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf/nginx.conf';
        $this->runCommand([
            $binary,
            '-p',
            $this->home . DIRECTORY_SEPARATOR . 'runtime/',
            '-c',
            $config,
            '-s',
            'quit',
        ]);
        $deadline = \microtime(true) + 5.0;
        while (@\posix_kill($pid, 0) && \microtime(true) < $deadline) {
            \usleep(50_000);
        }
        if (@\posix_kill($pid, 0)) {
            @\posix_kill($pid, SIGTERM);
        }
    }

    private function startBackend(
        int $port,
        string $projectUuid,
        string $instanceId,
        string $launchId,
        string $edgeSecret,
        string $marker,
        string $counterFile,
        string $backendLog,
        string $healthNonceMode = 'echo',
    ): int {
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            \pcntl_async_signals(true);
            $running = true;
            \pcntl_signal(SIGTERM, static function () use (&$running): void {
                $running = false;
            });
            $server = @\stream_socket_server(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $error,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );
            if (!\is_resource($server)) {
                exit(70);
            }
            \stream_set_blocking($server, false);
            while ($running) {
                $client = @\stream_socket_accept($server, 0.2);
                if (!\is_resource($client)) {
                    continue;
                }
                \stream_set_blocking($client, true);
                \stream_set_timeout($client, 2);
                $request = '';
                while (!\str_contains($request, "\r\n\r\n") && \strlen($request) < 131072) {
                    $chunk = @\fread($client, 8192);
                    if (!\is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                [$head, $receivedBody] = \array_pad(
                    \explode("\r\n\r\n", $request, 2),
                    2,
                    '',
                );
                $lines = \explode("\r\n", $head);
                $requestLine = (string)\array_shift($lines);
                $headers = [];
                foreach ($lines as $line) {
                    if (!\str_contains($line, ':')) {
                        continue;
                    }
                    [$name, $value] = \explode(':', $line, 2);
                    $headers[\strtolower(\trim($name))][] = \trim($value);
                }
                @\file_put_contents(
                    $backendLog,
                    \json_encode([
                        'request' => $requestLine,
                        'headers' => $headers,
                    ], JSON_UNESCAPED_SLASHES) . "\n",
                    FILE_APPEND | LOCK_EX,
                );
                if (\str_starts_with($requestLine, 'GET /_wls/health?')) {
                    \parse_str((string)\parse_url(
                        (string)\explode(' ', $requestLine)[1],
                        PHP_URL_QUERY,
                    ), $query);
                    $headerNonce = \strtolower(\trim((string)(
                        $headers['x-wls-probe-nonce'][0] ?? ''
                    )));
                    $gatewaySentinel = \preg_match(
                        '/\A[a-f0-9]{32}\z/D',
                        $headerNonce,
                    ) === 1;
                    $nonce = $gatewaySentinel
                        ? $headerNonce
                        : (string)($query['nonce'] ?? '');
                    $authorized = \hash_equals(
                        $edgeSecret,
                        (string)($headers['x-wls-edge-token'][0] ?? ''),
                    );
                    if ($gatewaySentinel) {
                        $body = \json_encode([
                            'status' => 'healthy',
                            'instance' => $instanceId,
                            'master_epoch' => 1,
                            'launch_id' => $launchId,
                            'nonce' => $nonce,
                        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        $status = $authorized ? '200 OK' : '403 Forbidden';
                        @\fwrite(
                            $client,
                            "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\n"
                                . 'Content-Length: ' . \strlen($body)
                                . "\r\nConnection: close\r\n\r\n{$body}",
                        );
                        @\fclose($client);
                        continue;
                    }
                    $reportedNonce = $healthNonceMode === 'wrong'
                        ? \str_repeat('0', 32)
                        : $nonce;
                    $capabilityEvidence = [
                        'schema' => 'wls-stateless-capability/1',
                        'runtime_source' => 'project_endpoint',
                        'runtime_declared' => true,
                        'instance_generation' => 1,
                        'reason' => 'declared_stateless_runtime',
                    ];
                    $attestation = [
                        'schema' => 'wls-backend-attestation/1',
                        'project_uuid' => $projectUuid,
                        'instance_id' => $instanceId,
                        'instance_generation' => 1,
                        'master_epoch' => 1,
                        'launch_id' => $launchId,
                        'listener_lease_id' => \substr(\hash(
                            'sha256',
                            $projectUuid . "\0" . $instanceId . "\0" . 1 . "\0" . $launchId,
                        ), 0, 32),
                        'edge_capability_digest' => \hash('sha256', $edgeSecret),
                        'session_capability' => 'stateless',
                        'session_capability_evidence_digest' => \hash(
                            'sha256',
                            GatewayClient::canonicalJson($capabilityEvidence),
                        ),
                        'backend_host' => '127.0.0.1',
                        'backend_port' => $port,
                        'nonce' => $reportedNonce,
                        'issued_at' => \time(),
                    ];
                    $binarySecret = \hex2bin($edgeSecret);
                    if (!\is_string($binarySecret) || \strlen($binarySecret) !== 32) {
                        @\fclose($client);
                        @\fclose($server);
                        exit(71);
                    }
                    $attestation['signature'] = \hash_hmac(
                        'sha256',
                        GatewayClient::canonicalJson($attestation),
                        $binarySecret,
                    );
                    if (\function_exists('sodium_memzero')) {
                        \sodium_memzero($binarySecret);
                    }
                    $body = \json_encode(
                        ['backend_attestation' => $attestation],
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    );
                    $status = $authorized && \preg_match('/\A[a-f0-9]{32}\z/D', $nonce) === 1
                        ? '200 OK'
                        : '403 Forbidden';
                    @\fwrite(
                        $client,
                        "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\n"
                            . 'Content-Length: ' . \strlen($body)
                            . "\r\nConnection: close\r\n\r\n{$body}",
                    );
                    @\fclose($client);
                    continue;
                }
                $authorized = \hash_equals(
                    $edgeSecret,
                    (string)($headers['x-wls-edge-token'][0] ?? ''),
                ) && \hash_equals(
                    $projectUuid,
                    (string)($headers['x-wls-project-uuid'][0] ?? ''),
                ) && \hash_equals(
                    $instanceId,
                    (string)($headers['x-wls-instance-id'][0] ?? ''),
                ) && (int)($headers['x-wls-backend-generation'][0] ?? 0) === 1;
                if (!$authorized) {
                    $body = '{"error":"invalid gateway backend identity"}';
                    @\fwrite(
                        $client,
                        "HTTP/1.1 403 Forbidden\r\nContent-Type: application/json\r\n"
                            . 'Content-Length: ' . \strlen($body)
                            . "\r\nConnection: close\r\n\r\n{$body}",
                    );
                    @\fclose($client);
                    continue;
                }
                if (\strtolower((string)($headers['upgrade'][0] ?? '')) === 'websocket') {
                    @\fwrite(
                        $client,
                        "HTTP/1.1 101 Switching Protocols\r\n"
                            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
                            . "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n",
                    );
                    @\fclose($client);
                    continue;
                }
                if ($requestLine === 'POST /__wls_abort_after_body HTTP/1.1') {
                    $contentLength = (int)($headers['content-length'][0] ?? 0);
                    while (
                        \strlen($receivedBody) < $contentLength
                        && \strlen($receivedBody) < 1048576
                    ) {
                        $chunk = @\fread(
                            $client,
                            \min(8192, $contentLength - \strlen($receivedBody)),
                        );
                        if (!\is_string($chunk) || $chunk === '') {
                            break;
                        }
                        $receivedBody .= $chunk;
                    }
                    if ($contentLength > 0 && \strlen($receivedBody) >= $contentLength) {
                        $count = (int)\trim((string)@\file_get_contents($counterFile));
                        @\file_put_contents($counterFile, (string)($count + 1), LOCK_EX);
                    }
                    @\fclose($client);
                    continue;
                }
                $count = (int)\trim((string)@\file_get_contents($counterFile));
                @\file_put_contents($counterFile, (string)($count + 1), LOCK_EX);
                $body = \json_encode([
                    'marker' => $marker,
                    'project_uuid' => $projectUuid,
                    'instance' => $instanceId,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                @\fwrite(
                    $client,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                        . 'Content-Length: ' . \strlen($body)
                        . "\r\nConnection: close\r\n\r\n{$body}",
                );
                @\fclose($client);
            }
            @\fclose($server);
            exit(0);
        }
        $this->backendPids[] = $pid;
        if ($healthNonceMode === 'wrong') {
            $this->waitForTcp($port, 5.0);
        } else {
            $this->waitForBackendHealth($port, $edgeSecret, 5.0);
        }
        return $pid;
    }

    /**
     * @return array{cert:string,key:string}
     */
    private function createCertificate(
        string $project,
        string $domain,
        string $name,
    ): array {
        $directory = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl/' . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $config = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<CONF
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = {$domain}

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$domain}
CONF
        ));
        $arguments = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $key = \openssl_pkey_new($arguments);
        self::assertNotFalse($key);
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $certificate = \openssl_csr_sign($request, null, $key, 30, $arguments);
        self::assertNotFalse($certificate);
        self::assertTrue(\openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(\openssl_pkey_export($key, $keyPem, null, $arguments));
        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertNotFalse(\file_put_contents($certificatePath, $certificatePem));
        self::assertNotFalse(\file_put_contents($keyPath, $keyPem));
        self::assertTrue(\chmod($certificatePath, 0600));
        self::assertTrue(\chmod($keyPath, 0600));
        return ['cert' => $certificatePath, 'key' => $keyPath];
    }

    private function reservePort(): int
    {
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $address = (string)\stream_socket_get_name($server, false);
        \fclose($server);
        $port = (int)\substr($address, (int)\strrpos($address, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        return $port;
    }

    private function waitForSocket(string $path, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;
        do {
            if (\file_exists($path)) {
                return;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);
        self::fail('Socket did not appear: ' . $path);
    }

    private function waitForFile(string $path, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;
        do {
            if (\is_file($path) && \filesize($path) > 0) {
                return;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);
        self::fail('File did not appear: ' . $path);
    }

    private function waitForTcp(int $port, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;
        do {
            $socket = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $error,
                0.2,
            );
            if (\is_resource($socket)) {
                \fclose($socket);
                return;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);
        self::fail('TCP listener did not appear: ' . $port . ' ' . $error);
    }

    private function waitForBackendHealth(
        int $port,
        string $edgeSecret,
        float $timeout,
    ): void {
        $deadline = \microtime(true) + $timeout;
        $last = '';
        do {
            $socket = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $error,
                0.2,
            );
            if (\is_resource($socket)) {
                $nonce = \bin2hex(\random_bytes(16));
                \stream_set_timeout($socket, 1);
                $request = "GET /_wls/health?detail=1&gateway=1&nonce={$nonce} HTTP/1.1\r\n"
                    . "Host: localhost\r\nX-WLS-Edge-Token: {$edgeSecret}\r\n"
                    . "X-WLS-Client-Protocol: HTTP/1.1\r\nConnection: close\r\n\r\n";
                @\fwrite($socket, $request);
                $last = (string)@\stream_get_contents($socket);
                @\fclose($socket);
                if (\str_starts_with($last, 'HTTP/1.1 200 ')
                    && \str_contains($last, '"nonce":"' . $nonce . '"')
                ) {
                    return;
                }
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);
        self::fail(
            'Backend health listener did not become ready: ' . $port
                . ' last=' . $last . ' error=' . $error,
        );
    }

    private function waitForHttp(string $url, int $expected, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;
        $last = '';
        do {
            $result = $this->runCommand([
                $this->curl,
                '--silent',
                '--show-error',
                '--noproxy',
                '*',
                '--connect-timeout',
                '1',
                '--max-time',
                '2',
                '--output',
                '/dev/null',
                '--write-out',
                '%{http_code}',
                $url,
            ]);
            $last = $result['output'];
            if ($result['code'] === 0 && (int)\trim($last) === $expected) {
                return;
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);
        self::fail(
            'HTTP listener did not become ready: ' . $url . ' last=' . $last
                . ' controller=' . $this->processLog('controller-primary')
                . ' controller_process=' . $this->processStatus('controller-primary')
                . ' broker=' . $this->processLog('broker')
                . ' journal=' . (string)@\file_get_contents(
                    $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl',
                )
                . ' nginx=' . (string)@\file_get_contents(
                    $this->home . DIRECTORY_SEPARATOR . 'runtime/logs/error.log',
                ),
        );
    }

    /** @return array<int,string> */
    private function protectedPortOwners(): array
    {
        $owners = [];
        foreach ([
            ['-iTCP:80', '-sTCP:LISTEN'],
            ['-iTCP:443', '-sTCP:LISTEN'],
            ['-iUDP:443'],
        ] as $arguments) {
            $result = $this->runCommand(['lsof', '-nP', ...$arguments, '-t']);
            foreach (\preg_split('/\s+/', \trim($result['output'])) ?: [] as $rawPid) {
                if (!\ctype_digit($rawPid)) {
                    continue;
                }
                $pid = (int)$rawPid;
                $command = $this->processCommand($pid);
                if ($command !== '') {
                    $owners[$pid] = $command;
                }
            }
        }
        return $owners;
    }

    private function assertProtectedPortOwnersUnchanged(): void
    {
        foreach ($this->protectedPortOwners as $pid => $command) {
            self::assertTrue(
                @\posix_kill($pid, 0),
                'A pre-existing 80/443 owner was stopped: ' . $pid . ' ' . $command,
            );
            self::assertSame(
                $command,
                $this->processCommand($pid),
                'A pre-existing 80/443 owner changed identity.',
            );
        }
    }

    private function processCommand(int $pid): string
    {
        $result = $this->runCommand(['ps', '-p', (string)$pid, '-o', 'command=']);
        return $result['code'] === 0 ? \trim($result['output']) : '';
    }

    private function which(string $binary): string
    {
        $result = $this->runCommand(['which', $binary]);
        return $result['code'] === 0 ? \trim($result['output']) : '';
    }

    private function findTlsAcceptanceOpenSsl(): string
    {
        $configured = \trim((string)\getenv('WLS_GATEWAY_TEST_OPENSSL'));
        $candidates = \array_values(\array_unique(\array_filter([
            $configured,
            '/opt/homebrew/bin/openssl',
            '/opt/homebrew/opt/openssl@3/bin/openssl',
            '/usr/local/bin/openssl',
            '/usr/local/opt/openssl@3/bin/openssl',
            $this->which('openssl'),
        ], static fn (string $candidate): bool => $candidate !== '')));
        foreach ($candidates as $candidate) {
            if (!\is_executable($candidate)) {
                continue;
            }
            $help = $this->runCommand([$candidate, 's_client', '-help']);
            if (\str_contains($help['output'], '-tls1_3')
                && \str_contains($help['output'], '-early_data')
            ) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command): array
    {
        $parts = \array_map(static fn (string $part): string => \escapeshellarg($part), $command);
        $output = [];
        $code = 0;
        \exec(\implode(' ', $parts) . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommandWithInput(array $command, string $input): array
    {
        $process = \proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        self::assertSame(\strlen($input), \fwrite($pipes[0], $input));
        \fclose($pipes[0]);
        $stdout = (string)\stream_get_contents($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);
        return [
            'code' => $code,
            'output' => $stdout . ($stderr !== '' ? "\n" . $stderr : ''),
        ];
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array{code:int,output:string}
     */
    private function runCommandWithEnvironment(
        array $command,
        array $environment = [],
    ): array {
        $current = \getenv();
        $processEnvironment = \array_replace(
            \is_array($current) ? $current : [],
            $environment,
        );
        $process = \proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $processEnvironment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = (string)\stream_get_contents($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);
        return [
            'code' => $code,
            'output' => $stdout . ($stderr !== '' ? "\n" . $stderr : ''),
        ];
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
