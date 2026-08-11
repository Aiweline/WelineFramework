<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;

final class GatewayProtocolSecurityTest extends TestCase
{
    private string $root = '';
    private string $home = '';
    private string $hostId = '';
    private string $adminSecret = '';
    private string $fencing = '';
    /** @var list<string> */
    private array $lastBrokerActions = [];
    private \ReflectionMethod $serveClient;
    private \WlsEdgeGatewayController $controller;

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('stream_socket_pair')) {
            self::markTestSkipped('The protocol security harness currently requires POSIX socket pairs.');
        }
        $temporaryRoot = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-protocol-'
            . \bin2hex(\random_bytes(8));
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $state = $this->home . DIRECTORY_SEPARATOR . 'state';
        $trust = $this->home . DIRECTORY_SEPARATOR . 'trust';
        $slot = $this->home . DIRECTORY_SEPARATOR . 'slots' . DIRECTORY_SEPARATOR . 'A';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0750, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'share', 0700, true));
        $this->hostId = \bin2hex(\random_bytes(16));
        $this->adminSecret = \bin2hex(\random_bytes(32));
        $this->fencing = \bin2hex(\random_bytes(32));
        self::assertNotFalse(\file_put_contents($trust . DIRECTORY_SEPARATOR . 'host-id', $this->hostId));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            $this->adminSecret,
        ));
        self::assertNotFalse(\file_put_contents($trust . DIRECTORY_SEPARATOR . 'active-slot', "A\n"));
        $trustBundle = $this->certificateAuthority();
        $trustBundlePath = $slot . DIRECTORY_SEPARATOR . 'share'
            . DIRECTORY_SEPARATOR . 'ca-bundle.pem';
        self::assertNotFalse(\file_put_contents($trustBundlePath, $trustBundle));
        self::assertTrue(\chmod($trustBundlePath, 0644));
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode([
                'slot' => 'A',
                'test_mode' => true,
                'release_ready' => false,
                'implementation_level' => 'wls-2.0',
                'security_profile' => 'native-broker-v1',
                'runtime_generation' => \hash('sha256', 'test-runtime-a'),
                'capabilities' => [
                    'certificate_public_trust_bundle' => true,
                ],
                'components' => [
                    'share/ca-bundle.pem' => [
                        'sha256' => \hash('sha256', $trustBundle),
                        'size' => \strlen($trustBundle),
                        'mode' => 0644,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $nginx = $slot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'nginx';
        self::assertNotFalse(\file_put_contents(
            $nginx,
            "#!/bin/sh\nprintf '%s\\n' 'nginx version: wls-test' >&2\nexit 0\n",
        ));
        self::assertTrue(\chmod($nginx, 0700));

        $run = $this->home . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'run';
        self::assertTrue(\mkdir($run, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token',
            $this->fencing,
        ));
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
        $this->controller = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $run . DIRECTORY_SEPARATOR . 'controller.sock',
        );
        $this->serveClient = new \ReflectionMethod($this->controller, 'serveClient');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testControllerPreservesPreProvisionedTrustDirectoryAcl(): void
    {
        self::assertSame(
            0750,
            (int)\fileperms($this->home . DIRECTORY_SEPARATOR . 'trust') & 0777,
        );
    }

    public function testControllerCapacityContractMatchesWlsTwoPlan(): void
    {
        self::assertSame(
            128,
            (new \ReflectionClassConstant(
                \WlsEdgeGatewayController::class,
                'MAX_PROJECTS',
            ))->getValue(),
        );
        self::assertSame(
            256,
            (new \ReflectionClassConstant(
                \WlsEdgeGatewayController::class,
                'MAX_ROUTES_PER_PROJECT',
            ))->getValue(),
        );
        self::assertSame(
            2048,
            (new \ReflectionClassConstant(
                \WlsEdgeGatewayController::class,
                'MAX_TOTAL_ROUTES',
            ))->getValue(),
        );
        $constant = static fn (string $name): int|float => (new \ReflectionClassConstant(
            \WlsEdgeGatewayController::class,
            $name,
        ))->getValue();
        self::assertSame(512, $constant('PROBE_MAX_IN_FLIGHT_DEFAULT'));
        self::assertSame(768, $constant('PROBE_MAX_IN_FLIGHT_HARD_LIMIT'));
        self::assertSame(64, $constant('PROBE_MAX_IN_FLIGHT_MINIMUM'));
        self::assertSame(60, $constant('PROBE_WINDOWS_SELECT_SAFE_LIMIT'));
        self::assertLessThanOrEqual(
            60.0,
            $constant('PUBLIC_ROUTE_PROBE_PROOF_TTL_SECONDS'),
        );
        self::assertGreaterThanOrEqual(
            0.5,
            $constant('BACKEND_PROBE_ENDPOINT_TIMEOUT_SECONDS'),
            'Loopback identity checks must retain a realistic endpoint budget.',
        );
        self::assertLessThanOrEqual(
            60.0,
            \ceil(
                $constant('MAX_TOTAL_ROUTES')
                    * $constant('MAX_BACKENDS_PER_ROUTE')
                    / $constant('PROBE_MAX_IN_FLIGHT_DEFAULT'),
            ) * $constant('BACKEND_PROBE_ENDPOINT_TIMEOUT_SECONDS'),
            'The maximum backend tuple closure must fit the 60-second contract.',
        );
        self::assertLessThanOrEqual(
            60.0,
            \ceil(
                $constant('MAX_TOTAL_ROUTES')
                    / $constant('PROBE_MAX_IN_FLIGHT_DEFAULT'),
            ) * $constant('PUBLIC_ROUTE_PROBE_ENDPOINT_TIMEOUT_SECONDS'),
            'The maximum public SNI route set must fit the 60-second contract.',
        );
        self::assertGreaterThanOrEqual(
            0.5,
            $constant('PUBLIC_ROUTE_PROBE_ENDPOINT_TIMEOUT_SECONDS'),
            'A public TLS handshake must retain a realistic endpoint budget.',
        );
        $batchesPerMaintenance = (int)\floor(
            ($constant('PUBLIC_ROUTE_PROBE_BUDGET_SECONDS') - 0.25)
                / $constant('PUBLIC_ROUTE_PROBE_ENDPOINT_TIMEOUT_SECONDS'),
        );
        $windowsCycles = (int)\ceil(
            $constant('MAX_TOTAL_ROUTES')
                / ($constant('PROBE_WINDOWS_SELECT_SAFE_LIMIT')
                    * $batchesPerMaintenance),
        );
        self::assertGreaterThanOrEqual(3, $batchesPerMaintenance);
        self::assertLessThanOrEqual(
            60.0,
            ($windowsCycles - 1) * $constant('HEALTH_INTERVAL')
                + $constant('PUBLIC_ROUTE_PROBE_BUDGET_SECONDS'),
            'Even the Windows select fence must close one 2048-route sweep before proof expiry.',
        );
        self::assertLessThanOrEqual(
            $constant('NONCE_WAL_MAX_BYTES'),
            $constant('MAX_DURABLE_NONCES')
                * $constant('NONCE_WAL_MAX_RECORD_BYTES'),
            'The complete durable replay set must fit one bounded WAL image.',
        );
        $heartbeatWorstCase = $constant('MAX_TOTAL_INSTANCES') * 3
            + $constant('MAX_TOTAL_INSTANCES') * 0.125
                * $constant('HEARTBEAT_NONCE_RETENTION_SECONDS')
            + $constant('MAX_PROJECTS') * 3;
        self::assertLessThan(
            $constant('MAX_HEARTBEAT_NONCES'),
            $heartbeatWorstCase,
            'Heartbeat replay capacity must cover instance and invalid-identity bursts.',
        );
        $readOnlyWorstCase = ($constant('MAX_PROJECTS') + 1)
            * (10 + 2 * 120);
        self::assertLessThan(
            $constant('MAX_READ_ONLY_NONCES'),
            $readOnlyWorstCase,
            'Read-only replay capacity must cover every bounded project/admin window.',
        );
        self::assertLessThan(
            $constant('MAX_RATE_WINDOWS'),
            $constant('MAX_TOTAL_INSTANCES') + 2 * $constant('MAX_PROJECTS') + 8,
        );
    }

    public function testControllerRejectsUnauthenticatedNativeBrokerProbe(): void
    {
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        self::assertSame(
            \strlen("WLS-BROKER-PROBE/1\tbad\tbad\n"),
            \fwrite($sockets[0], "WLS-BROKER-PROBE/1\tbad\tbad\n"),
        );
        $this->serveClient->invoke($this->controller, $sockets[1], 0.05);
        self::assertSame('', \stream_get_contents($sockets[0]));
        \fclose($sockets[0]);
    }

    public function testControllerAcceptsCompleteNativeBrokerIdentityEnvelope(): void
    {
        $response = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
        );

        self::assertTrue($response['ok'], \json_encode($response));
        self::assertSame('wls-edge/2', $response['protocol']);
        self::assertArrayHasKey('control_plane_ready', $response['payload']);
        self::assertFalse(
            $response['payload']['control_plane_ready'],
            'A test-only unsigned runtime must publish the admission field but remain untrusted.',
        );
    }

    public function testBrokerVerifiedBadSignaturesConsumeBoundedPreAuthenticationBudget(): void
    {
        $response = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
            tamperSignature: true,
        );
        self::assertFalse($response['ok']);
        self::assertSame('unauthorized', $response['error']['code']);

        $windows = new \ReflectionProperty(
            $this->controller,
            'preAuthenticationRateWindows',
        );
        $current = $windows->getValue($this->controller);
        self::assertCount(2, $current);
        $peerKey = (string)\array_values(\array_filter(
            \array_keys($current),
            static fn (string $key): bool => $key !== 'global',
        ))[0];
        self::assertLessThan(64.0, (float)$current[$peerKey]['tokens']);
        $current[$peerKey] = [
            'tokens' => 0.0,
            'at' => \hrtime(true) / 1_000_000_000,
        ];
        $windows->setValue($this->controller, $current);

        $limited = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
            tamperSignature: true,
        );
        self::assertFalse($limited['ok']);
        self::assertSame('rate_limited', $limited['error']['code']);
        self::assertStringContainsString(
            'pre-authentication rate limit exceeded',
            $limited['error']['message'],
        );
    }

    public function testControllerRejectsBrokerIdentityWithoutActionProtocolTwo(): void
    {
        $response = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
            brokerActionProtocol: null,
        );

        self::assertFalse($response['ok']);
        self::assertSame('broker_identity_invalid', $response['error']['code']);
        self::assertStringContainsString(
            'identity envelope fields are invalid',
            $response['error']['message'],
        );
    }

    public function testTestModeEnrollmentUsesOnlySyntheticSecurityAuthority(): void
    {
        $project = $this->createProject();
        $response = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174099',
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['synthetic.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );

        self::assertTrue($response['ok'], \json_encode($response));
        self::assertSame([], $this->lastBrokerActions);
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertTrue($state['native_security_attested']);
        self::assertNotSame(
            \str_repeat('0', 64),
            $state['native_security_ledger_digest'],
        );
    }

    public function testProductionEnrollmentRequiresNativeBrokerActionChannel(): void
    {
        $manifestPath = $this->home . DIRECTORY_SEPARATOR . 'slots/A/manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifest['test_mode'] = false;
        self::assertNotFalse(\file_put_contents(
            $manifestPath,
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        ));
        $project = $this->createProject();
        $prepare = new \ReflectionMethod(
            $this->controller,
            'prepareBrokerCertificateRoots',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Production enrollment requires native Broker AUTH_PREPARE.',
        );
        $prepare->invoke(
            $this->controller,
            \str_repeat('a', 32),
            '123e4567-e89b-42d3-a456-426614174096',
            \str_repeat('b', 64),
            0,
            $project,
            ['project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl'],
            [
                'kind' => 'posix',
                'uid' => (int)\posix_geteuid(),
                'gid' => (int)\posix_getegid(),
            ],
        );
    }

    public function testUnauthenticatedBrokerCannotHoldControlLoopForRequestTimeout(): void
    {
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        $started = \hrtime(true);
        $this->serveClient->invoke($this->controller, $sockets[1], 2.0);
        $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
        self::assertLessThan(
            0.8,
            $elapsed,
            'Unauthenticated Broker handshake consumed the ordinary request timeout.',
        );
        \fclose($sockets[0]);
    }

    public function testPosixNginxIdentityRejectsUnverifiedSlotOrConfig(): void
    {
        $slotA = $this->home . DIRECTORY_SEPARATOR . 'slots/A/bin/nginx';
        $slotBDir = $this->home . DIRECTORY_SEPARATOR . 'slots/B/bin';
        self::assertTrue(\mkdir($slotBDir, 0700, true));
        $slotB = $slotBDir . DIRECTORY_SEPARATOR . 'nginx';
        self::assertTrue(\copy($slotA, $slotB));
        self::assertTrue(\chmod($slotB, 0700));
        $config = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf/nginx.conf';
        self::assertTrue(
            \is_dir(\dirname($config))
                || \mkdir(\dirname($config), 0700, true),
        );
        self::assertNotFalse(\file_put_contents($config, "events {}\nhttp {}\n"));
        $expectedHash = (string)\hash_file('sha256', $slotA);
        $method = new \ReflectionMethod($this->controller, 'posixNginxIdentityMatches');
        $pid = \getmypid();
        $command = 'nginx: master process ' . $slotB . ' -c ' . $config;

        // Linux adds a /proc live-executable digest fence, so the current PHP
        // process cannot impersonate Nginx even with a matching command.
        self::assertSame(
            \PHP_OS_FAMILY !== 'Linux',
            $method->invoke($this->controller, $pid, $command, $expectedHash),
        );
        self::assertFalse($method->invoke(
            $this->controller,
            $pid,
            'nginx: master process ' . $slotB . ' -c /tmp/other.conf',
            $expectedHash,
        ));
        self::assertFalse($method->invoke(
            $this->controller,
            $pid,
            $command,
            \str_repeat('f', 64),
        ));
    }

    public function testProductionStartRequiresPublicListenerWithdrawalWithoutPid(): void
    {
        $this->assertProductionEntryRequiresPublicListenerWithdrawal('startDataPlane');
    }

    public function testProductionRestartRequiresPublicListenerWithdrawalWithoutPid(): void
    {
        $this->assertProductionEntryRequiresPublicListenerWithdrawal('restartDataPlane');
    }

    private function assertProductionEntryRequiresPublicListenerWithdrawal(
        string $entryMethod,
    ): void
    {
        $controller = $this->controller;
        $listener = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
        );
        self::assertIsResource(
            $listener,
            'Unable to reserve the stale public listener: ' . $errorCode . ' ' . $errorMessage,
        );
        try {
            $listenerName = (string)\stream_socket_get_name($listener, false);
            self::assertMatchesRegularExpression('/:[1-9][0-9]*\z/D', $listenerName);
            $port = (int)\substr($listenerName, (int)\strrpos($listenerName, ':') + 1);

            $stateProperty = new \ReflectionProperty($controller, 'state');
            $state = $stateProperty->getValue($controller);
            $state['public_http'] = $port;
            $state['public_https'] = $port;
            $stateProperty->setValue($controller, $state);

            $manifestFile = $this->home . DIRECTORY_SEPARATOR
                . 'slots/A/manifest.json';
            $manifest = \json_decode(
                (string)\file_get_contents($manifestFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $manifest['test_mode'] = false;
            $manifest['listen_profile'] = 'ipv4-only';
            self::assertNotFalse(\file_put_contents(
                $manifestFile,
                \json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ));
            $probe = \stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $probeErrorCode,
                $probeErrorMessage,
                0.2,
            );
            self::assertIsResource(
                $probe,
                'The reserved listener is not reachable: '
                    . $probeErrorCode . ' ' . $probeErrorMessage,
            );
            \fclose($probe);
            $method = new \ReflectionMethod($controller, $entryMethod);
            $result = null;
            try {
                $result = $entryMethod === 'restartDataPlane'
                    ? $method->invoke($controller, 'test listener withdrawal')
                    : $method->invoke($controller);
            } catch (\DomainException $exception) {
                // A non-root test fixture becomes an intentionally retired
                // Controller when its manifest is switched to production.
                // The generation fence may stop that Controller before its
                // diagnostic state can be persisted; it still must not gain
                // authority over the unknown listener.
                self::assertStringContainsString(
                    'Native Broker generation changed',
                    $exception->getMessage(),
                );
            }

            if ($entryMethod === 'startDataPlane' && \is_array($result)) {
                self::assertFalse($result['ok'] ?? true);
                self::assertSame('UNPROVEN', $result['state'] ?? null);
                self::assertStringContainsString(
                    'Native Broker TCP port proof is unavailable',
                    (string)($result['message'] ?? ''),
                );
            }
            self::assertFalse((new \ReflectionProperty(
                $controller,
                'serviceTreeRestartRequested',
            ))->getValue($controller));
            $failedState = $stateProperty->getValue($controller);
            self::assertSame(
                'UNPROVEN',
                $failedState['recovery']['stage'] ?? null,
            );
            self::assertStringContainsString(
                'Native Broker TCP port proof is unavailable',
                (string)($failedState['recovery']['last_failure'] ?? ''),
            );
            $stillOpen = \stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $stillOpenErrorCode,
                $stillOpenErrorMessage,
                0.2,
            );
            self::assertIsResource(
                $stillOpen,
                'The unknown listener was modified: '
                    . $stillOpenErrorCode . ' ' . $stillOpenErrorMessage,
            );
            \fclose($stillOpen);
        } finally {
            \fclose($listener);
        }
    }

    public function testEnrollmentIssuesHostBoundCredentialAndProjectCannotUseAdminOperation(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174000';
        $before = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($before['ok']);
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['shop.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);
        $this->assertResponseSignature($enrollment, $this->adminSecret);
        $credential = $enrollment['payload']['credential'];
        $credentialKeys = \array_keys($credential);
        \sort($credentialKeys);
        self::assertSame([
            'credential_generation',
            'credential_id',
            'host_id',
            'issued_at',
            'project_uuid',
            'protocol',
            'schema_version',
            'secret',
        ], $credentialKeys);
        self::assertSame($this->hostId, $credential['host_id']);
        self::assertSame($projectUuid, $credential['project_uuid']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $credential['credential_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $credential['secret']);
        self::assertSame(
            1,
            (int)$enrollment['payload']['security_generation'],
            'The first host security allocation is independent of the ordinary control generation.',
        );
        $after = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($after['ok']);
        self::assertSame(
            (int)$before['payload']['control_generation'] + 1,
            (int)$after['payload']['control_generation'],
            'Enrollment advances the control generation independently of its security allocation.',
        );
        self::assertSame(
            (int)$before['payload']['generation'],
            (int)$after['payload']['generation'],
            'Enrollment changes host authorization, not the active Nginx configuration.',
        );

        $forbidden = $this->request(
            'project',
            'stop',
            ['project_uuid' => $projectUuid, 'force' => true],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($forbidden['ok']);
        self::assertSame('unauthorized', $forbidden['error']['code']);
        self::assertStringContainsString(
            'cannot perform administrator operations',
            $forbidden['error']['message'],
        );
        $this->assertResponseSignature($forbidden, (string)$credential['secret']);
    }

    public function testAdminStoppedIntentUsesNativeLauncherCompatibleBinaryHmacKey(): void
    {
        (new \ReflectionMethod($this->controller, 'writeAdminStoppedIntent'))
            ->invoke($this->controller);
        $file = $this->home . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
        self::assertFileExists($file);
        $contents = (string)\file_get_contents($file);
        self::assertSame(1, \preg_match(
            '/\A(WLS-ADMIN-STOPPED\\/1\\n'
                . 'host_id=[a-f0-9]{32}\\n'
                . 'epoch=[a-f0-9]{32}\\n'
                . 'at=[0-9]+\\n'
                . 'nonce=[a-f0-9]{32}\\n)'
                . 'signature=([a-f0-9]{64})\\n\z/D',
            $contents,
            $matches,
        ));
        $key = \hex2bin($this->adminSecret);
        self::assertIsString($key);
        self::assertSame(
            \hash_hmac('sha256', (string)$matches[1], $key),
            (string)$matches[2],
        );
        \sodium_memzero($key);
    }

    public function testUnenrolledPeerTamperReplayAndCrossOwnerAreRejected(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174001';
        $unenrolled = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            \bin2hex(\random_bytes(16)),
            \bin2hex(\random_bytes(32)),
        );
        self::assertFalse($unenrolled['ok']);

        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['api.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        $credential = $enrollment['payload']['credential'];
        $nonce = \bin2hex(\random_bytes(16));
        $valid = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            nonce: $nonce,
        );
        self::assertTrue($valid['ok']);
        $this->assertResponseSignature($valid, (string)$credential['secret']);
        self::assertArrayHasKey('route_serving_ready', $valid['payload']);
        self::assertFalse(
            $valid['payload']['route_serving_ready'],
            'An enrolled project with no ACTIVE route must not gain a serving claim.',
        );
        self::assertFalse(
            $valid['payload']['project_ready'],
            'An enrolled project with no ACTIVE route must not claim whole-project convergence.',
        );

        $replayed = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            nonce: $nonce,
        );
        self::assertFalse($replayed['ok']);
        self::assertStringContainsString('replayed', $replayed['error']['message']);

        $tampered = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            tamperDigest: true,
        );
        self::assertFalse($tampered['ok']);
        self::assertStringContainsString('digest', \strtolower($tampered['error']['message']));
        $this->assertResponseSignature($tampered, (string)$credential['secret']);

        $wrongOwner = $this->request(
            'project',
            'own-status',
            ['project_uuid' => $projectUuid],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
            peerUid: (int)\posix_geteuid() + 1000,
        );
        self::assertFalse($wrongOwner['ok']);
        self::assertStringContainsString('peer identity', $wrongOwner['error']['message']);
    }

    public function testRequestMonotonicTimestampAndNonceRetentionResistWallClockRollback(): void
    {
        $stale = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
            monotonicTimestamp: (\hrtime(true) / 1_000_000_000) - 61.0,
        );
        self::assertFalse($stale['ok']);
        self::assertStringContainsString(
            'monotonic timestamp',
            \strtolower((string)$stale['error']['message']),
        );

        $hostBootId = (string)(new \ReflectionProperty(
            $this->controller,
            'hostBootId',
        ))->getValue($this->controller);
        $now = \hrtime(true) / 1_000_000_000;
        $legacyNonce = \str_repeat('1', 32);
        $freshNonce = \str_repeat('2', 32);
        $expiredNonce = \str_repeat('3', 32);
        $previousBootNonce = \str_repeat('4', 32);
        $normalize = new \ReflectionMethod($this->controller, 'normalizePersistedNonces');
        $normalized = $normalize->invoke($this->controller, [
            $legacyNonce => \time() - 3600,
            $freshNonce => [
                'seen_at' => \time(),
                'seen_monotonic' => $now - 30.0,
                'boot_id' => $hostBootId,
            ],
            $expiredNonce => [
                'seen_at' => \time(),
                'seen_monotonic' => $now - 121.0,
                'boot_id' => $hostBootId,
            ],
            $previousBootNonce => [
                'seen_at' => \time() - 3600,
                'seen_monotonic' => 1.0,
                'boot_id' => \str_repeat('f', 64),
            ],
            'not-a-valid-nonce' => \time(),
            \str_repeat('5', 32) => [
                'seen_at' => 'invalid',
                'seen_monotonic' => $now,
                'boot_id' => $hostBootId,
            ],
        ]);
        self::assertSame('legacy', $normalized[$legacyNonce]['boot_id']);
        self::assertSame(0.0, $normalized[$legacyNonce]['seen_monotonic']);
        self::assertArrayNotHasKey('not-a-valid-nonce', $normalized);
        self::assertArrayNotHasKey(\str_repeat('5', 32), $normalized);

        $nonces = new \ReflectionProperty($this->controller, 'nonces');
        $nonces->setValue($this->controller, $normalized);
        (new \ReflectionMethod($this->controller, 'pruneNonces'))
            ->invoke($this->controller);
        $retained = $nonces->getValue($this->controller);
        self::assertArrayHasKey($legacyNonce, $retained);
        self::assertArrayHasKey($freshNonce, $retained);
        self::assertArrayNotHasKey($expiredNonce, $retained);
        self::assertArrayHasKey($previousBootNonce, $retained);
    }

    public function testReadOnlyNonceTrafficCannotEvictDurableReplayEvidence(): void
    {
        $hostBootId = (string)(new \ReflectionProperty(
            $this->controller,
            'hostBootId',
        ))->getValue($this->controller);
        $now = \hrtime(true) / 1_000_000_000;
        $durableNonce = \str_repeat('a', 32);
        $durable = new \ReflectionProperty($this->controller, 'nonces');
        $durable->setValue($this->controller, [
            $durableNonce => [
                'seen_at' => \time(),
                'seen_monotonic' => $now,
                'boot_id' => $hostBootId,
            ],
        ]);
        $readOnly = new \ReflectionProperty($this->controller, 'readOnlyNonces');
        $readOnlyLimit = (int)(new \ReflectionClassConstant(
            \WlsEdgeGatewayController::class,
            'MAX_READ_ONLY_NONCES',
        ))->getValue();
        $readOnlyRecords = [];
        for ($index = 0; $index < $readOnlyLimit; ++$index) {
            $readOnlyRecords[\sprintf('%032x', $index + 1)] = [
                'seen_at' => \time(),
                'seen_monotonic' => $now,
                'boot_id' => $hostBootId,
            ];
        }
        $readOnly->setValue($this->controller, $readOnlyRecords);

        $statusNonce = \str_repeat('f', 32);
        $response = $this->request(
            'admin',
            'status',
            [],
            'admin',
            $this->adminSecret,
            nonce: $statusNonce,
        );

        self::assertTrue($response['ok'], \json_encode($response));
        self::assertArrayHasKey($durableNonce, $durable->getValue($this->controller));
        self::assertCount($readOnlyLimit, $readOnly->getValue($this->controller));
        self::assertArrayHasKey($statusNonce, $readOnly->getValue($this->controller));
        self::assertFileDoesNotExist(
            $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal',
            'Read-only authentication must not consume durable WAL capacity.',
        );
    }

    public function testRateLimitedAuthenticatedRequestDoesNotGrowNonceWal(): void
    {
        $authenticate = new \ReflectionMethod($this->controller, 'authenticate');
        $broker = [
            'channel' => 'admin',
            'uid' => (int)\posix_geteuid(),
        ];
        $lastAcceptedNonce = '';
        for ($index = 0; $index < 10; ++$index) {
            $request = $this->signedRequest(
                'admin',
                'enroll',
                [],
                'admin',
                $this->adminSecret,
            );
            $lastAcceptedNonce = (string)$request['nonce'];
            $result = $authenticate->invoke($this->controller, $request, $broker);
            self::assertSame('', $result['error']);
        }
        $wal = $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal';
        self::assertFileExists($wal);
        $acceptedSize = (int)\filesize($wal);
        self::assertGreaterThan(0, $acceptedSize);

        $rejected = $this->signedRequest(
            'admin',
            'enroll',
            [],
            'admin',
            $this->adminSecret,
        );
        $result = $authenticate->invoke($this->controller, $rejected, $broker);
        self::assertSame('rate_limited', $result['error_code']);
        self::assertStringContainsString('rate limit exceeded', $result['error']);
        \clearstatcache(true, $wal);
        self::assertSame($acceptedSize, (int)\filesize($wal));
        $nonces = (new \ReflectionProperty($this->controller, 'nonces'))
            ->getValue($this->controller);
        self::assertArrayHasKey($lastAcceptedNonce, $nonces);
        self::assertArrayNotHasKey((string)$rejected['nonce'], $nonces);
    }

    public function testNonceWalCompactionRetainsOnlyLiveReplaySetAndNewAppend(): void
    {
        $hostBootId = (string)(new \ReflectionProperty(
            $this->controller,
            'hostBootId',
        ))->getValue($this->controller);
        $encode = new \ReflectionMethod($this->controller, 'encodeNonceWalLine');
        $oldRecord = [
            'seen_at' => \time() - 3600,
            'seen_monotonic' => (\hrtime(true) / 1_000_000_000) - 3600.0,
            'boot_id' => $hostBootId,
        ];
        $raw = '';
        for ($index = 1; $index <= 100; ++$index) {
            $raw .= $encode->invoke(
                $this->controller,
                \sprintf('%032x', $index),
                $oldRecord,
            );
        }
        $wal = $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal';
        self::assertNotFalse(\file_put_contents($wal, $raw));
        self::assertTrue(\chmod($wal, 0600));

        $now = \hrtime(true) / 1_000_000_000;
        $retainedNonce = \str_repeat('d', 32);
        $newNonce = \str_repeat('e', 32);
        $retainedRecord = [
            'seen_at' => \time(),
            'seen_monotonic' => $now,
            'boot_id' => $hostBootId,
        ];
        (new \ReflectionProperty($this->controller, 'nonces'))->setValue(
            $this->controller,
            [$retainedNonce => $retainedRecord],
        );
        (new \ReflectionMethod($this->controller, 'compactNonceWal'))->invoke(
            $this->controller,
            \strlen($raw),
        );
        (new \ReflectionMethod($this->controller, 'appendNonceWal'))->invoke(
            $this->controller,
            $newNonce,
            $retainedRecord,
        );

        $published = \file_get_contents($wal);
        self::assertIsString($published);
        self::assertLessThan(2048, \strlen($published));
        $decoded = (new \ReflectionMethod($this->controller, 'decodeNonceWal'))
            ->invoke($this->controller, $published);
        self::assertSame([$retainedNonce, $newNonce], \array_keys($decoded));
    }

    public function testNonceWalRecoversOnlyUnterminatedTailAndRejectsMiddleCorruption(): void
    {
        $hostBootId = (string)(new \ReflectionProperty(
            $this->controller,
            'hostBootId',
        ))->getValue($this->controller);
        $nonce = \str_repeat('c', 32);
        $line = (new \ReflectionMethod($this->controller, 'encodeNonceWalLine'))
            ->invoke($this->controller, $nonce, [
                'seen_at' => \time(),
                'seen_monotonic' => \hrtime(true) / 1_000_000_000,
                'boot_id' => $hostBootId,
            ]);
        $wal = $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal';
        self::assertNotFalse(\file_put_contents($wal, $line . '{"partial":'));
        self::assertTrue(\chmod($wal, 0600));

        $tailRecovered = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/nonce-tail.sock',
        );
        self::assertTrue(
            (new \ReflectionProperty($tailRecovered, 'journalTrusted'))
                ->getValue($tailRecovered),
        );
        self::assertArrayHasKey(
            $nonce,
            (new \ReflectionProperty($tailRecovered, 'nonces'))->getValue($tailRecovered),
        );
        self::assertSame($line, \file_get_contents($wal));

        self::assertNotFalse(\file_put_contents($wal, "{\"invalid\":true}\n" . $line));
        $middleCorrupt = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/nonce-middle.sock',
        );
        self::assertFalse(
            (new \ReflectionProperty($middleCorrupt, 'nonceWalTrusted'))
                ->getValue($middleCorrupt),
        );
        self::assertTrue(
            (new \ReflectionProperty($middleCorrupt, 'journalTrusted'))
                ->getValue($middleCorrupt),
        );
        $state = (new \ReflectionProperty($middleCorrupt, 'state'))
            ->getValue($middleCorrupt);
        self::assertSame('NONCE_WAL_UNTRUSTED', $state['health_state']);
    }

    public function testNonceWalDuplicateLastWinsOrderSurvivesWallRollbackAndRestart(): void
    {
        $hostBootId = $this->hostBootId();
        $encode = new \ReflectionMethod($this->controller, 'encodeNonceWalLine');
        $firstNonce = \str_repeat('1', 32);
        $secondNonce = \str_repeat('2', 32);
        $now = \hrtime(true) / 1_000_000_000;
        $raw = $encode->invoke($this->controller, $firstNonce, [
            'seen_at' => \time() + 3600,
            'seen_monotonic' => $now - 2.0,
            'boot_id' => $hostBootId,
        ]) . $encode->invoke($this->controller, $secondNonce, [
            'seen_at' => \time() + 1800,
            'seen_monotonic' => $now - 1.0,
            'boot_id' => $hostBootId,
        ]) . $encode->invoke($this->controller, $firstNonce, [
            // The wall clock moved backwards, but this is the last durable
            // append and must therefore remain the newest replay evidence.
            'seen_at' => \time() - 3600,
            'seen_monotonic' => $now,
            'boot_id' => $hostBootId,
        ]);
        $wal = $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal';
        self::assertNotFalse(\file_put_contents($wal, $raw));
        self::assertTrue(\chmod($wal, 0600));

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/nonce-order.sock',
        );
        $records = (new \ReflectionProperty($restarted, 'nonces'))
            ->getValue($restarted);
        self::assertSame([$secondNonce, $firstNonce], \array_keys($records));

        (new \ReflectionMethod($restarted, 'compactNonceWal'))->invoke(
            $restarted,
            \strlen($raw),
        );
        $compacted = (string)\file_get_contents($wal);
        $decoded = (new \ReflectionMethod($restarted, 'decodeNonceWal'))
            ->invoke($restarted, $compacted);
        self::assertSame([$secondNonce, $firstNonce], \array_keys($decoded));

        $request = $this->signedRequest(
            'admin',
            'enroll',
            [],
            'admin',
            $this->adminSecret,
        );
        $request['nonce'] = $firstNonce;
        unset($request['signature']);
        $request['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($request),
            $this->adminSecret,
        );
        $authentication = (new \ReflectionMethod($restarted, 'authenticate'))->invoke(
            $restarted,
            $request,
            ['channel' => 'admin', 'uid' => (int)\posix_geteuid()],
        );
        self::assertStringContainsString('replayed', $authentication['error']);
    }

    public function testEstablishedNonceWalCannotDisappearSilently(): void
    {
        $request = $this->signedRequest(
            'admin',
            'enroll',
            [],
            'admin',
            $this->adminSecret,
        );
        $authentication = (new \ReflectionMethod($this->controller, 'authenticate'))
            ->invoke(
                $this->controller,
                $request,
                ['channel' => 'admin', 'uid' => (int)\posix_geteuid()],
            );
        self::assertSame('', $authentication['error']);
        $wal = $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal';
        self::assertFileExists($wal);
        $ledger = \json_decode((string)\file_get_contents(
            $this->home . DIRECTORY_SEPARATOR . 'state/security-ledger.json',
        ), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(8, $ledger['payload']['schema_version']);
        self::assertTrue($ledger['payload']['nonce_wal_established']);
        self::assertSame(
            [],
            $ledger['payload']['security_publication_recovery_authority'],
        );

        self::assertTrue(\unlink($wal));
        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/nonce-missing.sock',
        );
        self::assertFalse(
            (new \ReflectionProperty($restarted, 'nonceWalTrusted'))
                ->getValue($restarted),
        );
        $status = (new \ReflectionMethod($restarted, 'status'))->invoke($restarted);
        self::assertFalse($status['ready']);
        self::assertSame('NONCE_WAL_MISSING', $status['recovery']['stage']);
    }

    public function testNonceWalAuthorityPersistenceFailureRemainsRepairableAndFailClosed(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $failureTarget = \getenv(
            'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256'
        );
        $ledgerFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/security-ledger.json';
        $walFile = $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal';
        try {
            $baselineLedger = \json_decode(
                (string)\file_get_contents($ledgerFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertFalse($baselineLedger['payload']['nonce_wal_established']);

            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE='
                    . 'directory_fsync_after_rename_failed'
            );
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                    . \hash('sha256', $ledgerFile)
            );

            $mutation = $this->request(
                'admin',
                'upgrade',
                [],
                'admin',
                $this->adminSecret,
            );
            self::assertFalse($mutation['ok']);
            self::assertSame('storage_unavailable', $mutation['error']['code']);
            self::assertFileExists($walFile);
            self::assertNotSame('', (string)\file_get_contents($walFile));
            self::assertTrue(
                (new \ReflectionProperty(
                    $this->controller,
                    'nonceWalAuthorityPersistPending',
                ))->getValue($this->controller),
                'A failed first authority publication must remain retryable.',
            );

            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256');
            $repair = $this->request(
                'admin',
                'repair',
                ['accept_storage_recovery' => true],
                'admin',
                $this->adminSecret,
            );
            self::assertTrue($repair['ok'], \json_encode($repair));
            self::assertFalse(
                (new \ReflectionProperty(
                    $this->controller,
                    'nonceWalAuthorityPersistPending',
                ))->getValue($this->controller),
            );
            $repairedLedger = \json_decode(
                (string)\file_get_contents($ledgerFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(8, $repairedLedger['payload']['schema_version']);
            self::assertTrue($repairedLedger['payload']['nonce_wal_established']);
            self::assertSame(
                [],
                $repairedLedger['payload']['security_publication_recovery_authority'],
            );

            $restarted = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR
                    . 'runtime/run/nonce-authority-repaired.sock',
            );
            self::assertTrue(
                (new \ReflectionProperty($restarted, 'nonceWalTrusted'))
                    ->getValue($restarted),
            );

            self::assertTrue(\unlink($walFile));
            $missingWal = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR
                    . 'runtime/run/nonce-authority-missing.sock',
            );
            self::assertFalse(
                (new \ReflectionProperty($missingWal, 'nonceWalTrusted'))
                    ->getValue($missingWal),
            );
            $status = (new \ReflectionMethod($missingWal, 'status'))
                ->invoke($missingWal);
            self::assertFalse($status['ready']);
            self::assertSame('NONCE_WAL_MISSING', $status['recovery']['stage']);
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $failureTarget === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                        . $failureTarget
                );
        }
    }

    public function testClockUntrustedStateAllowsOnlyExactAdministratorAcknowledgement(): void
    {
        $clockWallAnchor = new \ReflectionProperty($this->controller, 'clockWallAnchor');
        $clockWallAnchor->setValue($this->controller, \time() - 10);

        $blocked = $this->request(
            'admin',
            'enroll',
            [],
            'admin',
            $this->adminSecret,
        );
        self::assertFalse($blocked['ok']);
        self::assertStringContainsString(
            'wall clock is untrusted',
            \strtolower((string)$blocked['error']['message']),
        );

        $combinedRepair = $this->request(
            'admin',
            'repair',
            [
                'accept_clock' => true,
                'accept_security_reset' => true,
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertFalse($combinedRepair['ok']);
        self::assertStringContainsString(
            'wall clock is untrusted',
            \strtolower((string)$combinedRepair['error']['message']),
        );
        self::assertFileDoesNotExist(
            $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal',
            'Clock-rejected requests must not consume durable replay capacity.',
        );

        $accepted = $this->request(
            'admin',
            'repair',
            ['accept_clock' => true],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($accepted['ok'], \json_encode($accepted));
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
    }

    public function testClockAndStorageFencesRequireAndAcceptOneExactCombinedRepair(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $freeBytes = \getenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES');
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=1');
            (new \ReflectionMethod($this->controller, 'markDiskPressure'))->invoke(
                $this->controller,
                'DISK_PRESSURE',
                'combined_clock_storage_recovery_test',
            );
            (new \ReflectionProperty($this->controller, 'readOnlyRecoveryMode'))
                ->setValue($this->controller, true);
            (new \ReflectionProperty($this->controller, 'clockWallAnchor'))
                ->setValue($this->controller, \time() - 10);

            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=33554432');
            $response = $this->request(
                'admin',
                'repair',
                [
                    'accept_clock' => true,
                    'accept_storage_recovery' => true,
                ],
                'admin',
                $this->adminSecret,
            );

            self::assertTrue($response['ok'], \json_encode($response));
            self::assertFileDoesNotExist(
                $this->home . DIRECTORY_SEPARATOR . 'state/disk-pressure.marker',
            );
            $state = (new \ReflectionProperty($this->controller, 'state'))
                ->getValue($this->controller);
            self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
            self::assertFalse(
                (new \ReflectionProperty($this->controller, 'readOnlyRecoveryMode'))
                    ->getValue($this->controller),
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $freeBytes === false
                ? \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES')
                : \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=' . $freeBytes);
        }
    }

    public function testJournalDistrustMarkerRequiresExplicitResetAndRecoversThroughProtocol(): void
    {
        (new \ReflectionMethod($this->controller, 'quarantineJournal'))->invoke(
            $this->controller,
            'explicit_protocol_reset_test',
        );
        $marker = (new \ReflectionMethod(
            $this->controller,
            'journalDistrustMarkerFile',
        ))->invoke($this->controller);
        self::assertIsString($marker);
        self::assertFileExists($marker);
        self::assertFalse(
            (new \ReflectionProperty($this->controller, 'journalTrusted'))
                ->getValue($this->controller),
        );

        $withoutReset = $this->request(
            'admin',
            'repair',
            ['accept_storage_recovery' => true],
            'admin',
            $this->adminSecret,
        );
        self::assertFalse($withoutReset['ok']);
        self::assertStringContainsString(
            'accept_journal_reset=true',
            (string)$withoutReset['error']['message'],
        );
        self::assertFileExists($marker);

        $repaired = $this->request(
            'admin',
            'repair',
            [
                'accept_storage_recovery' => true,
                'accept_journal_reset' => true,
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($repaired['ok'], \json_encode($repaired));
        self::assertFileDoesNotExist($marker);
        self::assertTrue(
            (new \ReflectionProperty($this->controller, 'journalTrusted'))
                ->getValue($this->controller),
        );
    }

    public function testClockTrustRecoversAfterAStableMonotonicWindow(): void
    {
        $clockWallAnchor = new \ReflectionProperty($this->controller, 'clockWallAnchor');
        $clockWallAnchor->setValue($this->controller, \time() - 10);
        $observe = new \ReflectionMethod($this->controller, 'observeWallClock');

        self::assertFalse($observe->invoke($this->controller));
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayHasKey('clock_untrusted_since', $state['security']);
        $status = (new \ReflectionMethod($this->controller, 'status'))
            ->invoke($this->controller);
        self::assertFalse($status['clock']['trusted']);
        self::assertGreaterThan(0, $status['clock']['recovery_remaining_seconds']);
        self::assertTrue($status['clock']['state_persist_pending']);

        $stableSince = new \ReflectionProperty(
            $this->controller,
            'clockStableSinceMonotonic',
        );
        $stableSince->setValue(
            $this->controller,
            (\hrtime(true) / 1_000_000_000) - 31.0,
        );

        self::assertTrue($observe->invoke($this->controller));
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
        self::assertSame('RECOVERING', $state['health_state']);
        self::assertSame('CLOCK_STABLE', $state['recovery']['stage']);
    }

    public function testMaintenanceRecoversAndPersistsClockTrustWithoutControlTraffic(): void
    {
        $clockWallAnchor = new \ReflectionProperty($this->controller, 'clockWallAnchor');
        $clockWallAnchor->setValue($this->controller, \time() - 10);
        $observe = new \ReflectionMethod($this->controller, 'observeWallClock');
        self::assertFalse($observe->invoke($this->controller));

        (new \ReflectionProperty($this->controller, 'clockStableSinceMonotonic'))
            ->setValue(
                $this->controller,
                (\hrtime(true) / 1_000_000_000) - 31.0,
            );
        (new \ReflectionProperty($this->controller, 'lastHealthAt'))
            ->setValue($this->controller, PHP_FLOAT_MAX);
        (new \ReflectionProperty($this->controller, 'lastBackendProbeAt'))
            ->setValue($this->controller, PHP_FLOAT_MAX);

        (new \ReflectionMethod($this->controller, 'maintenance'))
            ->invoke($this->controller);

        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertArrayNotHasKey('clock_untrusted_since', $state['security']);
        self::assertNotSame('', $state['security']['clock_retrusted_at']);
        self::assertFalse(
            (new \ReflectionProperty($this->controller, 'clockStatePersistPending'))
                ->getValue($this->controller),
        );
        $persisted = \json_decode((string)\file_get_contents(
            $this->home . DIRECTORY_SEPARATOR . 'state'
                . DIRECTORY_SEPARATOR . 'gateway-state.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey(
            'clock_untrusted_since',
            $persisted['payload']['security'],
        );
        $status = (new \ReflectionMethod($this->controller, 'status'))
            ->invoke($this->controller);
        self::assertTrue($status['clock']['trusted']);
        self::assertSame(0, $status['clock']['recovery_remaining_seconds']);
    }

    public function testSecurityLedgerSurvivesDerivedStateCorruptionAndFailsClosedWhenCorrupt(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174010';
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['ledger.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);
        $stateDir = $this->home . DIRECTORY_SEPARATOR . 'state';
        $ledger = $stateDir . DIRECTORY_SEPARATOR . 'security-ledger.json';
        self::assertFileExists($ledger);
        self::assertSame(0600, \fileperms($ledger) & 0777);

        self::assertNotFalse(\file_put_contents(
            $stateDir . DIRECTORY_SEPARATOR . 'gateway-state.json',
            '{"corrupt":true}',
        ));
        $recovered = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/recovered.sock',
        );
        $stateProperty = new \ReflectionProperty($recovered, 'state');
        $recoveredState = $stateProperty->getValue($recovered);
        self::assertArrayHasKey($projectUuid, $recoveredState['enrollments']);
        self::assertTrue($recoveredState['security_ledger_valid']);
        self::assertTrue($recoveredState['isolation_mode']);

        $stateRecoveryRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/recovered-again.sock',
        );
        $stateAfterSecondRestart = (new \ReflectionProperty(
            $stateRecoveryRestart,
            'state',
        ))->getValue($stateRecoveryRestart);
        self::assertTrue($stateAfterSecondRestart['isolation_mode']);
        self::assertSame('STATE_REBUILD', $stateAfterSecondRestart['health_state']);
        self::assertArrayHasKey($projectUuid, $stateAfterSecondRestart['enrollments']);

        self::assertNotFalse(\file_put_contents($ledger, '{"tampered":true}'));
        $isolated = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/isolated.sock',
        );
        $isolatedState = (new \ReflectionProperty($isolated, 'state'))->getValue($isolated);
        self::assertFalse($isolatedState['security_ledger_valid']);
        self::assertSame([], $isolatedState['enrollments']);
        self::assertSame('SECURITY_LEDGER_UNTRUSTED', $isolatedState['health_state']);
        self::assertFileExists($ledger . '.untrusted');

        $secondRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/isolated-again.sock',
        );
        $secondState = (new \ReflectionProperty($secondRestart, 'state'))->getValue($secondRestart);
        self::assertFalse($secondState['security_ledger_valid']);
        self::assertSame([], $secondState['enrollments']);
    }

    public function testSecurityTombstoneCannotReviveLegacyOrPriorGenerationRoute(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174011';
        $domain = 'tombstone.example.test';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 12,
        ];
        $state['security']['tombstones']['project:' . $projectUuid] = [
            'kind' => 'project_revoke',
            'project_uuid' => $projectUuid,
            'generation' => 11,
        ];
        $stateProperty->setValue($this->controller, $state);
        $allowed = new \ReflectionMethod($this->controller, 'routeAllowedBySecurity');

        self::assertFalse($allowed->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'certificate' => $this->pendingCertificateEnvelope($domain),
        ]));
        self::assertFalse($allowed->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'enrollment_security_generation' => 10,
            'certificate' => $this->pendingCertificateEnvelope($domain),
        ]));
        self::assertTrue($allowed->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'enrollment_security_generation' => 12,
            'certificate' => $this->pendingCertificateEnvelope($domain),
        ]));
    }

    public function testDomainTransferFencesSourceAndPublishesExactlyOneDesiredOwner(): void
    {
        $from = '123e4567-e89b-42d3-a456-426614174040';
        $to = '123e4567-e89b-42d3-a456-426614174041';
        $domain = 'transfer.example.test';
        $sourceRouteId = $this->canonicalRouteId($from, $domain);
        $targetRouteId = $this->canonicalRouteId($to, $domain);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 40;
        $state['enrollments'][$from] = [
            'project_uuid' => $from,
            'security_generation' => 31,
            'allowed_domains' => [$domain],
        ];
        $state['enrollments'][$to] = [
            'project_uuid' => $to,
            'security_generation' => 32,
            'allowed_domains' => [$domain],
        ];
        $state['projects'][$from] = [
            'project_uuid' => $from,
            'route_ids' => [$sourceRouteId],
        ];
        $sourceCertificate = $this->activeCertificateEnvelope(
            $domain,
            \str_repeat('a', 64),
            [
                'valid' => true,
                'pending' => false,
                'generation' => 7,
                'snapshot_digest' => \str_repeat('a', 64),
            ],
        );
        $state['routes'][$sourceRouteId] = [
            'route_id' => $sourceRouteId,
            'project_uuid' => $from,
            'enrollment_security_generation' => 31,
            'domain_security_generation' => 0,
            'route_generation' => 7,
            'domain' => $domain,
            'status' => 'ACTIVE',
            'certificate' => $sourceCertificate,
        ];
        $this->installCertificateFloor(
            $state,
            $from,
            $domain,
            $sourceCertificate,
            7,
        );
        $state['acme_challenges']['source-challenge'] = [
            'project_uuid' => $from,
            'domain' => $domain,
        ];
        $stateProperty->setValue($this->controller, $state);

        $prepared = (new \ReflectionMethod($this->controller, 'prepareDomainTransfer'))
            ->invoke($this->controller, [
                'domain' => $domain,
                'from_project_uuid' => $from,
                'to_project_uuid' => $to,
                'confirm' => true,
            ]);
        $transferId = (string)$prepared['transfer_id'];
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $transferId);
        $state = $stateProperty->getValue($this->controller);
        $state['transfers'][$transferId]['status'] = 'STAGED';
        $state['transfers'][$transferId]['target_project'] = [
            'project_uuid' => $to,
            'project_root' => '/target',
            'generation' => 9,
            'digest' => \str_repeat('b', 64),
            'idempotency_key' => 'transfer-test',
        ];
        $state['transfers'][$transferId]['target_instance'] = [
            'instance_id' => 'target-instance',
            'generation' => 3,
        ];
        $stateProperty->setValue($this->controller, $state);
        $candidate = [
            'route_id' => $targetRouteId,
            'project_uuid' => $to,
            'enrollment_security_generation' => 32,
            'domain_security_generation' => 0,
            'route_generation' => 1,
            'domain' => $domain,
            'status' => 'ACTIVE',
            'certificate' => $this->activeCertificateEnvelope(
                $domain,
                \str_repeat('c', 64),
                [
                    'valid' => true,
                    'pending' => false,
                    'generation' => 1,
                    'snapshot_digest' => \str_repeat('c', 64),
                ],
            ),
        ];
        (new \ReflectionMethod($this->controller, 'applyCommittedDomainTransfer'))
            ->invoke(
                $this->controller,
                $transferId,
                $state['transfers'][$transferId],
                $candidate,
                41,
            );

        $committed = $stateProperty->getValue($this->controller);
        self::assertSame('REMOVED', $committed['routes'][$sourceRouteId]['status']);
        self::assertSame('ACTIVE', $committed['routes'][$targetRouteId]['status']);
        self::assertSame(41, $committed['routes'][$targetRouteId]['domain_security_generation']);
        self::assertSame(
            $to,
            $committed['security']['tombstones']['domain:' . $domain]['to_project_uuid'],
        );
        self::assertSame([], $committed['projects'][$from]['route_ids']);
        self::assertSame([$targetRouteId], $committed['projects'][$to]['route_ids']);
        self::assertArrayNotHasKey('source-challenge', $committed['acme_challenges']);
        self::assertSame('COMMITTED', $committed['transfers'][$transferId]['status']);

        $allowed = new \ReflectionMethod($this->controller, 'routeAllowedBySecurity');
        self::assertFalse($allowed->invoke($this->controller, $state['routes'][$sourceRouteId]));
        self::assertTrue($allowed->invoke($this->controller, $committed['routes'][$targetRouteId]));
        try {
            (new \ReflectionMethod($this->controller, 'assertNoDomainConflicts'))
                ->invoke($this->controller, $from, [[
                    'project_uuid' => $from,
                    'domain' => $domain,
                ]]);
            self::fail('Transferred domain was accepted for its previous owner.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'retained by project ' . $to,
                $exception->getMessage(),
            );
        }
    }

    public function testRenderedRoutePinsBackendCapabilityAndSanitizesProxyHeaders(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174012';
        $domain = 'secure.example.test';
        $aliasDomain = 'secure-alias.example.test';
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        $secret = \str_repeat('b', 64);
        $backendIdentity = $this->signedBackendIdentity(
            $projectUuid,
            'gateway-test',
            7,
            7,
            \str_repeat('d', 32),
            $secret,
        );
        $project = $this->createProject();
        $certificateSources = [
            $domain => $this->createCertificate($project, $domain, 'secure'),
            $aliasDomain => $this->createCertificate($project, $aliasDomain, 'secure-alias'),
        ];
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 5,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $snapshotMethod = new \ReflectionMethod(
            $this->controller,
            'snapshotCertificate',
        );
        $provenanceMethod = new \ReflectionMethod(
            $this->controller,
            'certificateProvenanceDigest',
        );
        $certificates = [];
        foreach ($certificateSources as $certificateDomain => $source) {
            $name = $certificateDomain === $domain ? 'secure' : 'secure-alias';
            $sourceDigest = \hash(
                'sha256',
                \hash_file('sha256', $source['cert'])
                    . ':' . \hash_file('sha256', $source['key']) . ':',
            );
            $certificates[$certificateDomain] = $snapshotMethod->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $certificateDomain,
                [
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => $name . '/fullchain.pem',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => $name . '/privkey.pem',
                    ],
                    'source_digest' => $sourceDigest,
                    'generation' => 3,
                    'state' => 'active',
                    'pending' => false,
                    'trust_profile' => 'test',
                    'provider' => 'self_signed',
                    'material_class' => 'self_signed',
                    'provenance_digest' => $provenanceMethod->invoke(
                        $this->controller,
                        $certificateDomain,
                        $sourceDigest,
                        'test',
                        'self_signed',
                        'self_signed',
                    ),
                ],
            );
            self::assertTrue($certificates[$certificateDomain]['valid']);
        }
        $state = $stateProperty->getValue($this->controller);
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'enrollment_security_generation' => 5,
            'domain_security_generation' => 0,
            'route_generation' => 1,
            'instance_id' => 'gateway-test',
            'preferred_instance_id' => 'gateway-test',
            'distribution_mode' => 'single',
            'domain' => $domain,
            'status' => 'ACTIVE',
            'backends' => [['host' => '127.0.0.1', 'port' => 29001, 'weight' => 1]],
            'backend_identity' => $backendIdentity,
            'certificate' => $certificates[$domain],
        ];
        $secondRouteId = $this->canonicalRouteId($projectUuid, $aliasDomain);
        $state['routes'][$secondRouteId] = $state['routes'][$routeId];
        $state['routes'][$secondRouteId]['route_id'] = $secondRouteId;
        $state['routes'][$secondRouteId]['domain'] = $aliasDomain;
        $state['routes'][$secondRouteId]['certificate'] = $certificates[$aliasDomain];
        $floorKeyMethod = new \ReflectionMethod(
            $this->controller,
            'certificateFloorKey',
        );
        foreach ($certificates as $certificateDomain => $certificate) {
            $floorKey = $floorKeyMethod->invoke(
                $this->controller,
                $projectUuid,
                $certificateDomain,
            );
            $state['certificate_floors'][$floorKey] = [
                'schema_version' => 2,
                'project_uuid' => $projectUuid,
                'domain' => $certificateDomain,
                'generation' => 3,
                'source_digest' => $certificate['source_digest'],
                'trust_profile' => $certificate['trust_profile'],
                'provider' => $certificate['provider'],
                'material_class' => $certificate['material_class'],
                'provenance_digest' => $certificate['provenance_digest'],
                'route_generation' => 1,
                'revocation_generation' => 0,
                'revocation_source_digest' => '',
                'revocation_trust_profile' => '',
                'revocation_provenance_digest' => '',
            ];
        }
        $stateProperty->setValue($this->controller, $state);
        $config = (new \ReflectionMethod($this->controller, 'renderNginxConfig'))
            ->invoke($this->controller, false);

        self::assertStringContainsString('ssl_session_cache off;', $config);
        self::assertStringContainsString('ssl_session_tickets off;', $config);
        self::assertStringNotContainsString('ssl_session_ticket_key ', $config);
        self::assertStringContainsString('ssl_early_data off;', $config);
        self::assertStringContainsString(
            'map $server_protocol $wls_requires_http_host { default 1; "HTTP/3.0" 0; }',
            $config,
        );
        self::assertStringContainsString(
            'map "$wls_requires_http_host:$http_host" $wls_missing_authority { default 0; "1:" 1; }',
            $config,
        );
        self::assertStringContainsString(
            'if ($wls_missing_authority) { return 421; }',
            $config,
        );
        self::assertStringNotContainsString(
            'if ($http_host = "") { return 421; }',
            $config,
        );
        self::assertStringContainsString(
            'map "$ssl_server_name|$http_host" $wls_raw_authority_mismatch {',
            $config,
        );
        self::assertStringContainsString(
            '~*^([^|]+)\|\1(?::[0-9]+)?$ 0;',
            $config,
        );
        self::assertStringContainsString(
            '~^[^|]+\|$ 0;',
            $config,
        );
        self::assertStringContainsString(
            'map "$ssl_server_name|$host" $wls_effective_authority_mismatch {',
            $config,
        );
        self::assertStringContainsString(
            'if ($wls_raw_authority_mismatch) { return 421; }',
            $config,
        );
        self::assertStringContainsString(
            'if ($wls_effective_authority_mismatch) { return 421; }',
            $config,
        );
        self::assertStringNotContainsString(
            'if ($ssl_server_name != $host) { return 421; }',
            $config,
        );
        self::assertStringContainsString('location = /_wls/health {', $config);
        self::assertStringContainsString('if ($args != "") { return 404; }', $config);
        self::assertSame(
            1,
            \preg_match(
                '/proxy_pass http:\\/\\/(wls_backend_[a-f0-9]{32})\\/_wls\\/health\\?;/',
                $config,
                $upstreamMatch,
            ),
        );
        self::assertSame(1, \substr_count($config, '  upstream wls_backend_'));
        self::assertSame(4, \substr_count(
            $config,
            'proxy_pass http://' . $upstreamMatch[1] . '/_wls/health?;',
        ));
        self::assertStringContainsString('    keepalive 32;', $config);
        self::assertStringContainsString(
            '    keepalive_timeout ' . GatewayPaths::UPSTREAM_KEEPALIVE_TIMEOUT_SEC . 's;',
            $config,
        );
        self::assertStringContainsString('    keepalive_requests 10000;', $config);
        self::assertStringContainsString('  keepalive_requests 100000;', $config);
        self::assertStringContainsString('worker_shutdown_timeout 300s;', $config);
        self::assertStringNotContainsString('location = /_wls/health { return 404; }', $config);
        self::assertStringContainsString('location = /__wls_gateway_sentinel', $config);
        self::assertStringContainsString('proxy_set_header X-WLS-Edge-Token "' . $secret . '";', $config);
        self::assertStringContainsString('proxy_set_header X-Forwarded-For $remote_addr;', $config);
        self::assertStringContainsString('proxy_set_header Forwarded "";', $config);
        self::assertStringContainsString(
            'map $http_upgrade $wls_business_upstream_upgrade { default ""; websocket websocket; }',
            $config,
        );
        self::assertStringContainsString(
            'map $http_upgrade $wls_business_upstream_connection { default ""; websocket upgrade; }',
            $config,
        );
        self::assertStringContainsString('access_log off;', $config);
        self::assertStringNotContainsString('/access.log', $config);
        self::assertStringNotContainsString(
            'map $http_upgrade $connection_upgrade { default upgrade;',
            $config,
        );
        self::assertStringNotContainsString('proxy_set_header Upgrade $http_upgrade;', $config);
        self::assertStringContainsString(
            'proxy_set_header Upgrade $wls_business_upstream_upgrade;',
            $config,
        );
        self::assertStringContainsString(
            'proxy_set_header Connection $wls_business_upstream_connection;',
            $config,
        );
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertStringNotContainsString('worker_rlimit_nofile', $config);
        } else {
            self::assertStringContainsString('worker_rlimit_nofile 65536;', $config);
        }
        self::assertStringNotContainsString('$proxy_add_x_forwarded_for', $config);
    }

    public function testBackendEndpointRequiresLaunchFenceAndEdgeCapability(): void
    {
        $project = (string)\realpath($this->createProject());
        $projectUuid = '123e4567-e89b-42d3-a456-426614174095';
        $launchId = \str_repeat('c', 32);
        $identity = $this->signedBackendIdentity(
            $projectUuid,
            'gateway-test',
            9,
            9,
            $launchId,
            \str_repeat('a', 64),
        );
        unset($identity['edge_capability_secret']);
        $validator = new \ReflectionMethod($this->controller, 'validateBackendEndpointIdentity');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('incomplete or internally inconsistent');
        $validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => 29001, 'weight' => 1]],
        );
    }

    public function testBackendEndpointRejectsForgedSharedSessionEvidence(): void
    {
        $project = (string)\realpath($this->createProject());
        $projectUuid = '123e4567-e89b-42d3-a456-426614174097';
        $launchId = \str_repeat('e', 32);
        $secret = \str_repeat('b', 64);
        $evidence = [
            'schema' => 'wls-session-capability/1',
            'storage' => 'wls',
            'runtime_source' => 'project_shared_state',
            'runtime_registered' => true,
            'runtime_shared_service' => true,
            'host' => '127.0.0.1',
            'port' => 20970,
            'token_scope_digest' => \hash('sha256', 'session_server.20970.token'),
            'probe' => 'healthy',
            'reason' => 'authenticated_session_runtime',
        ];
        $identity = $this->signedBackendIdentity(
            $projectUuid,
            'gateway-capability',
            8,
            14,
            $launchId,
            $secret,
            'shared_session',
            $evidence,
        );

        $validator = new \ReflectionMethod(
            $this->controller,
            'validateBackendEndpointIdentity',
        );
        self::assertNull($validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => 29012, 'weight' => 1]],
        ));

        $identity['session_capability_evidence']['runtime_source'] = 'forged_runtime';
        $identity = $this->sealBackendIdentity($identity);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('session capability evidence');
        $validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => 29012, 'weight' => 1]],
        );
    }

    public function testBackendEndpointAcceptsOnlyFencedSignedListenerLease(): void
    {
        $project = (string)\realpath($this->createProject());
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $launchId = \str_repeat('d', 32);
        $secret = \str_repeat('a', 64);
        $joinPort = 24579;
        $identity = $this->signedBackendIdentity(
            $projectUuid,
            'gateway-join',
            7,
            13,
            $launchId,
            $secret,
        );
        $validator = new \ReflectionMethod($this->controller, 'validateBackendEndpointIdentity');
        self::assertNull($validator->invoke(
            $this->controller,
            $identity,
            $project,
            [['host' => '127.0.0.1', 'port' => $joinPort, 'weight' => 1]],
        ));

        $identity['listener_lease_id'] = 'stale';
        $identity = $this->sealBackendIdentity($identity);
        try {
            $validator->invoke(
                $this->controller,
                $identity,
                $project,
                [['host' => '127.0.0.1', 'port' => $joinPort, 'weight' => 1]],
            );
            self::fail('An invalid listener lease fence was accepted.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'incomplete or internally inconsistent',
                $exception->getMessage(),
            );
        }
    }

    public function testAcmeHttp01UsesOnlyExactExpiringProjectLease(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174098';
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['acme.example.test'],
                'capabilities' => ['acme_http_01' => true],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok'], \json_encode($enrollment));
        $credential = $enrollment['payload']['credential'];
        $token = 'TOKEN_123-abc';
        $keyAuthorization = $token . '.' . \str_repeat('A', 43);
        $snapshot = (new \ReflectionMethod($this->controller, 'snapshotCertificate'))
            ->invoke(
                $this->controller,
                $projectUuid,
                $project,
                'acme.example.test',
                $this->pendingCertificateEnvelope('acme.example.test'),
            );
        self::assertFalse($snapshot['valid']);
        self::assertTrue($snapshot['pending']);
        self::assertSame(0, $snapshot['generation']);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $routeId = \str_repeat('a', 32);
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'acme.example.test',
            'enrollment_security_generation' => (int)(
                $enrollment['payload']['security_generation'] ?? 0
            ),
            'domain_security_generation' => (int)(
                $enrollment['payload']['security_generation'] ?? 0
            ),
            'status' => 'PENDING_CERTIFICATE',
            'backends' => [],
            'certificate' => $snapshot,
        ];
        $stateProperty->setValue($this->controller, $state);
        $desiredChallenges = [[
            'domain' => 'acme.example.test',
            'token' => $token,
            'key_authorization' => $keyAuthorization,
            'expires_at' => \time() + 300,
        ]];
        $desiredChallengeDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($desiredChallenges),
        );
        $firstSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'desired_digest' => $desiredChallengeDigest,
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertTrue($firstSync['ok'], \json_encode($firstSync));
        $signedState = $stateProperty->getValue($this->controller);
        self::assertCount(1, $signedState['acme_challenges'] ?? []);
        $signedLease = \array_values((array)$signedState['acme_challenges'])[0];
        $controllerBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        self::assertSame($controllerBootId, $signedLease['host_boot_id'] ?? '');
        self::assertIsFloat($signedLease['issued_monotonic'] ?? null);
        self::assertGreaterThan(0.0, $signedLease['issued_monotonic']);
        $signedDuration = (float)($signedLease['deadline_monotonic'] ?? 0.0)
            - (float)($signedLease['issued_monotonic'] ?? 0.0);
        self::assertGreaterThan(0.0, $signedDuration);
        self::assertLessThanOrEqual(
            300.0,
            $signedDuration,
            'The Controller must not extend the project wall expiry to 900 seconds.',
        );
        $firstFence = \array_intersect_key($signedLease, \array_flip([
            'host_boot_id',
            'issued_monotonic',
            'deadline_monotonic',
        ]));

        $staleSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 1,
                'challenges' => [],
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($staleSync['ok']);
        self::assertStringContainsString('generation is stale', $staleSync['error']['message']);

        $ambiguousSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'challenges' => [],
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($ambiguousSync['ok']);
        self::assertStringContainsString('generation is ambiguous', $ambiguousSync['error']['message']);

        $driftedState = $stateProperty->getValue($this->controller);
        $driftedState['acme_challenges'] = [];
        $stateProperty->setValue($this->controller, $driftedState);

        $idempotentSync = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'desired_digest' => $desiredChallengeDigest,
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertTrue($idempotentSync['ok'], \json_encode($idempotentSync));
        $reconciledState = $stateProperty->getValue($this->controller);
        self::assertCount(
            1,
            $reconciledState['acme_challenges'] ?? [],
            'An idempotent replay must restore a drifted serving lease.',
        );
        self::assertSame(
            $firstFence,
            \array_intersect_key(
                \array_values((array)$reconciledState['acme_challenges'])[0],
                $firstFence,
            ),
            'An idempotent replay must restore, not extend, the host lease fence.',
        );

        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        $config = $render->invoke($this->controller, false);
        self::assertStringContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $config,
        );
        self::assertStringContainsString('return 200 "' . $keyAuthorization . '";', $config);
        self::assertStringNotContainsString('location ^~ /.well-known/acme-challenge/', $config);
        self::assertStringNotContainsString('proxy_pass http://wls_backend_', $config);

        (new \ReflectionProperty($this->controller, 'rateWindows'))
            ->setValue($this->controller, []);
        $beforeGenerationOnlyRefresh = $stateProperty->getValue($this->controller);
        $generationOnlyRefresh = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 3,
                'desired_digest' => $desiredChallengeDigest,
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertTrue($generationOnlyRefresh['ok'], \json_encode($generationOnlyRefresh));
        $afterGenerationOnlyRefresh = $stateProperty->getValue($this->controller);
        self::assertSame(
            $beforeGenerationOnlyRefresh['generation'],
            $afterGenerationOnlyRefresh['generation'],
            'An identical challenge set must not reload Nginx.',
        );
        self::assertSame(
            $firstFence,
            \array_intersect_key(
                \array_values((array)$afterGenerationOnlyRefresh['acme_challenges'])[0],
                $firstFence,
            ),
            'A generation-only replay must not extend the host lease fence.',
        );
        $stateFile = (new \ReflectionMethod($this->controller, 'stateFile'))
            ->invoke($this->controller);
        $persistedEnvelope = \json_decode(
            (string)\file_get_contents($stateFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            3,
            $persistedEnvelope['payload']['acme_generations'][$projectUuid]['generation'] ?? 0,
            'Generation-only ACME fencing must survive Controller restart.',
        );

        $tamperedDigest = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 3,
                'desired_digest' => \str_repeat('f', 64),
                'challenges' => $desiredChallenges,
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($tamperedDigest['ok']);
        self::assertStringContainsString(
            'desired digest does not match',
            $tamperedDigest['error']['message'],
        );

        (new \ReflectionProperty($this->controller, 'rateWindows'))
            ->setValue($this->controller, []);

        $outsideCapability = $this->request(
            'project',
            'acme-challenge-sync',
            [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 3,
                'challenges' => [[
                    'domain' => 'other.example.test',
                    'token' => $token,
                    'key_authorization' => $keyAuthorization,
                    'expires_at' => \time() + 300,
                ]],
            ],
            (string)$credential['credential_id'],
            (string)$credential['secret'],
        );
        self::assertFalse($outsideCapability['ok']);
        self::assertStringContainsString(
            'outside the project enrollment capability',
            $outsideCapability['error']['message'],
        );

        $state = $stateProperty->getValue($this->controller);
        $leaseId = (string)\array_key_first((array)$state['acme_challenges']);
        $activeLease = $state['acme_challenges'][$leaseId];
        $intentDigest = new \ReflectionMethod(
            $this->controller,
            'desiredConfigIntentDigest',
        );
        $beforeWallJumpDigest = $intentDigest->invoke($this->controller);
        $state['acme_challenges'][$leaseId]['expires_at'] = \time() - 1;
        $stateProperty->setValue($this->controller, $state);
        self::assertSame(
            $beforeWallJumpDigest,
            $intentDigest->invoke($this->controller),
            'Wall-clock display expiry must not change the rendered intent.',
        );
        self::assertStringContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );

        $crossBootState = $state;
        $crossBootState['acme_challenges'][$leaseId] = $activeLease;
        $crossBootState['acme_challenges'][$leaseId]['host_boot_id'] = \str_repeat('f', 64);
        $stateProperty->setValue($this->controller, $crossBootState);
        self::assertStringNotContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );

        $legacyState = $state;
        $legacyState['acme_challenges'][$leaseId] = $activeLease;
        unset(
            $legacyState['acme_challenges'][$leaseId]['host_boot_id'],
            $legacyState['acme_challenges'][$leaseId]['issued_monotonic'],
            $legacyState['acme_challenges'][$leaseId]['deadline_monotonic'],
        );
        $stateProperty->setValue($this->controller, $legacyState);
        self::assertStringNotContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );

        $damagedState = $state;
        $damagedState['acme_challenges'][$leaseId] = $activeLease;
        $damagedState['acme_challenges'][$leaseId]['deadline_monotonic']
            = (float)$activeLease['issued_monotonic'] + 901.0;
        $stateProperty->setValue($this->controller, $damagedState);
        self::assertNotSame(
            $beforeWallJumpDigest,
            $intentDigest->invoke($this->controller),
            'A damaged monotonic fence must leave the active config intent.',
        );
        self::assertStringNotContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );
        (new \ReflectionMethod($this->controller, 'expireLeases'))
            ->invoke($this->controller);
        self::assertArrayNotHasKey(
            $leaseId,
            (array)($stateProperty->getValue($this->controller)['acme_challenges'] ?? []),
            'Maintenance must retire a damaged host lease fence.',
        );
    }

    public function testAcmeHostLeaseFenceIsMonotonicAndReplayStable(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174097';
        $domain = 'lease.example.test';
        $routeId = \str_repeat('e', 32);
        $token = 'TOKEN_host_lease';
        $authorization = $token . '.' . \str_repeat('H', 43);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 1,
            'allowed_domains' => [$domain],
            'capabilities' => ['acme_http_01' => true],
        ];
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'enrollment_security_generation' => 1,
            'domain' => $domain,
            'status' => 'PENDING_CERTIFICATE',
            'certificate' => $this->pendingCertificateEnvelope($domain),
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionProperty($this->controller, 'deferPublication'))
            ->setValue($this->controller, true);

        $sync = new \ReflectionMethod($this->controller, 'syncAcmeChallenges');
        foreach ([\time() - 1, \time() + 902] as $invalidExpiry) {
            $invalidChallenges = [[
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $authorization,
                'expires_at' => $invalidExpiry,
            ]];
            try {
                $sync->invoke($this->controller, [
                    'project_uuid' => $projectUuid,
                    'challenge_generation' => 1,
                    'desired_digest' => \hash(
                        'sha256',
                        GatewayClient::canonicalJson($invalidChallenges),
                    ),
                    'challenges' => $invalidChallenges,
                ]);
                self::fail('An expired or overlong ACME wall lease was accepted.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'expiry is invalid',
                    $exception->getMessage(),
                );
            }
            self::assertSame(
                [],
                (array)($stateProperty->getValue($this->controller)['acme_challenges'] ?? []),
            );
        }

        $wallExpiresAt = \time() + 60;
        $challenges = [[
            'domain' => $domain,
            'token' => $token,
            'key_authorization' => $authorization,
            'expires_at' => $wallExpiresAt,
        ]];
        $digest = \hash('sha256', GatewayClient::canonicalJson($challenges));
        $first = $sync->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'challenge_generation' => 1,
            'desired_digest' => $digest,
            'challenges' => $challenges,
        ]);
        self::assertSame(1, $first['count']);
        $firstState = $stateProperty->getValue($this->controller);
        $leaseId = (string)\array_key_first((array)$firstState['acme_challenges']);
        $firstLease = $firstState['acme_challenges'][$leaseId];
        $fence = \array_intersect_key($firstLease, \array_flip([
            'host_boot_id',
            'issued_monotonic',
            'deadline_monotonic',
        ]));
        self::assertSame(
            (new \ReflectionProperty($this->controller, 'hostBootId'))
                ->getValue($this->controller),
            $fence['host_boot_id'] ?? '',
        );
        $shortDuration = (float)($fence['deadline_monotonic'] ?? 0.0)
            - (float)($fence['issued_monotonic'] ?? 0.0);
        self::assertGreaterThan(0.0, $shortDuration);
        self::assertLessThanOrEqual(
            60.0,
            $shortDuration,
            'A short remaining wall lease must not be extended to 900 seconds.',
        );
        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        self::assertStringContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );

        (new \ReflectionMethod($this->controller, 'completePublication'))
            ->invoke($this->controller);
        (new \ReflectionProperty($this->controller, 'configDirty'))
            ->setValue($this->controller, false);
        $sync->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'challenge_generation' => 1,
            'desired_digest' => $digest,
            'challenges' => $challenges,
        ]);
        $replayedLease = $stateProperty->getValue(
            $this->controller,
        )['acme_challenges'][$leaseId];
        self::assertSame($fence, \array_intersect_key($replayedLease, $fence));

        $sync->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'challenge_generation' => 2,
            'desired_digest' => $digest,
            'challenges' => $challenges,
        ]);
        $generationReplayState = $stateProperty->getValue($this->controller);
        self::assertSame(
            $fence,
            \array_intersect_key(
                $generationReplayState['acme_challenges'][$leaseId],
                $fence,
            ),
        );

        $active = new \ReflectionMethod($this->controller, 'activeAcmeChallenges');
        $crossBootReplayState = $generationReplayState;
        $crossBootReplayState['acme_challenges'][$leaseId]['host_boot_id']
            = \str_repeat('f', 64);
        $crossBootReplayState['acme_generations'][$projectUuid]['lease_fences'][$leaseId][
            'host_boot_id'
        ] = \str_repeat('f', 64);
        $stateProperty->setValue($this->controller, $crossBootReplayState);
        self::assertSame([], $active->invoke(
            $this->controller,
            $crossBootReplayState['routes'][$routeId],
        ));
        self::assertStringNotContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );
        $sync->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'challenge_generation' => 2,
            'desired_digest' => $digest,
            'challenges' => $challenges,
        ]);
        $generationReplayState = $stateProperty->getValue($this->controller);
        self::assertSame(
            (new \ReflectionProperty($this->controller, 'hostBootId'))
                ->getValue($this->controller),
            $generationReplayState['acme_challenges'][$leaseId]['host_boot_id'] ?? '',
            'An authenticated replay may re-sign a cross-boot lease on this boot.',
        );
        self::assertCount(1, $active->invoke(
            $this->controller,
            $generationReplayState['routes'][$routeId],
        ));
        $intentDigest = new \ReflectionMethod(
            $this->controller,
            'desiredConfigIntentDigest',
        );
        $beforeWallJumpDigest = $intentDigest->invoke($this->controller);
        $generationReplayState['acme_challenges'][$leaseId]['expires_at'] = 2;
        $stateProperty->setValue($this->controller, $generationReplayState);
        self::assertCount(1, $active->invoke(
            $this->controller,
            $generationReplayState['routes'][$routeId],
        ));
        self::assertSame(
            $beforeWallJumpDigest,
            $intentDigest->invoke($this->controller),
        );
        self::assertStringContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );

        $expiredState = $generationReplayState;
        $monotonicNow = \hrtime(true) / 1_000_000_000;
        $expiredState['acme_challenges'][$leaseId]['issued_monotonic']
            = $monotonicNow - 901.0;
        $expiredState['acme_challenges'][$leaseId]['deadline_monotonic']
            = $monotonicNow - 1.0;
        $expiredState['acme_generations'][$projectUuid]['lease_fences'][$leaseId][
            'issued_monotonic'
        ] = $monotonicNow - 901.0;
        $expiredState['acme_generations'][$projectUuid]['lease_fences'][$leaseId][
            'deadline_monotonic'
        ] = $monotonicNow - 1.0;
        $stateProperty->setValue($this->controller, $expiredState);
        self::assertStringNotContainsString(
            'location = /.well-known/acme-challenge/' . $token,
            $render->invoke($this->controller, false),
        );
        try {
            $sync->invoke($this->controller, [
                'project_uuid' => $projectUuid,
                'challenge_generation' => 2,
                'desired_digest' => $digest,
                'challenges' => $challenges,
            ]);
            self::fail('An expired host lease was extended by an idempotent replay.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'requires a newer project generation',
                $exception->getMessage(),
            );
        }
        $beforeClearGeneration = (int)$expiredState['generation'];
        $sync->invoke($this->controller, [
            'project_uuid' => $projectUuid,
            'challenge_generation' => 3,
            'desired_digest' => \hash('sha256', GatewayClient::canonicalJson([])),
            'challenges' => [],
        ]);
        self::assertGreaterThan(
            $beforeClearGeneration,
            (int)$stateProperty->getValue($this->controller)['generation'],
            'Clearing an expired lease must still republish the static Nginx config.',
        );
        (new \ReflectionMethod($this->controller, 'completePublication'))
            ->invoke($this->controller);
        (new \ReflectionProperty($this->controller, 'configDirty'))
            ->setValue($this->controller, false);

        $generationReplayState['acme_challenges'][$leaseId]['host_boot_id']
            = \str_repeat('f', 64);
        $stateProperty->setValue($this->controller, $generationReplayState);
        self::assertSame([], $active->invoke(
            $this->controller,
            $generationReplayState['routes'][$routeId],
        ));
        (new \ReflectionMethod($this->controller, 'expireLeases'))
            ->invoke($this->controller);
        self::assertArrayNotHasKey(
            $leaseId,
            (array)($stateProperty->getValue($this->controller)['acme_challenges'] ?? []),
        );
    }

    public function testJournalHashChainRecoversTailAndQuarantinesMiddleTamper(): void
    {
        $journal = new \ReflectionMethod($this->controller, 'journal');
        $journal->invoke($this->controller, 'first', ['value' => 1]);
        $journal->invoke($this->controller, 'second', ['value' => 2]);
        $file = $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl';
        $lines = \file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
        $initialized = \json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $first = \json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        $second = \json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('gateway_initialized', $initialized['event']);
        self::assertSame(1, $initialized['sequence']);
        self::assertSame(\str_repeat('0', 64), $initialized['previous_sha256']);
        self::assertSame(2, $first['sequence']);
        self::assertSame($initialized['sha256'], $first['previous_sha256']);
        self::assertSame(3, $second['sequence']);
        self::assertSame($first['sha256'], $second['previous_sha256']);

        self::assertNotFalse(\file_put_contents($file, '{"truncated":', FILE_APPEND));
        $tailRecovered = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/tail.sock',
        );
        self::assertTrue(
            (new \ReflectionProperty($tailRecovered, 'journalTrusted'))->getValue($tailRecovered),
        );
        self::assertSame(
            3,
            (new \ReflectionProperty($tailRecovered, 'journalSequence'))->getValue($tailRecovered),
        );

        $lines = \file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $tampered = \json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $tampered['event'] = 'tampered';
        $lines[0] = \json_encode($tampered, JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents($file, \implode("\n", $lines) . "\n"));
        $isolated = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/tamper.sock',
        );
        $isolatedState = (new \ReflectionProperty($isolated, 'state'))->getValue($isolated);
        self::assertFalse(
            (new \ReflectionProperty($isolated, 'journalTrusted'))->getValue($isolated),
        );
        self::assertTrue($isolatedState['isolation_mode']);
        self::assertSame('JOURNAL_UNTRUSTED', $isolatedState['health_state']);
        self::assertNotEmpty(\glob($file . '.corrupt-*') ?: []);
    }

    public function testJournalAppendFailsFastWhenAnotherProcessOwnsTheFileLock(): void
    {
        $file = $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl';
        $process = \proc_open(
            [
                PHP_BINARY,
                '-r',
                '$stream=fopen($argv[1], "r+b");'
                    . 'if (!is_resource($stream) || !flock($stream, LOCK_EX)) { exit(2); }'
                    . 'fwrite(STDOUT, "locked\\n"); fflush(STDOUT);'
                    . 'usleep(1500000); flock($stream, LOCK_UN); fclose($stream);',
                $file,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertIsArray($pipes);
        \fclose($pipes[0]);
        self::assertSame("locked\n", \fgets($pipes[1]));

        try {
            $started = \hrtime(true);
            $written = (new \ReflectionMethod($this->controller, 'journal'))->invoke(
                $this->controller,
                'lock-contention',
                ['value' => 1],
            );
            $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

            self::assertFalse($written);
            self::assertLessThan(
                0.75,
                $elapsedSeconds,
                'Journal lock contention must not stall the single-threaded controller loop.',
            );
        } finally {
            @\proc_terminate($process);
            \stream_get_contents($pipes[1]);
            \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            @\proc_close($process);
        }
    }

    public function testLkgBundleProtectsCertificateClosureAndRollbackGeneration(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $unusedDigest = \str_repeat('b', 64);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174019';
        $domain = 'lkg.example.test';
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        $project = $this->createProject();
        $source = $this->createCertificate($project, $domain, 'lkg');
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $state['generation'] = 19;
        $state['active_config_generation'] = 19;
        $state['pending_lkg_generation'] = 19;
        $state['pending_lkg_since'] = \time() - 20;
        $state['pending_lkg_since_monotonic'] = \hrtime(true) / 1_000_000_000 - 20;
        $state['pending_lkg_boot_id'] = $this->hostBootId();
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 17,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $certificate = (new \ReflectionMethod($this->controller, 'snapshotCertificate'))
            ->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $this->activeCertificateEnvelope($domain, $sourceDigest, [
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'lkg/fullchain.pem',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'lkg/privkey.pem',
                    ],
                    'generation' => 3,
                ]),
            );
        self::assertTrue($certificate['valid']);
        $digest = (string)$certificate['snapshot_digest'];
        $routes = [
            $routeId => [
                'route_id' => $routeId,
                'project_uuid' => $projectUuid,
                'route_generation' => 1,
                'enrollment_security_generation' => 17,
                'domain_security_generation' => 17,
                'domain' => $domain,
                'status' => 'STALE',
                'backends' => [],
                'instances' => [],
                'certificate' => $certificate,
                'force_https' => true,
            ],
        ];
        $configDirectory = $this->home . DIRECTORY_SEPARATOR . 'runtime/conf';
        self::assertTrue(\is_dir($configDirectory) || \mkdir($configDirectory, 0700, true));
        $config = "events {}\nhttp {}\n";
        self::assertNotFalse(\file_put_contents(
            $configDirectory . DIRECTORY_SEPARATOR . 'nginx.conf',
            $config,
        ));
        $state = $stateProperty->getValue($this->controller);
        $state['routes'] = $routes;
        $state['active_routes'] = $routes;
        $this->installCertificateFloor(
            $state,
            $projectUuid,
            $domain,
            $certificate,
        );
        $state['active_config_digest'] = \hash('sha256', $config);
        $state['pending_lkg_config_digest'] = $state['active_config_digest'];
        $canonicalRoutes = (new \ReflectionMethod($this->controller, 'canonicalJson'))
            ->invoke($this->controller, $routes);
        $state['pending_lkg_routes_digest'] = \hash('sha256', $canonicalRoutes);
        $stateProperty->setValue($this->controller, $state);

        $unusedSnapshot = $this->home . DIRECTORY_SEPARATOR . 'snapshots/'
            . $unusedDigest;
        self::assertTrue(\mkdir($unusedSnapshot, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $unusedSnapshot . DIRECTORY_SEPARATOR . 'source-cert.pem',
            "snapshot\n",
        ));
        self::assertTrue(\touch($unusedSnapshot, \time() - 8 * 86400));

        (new \ReflectionMethod($this->controller, 'promoteLkg'))->invoke($this->controller);
        $promoted = $stateProperty->getValue($this->controller);
        self::assertCount(1, $promoted['lkg']);
        $entry = $promoted['lkg'][0];
        self::assertSame([$digest], $entry['certificate_digests']);
        self::assertFileExists($entry['manifest_file']);
        self::assertFileExists($entry['file']);
        self::assertFileExists($entry['route_file']);

        $promoted['routes'] = [];
        $promoted['generation'] = 25;
        $promoted['active_config_generation'] = 25;
        $promoted['snapshot_gc_candidates'][$unusedDigest] = [
            'unreferenced_at' => \time() - 8 * 86400,
            'unreferenced_monotonic' => 1.0,
            'boot_id' => \str_repeat('f', 64),
        ];
        $stateProperty->setValue($this->controller, $promoted);
        self::assertFalse(
            (new \ReflectionMethod($this->controller, 'certificateSnapshotReferenced'))
                ->invoke($this->controller, $unusedDigest),
            'The GC candidate must not be protected by desired, active, publication, or LKG state.',
        );
        $collectSnapshots = new \ReflectionMethod($this->controller, 'collectSnapshots');
        $collectSnapshots->invoke($this->controller, 100.0);
        self::assertDirectoryExists(
            $this->home . DIRECTORY_SEPARATOR . 'snapshots/' . $digest,
        );
        self::assertDirectoryExists(
            $this->home . DIRECTORY_SEPARATOR . 'snapshots/' . $unusedDigest,
            'A cross-boot wall timestamp must not authorize snapshot deletion.',
        );

        $rebuilt = $stateProperty->getValue($this->controller);
        self::assertSame(
            $this->hostBootId(),
            $rebuilt['snapshot_gc_candidates'][$unusedDigest]['boot_id'],
        );
        self::assertSame(
            100.0,
            $rebuilt['snapshot_gc_candidates'][$unusedDigest]['unreferenced_monotonic'],
        );
        $rebuilt['snapshot_gc_candidates'][$unusedDigest] = [
            'unreferenced_at' => \time() - 8 * 86400,
            'unreferenced_monotonic' => '1',
            'boot_id' => $this->hostBootId(),
        ];
        $stateProperty->setValue($this->controller, $rebuilt);
        $collectSnapshots->invoke($this->controller, 200.0);
        $rebuilt = $stateProperty->getValue($this->controller);
        self::assertDirectoryExists(
            $this->home . DIRECTORY_SEPARATOR . 'snapshots/' . $unusedDigest,
            'A coerced monotonic string must rebuild rather than authorize deletion.',
        );
        self::assertSame(
            200.0,
            $rebuilt['snapshot_gc_candidates'][$unusedDigest]['unreferenced_monotonic'],
        );
        $rebuilt['snapshot_gc_candidates'][$unusedDigest] = [
            // Even a future wall timestamp is diagnostic only; a complete
            // same-boot monotonic window is the sole deletion authority.
            'unreferenced_at' => \time() + 31536000,
            'unreferenced_monotonic' => 1.0,
            'boot_id' => $this->hostBootId(),
        ];
        $stateProperty->setValue($this->controller, $rebuilt);
        $collectSnapshots->invoke($this->controller, 604802.0);
        self::assertDirectoryDoesNotExist(
            $this->home . DIRECTORY_SEPARATOR . 'snapshots/' . $unusedDigest,
        );

        self::assertTrue(
            (new \ReflectionMethod($this->controller, 'rollbackToLkg'))
                ->invoke($this->controller),
        );
        $rolledBack = $stateProperty->getValue($this->controller);
        self::assertSame(19, $rolledBack['active_config_generation']);
        self::assertSame(19, $rolledBack['last_lkg_rollback_generation']);

        self::assertNotFalse(\file_put_contents($entry['route_file'], '{"tampered":true}'));
        self::assertNull(
            (new \ReflectionMethod($this->controller, 'loadLkgBundle'))
                ->invoke($this->controller, $entry),
        );
    }

    public function testDiskPressureRejectsMutationAndConfirmedRepairRecreatesReserve(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $freeBytes = \getenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $reserveFailure = \getenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE');
        $reserve = $this->home . DIRECTORY_SEPARATOR . 'state/recovery.reserve';
        $marker = $this->home . DIRECTORY_SEPARATOR . 'state/disk-pressure.marker';
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=1');
            self::assertFileExists($reserve);

            try {
                (new \ReflectionMethod(
                    $this->controller,
                    'assertPersistentMutationAllowed',
                ))->invoke($this->controller, 'test-mutation');
                self::fail('A durable mutation was accepted below the disk threshold.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'storage reserve is below',
                    $exception->getMessage(),
                );
            }

            $state = (new \ReflectionProperty($this->controller, 'state'))
                ->getValue($this->controller);
            self::assertSame('DISK_PRESSURE', $state['health_state']);
            self::assertSame('DISK_PRESSURE', $state['recovery']['stage']);
            self::assertFileExists($marker);
            self::assertFileDoesNotExist($reserve);

            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=33554432');
            (new \ReflectionMethod($this->controller, 'repair'))->invoke(
                $this->controller,
                ['accept_storage_recovery' => true],
            );

            self::assertFileDoesNotExist($marker);
            self::assertFileExists($reserve);
            self::assertSame(65536, (int)\filesize($reserve));
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'journalTrusted'))
                    ->getValue($this->controller),
            );
            (new \ReflectionMethod(
                $this->controller,
                'assertPersistentMutationAllowed',
            ))->invoke($this->controller, 'post-repair-mutation');

            $stateProperty = new \ReflectionProperty($this->controller, 'state');
            $durableState = $stateProperty->getValue($this->controller);
            $stateFile = (new \ReflectionMethod($this->controller, 'stateFile'))
                ->invoke($this->controller);
            $durableHash = \hash_file('sha256', $stateFile);
            $mutated = $durableState;
            $mutated['generation'] = (int)$mutated['generation'] + 1;
            $stateProperty->setValue($this->controller, $mutated);
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=temporary_write_or_fsync_failed');
            try {
                (new \ReflectionMethod($this->controller, 'persistState'))
                    ->invoke($this->controller);
                self::fail('Injected atomic persistence failure was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'temporary gateway file',
                    $exception->getMessage(),
                );
            } finally {
                \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
            }

            self::assertSame($durableHash, \hash_file('sha256', $stateFile));
            self::assertSame([], \glob($stateFile . '.tmp-*') ?: []);
            self::assertFileExists($marker);
            self::assertFileDoesNotExist($reserve);
            $storage = (new \ReflectionMethod($this->controller, 'storageStatus'))
                ->invoke($this->controller);
            self::assertTrue($storage['pressure_marker']);
            self::assertFalse($storage['mutation_ready']);

            $durableStateContents = \file_get_contents($stateFile);
            self::assertIsString($durableStateContents);
            $securityLedgerFile = (new \ReflectionMethod(
                $this->controller,
                'securityLedgerFile',
            ))->invoke($this->controller);
            $securityLedgerContents = \file_get_contents($securityLedgerFile);
            self::assertIsString($securityLedgerContents);
            self::assertNotFalse(\file_put_contents($stateFile, '{"corrupt"'));
            self::assertNotFalse(\file_put_contents($securityLedgerFile, '{"corrupt"'));
            $pressureStateHash = \hash_file('sha256', $stateFile);
            $pressureLedgerHash = \hash_file('sha256', $securityLedgerFile);
            $journalFile = (new \ReflectionMethod($this->controller, 'journalFile'))
                ->invoke($this->controller);
            self::assertNotFalse(\file_put_contents(
                $journalFile,
                '{"partial"',
                FILE_APPEND,
            ));
            $pressureJournalHash = \hash_file('sha256', $journalFile);
            $staleShadowFile = $this->home . DIRECTORY_SEPARATOR
                . 'runtime/shadow/' . \str_repeat('a', 32) . '-0/evidence';
            self::assertTrue(\mkdir(\dirname($staleShadowFile), 0700, true));
            self::assertNotFalse(\file_put_contents($staleShadowFile, 'preserve'));
            $restarted = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime'
                    . DIRECTORY_SEPARATOR . 'run' . DIRECTORY_SEPARATOR . 'controller.sock',
            );
            self::assertFileDoesNotExist(
                $reserve,
                'A disk-pressure restart must not reallocate the released reserve.',
            );
            $restartedState = (new \ReflectionProperty($restarted, 'state'))
                ->getValue($restarted);
            self::assertSame('DISK_PRESSURE', $restartedState['health_state']);
            self::assertTrue($restartedState['isolation_mode']);
            self::assertSame(
                $pressureStateHash,
                \hash_file('sha256', $stateFile),
                'A disk-pressure restart must not quarantine state on disk.',
            );
            self::assertSame(
                $pressureLedgerHash,
                \hash_file('sha256', $securityLedgerFile),
                'A disk-pressure restart must not quarantine the security ledger on disk.',
            );
            self::assertSame(
                $pressureJournalHash,
                \hash_file('sha256', $journalFile),
                'A disk-pressure restart must not repair the journal on disk.',
            );
            self::assertFalse(
                (new \ReflectionProperty($restarted, 'journalTrusted'))
                    ->getValue($restarted),
            );
            self::assertFileExists(
                $staleShadowFile,
                'A disk-pressure restart must not mutate stale runtime artifacts.',
            );
            self::assertNotFalse(\file_put_contents($stateFile, $durableStateContents));
            self::assertNotFalse(\file_put_contents(
                $securityLedgerFile,
                $securityLedgerContents,
            ));

            try {
                (new \ReflectionMethod(
                    $this->controller,
                    'assertPersistentMutationAllowed',
                ))->invoke($this->controller, 'latched-pressure-mutation');
                self::fail('A marker-latched disk-pressure mutation was accepted.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'storage reserve is below',
                    $exception->getMessage(),
                );
            }

            (new \ReflectionProperty($this->controller, 'lastHealthAt'))
                ->setValue($this->controller, 0.0);
            (new \ReflectionProperty($this->controller, 'lastBackendProbeAt'))
                ->setValue($this->controller, 0.0);
            (new \ReflectionMethod($this->controller, 'maintenance'))
                ->invoke($this->controller);
            self::assertSame(
                $durableHash,
                \hash_file('sha256', $stateFile),
                'Disk-pressure maintenance must not enter a persistent write loop.',
            );
            $pressureState = $stateProperty->getValue($this->controller);
            self::assertSame('DISK_PRESSURE', $pressureState['health_state']);
            self::assertNull(
                (new \ReflectionProperty($this->controller, 'publication'))
                    ->getValue($this->controller),
            );

            $stateProperty->setValue($this->controller, $durableState);
            (new \ReflectionMethod($this->controller, 'repair'))->invoke(
                $this->controller,
                ['accept_storage_recovery' => true],
            );
            self::assertFileDoesNotExist($marker);
            self::assertFileExists($reserve);

            self::assertTrue(\unlink($securityLedgerFile));
            self::assertTrue(\unlink($reserve));
            \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE=after_write');
            $reserveFailedRestart = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime'
                    . DIRECTORY_SEPARATOR . 'run' . DIRECTORY_SEPARATOR . 'controller.sock',
            );
            \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE');
            self::assertFileExists(
                $marker,
                'A startup reserve allocation failure must latch disk pressure.',
            );
            self::assertFileDoesNotExist(
                $reserve,
                'A partial or untrusted recovery reserve must be released.',
            );
            $reserveFailedState = (new \ReflectionProperty($reserveFailedRestart, 'state'))
                ->getValue($reserveFailedRestart);
            self::assertSame('DISK_PRESSURE', $reserveFailedState['health_state']);

            $repaired = (new \ReflectionMethod($reserveFailedRestart, 'repair'))->invoke(
                $reserveFailedRestart,
                ['accept_storage_recovery' => true],
            );
            self::assertFileDoesNotExist($marker);
            self::assertFileExists($reserve);
            self::assertFileDoesNotExist(
                $securityLedgerFile,
                'Storage recovery must not recreate a historical security ledger from state.json.',
            );
            self::assertSame('SECURITY_LEDGER_MISSING', $repaired['state']);
            self::assertTrue($repaired['isolation_mode']);
            self::assertStringContainsString(
                'signed recovery material',
                (string)$repaired['recovery']['required_action'],
            );
            $doctor = (new \ReflectionMethod($reserveFailedRestart, 'doctor'))
                ->invoke($reserveFailedRestart);
            self::assertSame('SECURITY_LEDGER_MISSING', $doctor['state']);
            self::assertStringContainsString(
                'rotate/re-enroll',
                (string)$doctor['recovery']['required_action'],
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $freeBytes === false
                ? \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES')
                : \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=' . $freeBytes);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $reserveFailure === false
                ? \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_RECOVERY_RESERVE_FAILURE=' . $reserveFailure);
        }
    }

    public function testSignedStorageRecoveryCanPersistItsNonceBeforeLeavingReadOnlyMode(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $freeBytes = \getenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES');
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=1');
            (new \ReflectionMethod($this->controller, 'markDiskPressure'))->invoke(
                $this->controller,
                'DISK_PRESSURE',
                'protocol_recovery_test',
            );
            (new \ReflectionProperty($this->controller, 'readOnlyRecoveryMode'))
                ->setValue($this->controller, true);
            self::assertFileExists(
                $this->home . DIRECTORY_SEPARATOR . 'state/disk-pressure.marker',
            );

            \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=33554432');
            $response = $this->request(
                'admin',
                'repair',
                ['accept_storage_recovery' => true],
                'admin',
                $this->adminSecret,
            );
            self::assertTrue($response['ok'], \json_encode($response));
            self::assertFileDoesNotExist(
                $this->home . DIRECTORY_SEPARATOR . 'state/disk-pressure.marker',
            );
            self::assertFalse(
                (new \ReflectionProperty($this->controller, 'readOnlyRecoveryMode'))
                    ->getValue($this->controller),
            );
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'nonceWalTrusted'))
                    ->getValue($this->controller),
            );
            self::assertFileExists(
                $this->home . DIRECTORY_SEPARATOR . 'state/nonce.wal',
            );
            $state = (new \ReflectionProperty($this->controller, 'state'))
                ->getValue($this->controller);
            self::assertTrue($state['nonce_wal_established']);
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $freeBytes === false
                ? \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES')
                : \putenv('WLS_GATEWAY_TEST_DISK_FREE_BYTES=' . $freeBytes);
        }
    }

    public function testAtomicWriteReconcilesOnlyExactCommittedAfterImageAfterDirectorySyncFailure(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $failureTarget = \getenv(
            'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256'
        );
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $target = $configDir . DIRECTORY_SEPARATOR . 'atomic-after-image-test.conf';
        $other = $configDir . DIRECTORY_SEPARATOR . 'atomic-after-image-other.conf';
        $contents = "events {}\nhttp {}\n";
        $atomicWrite = new \ReflectionMethod($this->controller, 'atomicWrite');
        $reconcile = new \ReflectionMethod(
            $this->controller,
            'reconcileCommittedAtomicWrite',
        );
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE='
                    . 'directory_fsync_after_rename_failed'
            );
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                    . \hash('sha256', $target)
            );

            // The path fence prevents a publication-specific fault from
            // perturbing candidate, rollback, journal, or state writes.
            $atomicWrite->invoke($this->controller, $other, "events {}\n", 0600);
            self::assertSame("events {}\n", \file_get_contents($other));

            try {
                $atomicWrite->invoke($this->controller, $target, $contents, 0600);
                self::fail('The injected post-rename directory sync failure was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'after replacement',
                    $exception->getMessage(),
                );
            }

            self::assertSame(
                $contents,
                \file_get_contents($target),
                'The exception must model a rename that already committed.',
            );
            self::assertSame(
                'COMMITTED',
                $reconcile->invoke($this->controller, $target, $contents, 0600),
            );
            self::assertNotFalse(\file_put_contents($target, "events {}\n# tampered\n"));
            self::assertSame(
                'NO_MATCH',
                $reconcile->invoke($this->controller, $target, $contents, 0600),
                'A changed after-image must never be reconciled as the candidate commit.',
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $failureTarget === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256=' . $failureTarget
                );
        }
    }

    public function testPersistenceFailureReturnsSignedRejectionAndKeepsStatusReadable(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=temporary_write_or_fsync_failed');

            $mutation = $this->request(
                'admin',
                'upgrade',
                [],
                'admin',
                $this->adminSecret,
            );
            self::assertFalse($mutation['ok']);
            self::assertSame('storage_unavailable', $mutation['error']['code']);
            self::assertStringContainsString(
                'persistent operation rejected',
                $mutation['error']['message'],
            );
            $this->assertResponseSignature($mutation, $this->adminSecret);

            $status = $this->request(
                'admin',
                'status',
                [],
                'admin',
                $this->adminSecret,
            );
            self::assertTrue($status['ok']);
            self::assertSame('DISK_PRESSURE', $status['payload']['state']);
            self::assertFalse($status['payload']['storage']['mutation_ready']);
            self::assertFalse($status['payload']['storage']['recovery_reserve_ready']);
            $this->assertResponseSignature($status, $this->adminSecret);
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
        }
    }

    public function testRecoveryCircuitUsesMonotonicWindowAndReleasesOneRetry(): void
    {
        $this->assignAvailablePublicPortsForRecovery();
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $recordFailure = new \ReflectionMethod($this->controller, 'recordFailure');
        $circuitAction = new \ReflectionMethod(
            $this->controller,
            'recoveryCircuitAction',
        );

        for ($failure = 1; $failure <= 10; ++$failure) {
            $recordFailure->invoke($this->controller, 'failure-' . $failure);
        }
        $state = $stateProperty->getValue($this->controller);
        self::assertCount(10, $state['failure_events']);
        self::assertSame($hostBootId, $state['recovery']['circuit_boot_id']);
        self::assertGreaterThan(
            \hrtime(true) / 1_000_000_000,
            $state['recovery']['circuit_open_until_monotonic'],
        );
        foreach ($state['failure_events'] as $event) {
            self::assertSame($hostBootId, $event['boot_id']);
            self::assertGreaterThan(0.0, $event['at_monotonic']);
        }

        $until = (float)$state['recovery']['circuit_open_until_monotonic'];
        self::assertSame(
            'OPEN',
            $circuitAction->invoke($this->controller, $until - 0.01),
        );
        self::assertSame(
            $until,
            $stateProperty->getValue(
                $this->controller,
            )['recovery']['circuit_open_until_monotonic'],
        );
        (new \ReflectionMethod($this->controller, 'recoverDataPlane'))
            ->invoke($this->controller);
        $whileOpen = $stateProperty->getValue($this->controller);
        self::assertCount(10, $whileOpen['failure_events']);
        self::assertSame('CIRCUIT_OPEN', $whileOpen['recovery']['stage']);
        self::assertSame(
            $until,
            $whileOpen['recovery']['circuit_open_until_monotonic'],
        );
        self::assertSame(
            'RETRY',
            $circuitAction->invoke($this->controller, $until + 0.01),
        );
        $released = $stateProperty->getValue($this->controller);
        self::assertSame(0.0, $released['recovery']['circuit_open_until_monotonic']);
        self::assertSame(0, $released['recovery']['next_retry_at']);
        self::assertSame('CLOSED', $circuitAction->invoke($this->controller, $until + 0.02));

        $released['failure_events'] = [[
            'at' => \time(),
            'reason' => 'legacy-wall-clock-only',
        ]];
        $released['recovery']['consecutive_failures'] = 0;
        $released['recovery']['backoff_attempt'] = 0;
        $stateProperty->setValue($this->controller, $released);
        $recordFailure->invoke($this->controller, 'new-monotonic-failure');
        $filtered = $stateProperty->getValue($this->controller);
        self::assertCount(1, $filtered['failure_events']);
        self::assertSame(
            'new-monotonic-failure',
            $filtered['failure_events'][0]['reason'],
        );
    }

    public function testBinaryObservationFailurePreservesOriginalObservationStart(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $monotonicNow = \hrtime(true) / 1_000_000_000;
        $originalStart = $monotonicNow - 120.0;
        $state = $stateProperty->getValue($this->controller);
        $state['binary_transaction'] = [
            'phase' => 'OBSERVING',
            'from_slot' => 'B',
            'to_slot' => 'A',
            'started_at' => \time() - 120,
            'started_at_monotonic' => $originalStart,
            'healthy_since' => \time() - 60,
            'healthy_since_monotonic' => $monotonicNow - 60.0,
            'observation_boot_id' => $hostBootId,
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'resetBinaryObservationHealthy'))
            ->invoke($this->controller);

        $reset = $stateProperty->getValue($this->controller)['binary_transaction'];
        self::assertEqualsWithDelta(
            $originalStart,
            (float)$reset['started_at_monotonic'],
            0.000001,
        );
        self::assertSame(0, $reset['healthy_since']);
        self::assertSame(0.0, $reset['healthy_since_monotonic']);
    }

    public function testMissingBinaryRollbackAuthorityContinuesNormalRecovery(): void
    {
        $rollbackSlot = $this->createVerifiedRollbackSlotFixture('B');
        self::assertTrue(\unlink($rollbackSlot['binary']));

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $monotonicNow = \hrtime(true) / 1_000_000_000;
        $state = $stateProperty->getValue($this->controller);
        $state['binary_transaction'] = [
            'phase' => 'OBSERVING',
            'from_slot' => 'B',
            'to_slot' => 'A',
            'started_at' => \time() - 10,
            'started_at_monotonic' => $monotonicNow - 10.0,
            'healthy_since' => 0,
            'healthy_since_monotonic' => 0.0,
            'observation_boot_id' => $hostBootId,
        ];
        $state['failure_events'] = [];
        for ($failure = 1; $failure <= 9; ++$failure) {
            $state['failure_events'][] = [
                'at' => \time() - (10 - $failure),
                'at_monotonic' => $monotonicNow - (10.0 - $failure),
                'boot_id' => $hostBootId,
                'reason' => 'prior-recovery-failure-' . $failure,
            ];
        }
        $state['recovery']['stage'] = 'BINARY_ROLLBACK_REQUEST_REJECTED';
        $state['recovery']['last_failure'] = 'previous rollback request was rejected';
        $state['recovery']['consecutive_failures'] = 9;
        $stateProperty->setValue($this->controller, $state);
        $this->assignAvailablePublicPortsForRecovery();

        (new \ReflectionMethod($this->controller, 'recoverDataPlane'))
            ->invoke($this->controller);

        $recovered = $stateProperty->getValue($this->controller);
        self::assertSame(
            'ROLLBACK_UNAVAILABLE',
            $recovered['binary_transaction']['phase'],
        );
        self::assertStringContainsString(
            'previous-slot executable is missing or unsafe',
            (string)$recovered['binary_transaction']['rollback_unavailable_reason'],
        );
        self::assertSame('CIRCUIT_OPEN', $recovered['recovery']['stage']);
        self::assertCount(10, $recovered['failure_events']);
        self::assertSame(10, $recovered['recovery']['consecutive_failures']);
    }

    public function testExpiredBinaryRollbackAuthorityContinuesNormalRecovery(): void
    {
        $runtimeGeneration = \str_repeat('a', 64);
        $manifestFile = $this->home . DIRECTORY_SEPARATOR . 'slots/A/manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($manifestFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifest['runtime_generation'] = $runtimeGeneration;
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        ));
        $this->createVerifiedRollbackSlotFixture('B');

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $preparedAt = \time() - 1200;
        $monotonicMilliseconds = \intdiv(\hrtime(true), 1_000_000);
        if ($monotonicMilliseconds <= 900_001) {
            self::markTestSkipped(
                'Host uptime is too short to construct an expired monotonic rollback intent.',
            );
        }
        $preparedMonotonic = $monotonicMilliseconds - 900_001;
        $nonce = \str_repeat('b', 32);
        $intentPayload = "WLS-UPGRADE/2\n"
            . 'host_id=' . $this->hostId . "\n"
            . "from=B\nto=A\n"
            . 'prepared_at=' . $preparedAt . "\n"
            . 'deadline=' . ($preparedAt + 300) . "\n"
            . 'runtime_generation=' . $runtimeGeneration . "\n"
            . 'host_boot_id=' . $hostBootId . "\n"
            . 'prepared_monotonic_ms=' . $preparedMonotonic . "\n"
            . 'activation_deadline_monotonic_ms='
                . ($preparedMonotonic + 300_000) . "\n"
            . 'rollback_deadline_monotonic_ms='
                . ($preparedMonotonic + 900_000) . "\n"
            . 'nonce=' . $nonce . "\n";
        $key = \hex2bin($this->adminSecret);
        self::assertIsString($key);
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust/upgrade.intent',
            $intentPayload . 'signature=' . \hash_hmac('sha256', $intentPayload, $key) . "\n",
        ));

        $monotonicNow = \hrtime(true) / 1_000_000_000;
        $state = $stateProperty->getValue($this->controller);
        $state['active_slot'] = 'A';
        $state['previous_slot'] = 'B';
        $state['binary_transaction'] = [
            'phase' => 'OBSERVING',
            'from_slot' => 'B',
            'to_slot' => 'A',
            'started_at' => \time() - 10,
            'started_at_monotonic' => $monotonicNow - 10.0,
            'healthy_since' => 0,
            'healthy_since_monotonic' => 0.0,
            'observation_boot_id' => $hostBootId,
        ];
        $state['failure_events'] = [];
        $state['recovery']['stage'] = 'BINARY_ROLLBACK_REQUEST_REJECTED';
        $state['recovery']['last_failure'] = 'previous rollback request was rejected';
        $state['recovery']['consecutive_failures'] = 0;
        $stateProperty->setValue($this->controller, $state);
        $this->assignAvailablePublicPortsForRecovery();

        (new \ReflectionMethod($this->controller, 'recoverDataPlane'))
            ->invoke($this->controller);

        $recovered = $stateProperty->getValue($this->controller);
        self::assertSame(
            'ROLLBACK_UNAVAILABLE',
            $recovered['binary_transaction']['phase'],
        );
        self::assertStringContainsString(
            'outside the signed transaction',
            (string)$recovered['binary_transaction']['rollback_unavailable_reason'],
        );
        self::assertSame(1, $recovered['recovery']['consecutive_failures']);
    }

    public function testMultipleInstancesRequireCapabilityBeforeDistributionAndFailOver(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174020';
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'capabilities' => ['stateless' => false, 'shared_session' => false],
        ];
        $route = [
            'project_uuid' => $projectUuid,
            'domain' => 'multi.example.test',
            'certificate' => [
                'valid' => true,
                'snapshot_digest' => \str_repeat('a', 64),
                'generation' => 1,
            ],
            'instances' => [
                'zeta' => $this->instanceLease('zeta', 29002, 'shared_session'),
                'alpha' => $this->instanceLease('alpha', 29001, 'shared_session'),
            ],
            'backends' => [],
            'status' => 'PENDING_BACKEND',
            'last_heartbeat' => \time(),
            'stale_since' => null,
        ];
        $stateProperty->setValue($this->controller, $state);
        $selector = new \ReflectionMethod($this->controller, 'selectRouteBackends');
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('alpha', $route['preferred_instance_id']);
        self::assertSame('alpha', $route['instance_id']);
        self::assertSame([29001], \array_column($route['backends'], 'port'));
        self::assertSame('ACTIVE', $route['status']);

        $route['instances']['alpha']['last_heartbeat'] = 1;
        $route['instances']['zeta']['last_heartbeat'] = \PHP_INT_MAX;
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('alpha', $route['instance_id']);
        self::assertSame([29001], \array_column($route['backends'], 'port'));

        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid]['capabilities']['shared_session'] = true;
        $stateProperty->setValue($this->controller, $state);
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame([29001, 29002], \array_column($route['backends'], 'port'));
        self::assertSame('shared_session', $route['distribution_mode']);
        self::assertSame(
            ['alpha', 'zeta'],
            \array_keys($route['backend_instances']),
        );
        self::assertSame(
            $route['instances']['alpha']['backend_identity'],
            $route['backend_identity'],
        );

        $mismatchedEvidence = $route['instances']['zeta']['backend_identity'][
            'session_capability_evidence'
        ];
        $mismatchedEvidence['port'] = 31999;
        $route['instances']['zeta']['backend_identity'][
            'session_capability_evidence'
        ] = $mismatchedEvidence;
        $route['instances']['zeta']['backend_identity'][
            'session_capability_evidence_digest'
        ] = \hash('sha256', GatewayClient::canonicalJson($mismatchedEvidence));
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame(
            'single',
            $route['distribution_mode'],
            'Instances using different shared Session runtimes must never share traffic.',
        );
        self::assertSame([29001], \array_column($route['backends'], 'port'));

        $route['instances']['alpha']['backend_healthy'] = false;
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('zeta', $route['instance_id']);
        self::assertSame([29002], \array_column($route['backends'], 'port'));
        self::assertSame('single', $route['distribution_mode']);

        $route['instances']['zeta']['backend_healthy'] = false;
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('', $route['instance_id']);
        self::assertSame([], $route['backends']);
        self::assertSame([], $route['backend_instances']);
        self::assertSame('PENDING_BACKEND', $route['status']);
        self::assertNull($route['stale_since']);
    }

    public function testActiveBackendProbeKeepsAuthenticatedTransportAndFailsClosedOnIdentityMismatch(): void
    {
        $applyResult = new \ReflectionMethod(
            $this->controller,
            'applyBackendProbeResult',
        );
        $instance = ['backend_healthy' => true];

        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(1, $instance['backend_probe_failures']);

        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(2, $instance['backend_probe_failures']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertFalse($instance['backend_healthy']);
        self::assertSame(3, $instance['backend_probe_failures']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'transport'],
        ));
        self::assertFalse($instance['backend_healthy']);
        self::assertSame(4, $instance['backend_probe_failures']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, true, ''],
        ));
        self::assertTrue($instance['backend_healthy']);
        self::assertSame(0, $instance['backend_probe_failures']);
        self::assertSame('', $instance['last_backend_probe_failure_kind']);

        self::assertFalse($applyResult->invokeArgs(
            $this->controller,
            [&$instance, false, 'identity'],
        ));
        self::assertFalse($instance['backend_healthy']);
        self::assertSame('identity', $instance['last_backend_probe_failure_kind']);

        $neverAuthenticated = ['backend_healthy' => false];
        self::assertTrue($applyResult->invokeArgs(
            $this->controller,
            [&$neverAuthenticated, false, 'transport'],
        ));
        self::assertFalse($neverAuthenticated['backend_healthy']);
    }

    public function testCertificateReferencesRequireEnrolledAliasAndRejectTraversal(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174021';
        $certificateRoot = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl';
        $certificate = $certificateRoot . DIRECTORY_SEPARATOR . 'route.pem';
        self::assertNotFalse(\file_put_contents($certificate, "certificate\n"));
        self::assertTrue(\chmod($certificate, 0600));

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'certificate_roots' => ['project_ssl' => $certificateRoot],
        ];
        $stateProperty->setValue($this->controller, $state);
        $resolver = new \ReflectionMethod($this->controller, 'resolveCertificateReference');

        self::assertSame(\realpath($certificate), $resolver->invoke($this->controller, $projectUuid, [
            'root_alias' => 'project_ssl',
            'relative_path' => 'route.pem',
        ]));

        foreach ([
            ['root_alias' => 'unknown', 'relative_path' => 'route.pem'],
            ['root_alias' => 'project_ssl', 'relative_path' => '../route.pem'],
            ['root_alias' => 'project_ssl', 'relative_path' => '/etc/passwd'],
        ] as $invalid) {
            try {
                $resolver->invoke($this->controller, $projectUuid, $invalid);
                self::fail('Invalid certificate reference was accepted.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCertificateWildcardMatchesExactlyOneLabelAndNeverTheApex(): void
    {
        $covers = new \ReflectionMethod($this->controller, 'certificateCoversDomain');
        $wildcard = [
            'extensions' => ['subjectAltName' => 'DNS:*.example.test'],
            'subject' => ['CN' => '*.example.test'],
        ];
        self::assertTrue($covers->invoke($this->controller, $wildcard, 'shop.example.test'));
        self::assertFalse($covers->invoke($this->controller, $wildcard, 'example.test'));
        self::assertFalse($covers->invoke($this->controller, $wildcard, 'a.shop.example.test'));

        $apex = [
            'extensions' => ['subjectAltName' => 'DNS:example.test'],
            'subject' => ['CN' => 'example.test'],
        ];
        self::assertFalse($covers->invoke($this->controller, $apex, '*.example.test'));
        self::assertFalse($covers->invoke($this->controller, [
            'extensions' => [],
            'subject' => ['CN' => 'legacy.example.test'],
        ], 'legacy.example.test'));
    }

    public function testCertificateSnapshotIsContentAddressedAndDamagedBundleIsNeverOverwritten(): void
    {
        $project = $this->createProject();
        $domain = 'snapshot.example.test';
        $projectUuid = '123e4567-e89b-42d3-a456-426614174022';
        $source = $this->createCertificate($project, $domain, 'snapshot');
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 1,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $certificate = $this->activeCertificateEnvelope($domain, $sourceDigest, [
            'cert' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'snapshot/fullchain.pem',
            ],
            'key' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'snapshot/privkey.pem',
            ],
            'generation' => 1,
        ]);
        $snapshotMethod = new \ReflectionMethod(
            $this->controller,
            'snapshotCertificate',
        );
        $mismatch = $this->createCertificate($project, $domain, 'mismatch');
        $invalid = $certificate;
        $invalid['key']['relative_path'] = 'mismatch/privkey.pem';
        $invalid['source_digest'] = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $mismatch['key']) . ':',
        );
        $invalid['provenance_digest'] = ProjectCertificateGenerationStore::provenanceDigest(
            $domain,
            $invalid['source_digest'],
            'test',
            'self_signed',
            'self_signed',
        );
        try {
            $snapshotMethod->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $invalid,
            );
            self::fail('A mismatched private key was accepted.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'private key does not match',
                $exception->getMessage(),
            );
        }
        self::assertCount(
            0,
            \glob($this->home . DIRECTORY_SEPARATOR . 'snapshots/*') ?: [],
        );

        $first = $snapshotMethod->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $domain,
            $certificate,
        );
        self::assertTrue($first['valid']);
        self::assertSame($sourceDigest, $first['snapshot_digest']);
        self::assertFileExists($first['cert_path']);
        self::assertFileExists($first['key_path']);
        self::assertFileExists(
            \dirname((string)$first['key_path']) . DIRECTORY_SEPARATOR . 'manifest.json',
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($first['key_path']) & 0777);
        }

        $idempotent = $snapshotMethod->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $domain,
            $certificate,
        );
        self::assertSame($first['cert_path'], $idempotent['cert_path']);
        self::assertSame($first['key_path'], $idempotent['key_path']);

        self::assertNotFalse(\file_put_contents(
            $first['key_path'],
            "\ntampered\n",
            FILE_APPEND,
        ));
        try {
            $snapshotMethod->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $certificate,
            );
            self::fail('A damaged immutable snapshot was silently overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'damaged; refusing to overwrite',
                $exception->getMessage(),
            );
        }
        self::assertStringEndsWith(
            "tampered\n",
            (string)\file_get_contents($first['key_path']),
        );
        self::assertCount(
            1,
            \glob($this->home . DIRECTORY_SEPARATOR . 'snapshots/*') ?: [],
        );
    }

    public function testReusableSnapshotRetainsEachRouteProvenanceEnvelope(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174122';
        $wildcard = '*.reuse-provenance.example.test';
        $source = $this->createCertificate(
            $project,
            $wildcard,
            'reuse-provenance',
        );
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 1,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $snapshot = new \ReflectionMethod($this->controller, 'snapshotCertificate');
        $references = [
            'cert' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'reuse-provenance/fullchain.pem',
            ],
            'key' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'reuse-provenance/privkey.pem',
            ],
            'generation' => 1,
        ];
        $firstDomain = 'one.reuse-provenance.example.test';
        $secondDomain = 'two.reuse-provenance.example.test';
        $first = $snapshot->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $firstDomain,
            $this->activeCertificateEnvelope($firstDomain, $sourceDigest, $references),
        );
        $copyDirectory = $project . DIRECTORY_SEPARATOR
            . 'app/etc/ssl/reuse-provenance-copy';
        self::assertTrue(\mkdir($copyDirectory, 0700, true));
        self::assertTrue(\copy(
            $source['cert'],
            $copyDirectory . DIRECTORY_SEPARATOR . 'fullchain.pem',
        ));
        self::assertTrue(\copy(
            $source['key'],
            $copyDirectory . DIRECTORY_SEPARATOR . 'privkey.pem',
        ));
        self::assertTrue(\chmod(
            $copyDirectory . DIRECTORY_SEPARATOR . 'privkey.pem',
            0600,
        ));
        $secondReferences = [
            'cert' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'reuse-provenance-copy/fullchain.pem',
            ],
            'key' => [
                'root_alias' => 'project_ssl',
                'relative_path' => 'reuse-provenance-copy/privkey.pem',
            ],
            'generation' => 2,
        ];
        $second = $snapshot->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $secondDomain,
            $this->activeCertificateEnvelope(
                $secondDomain,
                $sourceDigest,
                $secondReferences,
                'external',
            ),
        );

        self::assertSame($first['snapshot_digest'], $second['snapshot_digest']);
        self::assertNotSame($first['provenance_digest'], $second['provenance_digest']);
        self::assertSame(
            ProjectCertificateGenerationStore::provenanceDigest(
                $secondDomain,
                $sourceDigest,
                'test',
                'external',
                'self_signed',
            ),
            $second['provenance_digest'],
        );
        self::assertSame('test', $second['trust_profile']);
        self::assertSame('external', $second['provider']);
        self::assertSame('self_signed', $second['material_class']);
        self::assertSame(2, $second['generation']);
        self::assertSame(
            $secondReferences['cert'],
            $second['source_refs']['cert'],
        );
        self::assertSame(
            $secondReferences['key'],
            $second['source_refs']['key'],
        );
    }

    public function testAbortedRoutingMutationRestoresDesiredStateAndClearsJournal(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 41;
        $state['active_config_generation'] = 39;
        $state['pending_lkg_generation'] = 38;
        $state['pending_lkg_since'] = 1234;
        $state['isolation_mode'] = false;
        $state['projects'] = ['project-before' => ['generation' => 3]];
        $state['instances'] = ['project-before' => ['instance-before' => ['generation' => 7]]];
        $state['routes'] = ['route-before' => ['status' => 'ACTIVE']];
        $state['acme_challenges'] = ['challenge-before' => ['expires_at' => 12345]];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, false);

        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'test-abort');
        $mutated = $stateProperty->getValue($this->controller);
        $mutated['generation'] = 42;
        $mutated['active_config_generation'] = 42;
        $mutated['pending_lkg_generation'] = 42;
        $mutated['pending_lkg_since'] = 5678;
        $mutated['isolation_mode'] = true;
        $mutated['projects'] = ['project-after' => ['generation' => 4]];
        $mutated['instances'] = [];
        $mutated['routes'] = ['route-after' => ['status' => 'PENDING_BACKEND']];
        $mutated['acme_challenges'] = ['challenge-after' => ['expires_at' => 67890]];
        $stateProperty->setValue($this->controller, $mutated);
        $dirtyProperty->setValue($this->controller, true);

        (new \ReflectionMethod($this->controller, 'abortRoutingMutation'))
            ->invoke($this->controller, 'injected failure');

        $restored = $stateProperty->getValue($this->controller);
        self::assertSame(41, $restored['generation']);
        self::assertSame(39, $restored['active_config_generation']);
        self::assertSame(38, $restored['pending_lkg_generation']);
        self::assertSame(1234, $restored['pending_lkg_since']);
        self::assertFalse($restored['isolation_mode']);
        self::assertSame(['project-before' => ['generation' => 3]], $restored['projects']);
        self::assertSame(
            ['project-before' => ['instance-before' => ['generation' => 7]]],
            $restored['instances'],
        );
        self::assertSame(['route-before' => ['status' => 'ACTIVE']], $restored['routes']);
        self::assertSame(
            ['challenge-before' => ['expires_at' => 12345]],
            $restored['acme_challenges'],
        );
        self::assertFalse($dirtyProperty->getValue($this->controller));
        self::assertNull($publicationProperty->getValue($this->controller));
        $publicationFile = (new \ReflectionMethod($this->controller, 'publicationFile'))
            ->invoke($this->controller);
        self::assertFileDoesNotExist($publicationFile);
    }

    public function testIrrevocableSecurityMutationNeverRestoresRevokedRoute(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174030';
        $routeId = \str_repeat('d', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $state = $stateProperty->getValue($this->controller);
        $state['ready'] = true;
        $state['projects'][$projectUuid] = ['generation' => 1];
        $state['instances'][$projectUuid] = ['instance' => ['generation' => 1]];
        $state['enrollments'][$projectUuid] = ['security_generation' => 1];
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'status' => 'ACTIVE',
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'test-revoke');
        $mutated = $stateProperty->getValue($this->controller);
        unset($mutated['projects'][$projectUuid]);
        unset($mutated['instances'][$projectUuid]);
        unset($mutated['enrollments'][$projectUuid]);
        $mutated['routes'][$routeId]['status'] = 'REMOVED';
        $mutated['security']['tombstones']['project:' . $projectUuid] = [
            'project_uuid' => $projectUuid,
            'generation' => 2,
        ];
        $stateProperty->setValue($this->controller, $mutated);
        (new \ReflectionMethod($this->controller, 'markPublicationIrrevocableSecurity'))
            ->invoke($this->controller);
        (new \ReflectionMethod($this->controller, 'abortRoutingMutation'))
            ->invoke($this->controller, 'injected publication failure');

        $failedClosed = $stateProperty->getValue($this->controller);
        self::assertArrayNotHasKey($projectUuid, $failedClosed['projects']);
        self::assertArrayNotHasKey($projectUuid, $failedClosed['instances']);
        self::assertArrayNotHasKey($projectUuid, $failedClosed['enrollments']);
        self::assertSame('REMOVED', $failedClosed['routes'][$routeId]['status']);
        self::assertArrayHasKey(
            'project:' . $projectUuid,
            $failedClosed['security']['tombstones'],
        );
        self::assertFalse($failedClosed['ready']);
        self::assertTrue($failedClosed['isolation_mode']);
        self::assertSame('SECURITY_MUTATION_FAILED_CLOSED', $failedClosed['health_state']);
        self::assertTrue($dirtyProperty->getValue($this->controller));
    }

    public function testRemovedRouteCannotBeReactivatedByBackendSelectionOrLeaseSweep(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174031';
        $routeId = $this->canonicalRouteId($projectUuid, 'revoked.example.test');
        $instanceId = 'revoked-instance';
        $lease = $this->instanceLease($instanceId, 29120, 'stateless');
        $removedAt = \time() - 60;
        $route = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'revoked.example.test',
            'enrollment_security_generation' => 8,
            'domain_security_generation' => 0,
            'route_generation' => 1,
            'status' => 'REMOVED',
            'removed_at' => $removedAt,
            'certificate' => [
                'valid' => true,
                'snapshot_digest' => \str_repeat('e', 64),
                'generation' => 7,
            ],
            'instances' => [$instanceId => $lease],
            'backends' => [],
            'backend_instances' => [],
        ];

        $selector = new \ReflectionMethod($this->controller, 'selectRouteBackends');
        $selector->invokeArgs($this->controller, [&$route]);
        self::assertSame('REMOVED', $route['status']);
        self::assertSame($removedAt, $route['removed_at']);
        self::assertSame($this->hostBootId(), $route['removed_boot_id']);
        self::assertGreaterThan(0.0, $route['removed_at_monotonic']);
        self::assertSame([], $route['instances']);
        self::assertSame([], $route['backends']);
        self::assertSame([], $route['backend_instances']);
        self::assertSame('', $route['preferred_instance_id']);
        self::assertSame('', $route['instance_id']);
        self::assertSame([], $route['backend_identity']);

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = $route;
        $state['security']['tombstones']['project:' . $projectUuid] = [
            'kind' => 'project_revoke',
            'project_uuid' => $projectUuid,
            'generation' => 7,
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'expireLeases'))
            ->invoke($this->controller);

        $swept = $stateProperty->getValue($this->controller);
        self::assertSame('REMOVED', $swept['routes'][$routeId]['status']);
        self::assertSame($removedAt, $swept['routes'][$routeId]['removed_at']);
        self::assertSame([], $swept['routes'][$routeId]['instances']);
    }

    public function testRemovedRouteCompactionUsesCurrentBootMonotonicRetentionFence(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174034';
        $domain = 'retained.example.test';
        $project = $this->createProject();
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => [$domain],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok'], \json_encode($enrollment));
        $securityGeneration = (int)($enrollment['payload']['security_generation'] ?? 0);
        self::assertGreaterThan(0, $securityGeneration);
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'enrollment_security_generation' => $securityGeneration,
            'domain_security_generation' => 0,
            'route_generation' => 1,
            'status' => 'REMOVED',
            // Wall time is deliberately mature/future across the two sweeps;
            // neither value may authorize or veto a duration decision.
            'removed_at' => \time() - 172800,
            'removed_at_monotonic' => 10.0,
            'removed_boot_id' => \str_repeat('f', 64),
            'certificate' => [],
            'instances' => [],
            'backends' => [],
            'backend_instances' => [],
        ];
        $stateProperty->setValue($this->controller, $state);

        $compact = new \ReflectionMethod($this->controller, 'compactRemovedRoutes');
        $compact->invoke($this->controller, 1000.0);

        $rebuilt = $stateProperty->getValue($this->controller);
        self::assertArrayHasKey($routeId, $rebuilt['routes']);
        self::assertSame(
            $this->hostBootId(),
            $rebuilt['routes'][$routeId]['removed_boot_id'],
        );
        self::assertSame(1000.0, $rebuilt['routes'][$routeId]['removed_at_monotonic']);

        $rebuilt['routes'][$routeId]['removed_at'] = \time() + 31536000;
        $rebuilt['routes'][$routeId]['removed_at_monotonic'] = 1000.0;
        $stateProperty->setValue($this->controller, $rebuilt);
        $compact->invoke($this->controller, 87401.0);

        $compacted = $stateProperty->getValue($this->controller);
        self::assertArrayNotHasKey($routeId, $compacted['routes']);
        self::assertSame(
            'COMPACTED',
            $compacted['domain_ownership'][$domain]['status'],
        );
    }

    public function testQueuedPublicationCoalescesAndFailedRequestKeepsEarlierAcceptedState(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174032';
        $otherProjectUuid = '123e4567-e89b-42d3-a456-426614174033';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $deferProperty = new \ReflectionProperty($this->controller, 'deferPublication');
        $requestOperation = new \ReflectionProperty($this->controller, 'requestOperation');
        $requestPrincipal = new \ReflectionProperty($this->controller, 'requestPrincipal');
        $lastOperation = new \ReflectionProperty($this->controller, 'lastQueuedOperationId');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $begin = new \ReflectionMethod($this->controller, 'beginRoutingMutation');
        $publish = new \ReflectionMethod($this->controller, 'publishIfDirty');
        $abort = new \ReflectionMethod($this->controller, 'abortRoutingMutation');
        $operationStatus = new \ReflectionMethod($this->controller, 'operationStatus');

        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 51;
        $state['operations'] = [];
        $state['routes'] = [];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, true);
        $deferProperty->setValue($this->controller, true);
        $requestOperation->setValue($this->controller, 'register');
        $requestPrincipal->setValue($this->controller, $projectUuid);

        $begin->invoke($this->controller, 'register:' . $projectUuid . ':instance-a');
        $state = $stateProperty->getValue($this->controller);
        $state['routes']['accepted-route'] = ['project_uuid' => $projectUuid];
        $stateProperty->setValue($this->controller, $state);
        self::assertTrue($publish->invoke($this->controller));

        $operationId = $lastOperation->getValue($this->controller);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $operationId);
        $publication = $publicationProperty->getValue($this->controller);
        self::assertSame('PENDING_PUBLICATION', $publication['phase']);
        self::assertSame([$operationId], $publication['operation_ids']);
        self::assertSame(
            'PENDING_PUBLICATION',
            $operationStatus->invoke(
                $this->controller,
                $operationId,
                $projectUuid,
                false,
            )['state'],
        );

        $requestOperation->setValue($this->controller, 'register');
        $requestPrincipal->setValue($this->controller, $otherProjectUuid);
        $begin->invoke($this->controller, 'register:' . $otherProjectUuid . ':instance-b');
        $state = $stateProperty->getValue($this->controller);
        $state['routes']['rejected-route'] = ['project_uuid' => $otherProjectUuid];
        $stateProperty->setValue($this->controller, $state);
        $abort->invoke($this->controller, 'second request rejected');

        $restored = $stateProperty->getValue($this->controller);
        self::assertArrayHasKey('accepted-route', $restored['routes']);
        self::assertArrayNotHasKey('rejected-route', $restored['routes']);
        self::assertSame(
            'PENDING_PUBLICATION',
            $publicationProperty->getValue($this->controller)['phase'],
        );
        self::assertSame(
            'PENDING_PUBLICATION',
            $restored['operations'][$operationId]['state'],
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('belongs to another project');
        $operationStatus->invoke(
            $this->controller,
            $operationId,
            $otherProjectUuid,
            false,
        );
    }

    public function testActivePublicationKeepsReadsAvailableAndSerializesRoutingMutations(): void
    {
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'publication-control-availability');
        $publication = $publicationProperty->getValue($this->controller);
        $publication['phase'] = 'PREPARED';
        $publicationProperty->setValue($this->controller, $publication);

        $dispatch = new \ReflectionMethod($this->controller, 'dispatch');
        $status = $dispatch->invoke($this->controller, 'status', []);
        self::assertArrayHasKey('ready', $status);
        self::assertSame('PREPARED', $status['publication']['phase']);

        try {
            $dispatch->invoke($this->controller, 'register', []);
            self::fail('A second routing mutation entered an active publication.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'Gateway publication is active; retry_after=1.',
                $exception->getMessage(),
            );
        }
    }

    public function testRegistrationFloodCannotConsumeLeaseHeartbeatBudget(): void
    {
        $rateLimit = new \ReflectionMethod($this->controller, 'assertRateLimit');
        $principal = '123e4567-e89b-42d3-a456-426614174099';
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $rateLimit->invoke($this->controller, 'project', $principal, 'register');
        }
        try {
            $rateLimit->invoke($this->controller, 'project', $principal, 'register');
            self::fail('The project mutation bucket was not exhausted.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('rate limit exceeded', $exception->getMessage());
        }

        // Heartbeats are state-only and retain a distinct bounded budget, so
        // an idempotent registration retry loop cannot expire a live route.
        $rateLimit->invoke(
            $this->controller,
            'project',
            $principal,
            'heartbeat',
            'instance-live',
        );
        self::assertTrue(true);
    }

    public function testHeartbeatReplayAcrossControllerIncarnationCannotExtendLease(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174096';
        $instanceId = 'heartbeat-replay';
        $credentialId = \str_repeat('a', 32);
        $secret = \str_repeat('b', 64);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'credential_secret' => $secret,
            'security_generation' => 1,
            'owner' => [
                'kind' => 'posix',
                'uid' => (int)\posix_geteuid(),
                'gid' => (int)\posix_getegid(),
            ],
        ];
        $state['projects'][$projectUuid] = [
            'generation' => 1,
            'route_ids' => [],
        ];
        $lease = $this->instanceLease($instanceId, 29105, 'heartbeat-replay');
        $lease['last_heartbeat_monotonic']
            = \hrtime(true) / 1_000_000_000 - 2.0;
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $stateProperty->setValue($this->controller, $state);

        $payload = [
            'project_uuid' => $projectUuid,
            'project_generation' => 1,
            'instance_id' => $instanceId,
            'instance_generation' => 1,
            'instance_digest' => (string)$lease['digest'],
            'master_epoch' => 1,
            'launch_id' => \str_repeat('c', 32),
            'gateway_epoch' => (string)$state['epoch'],
            'host_boot_id' => $this->hostBootId(),
        ];
        $request = $this->signedRequest(
            'project',
            'heartbeat',
            $payload,
            $credentialId,
            $secret,
        );
        $authenticate = new \ReflectionMethod($this->controller, 'authenticate');
        $broker = [
            'channel' => 'project',
            'uid' => (int)\posix_geteuid(),
        ];
        $first = $authenticate->invoke($this->controller, $request, $broker);
        self::assertSame('', $first['error']);
        (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            $payload + [
                '_authenticated_monotonic' => $first['authenticated_monotonic'],
                '_authenticated_timestamp' => $first['authenticated_timestamp'],
            ],
        );
        $firstLease = $stateProperty->getValue($this->controller)
            ['instances'][$projectUuid][$instanceId]['last_heartbeat_monotonic'];
        self::assertSame((float)$request['monotonic_timestamp'], $firstLease);

        $sameProcessReplay = $authenticate->invoke(
            $this->controller,
            $request,
            $broker,
        );
        self::assertStringContainsString('replayed', $sameProcessReplay['error']);

        (new \ReflectionProperty($this->controller, 'heartbeatNonces'))
            ->setValue($this->controller, []);
        (new \ReflectionProperty($this->controller, 'controllerStartedMonotonic'))
            ->setValue($this->controller, \hrtime(true) / 1_000_000_000);
        (new \ReflectionProperty($this->controller, 'controllerIncarnation'))
            ->setValue($this->controller, \bin2hex(\random_bytes(16)));
        $afterRestart = $authenticate->invoke($this->controller, $request, $broker);
        self::assertSame('', $afterRestart['error']);
        (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            $payload + [
                '_authenticated_monotonic' => $afterRestart['authenticated_monotonic'],
                '_authenticated_timestamp' => $afterRestart['authenticated_timestamp'],
            ],
        );
        $replayedLease = $stateProperty->getValue($this->controller)
            ['instances'][$projectUuid][$instanceId]['last_heartbeat_monotonic'];
        self::assertSame(
            $firstLease,
            $replayedLease,
            'A post-crash replay must renew only its original signed instant.',
        );

        $expired = $this->signedRequest(
            'project',
            'heartbeat',
            $payload,
            $credentialId,
            $secret,
        );
        $expired['monotonic_timestamp']
            = \hrtime(true) / 1_000_000_000 - 6.0;
        unset($expired['signature']);
        $expired['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($expired),
            $secret,
        );
        $rejected = $authenticate->invoke($this->controller, $expired, $broker);
        self::assertStringContainsString(
            'monotonic timestamp is outside',
            $rejected['error'],
        );
    }

    public function testRegisteredHeartbeatCheckpointSurvivesControllerRestart(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174081',
            'checkpoint-restart',
            'checkpoint-restart.example.test',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $baselineMonotonic = \hrtime(true) / 1_000_000_000 - 31.0;
        $baselineWall = \time() - 31;
        foreach (['instances', 'routes', 'active_routes'] as $scope) {
            if ($scope === 'instances') {
                $state[$scope][$fixture['project_uuid']][$fixture['instance_id']]
                    ['last_heartbeat'] = $baselineWall;
                $state[$scope][$fixture['project_uuid']][$fixture['instance_id']]
                    ['last_heartbeat_monotonic'] = $baselineMonotonic;
                continue;
            }
            $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                ['last_heartbeat'] = $baselineWall;
            $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                ['last_heartbeat_monotonic'] = $baselineMonotonic;
        }
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistState'))
            ->invoke($this->controller);

        $stateFile = (new \ReflectionMethod($this->controller, 'stateFile'))
            ->invoke($this->controller);
        $stateDigestBeforeHeartbeat = \hash_file('sha256', $stateFile);
        self::assertIsString($stateDigestBeforeHeartbeat);
        $authenticatedMonotonic = \hrtime(true) / 1_000_000_000;
        $authenticatedTimestamp = \time();
        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $fixture['project_uuid'],
                'project_generation' => $fixture['project_generation'],
                'instance_id' => $fixture['instance_id'],
                'instance_generation' => $fixture['instance_generation'],
                'instance_digest' => $fixture['instance_digest'],
                'master_epoch' => $fixture['master_epoch'],
                'launch_id' => $fixture['launch_id'],
                'gateway_epoch' => $fixture['gateway_epoch'],
                'host_boot_id' => $this->hostBootId(),
                '_authenticated_monotonic' => $authenticatedMonotonic,
                '_authenticated_timestamp' => $authenticatedTimestamp,
            ],
        );

        self::assertFalse($heartbeat['re_register_required']);
        self::assertArrayHasKey('lease_receipt', $heartbeat);
        self::assertSame(
            $authenticatedMonotonic,
            $heartbeat['lease_receipt']['issued_monotonic'],
        );
        self::assertSame(
            $stateDigestBeforeHeartbeat,
            \hash_file('sha256', $stateFile),
            'A durable heartbeat checkpoint must not rewrite the complete gateway state.',
        );

        $checkpointFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/lease-checkpoint.json';
        self::assertFileExists($checkpointFile);
        self::assertLessThanOrEqual(4_194_304, (int)\filesize($checkpointFile));
        $checkpoint = \json_decode(
            (string)\file_get_contents($checkpointFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('wls-edge-lease-checkpoint/1', $checkpoint['payload']['schema']);
        self::assertSame(
            32,
            \strlen((string)$checkpoint['payload']['instances'][0]
                ['instance_fence']['launch_id']),
            'The compact lease fence must use the protocol-defined 32-hex launch ID.',
        );

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/checkpoint-restart.sock',
        );
        $restartedState = (new \ReflectionProperty($restarted, 'state'))
            ->getValue($restarted);
        self::assertSame(
            $authenticatedTimestamp,
            $restartedState['instances'][$fixture['project_uuid']]
                [$fixture['instance_id']]['last_heartbeat'],
        );
        self::assertSame(
            $authenticatedMonotonic,
            $restartedState['instances'][$fixture['project_uuid']]
                [$fixture['instance_id']]['last_heartbeat_monotonic'],
        );
        self::assertSame(
            $authenticatedMonotonic,
            $restartedState['routes'][$fixture['route_id']]['instances']
                [$fixture['instance_id']]['last_heartbeat_monotonic'],
        );
        self::assertSame(
            'ACTIVE',
            $restartedState['instances'][$fixture['project_uuid']]
                [$fixture['instance_id']]['status'],
            'A checkpoint may refresh an existing lease but may not change lifecycle state.',
        );
    }

    public function testLeaseCheckpointCannotCrossAnInstanceIdentityFence(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174082',
            'checkpoint-fence',
            'checkpoint-fence.example.test',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $baselineMonotonic = \hrtime(true) / 1_000_000_000 - 20.0;
        $baselineWall = \time() - 20;
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat'] = $baselineWall;
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat_monotonic'] = $baselineMonotonic;
        $state['routes'][$fixture['route_id']]['instances'][$fixture['instance_id']]
            ['last_heartbeat'] = $baselineWall;
        $state['routes'][$fixture['route_id']]['instances'][$fixture['instance_id']]
            ['last_heartbeat_monotonic'] = $baselineMonotonic;
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistState'))
            ->invoke($this->controller);

        $checkpointMonotonic = \hrtime(true) / 1_000_000_000;
        (new \ReflectionMethod($this->controller, 'touchInstanceLease'))->invoke(
            $this->controller,
            $fixture['project_uuid'],
            $fixture['instance_id'],
            $fixture['master_epoch'],
            $fixture['launch_id'],
            $fixture['instance_generation'],
            null,
            $checkpointMonotonic,
            \time(),
        );
        (new \ReflectionMethod($this->controller, 'persistLeaseCheckpoint'))
            ->invoke($this->controller);

        $state = $stateProperty->getValue($this->controller);
        $nextLaunchId = \str_repeat('d', 32);
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]['launch_id']
            = $nextLaunchId;
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat'] = $baselineWall;
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat_monotonic'] = $baselineMonotonic;
        foreach (['routes', 'active_routes'] as $scope) {
            $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                ['launch_id'] = $nextLaunchId;
            $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                ['last_heartbeat'] = $baselineWall;
            $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                ['last_heartbeat_monotonic'] = $baselineMonotonic;
        }
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistState'))
            ->invoke($this->controller);

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/checkpoint-fence.sock',
        );
        $restartedState = (new \ReflectionProperty($restarted, 'state'))
            ->getValue($restarted);
        self::assertSame(
            $nextLaunchId,
            $restartedState['instances'][$fixture['project_uuid']]
                [$fixture['instance_id']]['launch_id'],
        );
        self::assertSame(
            $baselineMonotonic,
            $restartedState['instances'][$fixture['project_uuid']]
                [$fixture['instance_id']]['last_heartbeat_monotonic'],
            'A lease from the retired launch must not refresh its replacement.',
        );
    }

    public function testUntrustedJournalPreventsLeaseCheckpointOverlay(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174083',
            'checkpoint-journal',
            'checkpoint-journal.example.test',
        );
        (new \ReflectionMethod($this->controller, 'touchInstanceLease'))->invoke(
            $this->controller,
            $fixture['project_uuid'],
            $fixture['instance_id'],
            $fixture['master_epoch'],
            $fixture['launch_id'],
            $fixture['instance_generation'],
        );
        (new \ReflectionMethod($this->controller, 'persistLeaseCheckpoint'))
            ->invoke($this->controller);

        $journalFile = $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl';
        $lines = \file($journalFile, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $tampered = \json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $tampered['event'] = 'tampered-before-checkpoint-overlay';
        $lines[0] = \json_encode($tampered, JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents(
            $journalFile,
            \implode("\n", $lines) . "\n",
        ));

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/checkpoint-untrusted-journal.sock',
        );
        self::assertFalse(
            (new \ReflectionProperty($restarted, 'journalTrusted'))->getValue($restarted),
        );
        $state = (new \ReflectionProperty($restarted, 'state'))->getValue($restarted);
        self::assertTrue($state['isolation_mode']);
        self::assertSame('JOURNAL_UNTRUSTED', $state['health_state']);
        self::assertArrayNotHasKey(
            $fixture['project_uuid'],
            (array)($state['instances'] ?? []),
            'Derived checkpoint leases must not repopulate state after audit trust fails.',
        );
    }

    public function testHeartbeatCheckpointFailureFallsBackOrRefusesAReceipt(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174084',
            'checkpoint-failure',
            'checkpoint-failure.example.test',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $baselineMonotonic = \hrtime(true) / 1_000_000_000 - 31.0;
        $baselineWall = \time() - 31;
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat'] = $baselineWall;
        $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat_monotonic'] = $baselineMonotonic;
        $state['routes'][$fixture['route_id']]['instances'][$fixture['instance_id']]
            ['last_heartbeat'] = $baselineWall;
        $state['routes'][$fixture['route_id']]['instances'][$fixture['instance_id']]
            ['last_heartbeat_monotonic'] = $baselineMonotonic;
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistState'))
            ->invoke($this->controller);

        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $failureTarget = \getenv(
            'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256'
        );
        $checkpointFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/lease-checkpoint.json';
        $stateFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/gateway-state.json';
        $heartbeatMethod = new \ReflectionMethod($this->controller, 'heartbeat');
        $payload = [
            'project_uuid' => $fixture['project_uuid'],
            'project_generation' => $fixture['project_generation'],
            'instance_id' => $fixture['instance_id'],
            'instance_generation' => $fixture['instance_generation'],
            'instance_digest' => $fixture['instance_digest'],
            'master_epoch' => $fixture['master_epoch'],
            'launch_id' => $fixture['launch_id'],
            'gateway_epoch' => $fixture['gateway_epoch'],
            'host_boot_id' => $this->hostBootId(),
        ];
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE='
                    . 'directory_fsync_after_rename_failed'
            );
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                    . \hash('sha256', $checkpointFile)
            );
            $fallbackHeartbeat = $heartbeatMethod->invoke(
                $this->controller,
                $payload + [
                    '_authenticated_monotonic' => \hrtime(true) / 1_000_000_000,
                    '_authenticated_timestamp' => \time(),
                ],
            );
            self::assertArrayHasKey('lease_receipt', $fallbackHeartbeat);
            self::assertFalse(
                (new \ReflectionProperty($this->controller, 'leaseCheckpointDirty'))
                    ->getValue($this->controller),
                'A successful full-state fallback durably covers the renewed lease.',
            );

            // Force the next acknowledgement beyond the durability threshold,
            // then make the compact write latch disk pressure. The full-state
            // fallback must not be attempted after that mutation fence closes.
            $durable = new \ReflectionProperty(
                $this->controller,
                'durableLeaseMonotonic',
            );
            $durableMap = $durable->getValue($this->controller);
            foreach ($durableMap as $key => $_) {
                $durableMap[$key] = \hrtime(true) / 1_000_000_000 - 31.0;
            }
            $durable->setValue($this->controller, $durableMap);
            $stateDigestBeforeDiskFailure = \hash_file('sha256', $stateFile);
            self::assertIsString($stateDigestBeforeDiskFailure);
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=temporary_write_or_fsync_failed');
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256');
            try {
                $heartbeatMethod->invoke(
                    $this->controller,
                    $payload + [
                        '_authenticated_monotonic' => \hrtime(true) / 1_000_000_000,
                        '_authenticated_timestamp' => \time(),
                    ],
                );
                self::fail('A heartbeat received a receipt without a durable lease image.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'cannot be durably acknowledged',
                    $exception->getMessage(),
                );
            }
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'leaseCheckpointDirty'))
                    ->getValue($this->controller),
                'A refused durability acknowledgement must retain the dirty lease for repair.',
            );
            self::assertSame(
                $stateDigestBeforeDiskFailure,
                \hash_file('sha256', $stateFile),
                'A checkpoint failure that latched disk pressure must not attempt a full-state write.',
            );
            $pressureState = $stateProperty->getValue($this->controller);
            self::assertSame('DISK_PRESSURE', $pressureState['health_state']);
            self::assertSame('ATOMIC_WRITE_FAILED', $pressureState['recovery']['stage']);
            self::assertSame(
                'temporary_write_or_fsync_failed',
                $pressureState['recovery']['last_failure'],
            );
            self::assertFalse($pressureState['ready']);

            (new \ReflectionMethod($this->controller, 'markDiskPressure'))->invoke(
                $this->controller,
                'SECONDARY_DISK_FAILURE',
                'secondary_failure_must_not_replace_primary',
            );
            $repeatedPressure = $stateProperty->getValue($this->controller);
            self::assertSame(
                'ATOMIC_WRITE_FAILED',
                $repeatedPressure['recovery']['stage'],
            );
            self::assertSame(
                'temporary_write_or_fsync_failed',
                $repeatedPressure['recovery']['last_failure'],
            );

            $pressureRestart = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR
                    . 'runtime/run/checkpoint-pressure-restart.sock',
            );
            (new \ReflectionMethod($pressureRestart, 'adoptOrRecoverDataPlane'))
                ->invoke($pressureRestart);
            $pressureRestartState = (new \ReflectionProperty(
                $pressureRestart,
                'state',
            ))->getValue($pressureRestart);
            self::assertFalse($pressureRestartState['ready']);
            self::assertSame('DISK_PRESSURE', $pressureRestartState['health_state']);
            self::assertSame(
                'ATOMIC_WRITE_FAILED',
                $pressureRestartState['recovery']['stage'],
            );
            self::assertSame(
                'temporary_write_or_fsync_failed',
                $pressureRestartState['recovery']['last_failure'],
                'A restart must recover the exact first failure from the bounded marker.',
            );
            self::assertArrayHasKey(
                'observed_data_plane_stage',
                $pressureRestartState['recovery'],
            );

            $markerFile = $this->home . DIRECTORY_SEPARATOR
                . 'state/disk-pressure.marker';
            self::assertNotFalse(\file_put_contents($markerFile, "corrupt\tmarker\n"));
            $corruptMarkerDigest = \hash_file('sha256', $markerFile);
            self::assertIsString($corruptMarkerDigest);
            $corruptRestart = new \WlsEdgeGatewayController(
                $this->home,
                'unix://' . $this->home . DIRECTORY_SEPARATOR
                    . 'runtime/run/checkpoint-pressure-corrupt.sock',
            );
            $corruptState = (new \ReflectionProperty($corruptRestart, 'state'))
                ->getValue($corruptRestart);
            self::assertFalse($corruptState['ready']);
            self::assertSame('DISK_PRESSURE', $corruptState['health_state']);
            self::assertSame(
                'DISK_PRESSURE_MARKER_UNTRUSTED',
                $corruptState['recovery']['stage'],
            );
            self::assertSame(
                'disk_pressure_marker_invalid',
                $corruptState['recovery']['last_failure'],
            );
            self::assertSame(
                $corruptMarkerDigest,
                \hash_file('sha256', $markerFile),
                'An untrusted marker must remain untouched for administrator repair.',
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $failureTarget === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                        . $failureTarget
                );
        }
    }

    public function testLeaseCheckpointMaintenanceThrottlePreservesDiskFailure(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174086',
            'checkpoint-maintenance',
            'checkpoint-maintenance.example.test',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty(
            $this->controller,
            'leaseCheckpointDirty',
        );
        $attemptProperty = new \ReflectionProperty(
            $this->controller,
            'lastLeaseCheckpointAttemptAt',
        );
        $maintenance = new \ReflectionMethod($this->controller, 'maintenance');
        foreach (['lastHealthAt', 'lastBackendProbeAt', 'lastRetentionCollectionAt']
            as $timer
        ) {
            (new \ReflectionProperty($this->controller, $timer))
                ->setValue($this->controller, PHP_FLOAT_MAX);
        }

        $state = $stateProperty->getValue($this->controller);
        $validProjectDigest = $state['projects'][$fixture['project_uuid']]['digest'];
        $state['projects'][$fixture['project_uuid']]['digest'] = 'invalid-fence';
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, true);
        $firstBaseline = \hrtime(true) / 1_000_000_000 - 6.0;
        $attemptProperty->setValue($this->controller, $firstBaseline);

        $maintenance->invoke($this->controller);
        $firstAttempt = $attemptProperty->getValue($this->controller);
        self::assertGreaterThan($firstBaseline, $firstAttempt);
        self::assertTrue($dirtyProperty->getValue($this->controller));
        $failed = $stateProperty->getValue($this->controller);
        self::assertSame('CONTROL_DEGRADED', $failed['health_state']);
        self::assertSame('LEASE_CHECKPOINT_FAILED', $failed['recovery']['stage']);

        // Pin the last attempt at the current monotonic instant so this check
        // remains deterministic even on a paused or overloaded test host.
        $withinThrottle = \hrtime(true) / 1_000_000_000;
        $attemptProperty->setValue($this->controller, $withinThrottle);
        $maintenance->invoke($this->controller);
        self::assertSame(
            $withinThrottle,
            $attemptProperty->getValue($this->controller),
            'A dirty checkpoint must not retry before five seconds elapsed.',
        );

        $retryBaseline = \hrtime(true) / 1_000_000_000 - 5.1;
        $attemptProperty->setValue($this->controller, $retryBaseline);
        $maintenance->invoke($this->controller);
        self::assertGreaterThan(
            $retryBaseline,
            $attemptProperty->getValue($this->controller),
            'A failed checkpoint must become retryable after five seconds.',
        );

        $state = $stateProperty->getValue($this->controller);
        $state['projects'][$fixture['project_uuid']]['digest'] = $validProjectDigest;
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, true);
        $attemptProperty->setValue(
            $this->controller,
            \hrtime(true) / 1_000_000_000 - 6.0,
        );

        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $failureTarget = \getenv(
            'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256'
        );
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            (new \ReflectionMethod($this->controller, 'ensureRecoveryReserve'))
                ->invoke($this->controller);
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE='
                    . 'temporary_write_or_fsync_failed'
            );
            \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256');

            $beforeDiskAttempt = $attemptProperty->getValue($this->controller);
            $maintenance->invoke($this->controller);

            self::assertGreaterThan(
                $beforeDiskAttempt,
                $attemptProperty->getValue($this->controller),
            );
            self::assertTrue($dirtyProperty->getValue($this->controller));
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'running'))
                    ->getValue($this->controller),
                'A checkpoint storage failure must not terminate the Controller loop.',
            );
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'readOnlyRecoveryMode'))
                    ->getValue($this->controller),
            );
            $pressure = $stateProperty->getValue($this->controller);
            self::assertFalse($pressure['ready']);
            self::assertSame('DISK_PRESSURE', $pressure['health_state']);
            self::assertSame('ATOMIC_WRITE_FAILED', $pressure['recovery']['stage']);
            self::assertSame(
                'temporary_write_or_fsync_failed',
                $pressure['recovery']['last_failure'],
            );
            (new \ReflectionProperty($this->controller, 'lastHealthAt'))
                ->setValue($this->controller, 0.0);
            $maintenance->invoke($this->controller);
            $observedPressure = $stateProperty->getValue($this->controller);
            self::assertSame(
                'ATOMIC_WRITE_FAILED',
                $observedPressure['recovery']['stage'],
                'Read-only data-plane observation must not replace the storage root cause.',
            );
            self::assertSame(
                'temporary_write_or_fsync_failed',
                $observedPressure['recovery']['last_failure'],
            );
            self::assertArrayHasKey(
                'observed_data_plane_stage',
                $observedPressure['recovery'],
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $failureTarget === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                        . $failureTarget
                );
        }
    }

    public function testLeaseMaintenanceAtomicFailureDoesNotExitController(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $failureTarget = \getenv(
            'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256'
        );
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            (new \ReflectionMethod($this->controller, 'ensureRecoveryReserve'))
                ->invoke($this->controller);
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE='
                    . 'directory_fsync_after_rename_failed'
            );
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                    . \hash(
                        'sha256',
                        $this->home . DIRECTORY_SEPARATOR
                            . 'state/publication-current.json',
                    )
            );
            (new \ReflectionProperty($this->controller, 'leaseCheckpointDirty'))
                ->setValue($this->controller, false);
            (new \ReflectionProperty($this->controller, 'lastHealthAt'))
                ->setValue($this->controller, 0.0);
            (new \ReflectionProperty($this->controller, 'lastBackendProbeAt'))
                ->setValue($this->controller, PHP_FLOAT_MAX);
            (new \ReflectionProperty($this->controller, 'lastRetentionCollectionAt'))
                ->setValue($this->controller, PHP_FLOAT_MAX);

            (new \ReflectionMethod($this->controller, 'maintenance'))
                ->invoke($this->controller);

            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'running'))
                    ->getValue($this->controller),
            );
            self::assertFalse(
                (new \ReflectionProperty($this->controller, 'serviceTreeRestartRequested'))
                    ->getValue($this->controller),
            );
            $state = (new \ReflectionProperty($this->controller, 'state'))
                ->getValue($this->controller);
            self::assertSame('CONTROL_DEGRADED', $state['health_state']);
            self::assertSame('LEASE_MAINTENANCE_FAILED', $state['recovery']['stage']);
            self::assertStringContainsString(
                'Lease maintenance transaction aborted',
                $state['recovery']['last_failure'],
            );
            self::assertNull(
                (new \ReflectionProperty($this->controller, 'publication'))
                    ->getValue($this->controller),
                'A recoverable maintenance transaction failure must close its rollback journal.',
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $failureTarget === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                        . $failureTarget
                );
        }
    }

    public function testRecoverDataPlaneAtomicFailureDoesNotExitController(): void
    {
        $testMode = \getenv('WLS_GATEWAY_TEST_MODE');
        $atomicFailure = \getenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE');
        $failureTarget = \getenv(
            'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256'
        );
        try {
            \putenv('WLS_GATEWAY_TEST_MODE=1');
            (new \ReflectionMethod($this->controller, 'ensureRecoveryReserve'))
                ->invoke($this->controller);
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE='
                    . 'directory_fsync_after_rename_failed'
            );
            \putenv(
                'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                    . \hash(
                        'sha256',
                        $this->home . DIRECTORY_SEPARATOR
                            . 'state/gateway-state.json',
                    )
            );
            (new \ReflectionProperty($this->controller, 'leaseCheckpointDirty'))
                ->setValue($this->controller, false);
            (new \ReflectionProperty($this->controller, 'lastHealthAt'))
                ->setValue($this->controller, 0.0);
            (new \ReflectionProperty($this->controller, 'lastBackendProbeAt'))
                ->setValue($this->controller, PHP_FLOAT_MAX);
            (new \ReflectionProperty($this->controller, 'lastRetentionCollectionAt'))
                ->setValue($this->controller, PHP_FLOAT_MAX);

            (new \ReflectionMethod($this->controller, 'maintenance'))
                ->invoke($this->controller);

            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'running'))
                    ->getValue($this->controller),
            );
            self::assertFalse(
                (new \ReflectionProperty($this->controller, 'serviceTreeRestartRequested'))
                    ->getValue($this->controller),
            );
            $state = (new \ReflectionProperty($this->controller, 'state'))
                ->getValue($this->controller);
            self::assertSame('CONTROL_DEGRADED', $state['health_state']);
            self::assertSame('DATA_PLANE_RECOVERY_FAILED', $state['recovery']['stage']);
            self::assertStringContainsString(
                'Data-plane recovery maintenance failed',
                $state['recovery']['last_failure'],
            );
        } finally {
            $testMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $testMode);
            $atomicFailure === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE=' . $atomicFailure);
            $failureTarget === false
                ? \putenv('WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256')
                : \putenv(
                    'WLS_GATEWAY_TEST_ATOMIC_WRITE_FAILURE_TARGET_SHA256='
                        . $failureTarget
                );
        }
    }

    public function testIdempotentRegisterAndRenewReceiptsSurviveControllerRestart(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174085',
            'checkpoint-idempotent',
            'checkpoint-idempotent.example.test',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $persistState = new \ReflectionMethod($this->controller, 'persistState');
        $ageDurableLease = function () use (
            $fixture,
            $stateProperty,
            $persistState,
        ): float {
            $baselineMonotonic = \hrtime(true) / 1_000_000_000 - 31.0;
            $baselineWall = \time() - 31;
            $state = $stateProperty->getValue($this->controller);
            $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
                ['last_heartbeat'] = $baselineWall;
            $state['instances'][$fixture['project_uuid']][$fixture['instance_id']]
                ['last_heartbeat_monotonic'] = $baselineMonotonic;
            foreach (['routes', 'active_routes'] as $scope) {
                $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                    ['last_heartbeat'] = $baselineWall;
                $state[$scope][$fixture['route_id']]['instances'][$fixture['instance_id']]
                    ['last_heartbeat_monotonic'] = $baselineMonotonic;
            }
            $stateProperty->setValue($this->controller, $state);
            $persistState->invoke($this->controller);
            return $baselineMonotonic;
        };

        $registerBaseline = $ageDurableLease();
        $register = $this->request(
            'project',
            'register',
            $fixture['register_payload'],
            $fixture['credential_id'],
            $fixture['credential_secret'],
        );
        self::assertTrue($register['ok'], \json_encode($register));
        self::assertTrue($register['payload']['idempotent']);
        self::assertArrayHasKey('lease_receipt', $register['payload']);
        $registeredLease = $stateProperty->getValue($this->controller)
            ['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat_monotonic'];
        self::assertGreaterThan($registerBaseline, $registeredLease);

        $registerRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/checkpoint-idempotent-register.sock',
        );
        $registerRestartState = (new \ReflectionProperty($registerRestart, 'state'))
            ->getValue($registerRestart);
        self::assertSame(
            $registeredLease,
            $registerRestartState['instances'][$fixture['project_uuid']]
                [$fixture['instance_id']]['last_heartbeat_monotonic'],
            'The real Broker register fast path must checkpoint before signing its receipt.',
        );

        $renewBaseline = $ageDurableLease();
        $renewPayload = $fixture['register_payload'];
        $renewPayload['expected_route_generations'] = [
            $fixture['route_id'] => $fixture['route_generation'],
        ];
        $renew = $this->request(
            'project',
            'renew',
            $renewPayload,
            $fixture['credential_id'],
            $fixture['credential_secret'],
        );
        self::assertTrue($renew['ok'], \json_encode($renew));
        self::assertTrue($renew['payload']['idempotent']);
        self::assertArrayHasKey('lease_receipt', $renew['payload']);
        $renewedLease = $stateProperty->getValue($this->controller)
            ['instances'][$fixture['project_uuid']][$fixture['instance_id']]
            ['last_heartbeat_monotonic'];
        self::assertGreaterThan($renewBaseline, $renewedLease);

        $renewRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/checkpoint-idempotent-renew.sock',
        );
        $renewRestartState = (new \ReflectionProperty($renewRestart, 'state'))
            ->getValue($renewRestart);
        self::assertSame(
            $renewedLease,
            $renewRestartState['routes'][$fixture['route_id']]['instances']
                [$fixture['instance_id']]['last_heartbeat_monotonic'],
            'The real Broker renew fast path must checkpoint before signing its receipt.',
        );
    }

    public function testLeaseCheckpointIsBoundedAtMaximumInstanceCapacity(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['projects'] = [];
        $state['instances'] = [];
        $instanceCount = 0;
        for ($projectIndex = 1; $projectIndex <= 32; ++$projectIndex) {
            $projectUuid = \sprintf(
                '123e4567-e89b-42d3-a456-%012x',
                $projectIndex,
            );
            $state['projects'][$projectUuid] = [
                'project_uuid' => $projectUuid,
                'generation' => 1,
                'digest' => \hash('sha256', 'checkpoint-project-' . $projectIndex),
                'route_ids' => [],
            ];
            for ($instanceIndex = 1; $instanceIndex <= 64; ++$instanceIndex) {
                $instanceId = 'checkpoint-' . $projectIndex . '-' . $instanceIndex;
                $state['instances'][$projectUuid][$instanceId]
                    = $this->instanceLease($instanceId, 20000 + $instanceIndex, 'isolated');
                ++$instanceCount;
            }
        }
        self::assertSame(2048, $instanceCount);
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistLeaseCheckpoint'))
            ->invoke($this->controller);
        $checkpointFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/lease-checkpoint.json';
        self::assertFileExists($checkpointFile);
        self::assertLessThanOrEqual(4_194_304, (int)\filesize($checkpointFile));
        $checkpoint = \json_decode(
            (string)\file_get_contents($checkpointFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertCount(2048, $checkpoint['payload']['instances']);

        $overflowProject = '123e4567-e89b-42d3-a456-000000000021';
        $overflowInstance = 'checkpoint-overflow';
        $state['projects'][$overflowProject] = [
            'project_uuid' => $overflowProject,
            'generation' => 1,
            'digest' => \hash('sha256', 'checkpoint-overflow'),
            'route_ids' => [],
        ];
        $state['instances'][$overflowProject][$overflowInstance]
            = $this->instanceLease($overflowInstance, 29999, 'isolated');
        $stateProperty->setValue($this->controller, $state);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instance capacity is exhausted');
        (new \ReflectionMethod($this->controller, 'persistLeaseCheckpoint'))
            ->invoke($this->controller);
    }

    public function testSteadyHealthAndUnchangedBackendProbeAvoidFullStateWrites(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php',
        );
        $probeStart = \strpos($source, 'private function probeActiveBackends(): bool');
        $probeEnd = \strpos($source, 'private function applyBackendProbeResult(', $probeStart);
        self::assertIsInt($probeStart);
        self::assertIsInt($probeEnd);
        $probeSource = \substr($source, $probeStart, $probeEnd - $probeStart);
        self::assertStringNotContainsString(
            '$this->persistState()',
            $probeSource,
            'Probe diagnostics must stay in memory unless a routing digest transition persists.',
        );

        $healthyStart = \strpos(
            $source,
            '$durableStateBefore = $this->dataPlaneRecoveryDurableSnapshot();',
        );
        $healthyEnd = \strpos(
            $source,
            '$this->state[\'ready\'] = false;',
            $healthyStart,
        );
        self::assertIsInt($healthyStart);
        self::assertIsInt($healthyEnd);
        $healthySource = \substr($source, $healthyStart, $healthyEnd - $healthyStart);
        self::assertStringContainsString(
            '$this->statePersistenceSequence === $persistenceSequenceBefore',
            $healthySource,
        );
        self::assertStringContainsString(
            '$durableStateBefore !== $this->dataPlaneRecoveryDurableSnapshot()',
            $healthySource,
        );
        self::assertSame(
            1,
            \substr_count($healthySource, '$this->persistState()'),
            'The healthy branch may persist only behind its bounded durable-state change gate.',
        );
    }

    public function testRepeatedDeferredRouteProbeRoundsStayInMemoryWithoutRecoveryWrites(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['ready'] = true;
        $state['isolation_mode'] = false;
        $state['health_state'] = 'HEALTHY';
        $state['recovery']['stage'] = 'HEALTHY';
        $state['recovery']['consecutive_failures'] = 4;
        $state['recovery']['backoff_attempt'] = 2;
        $state['failure_events'] = [[
            'at' => \time(),
            'at_monotonic' => \hrtime(true) / 1_000_000_000,
            'boot_id' => $this->hostBootId(),
            'reason' => 'preexisting evidence',
        ]];
        $stateProperty->setValue($this->controller, $state);
        $persistenceSequence = new \ReflectionProperty(
            $this->controller,
            'statePersistenceSequence',
        );
        $beforeSequence = $persistenceSequence->getValue($this->controller);
        $stateFile = $this->home . DIRECTORY_SEPARATOR . 'state/gateway-state.json';
        $journalFile = $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl';
        $beforeStateHash = \hash_file('sha256', $stateFile);
        $beforeJournalHash = \hash_file('sha256', $journalFile);
        $beforeStateStatus = \lstat($stateFile);
        self::assertIsArray($beforeStateStatus);
        $observe = new \ReflectionMethod(
            $this->controller,
            'observeDeferredPublicRouteProbe',
        );

        for ($round = 1; $round <= 12; ++$round) {
            $observe->invoke($this->controller, $round * 5.0);
        }

        self::assertSame(
            $beforeSequence,
            $persistenceSequence->getValue($this->controller),
        );
        self::assertSame($beforeStateHash, \hash_file('sha256', $stateFile));
        self::assertSame($beforeJournalHash, \hash_file('sha256', $journalFile));
        $afterStateStatus = \lstat($stateFile);
        self::assertIsArray($afterStateStatus);
        self::assertSame($beforeStateStatus['mtime'], $afterStateStatus['mtime']);
        self::assertSame($beforeStateStatus['size'], $afterStateStatus['size']);
        $after = $stateProperty->getValue($this->controller);
        self::assertTrue($after['ready']);
        self::assertSame('CONTROL_DEGRADED', $after['health_state']);
        self::assertSame('ROUTE_PROBE_DEFERRED', $after['recovery']['stage']);
        self::assertSame(60.0, $after['recovery']['route_probe_coverage_age_seconds']);
        self::assertSame(4, $after['recovery']['consecutive_failures']);
        self::assertSame(2, $after['recovery']['backoff_attempt']);
        self::assertSame($state['failure_events'], $after['failure_events']);
        self::assertFalse(
            (new \ReflectionProperty(
                $this->controller,
                'serviceTreeRestartRequested',
            ))->getValue($this->controller),
        );
    }

    public function testReadOnlyStatusUsesStorageCacheWithoutAWriteProbe(): void
    {
        $cache = new \ReflectionProperty($this->controller, 'storageStatusCache');
        $cachedAt = new \ReflectionProperty($this->controller, 'storageStatusCachedAt');
        $probeCount = new \ReflectionProperty(
            $this->controller,
            'storagePersistenceProbeCount',
        );
        $snapshotScanCount = new \ReflectionProperty(
            $this->controller,
            'snapshotStorageScanCount',
        );
        $statusMethod = new \ReflectionMethod($this->controller, 'status');
        $storageMethod = new \ReflectionMethod($this->controller, 'storageStatus');
        $stateDirectory = $this->home . DIRECTORY_SEPARATOR . 'state';
        $stateFile = $stateDirectory . DIRECTORY_SEPARATOR . 'gateway-state.json';
        $journalFile = $stateDirectory . DIRECTORY_SEPARATOR . 'journal.jsonl';

        $cache->setValue($this->controller, null);
        $cachedAt->setValue($this->controller, 0.0);
        $beforeProbeCount = $probeCount->getValue($this->controller);
        $beforeSnapshotScanCount = $snapshotScanCount->getValue($this->controller);
        $beforeEntries = \scandir($stateDirectory);
        self::assertIsArray($beforeEntries);
        $beforeStateDigest = \hash_file('sha256', $stateFile);
        $beforeJournalDigest = \hash_file('sha256', $journalFile);

        $cold = $statusMethod->invoke($this->controller, false);

        self::assertSame($beforeProbeCount, $probeCount->getValue($this->controller));
        self::assertSame(
            $beforeSnapshotScanCount,
            $snapshotScanCount->getValue($this->controller),
            'A cold read-only status must not walk the certificate snapshot tree.',
        );
        self::assertSame('UNKNOWN', $cold['storage']['persistence_probe_state']);
        self::assertFalse($cold['storage']['persistence_probe_fresh']);
        self::assertNull($cold['storage']['persistence_writable']);
        self::assertFalse($cold['storage']['mutation_ready']);
        self::assertSame($beforeEntries, \scandir($stateDirectory));
        self::assertSame($beforeStateDigest, \hash_file('sha256', $stateFile));
        self::assertSame($beforeJournalDigest, \hash_file('sha256', $journalFile));

        $active = $storageMethod->invoke($this->controller, true, true);
        self::assertGreaterThan(
            $beforeProbeCount,
            $probeCount->getValue($this->controller),
            'Maintenance/mutation refresh owns the persistence write probe.',
        );
        self::assertTrue($active['persistence_probe_fresh']);
        self::assertGreaterThan(
            $beforeSnapshotScanCount,
            $snapshotScanCount->getValue($this->controller),
            'Only an active maintenance/mutation refresh owns snapshot quota scans.',
        );
        $afterActiveProbeCount = $probeCount->getValue($this->controller);
        $afterActiveSnapshotScanCount = $snapshotScanCount->getValue($this->controller);
        $cached = $statusMethod->invoke($this->controller, false);
        self::assertSame(
            $afterActiveProbeCount,
            $probeCount->getValue($this->controller),
        );
        self::assertTrue($cached['storage']['persistence_probe_fresh']);
        self::assertSame('CACHED_FRESH', $cached['storage']['storage_metrics_state']);
        self::assertSame(
            $afterActiveSnapshotScanCount,
            $snapshotScanCount->getValue($this->controller),
        );

        $cache->setValue($this->controller, null);
        $cachedAt->setValue($this->controller, 0.0);
        (new \ReflectionProperty($this->controller, 'probeCriticalSectionDepth'))
            ->setValue($this->controller, 1);
        $nested = $statusMethod->invoke($this->controller, false);
        self::assertSame(
            $afterActiveProbeCount,
            $probeCount->getValue($this->controller),
        );
        self::assertSame('UNKNOWN', $nested['storage']['storage_metrics_state']);
        self::assertFalse($nested['storage']['mutation_ready']);
        self::assertSame(
            $afterActiveSnapshotScanCount,
            $snapshotScanCount->getValue($this->controller),
        );
        $forcedRefresh = $storageMethod->invoke($this->controller, true, true);
        self::assertSame(
            $afterActiveProbeCount,
            $probeCount->getValue($this->controller),
            'Probe critical sections must suppress even an explicit write-probe refresh.',
        );
        self::assertFalse($forcedRefresh['mutation_ready']);
        try {
            (new \ReflectionMethod(
                $this->controller,
                'assertPersistentMutationPreconditions',
            ))->invoke($this->controller, 'nested-status-regression');
            self::fail('A mutation used an unknown nested storage observation.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'persistence proof is not fresh',
                $exception->getMessage(),
            );
        }
        self::assertFalse(
            (new \ReflectionProperty($this->controller, 'readOnlyRecoveryMode'))
                ->getValue($this->controller),
            'An unknown read-only observation must fail closed without inventing disk pressure.',
        );
        self::assertFileDoesNotExist(
            $stateDirectory . DIRECTORY_SEPARATOR . 'disk-pressure.marker',
        );
    }

    public function testReadOnlyDispatchHasAHardControllerPersistenceFence(): void
    {
        $dispatch = new \ReflectionMethod($this->controller, 'dispatch');
        $persistState = new \ReflectionMethod($this->controller, 'persistState');
        $sequence = new \ReflectionProperty(
            $this->controller,
            'statePersistenceSequence',
        );
        $readOnlyDepth = new \ReflectionProperty(
            $this->controller,
            'readOnlyRequestDepth',
        );
        $stateFile = $this->home . DIRECTORY_SEPARATOR . 'state/gateway-state.json';
        $journalFile = $this->home . DIRECTORY_SEPARATOR . 'state/journal.jsonl';
        $beforeSequence = $sequence->getValue($this->controller);
        $beforeStateDigest = \hash_file('sha256', $stateFile);
        $beforeJournalDigest = \hash_file('sha256', $journalFile);

        $status = $dispatch->invoke($this->controller, 'status', []);
        $doctor = $dispatch->invoke($this->controller, 'doctor', []);

        self::assertArrayHasKey('data_plane', $status);
        self::assertArrayHasKey('binary', $doctor);
        self::assertSame(0, $readOnlyDepth->getValue($this->controller));
        self::assertSame($beforeSequence, $sequence->getValue($this->controller));
        self::assertSame($beforeStateDigest, \hash_file('sha256', $stateFile));
        self::assertSame($beforeJournalDigest, \hash_file('sha256', $journalFile));

        $readOnlyDepth->setValue($this->controller, 1);
        try {
            $persistState->invoke($this->controller);
            self::fail('A read-only request was allowed to persist Controller state.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'Read-only gateway operations must not persist Controller state.',
                $exception->getMessage(),
            );
        } finally {
            $readOnlyDepth->setValue($this->controller, 0);
        }
    }

    public function testFinalAdminStopIntentFencesRecoveryWhenDataPlaneStopThrows(): void
    {
        $failure = \getenv('WLS_GATEWAY_TEST_STOP_DATA_PLANE_FAILURE');
        try {
            \putenv('WLS_GATEWAY_TEST_STOP_DATA_PLANE_FAILURE=throw');
            $result = (new \ReflectionMethod($this->controller, 'stopGateway'))
                ->invoke($this->controller, [
                    'confirm' => true,
                    'force' => true,
                ]);

            self::assertTrue($result['accepted']);
            self::assertFalse($result['data_plane_stopped']);
            self::assertTrue($result['manual_cleanup_required']);
            self::assertSame(
                'Stop intent accepted; manual data-plane cleanup required.',
                $result['message'],
            );
            self::assertFileExists(
                $this->home . DIRECTORY_SEPARATOR . 'trust'
                    . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
            );
            self::assertFalse(
                (new \ReflectionProperty($this->controller, 'running'))
                    ->getValue($this->controller),
            );
            self::assertTrue(
                (new \ReflectionProperty($this->controller, 'adminStopFenceActive'))
                    ->getValue($this->controller),
            );
            $stateProperty = new \ReflectionProperty($this->controller, 'state');
            $state = $stateProperty->getValue($this->controller);
            self::assertSame('ADMIN_STOPPING', $state['health_state']);
            self::assertSame(
                'ADMIN_STOP_CLEANUP_REQUIRED',
                $state['recovery']['stage'],
            );
            self::assertStringContainsString(
                'Injected data-plane stop failure',
                $state['recovery']['last_failure'],
            );
            $stateFile = $this->home . DIRECTORY_SEPARATOR
                . 'state/gateway-state.json';
            $before = \hash_file('sha256', $stateFile);

            // run() performs one final maintenance tail after serving the stop
            // response. It and every direct recovery/start path must be inert.
            (new \ReflectionMethod($this->controller, 'maintenance'))
                ->invoke($this->controller);
            (new \ReflectionMethod($this->controller, 'recoverDataPlane'))
                ->invoke($this->controller);
            (new \ReflectionMethod($this->controller, 'restartDataPlane'))
                ->invoke($this->controller, 'stop-fence-regression');
            $start = (new \ReflectionMethod($this->controller, 'startDataPlane'))
                ->invoke($this->controller);
            $reload = (new \ReflectionMethod($this->controller, 'reloadDataPlane'))
                ->invoke($this->controller);
            (new \ReflectionMethod($this->controller, 'requestServiceTreeRestart'))
                ->invoke($this->controller, 'must remain administratively stopped');

            self::assertSame('ADMIN_STOPPING', $start['state']);
            self::assertFalse($reload['ok']);
            self::assertFalse(
                (new \ReflectionProperty(
                    $this->controller,
                    'serviceTreeRestartRequested',
                ))->getValue($this->controller),
            );
            self::assertSame($before, \hash_file('sha256', $stateFile));
            $after = $stateProperty->getValue($this->controller);
            self::assertSame('ADMIN_STOPPING', $after['health_state']);
            self::assertSame(
                'ADMIN_STOP_CLEANUP_REQUIRED',
                $after['recovery']['stage'],
            );
        } finally {
            $failure === false
                ? \putenv('WLS_GATEWAY_TEST_STOP_DATA_PLANE_FAILURE')
                : \putenv('WLS_GATEWAY_TEST_STOP_DATA_PLANE_FAILURE=' . $failure);
        }
    }

    public function testControllerResponseSanitizesNestedSecretsBeforeSigning(): void
    {
        $marker = 'controller-response-sensitive-marker';
        $payload = [
            'route' => [
                'domain' => 'public.example.test',
                'backend' => (object)[
                    'backend_id' => 'backend-public-a',
                    'health' => 'READY',
                    'credential_id' => 'credential-public-id',
                    'credential_reference' => 'credential-public-reference',
                    'credential_generation' => 3,
                    'credential' => $marker,
                    'credential.value' => $marker,
                    'credential-material' => $marker,
                    'credential_material_hash' => $marker,
                    'secret_generation' => $marker,
                    'token_identifier' => $marker,
                    'secret_credential_id' => $marker,
                    'token_credential_reference' => $marker,
                    'authorization_credential_generation' => $marker,
                    'secret_credential_digest' => $marker,
                    'token_credential_hash' => $marker,
                    'authorization_credential_fingerprint' => $marker,
                    'private_key_credential_thumbprint' => $marker,
                    'edge_capability_secret' => $marker,
                    'API-Key' => $marker,
                    'Authorization' => $marker,
                    'auth.header' => $marker,
                    'PassWord' => $marker,
                    'pass-phrase' => $marker,
                    'Credential' => $marker,
                    'Signing_Key' => $marker,
                    'PRIVATE KEY' => $marker,
                    'secret_digest' => 'digest-public',
                    'private_key_fingerprint' => 'fingerprint-public',
                    'response_signature_metadata' => [
                        'algorithm' => 'sha256',
                        'key_id' => 'signer-public',
                    ],
                ],
            ],
            'json_serializable' => new class($marker) implements \JsonSerializable {
                public function __construct(private readonly string $marker)
                {
                }

                public function jsonSerialize(): mixed
                {
                    return (object)[
                        'state' => 'PUBLIC',
                        'credential_identifier' => 'json-credential-public-id',
                        'credential_material' => $this->marker,
                        'nested' => (object)[
                            'status' => 'READY',
                            'private_key' => $this->marker,
                        ],
                    ];
                }
            },
            'self_serializing' => new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return $this;
                }
            },
            'project_id' => 'project-public-a',
            'instances' => [
                [
                    'instance_id' => 'instance-public-a',
                    'state' => 'ACTIVE',
                    'status' => 'HEALTHY',
                    'generation' => 7,
                    'epoch' => 'epoch-public',
                    'timestamp' => 1234567890,
                    'nonce_id' => 'nonce-public',
                    'nested' => [
                        ['To_Ken' => $marker, 'status' => 'ACTIVE'],
                        (object)[
                            'AuthCredential' => $marker,
                            'thumb_print' => 'thumbprint-public',
                        ],
                    ],
                ],
                [
                    'instance_id' => 'instance-public-b',
                    'state' => 'STANDBY',
                ],
            ],
            'hash' => 'hash-public',
            'security_signature_status' => 'verified',
        ];
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        (new \ReflectionMethod($this->controller, 'writeResponse'))->invoke(
            $this->controller,
            $sockets[1],
            'response-sanitization-request',
            false,
            $payload,
            'public_error_code',
            'Public operational message.',
            $this->adminSecret,
        );
        \fclose($sockets[1]);
        $line = \fgets($sockets[0], 4 * 1024 * 1024);
        \fclose($sockets[0]);
        self::assertIsString($line);
        self::assertStringNotContainsString($marker, $line);
        $response = \json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($response['ok']);
        self::assertSame('public_error_code', $response['error']['code']);
        self::assertSame('Public operational message.', $response['error']['message']);
        self::assertSame('public.example.test', $response['payload']['route']['domain']);
        self::assertSame('project-public-a', $response['payload']['project_id']);
        self::assertSame(
            ['instance-public-a', 'instance-public-b'],
            \array_column($response['payload']['instances'], 'instance_id'),
        );
        $backend = $response['payload']['route']['backend'];
        foreach ([
            'credential',
            'credential.value',
            'credential-material',
            'credential_material_hash',
            'secret_generation',
            'token_identifier',
            'secret_credential_id',
            'token_credential_reference',
            'authorization_credential_generation',
            'secret_credential_digest',
            'token_credential_hash',
            'authorization_credential_fingerprint',
            'private_key_credential_thumbprint',
            'edge_capability_secret',
            'API-Key',
            'Authorization',
            'auth.header',
            'PassWord',
            'pass-phrase',
            'Credential',
            'Signing_Key',
            'PRIVATE KEY',
        ] as $sensitiveKey) {
            self::assertArrayNotHasKey($sensitiveKey, $backend);
        }
        self::assertSame('credential-public-id', $backend['credential_id']);
        self::assertSame('credential-public-reference', $backend['credential_reference']);
        self::assertSame(3, $backend['credential_generation']);
        self::assertArrayNotHasKey(
            'To_Ken',
            $response['payload']['instances'][0]['nested'][0],
        );
        self::assertArrayNotHasKey(
            'AuthCredential',
            $response['payload']['instances'][0]['nested'][1],
        );
        self::assertSame('PUBLIC', $response['payload']['json_serializable']['state']);
        self::assertSame(
            'json-credential-public-id',
            $response['payload']['json_serializable']['credential_identifier'],
        );
        self::assertArrayNotHasKey(
            'credential_material',
            $response['payload']['json_serializable'],
        );
        self::assertSame('READY', $response['payload']['json_serializable']['nested']['status']);
        self::assertArrayNotHasKey(
            'private_key',
            $response['payload']['json_serializable']['nested'],
        );
        self::assertNull($response['payload']['self_serializing']);
        self::assertSame('backend-public-a', $backend['backend_id']);
        self::assertSame('READY', $backend['health']);
        self::assertSame('digest-public', $backend['secret_digest']);
        self::assertSame('fingerprint-public', $backend['private_key_fingerprint']);
        self::assertSame(
            ['algorithm' => 'sha256', 'key_id' => 'signer-public'],
            $backend['response_signature_metadata'],
        );
        self::assertSame(
            'thumbprint-public',
            $response['payload']['instances'][0]['nested'][1]['thumb_print'],
        );
        self::assertSame(7, $response['payload']['instances'][0]['generation']);
        self::assertSame('epoch-public', $response['payload']['instances'][0]['epoch']);
        self::assertSame(1234567890, $response['payload']['instances'][0]['timestamp']);
        self::assertSame('nonce-public', $response['payload']['instances'][0]['nonce_id']);
        self::assertSame('hash-public', $response['payload']['hash']);
        self::assertSame('verified', $response['payload']['security_signature_status']);
        $this->assertResponseSignature($response, $this->adminSecret);
    }

    public function testStalledPublicationProbeStillServicesNewControlRequest(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The cooperative publication probe test requires pcntl.');
        }
        $controlSocket = $this->root . DIRECTORY_SEPARATOR . 'publication-control.sock';
        $server = \stream_socket_server(
            'unix://' . $controlSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        \stream_set_blocking($server, false);
        $controlServer = new \ReflectionProperty($this->controller, 'controlServer');
        $controlServer->setValue($this->controller, $server);

        $request = [
            'protocol' => 'wls-edge/2',
            'channel' => 'admin',
            'host_id' => $this->hostId,
            'credential_id' => 'admin',
            'operation' => 'status',
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => \hrtime(true) / 1_000_000_000,
            'nonce' => \bin2hex(\random_bytes(16)),
            'payload' => [],
        ];
        $request['request_digest'] = \hash('sha256', GatewayClient::canonicalJson([
            'operation' => 'status',
            'payload' => [],
        ]));
        $request['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($request),
            $this->adminSecret,
        );
        $encoded = \json_encode(
            $request,
            JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $broker = \json_encode([
            'broker_schema' => 1,
            'action_protocol' => 2,
            'channel' => 'admin',
            'uid' => (int)\posix_geteuid(),
            'gid' => (int)\posix_getegid(),
            'pid' => \getmypid(),
            'fencing_token' => $this->fencing,
            'payload_length' => \strlen($encoded),
        ], JSON_THROW_ON_ERROR) . "\n";
        $handshake = $this->brokerHandshake();
        $responseFile = $this->root . DIRECTORY_SEPARATOR . 'publication-control-response.json';
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            @\fclose($server);
            \usleep(30000);
            $started = \microtime(true);
            $client = @\stream_socket_client('unix://' . $controlSocket, $childErrno, $childError, 1);
            if (!\is_resource($client)) {
                @\file_put_contents($responseFile, \json_encode([
                    'error' => $childError !== '' ? $childError : (string)$childErrno,
                ]));
                exit(70);
            }
            \stream_set_timeout($client, 1);
            @\fwrite($client, $handshake['probe'] . $broker . $encoded);
            $ready = @\fgets($client, 512);
            if ($ready !== $handshake['ready']) {
                @\fclose($client);
                @\file_put_contents($responseFile, \json_encode([
                    'error' => 'broker handshake failed',
                ]));
                exit(72);
            }
            $response = @\fgets($client, 4 * 1024 * 1024);
            @\fclose($client);
            @\file_put_contents($responseFile, \json_encode([
                'elapsed_ms' => (\microtime(true) - $started) * 1000,
                'response' => \is_string($response) ? \json_decode($response, true) : null,
            ]));
            exit(\is_string($response) ? 0 : 71);
        }

        (new \ReflectionMethod($this->controller, 'publicationProbePause'))
            ->invoke($this->controller);
        \pcntl_waitpid($pid, $status);
        @\fclose($server);
        $controlServer->setValue($this->controller, null);
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
        $result = \json_decode((string)\file_get_contents($responseFile), true);
        self::assertIsArray($result);
        self::assertLessThan(350.0, (float)($result['elapsed_ms'] ?? 9999));
        self::assertTrue((bool)($result['response']['ok'] ?? false));
        self::assertArrayHasKey('ready', (array)($result['response']['payload'] ?? []));
    }

    public function testPublicationProbeRejectsIncompleteBrokerWithoutExhaustingProbeBudget(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The cooperative publication probe test requires pcntl.');
        }
        $controlSocket = $this->root . DIRECTORY_SEPARATOR
            . 'publication-incomplete-control.sock';
        $server = \stream_socket_server(
            'unix://' . $controlSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        \stream_set_blocking($server, false);
        $controlServer = new \ReflectionProperty($this->controller, 'controlServer');
        $controlServer->setValue($this->controller, $server);
        $handshake = $this->brokerHandshake();

        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            @\fclose($server);
            $client = @\stream_socket_client(
                'unix://' . $controlSocket,
                $childErrno,
                $childError,
                1,
            );
            if (!\is_resource($client)) {
                exit(70);
            }
            // Authenticate the Broker boundary first, then leave its identity
            // envelope incomplete to exercise the bounded second-stage read.
            @\fwrite($client, $handshake['probe']);
            if (@\fgets($client, 512) !== $handshake['ready']) {
                @\fclose($client);
                exit(71);
            }
            @\fwrite($client, '{"broker_schema":1');
            \usleep(250000);
            @\fclose($client);
            exit(0);
        }

        \usleep(30000);
        $started = \microtime(true);
        (new \ReflectionMethod($this->controller, 'publicationProbePause'))
            ->invoke($this->controller);
        $elapsedMs = (\microtime(true) - $started) * 1000;
        \pcntl_waitpid($pid, $status);
        @\fclose($server);
        $controlServer->setValue($this->controller, null);

        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
        self::assertLessThan(180.0, $elapsedMs);
    }

    public function testIdenticalRegistrationRetryReturnsPendingOperationWithoutMutation(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174034';
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['retry.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);

        $operationId = \str_repeat('c', 32);
        $instanceId = 'retry-instance';
        $launchId = \str_repeat('3', 32);
        $domain = 'retry.example.test';
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        $routePayload = [
            'route_id' => $routeId,
            'domain' => $domain,
            'force_https' => true,
            'force_root_to_www' => false,
            'root_to_www_target' => '',
            'backends' => [
                ['host' => '127.0.0.1', 'port' => 29150, 'weight' => 1],
            ],
            'backend_identity' => $this->signedBackendIdentity(
                $projectUuid,
                $instanceId,
                4,
                4,
                $launchId,
                \str_repeat('a', 64),
            ),
            'certificate' => $this->pendingCertificateEnvelope($domain),
        ];
        $candidateRoute = (new \ReflectionMethod($this->controller, 'validateRoute'))
            ->invoke(
                $this->controller,
                $routePayload,
                $projectUuid,
                $project,
                $instanceId,
                4,
                4,
                $launchId,
                \hrtime(true) / 1_000_000_000 + 5.0,
                true,
                true,
            );
        $projectDigest = (new \ReflectionMethod($this->controller, 'projectDesiredDigest'))
            ->invoke($this->controller, $projectUuid, $project, [$candidateRoute]);
        $instanceDigest = (new \ReflectionMethod($this->controller, 'instanceDesiredDigest'))
            ->invoke($this->controller, $projectUuid, $instanceId, 4, [$candidateRoute]);
        $nonCertificateDesiredDigest = (new \ReflectionMethod(
            $this->controller,
            'nonCertificateDesiredDigest',
        ))->invoke($this->controller, $projectUuid, $project, [$candidateRoute]);
        $idempotencyKey = \substr(\hash(
            'sha256',
            $projectUuid . ':desired:2:' . $projectDigest,
        ), 0, 40);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $lastOperation = new \ReflectionProperty($this->controller, 'lastQueuedOperationId');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 44;
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'generation' => 2,
            'digest' => $projectDigest,
            'idempotency_key' => $idempotencyKey,
            'non_certificate_desired_digest' => $nonCertificateDesiredDigest,
            'route_ids' => [$routeId],
        ];
        $state['instances'][$projectUuid][$instanceId] = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'digest' => $instanceDigest,
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'status' => 'ACTIVE',
        ];
        $state['routes'][$routeId] = $candidateRoute;
        $state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'operation' => 'register',
            'principal' => $projectUuid,
            'project_uuid' => $projectUuid,
            'state' => 'ACTIVATING',
            'desired_generation' => 44,
            'active_generation' => 0,
            'created_unix' => \time(),
            'completed_unix' => 0,
            'error' => '',
            'result_context' => ['instance_id' => $instanceId],
        ];
        $stateProperty->setValue($this->controller, $state);

        $result = (new \ReflectionMethod($this->controller, 'register'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'instance_id' => $instanceId,
                'project_generation' => 2,
                'request_digest' => $projectDigest,
                'idempotency_key' => $idempotencyKey,
                'instance_generation' => 4,
                'instance_digest' => $instanceDigest,
                'non_certificate_desired_digest' => $nonCertificateDesiredDigest,
                'master_epoch' => 4,
                'launch_id' => $launchId,
                'gateway_epoch' => (string)$state['epoch'],
                'host_boot_id' => $this->hostBootId(),
                'routes' => [$routePayload],
                '_broker_peer' => [
                    'channel' => 'project',
                    'uid' => (int)\posix_geteuid(),
                    'gid' => (int)\posix_getegid(),
                    'pid' => \getmypid(),
                ],
            ],
            false,
        );

        $unchanged = $stateProperty->getValue($this->controller);
        self::assertTrue($result['idempotent']);
        self::assertSame($operationId, $lastOperation->getValue($this->controller));
        self::assertSame(44, $unchanged['generation']);
        self::assertSame(4, $unchanged['instances'][$projectUuid][$instanceId]['generation']);
        self::assertSame('ACTIVATING', $unchanged['operations'][$operationId]['state']);
    }

    public function testSameProjectGenerationMayRefreshSameLiveInstanceCapabilityDigest(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174037';
        $instanceId = 'capability-refresh-instance';
        $launchId = \str_repeat('3', 32);
        $projectDigest = \str_repeat('1', 64);
        $idempotencyKey = \substr(\hash(
            'sha256',
            $projectUuid . ':desired:2:' . $projectDigest,
        ), 0, 40);
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['capability.example.test'],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok']);

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'generation' => 2,
            'digest' => $projectDigest,
            'idempotency_key' => $idempotencyKey,
            'route_ids' => [],
        ];
        $state['instances'][$projectUuid][$instanceId] = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'digest' => \str_repeat('2', 64),
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'status' => 'ACTIVE',
        ];
        $stateProperty->setValue($this->controller, $state);

        $register = new \ReflectionMethod($this->controller, 'register');
        try {
            $register->invoke(
                $this->controller,
                [
                    'project_uuid' => $projectUuid,
                    'project_root' => $project,
                    'instance_id' => $instanceId,
                    'project_generation' => 2,
                    'request_digest' => $projectDigest,
                    'idempotency_key' => $idempotencyKey,
                    'instance_generation' => 4,
                    'instance_digest' => \str_repeat('5', 64),
                    'non_certificate_desired_digest' => \str_repeat('9', 64),
                    'master_epoch' => 4,
                    'launch_id' => $launchId,
                    'gateway_epoch' => (string)$state['epoch'],
                    'host_boot_id' => $this->hostBootId(),
                    'routes' => [],
                    '_broker_peer' => [
                        'channel' => 'project',
                        'uid' => (int)\posix_geteuid(),
                        'gid' => (int)\posix_getegid(),
                        'pid' => \getmypid(),
                    ],
                ],
                false,
            );
            self::fail('The deliberately incomplete route set was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'Registration must contain 1..256 routes.',
                $exception->getMessage(),
                'The same fenced launch must pass the digest-refresh gate before route validation.',
            );
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Same instance generation has a different instance digest.');
        $register->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'instance_id' => $instanceId,
                'project_generation' => 4,
                'request_digest' => \str_repeat('6', 64),
                'idempotency_key' => \substr(\hash(
                    'sha256',
                    $projectUuid . ':desired:4:' . \str_repeat('6', 64),
                ), 0, 40),
                'instance_generation' => 4,
                'instance_digest' => \str_repeat('7', 64),
                'non_certificate_desired_digest' => \str_repeat('9', 64),
                'master_epoch' => 4,
                'launch_id' => \str_repeat('8', 32),
                'gateway_epoch' => (string)$state['epoch'],
                'host_boot_id' => $this->hostBootId(),
                'routes' => [],
                '_broker_peer' => [
                    'channel' => 'project',
                    'uid' => (int)\posix_geteuid(),
                    'gid' => (int)\posix_getegid(),
                    'pid' => \getmypid(),
                ],
            ],
            false,
        );
    }

    public function testSameGenerationCapabilityRefreshCannotChangeBackendRouteState(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceId = 'capability-only-refresh';
        $routeId = \str_repeat('9', 32);
        $launchId = \str_repeat('4', 32);
        $backends = [['host' => '127.0.0.1', 'port' => 29140, 'weight' => 1]];
        $identity = [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceId,
            'generation' => 4,
            'endpoint_file' => '/tmp/capability-endpoint.json',
            'master_pid' => 4100,
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'edge_capability_digest' => \str_repeat('a', 64),
            'session_capability' => 'isolated',
        ];
        $identity['digest'] = \hash('sha256', GatewayClient::canonicalJson($identity));
        $evidence = [
            'schema' => 'wls-stateless-capability/1',
            'runtime_source' => 'project_endpoint',
            'runtime_declared' => true,
            'instance_generation' => 4,
            'reason' => 'declared_stateless_runtime',
        ];
        $nextIdentity = $identity;
        $nextIdentity['session_capability'] = 'stateless';
        $nextIdentity['session_capability_evidence'] = $evidence;
        $nextIdentity['session_capability_evidence_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($evidence),
        );
        $nextIdentity['digest'] = \hash('sha256', GatewayClient::canonicalJson(
            \array_diff_key($nextIdentity, ['digest' => true]),
        ));
        $existingInstance = [
            'instance_id' => $instanceId,
            'generation' => 4,
            'digest' => \str_repeat('b', 64),
            'master_epoch' => 4,
            'launch_id' => $launchId,
            'backends' => $backends,
            'backend_identity' => $identity,
        ];
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['projects'][$projectUuid] = ['route_ids' => [$routeId]];
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'capability-only.example.test',
            'force_https' => true,
            'certificate' => [
                'source_digest' => \str_repeat('c', 64),
                'generation' => 3,
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $candidate = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'capability-only.example.test',
            'force_https' => true,
            'certificate' => [
                'source_digest' => \str_repeat('c', 64),
                'generation' => 3,
            ],
            'backends' => $backends,
            'backend_identity' => $nextIdentity,
        ];
        $guard = new \ReflectionMethod(
            $this->controller,
            'assertCapabilityOnlyInstanceRefresh',
        );
        $guard->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            $existingInstance,
            [$candidate],
        );

        $candidate['backends'][0]['port'] = 29141;
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Same instance generation may refresh capability evidence only.',
        );
        $guard->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            $existingInstance,
            [$candidate],
        );
    }

    public function testPreparedPublicationResumesAsPendingAfterControllerRestart(): void
    {
        $operationId = \str_repeat('a', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 71;
        $state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'state' => 'PREPARING',
            'created_unix' => \time(),
            'completed_unix' => 0,
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'restart-test');
        $publication = $publicationProperty->getValue($this->controller);
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $candidate = $configDir . DIRECTORY_SEPARATOR . 'candidate-71-'
            . $publication['transaction_id'] . '.conf';
        self::assertNotFalse(\file_put_contents($candidate, "events {}\nhttp {}\n"));
        $publication['phase'] = 'PREPARED';
        $publication['candidate_generation'] = 71;
        $publication['candidate_file'] = $candidate;
        $publication['candidate_digest'] = \hash_file('sha256', $candidate);
        $publication['operation_ids'] = [$operationId];
        $publicationProperty->setValue($this->controller, $publication);
        (new \ReflectionMethod($this->controller, 'persistState'))->invoke($this->controller);
        (new \ReflectionMethod($this->controller, 'persistPublication'))
            ->invoke($this->controller);

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/restarted.sock',
        );
        $restartedState = (new \ReflectionProperty($restarted, 'state'))->getValue($restarted);
        $restartedPublication = (new \ReflectionProperty(
            $restarted,
            'publication',
        ))->getValue($restarted);
        $publicationDueAt = (new \ReflectionProperty(
            $restarted,
            'publicationDueAt',
        ))->getValue($restarted);

        self::assertFileDoesNotExist($candidate);
        self::assertSame('PENDING_PUBLICATION', $restartedPublication['phase']);
        self::assertSame('', $restartedPublication['candidate_file']);
        self::assertSame('', $restartedPublication['candidate_digest']);
        self::assertSame('PENDING_PUBLICATION', $restartedState['operations'][$operationId]['state']);
        self::assertSame('PUBLICATION_RECOVERY', $restartedState['health_state']);
        self::assertGreaterThan(0.0, $publicationDueAt);
        self::assertTrue((new \ReflectionProperty($restarted, 'configDirty'))->getValue($restarted));
    }

    public function testShadowVerifiedRecoveryRemovesUnjournaledRollbackArtifact(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 73;
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'rollback-crash-test');
        $publication = $publicationProperty->getValue($this->controller);
        $transactionId = (string)$publication['transaction_id'];
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $active = $configDir . DIRECTORY_SEPARATOR . 'nginx.conf';
        $candidate = $configDir . DIRECTORY_SEPARATOR . 'candidate-73-'
            . $transactionId . '.conf';
        $rollback = $active . '.rollback.' . $transactionId;
        $activeConfig = "# verified active\nevents {}\nhttp {}\n";
        self::assertNotFalse(\file_put_contents($active, $activeConfig));
        self::assertNotFalse(\file_put_contents($candidate, "# candidate\nevents {}\nhttp {}\n"));
        // Crash model: atomicWrite($rollback) completed, but rollback_file and
        // the ACTIVATING phase were not persisted yet.
        self::assertNotFalse(\file_put_contents($rollback, $activeConfig));
        $publication['phase'] = 'SHADOW_VERIFIED';
        $publication['candidate_generation'] = 73;
        $publication['candidate_file'] = $candidate;
        $publication['candidate_digest'] = \hash_file('sha256', $candidate);
        $publication['rollback_file'] = '';
        $publicationProperty->setValue($this->controller, $publication);
        (new \ReflectionMethod($this->controller, 'persistState'))->invoke($this->controller);
        (new \ReflectionMethod($this->controller, 'persistPublication'))
            ->invoke($this->controller);

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/rollback-crash-restarted.sock',
        );
        $restartedPublication = (new \ReflectionProperty($restarted, 'publication'))
            ->getValue($restarted);

        self::assertSame($activeConfig, \file_get_contents($active));
        self::assertFileDoesNotExist($candidate);
        self::assertFileDoesNotExist($rollback);
        self::assertSame('PENDING_PUBLICATION', $restartedPublication['phase']);
        self::assertSame('', $restartedPublication['rollback_file']);
    }

    public function testStartupBoundsPublicationDiagnosticsAndRemovesOnlyExactAtomicTemps(): void
    {
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $rejected = [];
        $rollbacks = [];
        foreach (['a', 'b', 'c', 'd'] as $offset => $digit) {
            $transactionId = \str_repeat($digit, 32);
            $rejectedPath = $configDir . DIRECTORY_SEPARATOR
                . 'nginx.conf.rejected.' . $transactionId;
            $rollbackPath = $configDir . DIRECTORY_SEPARATOR
                . 'nginx.conf.rollback.' . $transactionId;
            self::assertNotFalse(\file_put_contents($rejectedPath, '# rejected ' . $digit));
            self::assertNotFalse(\file_put_contents($rollbackPath, '# rollback ' . $digit));
            self::assertTrue(\touch($rejectedPath, 100 + $offset));
            self::assertTrue(\touch($rollbackPath, 100 + $offset));
            $rejected[] = $rejectedPath;
            $rollbacks[] = $rollbackPath;
        }
        $recoveryDiagnostic = $configDir . DIRECTORY_SEPARATOR
            . 'nginx.conf.rejected.recovery';
        $malformedDiagnostic = $configDir . DIRECTORY_SEPARATOR
            . 'nginx.conf.rejected.not-a-transaction';
        $lkgCandidate = $configDir . DIRECTORY_SEPARATOR
            . 'lkg-candidate-deadbeefdeadbeef.conf';
        foreach ([$recoveryDiagnostic, $malformedDiagnostic, $lkgCandidate] as $path) {
            self::assertNotFalse(\file_put_contents($path, '# protected sentinel'));
        }
        $atomicTemps = [
            $configDir . DIRECTORY_SEPARATOR . 'nginx.conf.tmp-' . \str_repeat('1', 12),
            $configDir . DIRECTORY_SEPARATOR . 'nginx.conf.rollback.'
                . \str_repeat('e', 32) . '.tmp-' . \str_repeat('2', 12),
            $configDir . DIRECTORY_SEPARATOR . 'nginx.conf.rejected.'
                . \str_repeat('f', 32) . '.tmp-' . \str_repeat('3', 12),
            $recoveryDiagnostic . '.tmp-' . \str_repeat('4', 12),
            $configDir . DIRECTORY_SEPARATOR . 'candidate-81-'
                . \str_repeat('9', 32) . '.conf.tmp-' . \str_repeat('5', 12),
        ];
        $orphanCandidate = $configDir . DIRECTORY_SEPARATOR . 'candidate-82-'
            . \str_repeat('8', 32) . '.conf';
        self::assertNotFalse(\file_put_contents($orphanCandidate, '# orphan candidate'));
        foreach ($atomicTemps as $path) {
            self::assertNotFalse(\file_put_contents($path, '# interrupted atomic write'));
        }

        new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR
                . 'runtime/run/artifact-recovery-restarted.sock',
        );

        foreach ($atomicTemps as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertFileDoesNotExist($orphanCandidate);
        self::assertFileExists($recoveryDiagnostic);
        self::assertFileExists($malformedDiagnostic);
        self::assertFileExists($lkgCandidate);
        self::assertFileDoesNotExist($rejected[0]);
        self::assertFileDoesNotExist($rejected[1]);
        self::assertFileExists($rejected[2]);
        self::assertFileExists($rejected[3]);
        self::assertFileDoesNotExist($rollbacks[0]);
        self::assertFileDoesNotExist($rollbacks[1]);
        self::assertFileExists($rollbacks[2]);
        self::assertFileExists($rollbacks[3]);
    }

    public function testPublicationArtifactCleanupFailsClosedOnReservedCaseAlias(): void
    {
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $canonicalTemp = $configDir . DIRECTORY_SEPARATOR
            . 'nginx.conf.tmp-' . \str_repeat('a', 12);
        $reservedAlias = $configDir . DIRECTORY_SEPARATOR
            . 'NGINX.CONF.REJECTED.' . \str_repeat('b', 32);
        self::assertNotFalse(\file_put_contents($canonicalTemp, '# removable temp'));
        self::assertNotFalse(\file_put_contents($reservedAlias, '# reserved case alias'));

        (new \ReflectionMethod($this->controller, 'reconcilePublicationConfigArtifacts'))
            ->invoke($this->controller);

        self::assertFileExists(
            $canonicalTemp,
            'A reserved case alias must abort the complete cleanup before any deletion.',
        );
        self::assertFileExists($reservedAlias);
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        self::assertStringContainsString(
            'non-canonical case',
            (string)($state['recovery']['last_cleanup_failure'] ?? ''),
        );
    }

    public function testPublicationArtifactCleanupPreflightsAllTypesBeforeAnyDeletion(): void
    {
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $canonicalTemp = $configDir . DIRECTORY_SEPARATOR
            . 'nginx.conf.tmp-' . \str_repeat('1', 12);
        $sentinel = $configDir . DIRECTORY_SEPARATOR
            . 'unmanaged-cleanup-sentinel';
        $unsafeTemp = $configDir . DIRECTORY_SEPARATOR
            . 'nginx.conf.rejected.' . \str_repeat('c', 32)
            . '.tmp-' . \str_repeat('2', 12);
        self::assertNotFalse(\file_put_contents($canonicalTemp, '# removable temp'));
        self::assertNotFalse(\file_put_contents($sentinel, '# sentinel'));
        self::assertTrue(\symlink($sentinel, $unsafeTemp));

        (new \ReflectionMethod($this->controller, 'reconcilePublicationConfigArtifacts'))
            ->invoke($this->controller);

        self::assertFileExists(
            $canonicalTemp,
            'An unsafe selected artifact must abort cleanup before a valid artifact is deleted.',
        );
        self::assertTrue(\is_link($unsafeTemp));
        self::assertFileExists($sentinel);
    }

    public function testPublicationArtifactRetentionNeverRemovesLiveTransactionRollback(): void
    {
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $transactionIds = [
            \str_repeat('1', 32),
            \str_repeat('2', 32),
            \str_repeat('3', 32),
            \str_repeat('4', 32),
        ];
        $rollbacks = [];
        foreach ($transactionIds as $offset => $transactionId) {
            $path = $configDir . DIRECTORY_SEPARATOR
                . 'nginx.conf.rollback.' . $transactionId;
            self::assertNotFalse(\file_put_contents($path, '# rollback ' . $offset));
            self::assertTrue(\touch($path, 100 + $offset));
            $rollbacks[] = $path;
        }
        $publicationProperty->setValue($this->controller, [
            'transaction_id' => $transactionIds[0],
            'phase' => 'ACTIVATING',
            'rollback_file' => $rollbacks[0],
        ]);

        (new \ReflectionMethod($this->controller, 'reconcilePublicationConfigArtifacts'))
            ->invoke($this->controller);

        self::assertFileExists(
            $rollbacks[0],
            'The live rollback must survive even when its timestamp is the oldest.',
        );
        self::assertFileDoesNotExist($rollbacks[1]);
        self::assertFileExists($rollbacks[2]);
        self::assertFileExists($rollbacks[3]);
    }

    public function testPublicationArtifactRetentionNeverRemovesLiveCandidate(): void
    {
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $transactionId = \str_repeat('5', 32);
        $liveCandidate = $configDir . DIRECTORY_SEPARATOR
            . 'candidate-91-' . $transactionId . '.conf';
        $orphanCandidate = $configDir . DIRECTORY_SEPARATOR
            . 'candidate-92-' . \str_repeat('6', 32) . '.conf';
        self::assertNotFalse(\file_put_contents($liveCandidate, '# live candidate'));
        self::assertNotFalse(\file_put_contents($orphanCandidate, '# orphan candidate'));
        $publicationProperty->setValue($this->controller, [
            'transaction_id' => $transactionId,
            'phase' => 'ACTIVATING',
            'candidate_generation' => 91,
            'candidate_file' => $liveCandidate,
            'rollback_file' => '',
        ]);

        (new \ReflectionMethod($this->controller, 'reconcilePublicationConfigArtifacts'))
            ->invoke($this->controller);

        self::assertFileExists($liveCandidate);
        self::assertFileDoesNotExist($orphanCandidate);
    }

    public function testCompletedPublicationImmediatelyCollectsItsTransactionArtifacts(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['generation'] = 93;
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'terminal-artifact-cleanup');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $publication = $publicationProperty->getValue($this->controller);
        $transactionId = (string)$publication['transaction_id'];
        $configDir = (new \ReflectionMethod($this->controller, 'configDir'))
            ->invoke($this->controller);
        $candidate = $configDir . DIRECTORY_SEPARATOR
            . 'candidate-93-' . $transactionId . '.conf';
        $rollback = $configDir . DIRECTORY_SEPARATOR
            . 'nginx.conf.rollback.' . $transactionId;
        self::assertNotFalse(\file_put_contents($candidate, '# terminal candidate'));
        self::assertNotFalse(\file_put_contents($rollback, '# terminal rollback'));
        $publication['phase'] = 'FAILED';
        $publication['candidate_generation'] = 93;
        $publication['candidate_file'] = $candidate;
        $publication['rollback_file'] = $rollback;
        $publicationProperty->setValue($this->controller, $publication);
        (new \ReflectionMethod($this->controller, 'persistPublication'))
            ->invoke($this->controller);

        (new \ReflectionMethod($this->controller, 'completePublication'))
            ->invoke($this->controller);

        self::assertNull($publicationProperty->getValue($this->controller));
        self::assertFileDoesNotExist($candidate);
        self::assertFileDoesNotExist($rollback);
    }

    public function testCorruptPublicationFailsPendingOperationsAndPersistsIsolation(): void
    {
        $operationId = \str_repeat('b', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['operations'][$operationId] = [
            'operation_id' => $operationId,
            'state' => 'ACTIVATING',
            'created_unix' => \time(),
            'completed_unix' => 0,
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionMethod($this->controller, 'persistState'))->invoke($this->controller);
        $publicationFile = (new \ReflectionMethod($this->controller, 'publicationFile'))
            ->invoke($this->controller);
        self::assertNotFalse(\file_put_contents($publicationFile, '{"corrupt":true}'));

        $restarted = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/corrupt-publication.sock',
        );
        $restartedState = (new \ReflectionProperty($restarted, 'state'))->getValue($restarted);
        self::assertTrue($restartedState['isolation_mode']);
        self::assertFalse($restartedState['ready']);
        self::assertSame('STATE_REBUILD', $restartedState['health_state']);
        self::assertSame('FAILED', $restartedState['operations'][$operationId]['state']);
        self::assertStringContainsString(
            'corrupt',
            $restartedState['operations'][$operationId]['error'],
        );
        self::assertFileDoesNotExist($publicationFile);
        self::assertNotSame([], \glob($publicationFile . '.corrupt-*') ?: []);

        $secondRestart = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/corrupt-again.sock',
        );
        $secondState = (new \ReflectionProperty($secondRestart, 'state'))
            ->getValue($secondRestart);
        self::assertTrue($secondState['isolation_mode']);
        self::assertSame('STATE_REBUILD', $secondState['health_state']);
        self::assertSame('FAILED', $secondState['operations'][$operationId]['state']);
    }

    public function testShadowCandidateUsesOnlyIndependentLoopbackPortsAndRuntimeFiles(): void
    {
        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        $source = $render->invoke($this->controller, false);
        $root = $this->home . DIRECTORY_SEPARATOR . 'runtime/shadow/test';
        $pid = $root . DIRECTORY_SEPARATOR . 'nginx.pid';
        $shadow = (new \ReflectionMethod($this->controller, 'shadowConfig'))->invoke(
            $this->controller,
            $source,
            28180,
            28443,
            28643,
            $pid,
            $root,
        );

        self::assertStringContainsString('listen 127.0.0.1:28180', $shadow);
        self::assertStringContainsString('listen 127.0.0.1:28443 ssl', $shadow);
        self::assertStringContainsString('listen 127.0.0.1:28643;', $shadow);
        self::assertStringContainsString('pid "' . $pid . '";', $shadow);
        self::assertStringNotContainsString(
            'listen 0.0.0.0:' . (int)\getenv('WLS_GATEWAY_LISTEN_HTTP'),
            $shadow,
        );
        self::assertStringNotContainsString(
            'listen 0.0.0.0:' . (int)\getenv('WLS_GATEWAY_LISTEN_HTTPS'),
            $shadow,
        );
        self::assertStringNotContainsString('listen [::]:', $shadow);
        self::assertStringNotContainsString(' quic', $shadow);
    }

    public function testH3RuntimeQuarantineSurvivesRouteGenerationsUntilRuntimeChanges(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['h3_capable'] = true;
        $state['h3_capability_runtime_generation'] = 'test-runtime-a';
        $state['h3_quarantined_runtime_generation'] = 'test-runtime-a';
        $state['generation'] = (int)$state['generation'] + 1;
        $stateProperty->setValue($this->controller, $state);

        $render = new \ReflectionMethod($this->controller, 'renderNginxConfig');
        $render->invoke($this->controller, true);
        $quarantined = $stateProperty->getValue($this->controller);
        self::assertFalse($quarantined['h3_enabled']);

        $quarantined['generation'] = (int)$quarantined['generation'] + 1;
        $stateProperty->setValue($this->controller, $quarantined);
        $render->invoke($this->controller, true);
        self::assertFalse($stateProperty->getValue($this->controller)['h3_enabled']);

        $manifest = $this->home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A' . DIRECTORY_SEPARATOR . 'manifest.json';
        $decoded = \json_decode((string)\file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $decoded['runtime_generation'] = 'test-runtime-b';
        self::assertNotFalse(\file_put_contents(
            $manifest,
            \json_encode($decoded, JSON_THROW_ON_ERROR),
        ));
        $nginx = $this->home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A' . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'nginx';
        self::assertNotFalse(\file_put_contents(
            $nginx,
            "#!/bin/sh\n"
                . "printf '%s\\n' 'nginx version: wls-test' "
                . "'configure arguments: --with-http_v3_module' >&2\n"
                . "exit 0\n",
        ));
        self::assertTrue(\chmod($nginx, 0700));
        $render->invoke($this->controller, true);
        $nextRuntime = $stateProperty->getValue($this->controller);
        self::assertFalse($nextRuntime['h3_enabled']);
        self::assertStringContainsString(
            'runtime QUIC attestation is unavailable',
            (string)$nextRuntime['h3_reason'],
        );
    }

    public function testH3UnknownSniUsesTheNeutralDefaultQuicServer(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174034';
        $domain = 'h3-neutral.example.test';
        $project = $this->createProject();
        $source = $this->createCertificate($project, $domain, 'h3-neutral');
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $runtimeGeneration = (new \ReflectionMethod(
            $this->controller,
            'activeRuntimeGeneration',
        ))->invoke($this->controller);
        self::assertNotSame('', $runtimeGeneration);
        $state['isolation_mode'] = false;
        $state['security_ledger_valid'] = true;
        $state['h3_capable'] = true;
        $state['h3_capability_runtime_generation'] = $runtimeGeneration;
        $state['h3_quarantined_runtime_generation'] = '';
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 71,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $certificate = (new \ReflectionMethod($this->controller, 'snapshotCertificate'))
            ->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $this->activeCertificateEnvelope($domain, $sourceDigest, [
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'h3-neutral/fullchain.pem',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'h3-neutral/privkey.pem',
                    ],
                    'generation' => 1,
                ]),
            );
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        $backendIdentity = $this->signedBackendIdentity(
            $projectUuid,
            'h3-neutral',
            1,
            1,
            \str_repeat('a', 32),
            \str_repeat('b', 64),
        );
        $state = $stateProperty->getValue($this->controller);
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'enrollment_security_generation' => 71,
            'domain_security_generation' => 0,
            'route_generation' => 1,
            'instance_id' => 'h3-neutral',
            'preferred_instance_id' => 'h3-neutral',
            'distribution_mode' => 'single',
            'domain' => $domain,
            'status' => 'ACTIVE',
            'backends' => [[
                'host' => '127.0.0.1',
                'port' => 29540,
                'weight' => 1,
            ]],
            'backend_identity' => $backendIdentity,
            'certificate' => $certificate,
            'force_https' => true,
            'force_root_to_www' => false,
        ];
        $this->installCertificateFloor(
            $state,
            $projectUuid,
            $domain,
            $certificate,
        );
        $stateProperty->setValue($this->controller, $state);

        $config = (new \ReflectionMethod($this->controller, 'renderNginxConfig'))
            ->invoke($this->controller, true);
        $httpsPort = (int)$state['public_https'];
        self::assertStringContainsString(
            'listen 0.0.0.0:' . $httpsPort
                . ' quic reuseport default_server;',
            $config,
        );
        self::assertMatchesRegularExpression(
            '/server \{[\s\S]*?listen 0\.0\.0\.0:' . $httpsPort
                . ' quic reuseport default_server;[\s\S]*?'
                . 'ssl_certificate [^;]*neutral-cert\.pem"?;[\s\S]*?'
                . 'server_name _;[\s\S]*?return 421;[\s\S]*?\}/',
            $config,
        );
        self::assertStringNotContainsString(
            "quic reuseport;\n",
            $config,
        );
        self::assertSame(
            1,
            \substr_count(
                $config,
                'listen 0.0.0.0:' . $httpsPort
                    . ' quic reuseport default_server;',
            ),
        );
        self::assertSame(
            1,
            \substr_count(
                $config,
                'listen 0.0.0.0:' . $httpsPort . ' quic;',
            ),
        );
        $tenantServerName = 'server_name ' . $domain . ';';
        $tenantServerNameOffset = \strrpos($config, $tenantServerName);
        self::assertIsInt($tenantServerNameOffset);
        $tenantPrefix = \substr($config, 0, $tenantServerNameOffset);
        $tenantStart = \strrpos($tenantPrefix, '  server {');
        self::assertIsInt($tenantStart);
        $tenantEnd = \strpos($config, "\n  }", $tenantServerNameOffset);
        self::assertIsInt($tenantEnd);
        $tenantServer = \substr(
            $config,
            $tenantStart,
            $tenantEnd + 4 - $tenantStart,
        );
        self::assertStringContainsString(
            'listen 0.0.0.0:' . $httpsPort . ' quic;',
            $tenantServer,
        );
        self::assertStringNotContainsString('reuseport', $tenantServer);
        self::assertStringNotContainsString('default_server', $tenantServer);
        self::assertTrue($stateProperty->getValue($this->controller)['h3_enabled']);

        $repositoryRoot = \dirname(__DIR__, 9);
        $nginx = \trim((string)\getenv('WLS_TEST_NGINX_BINARY'));
        if ($nginx === '') {
            $nginx = $repositoryRoot . DIRECTORY_SEPARATOR
                . 'var/server/nginx-build/nginx-1.30.4/objs/nginx';
        }
        if (\is_file($nginx) && \is_executable($nginx)) {
            $configPath = $this->home . DIRECTORY_SEPARATOR
                . 'runtime/conf/h3-neutral-nginx.conf';
            self::assertNotFalse(\file_put_contents($configPath, $config));
            $nginxPrefix = $this->home . DIRECTORY_SEPARATOR
                . 'runtime/nginx-test-prefix';
            self::assertTrue(\mkdir(
                $nginxPrefix . DIRECTORY_SEPARATOR . 'logs',
                0700,
                true,
            ));
            $process = \proc_open(
                [
                    $nginx,
                    '-p',
                    $nginxPrefix . DIRECTORY_SEPARATOR,
                    '-t',
                    '-c',
                    $configPath,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repositoryRoot,
            );
            self::assertIsResource($process);
            self::assertIsArray($pipes);
            \fclose($pipes[0]);
            $stdout = (string)\stream_get_contents($pipes[1]);
            $stderr = (string)\stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            self::assertSame(
                0,
                \proc_close($process),
                $stdout . $stderr,
            );
            self::assertStringContainsString(
                'test is successful',
                $stdout . $stderr,
            );
        }
    }

    public function testPublicRouteProbeProofNeverRefreshesTenantLeases(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174035';
        $activeId = 'instance-published';
        $staleId = 'instance-stale';
        $active = $this->instanceLease($activeId, 29110, 'stateless');
        $active['last_heartbeat'] = \time() - 100;
        $active['last_heartbeat_monotonic'] -= 100.0;
        $stale = $this->instanceLease($staleId, 29111, 'stateless');
        $stale['status'] = 'STALE';
        $stale['last_heartbeat'] = \time() - 200;
        $stale['last_heartbeat_monotonic'] -= 200.0;

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 51,
        ];
        $state['instances'][$projectUuid] = [
            $activeId => $active,
            $staleId => $stale,
        ];
        $state['routes'][\str_repeat('6', 32)] = [
            'route_id' => \str_repeat('6', 32),
            'project_uuid' => $projectUuid,
            'domain' => 'published.example.test',
            'enrollment_security_generation' => 51,
            'status' => 'ACTIVE',
            'instance_id' => $activeId,
            'instances' => [$activeId => $active],
            'last_heartbeat' => $active['last_heartbeat'],
        ];
        $state['routes'][\str_repeat('7', 32)] = [
            'route_id' => \str_repeat('7', 32),
            'project_uuid' => $projectUuid,
            'domain' => 'stale.example.test',
            'enrollment_security_generation' => 51,
            'status' => 'STALE',
            'instance_id' => $staleId,
            'instances' => [$staleId => $stale],
            'last_heartbeat' => $stale['last_heartbeat'],
        ];
        $stateProperty->setValue($this->controller, $state);

        $recordProbe = new \ReflectionMethod(
            $this->controller,
            'recordPublicRouteProbeResult',
        );
        $recordProbe->invoke(
            $this->controller,
            $state['routes'][\str_repeat('6', 32)],
            true,
            false,
            '',
        );
        $recordProbe->invoke(
            $this->controller,
            $state['routes'][\str_repeat('7', 32)],
            false,
            false,
            'backend_transport',
        );
        $afterProbe = $stateProperty->getValue($this->controller);
        self::assertSame(
            $active['last_heartbeat'],
            $afterProbe['instances'][$projectUuid][$activeId]['last_heartbeat'],
        );
        self::assertSame(
            $active['last_heartbeat_monotonic'],
            $afterProbe['routes'][\str_repeat('6', 32)]['instances'][$activeId]['last_heartbeat_monotonic'],
        );
        self::assertSame(
            $stale['last_heartbeat'],
            $afterProbe['instances'][$projectUuid][$staleId]['last_heartbeat'],
        );
        self::assertSame(
            $stale['last_heartbeat_monotonic'],
            $afterProbe['routes'][\str_repeat('7', 32)]['instances'][$staleId]['last_heartbeat_monotonic'],
        );
        $probeResults = (new \ReflectionProperty(
            $this->controller,
            'publicRouteProbeResults',
        ))->getValue($this->controller);
        self::assertTrue($probeResults[\str_repeat('6', 32)]['success']);
        self::assertFalse($probeResults[\str_repeat('7', 32)]['success']);
    }

    public function testTimedOutPublicRouteProbeAdvancesWithoutStarvingLaterTenant(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174036';
        $domain = 'slow-probe.example.test';
        $source = $this->createCertificate($project, $domain, 'slow-probe');
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 61,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $certificate = (new \ReflectionMethod($this->controller, 'snapshotCertificate'))
            ->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $domain,
                $this->activeCertificateEnvelope($domain, $sourceDigest, [
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'slow-probe/fullchain.pem',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'slow-probe/privkey.pem',
                    ],
                    'generation' => 1,
                ]),
            );

        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        self::assertMatchesRegularExpression('/:[0-9]+\z/D', $endpoint);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        $backend = ['host' => '127.0.0.1', 'port' => 29536, 'weight' => 1];
        $identity = ['generation' => 1];
        $route = [
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'enrollment_security_generation' => 61,
            'domain_security_generation' => 0,
            'status' => 'ACTIVE',
            'route_generation' => 1,
            'certificate' => $certificate,
            'instance_id' => 'slow-probe',
            'backends' => [$backend],
            'backend_identity' => $identity,
            'backend_instances' => [
                'slow-probe' => [
                    'instance_id' => 'slow-probe',
                    'backends' => [$backend],
                    'backend_identity' => $identity,
                ],
            ],
        ];
        $firstRouteId = \str_repeat('1', 32);
        $secondRouteId = \str_repeat('2', 32);
        $firstRoute = ['route_id' => $firstRouteId] + $route;
        $secondRoute = ['route_id' => $secondRouteId] + $route;
        $state = $stateProperty->getValue($this->controller);
        $state['isolation_mode'] = false;
        $state['active_config_generation'] = 0;
        $state['active_routes'] = [];
        $state['public_https'] = $port;
        $state['routes'] = [
            $firstRouteId => $firstRoute,
            $secondRouteId => $secondRoute,
        ];
        $this->installCertificateFloor(
            $state,
            $projectUuid,
            $domain,
            $certificate,
        );
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionProperty($this->controller, 'publicRouteProbeCursor'))
            ->setValue($this->controller, 0);
        (new \ReflectionProperty($this->controller, 'publicRouteProbeResults'))
            ->setValue($this->controller, []);
        (new \ReflectionProperty($this->controller, 'publicRouteProbeSetDigest'))
            ->setValue($this->controller, '');
        $probe = new \ReflectionMethod($this->controller, 'publicRoutesReachable');

        try {
            self::assertFalse($probe->invoke(
                $this->controller,
                false,
                \hrtime(true) / 1_000_000_000 - 1.0,
                null,
            ));
            self::assertSame(
                'deferred',
                (new \ReflectionProperty($this->controller, 'lastPublicProbeFailureKind'))
                    ->getValue($this->controller),
            );
            self::assertSame(
                0,
                (new \ReflectionProperty($this->controller, 'publicRouteProbeCursor'))
                    ->getValue($this->controller),
                'A route whose external probe never began must retain its cursor.',
            );

            self::assertFalse($probe->invoke(
                $this->controller,
                false,
                \hrtime(true) / 1_000_000_000 + 2.0,
                null,
            ));
            self::assertSame(
                2,
                (new \ReflectionProperty($this->controller, 'publicRouteProbeCursor'))
                    ->getValue($this->controller),
                'The bounded TLS batch must advance every attempted tenant.',
            );
            $results = (new \ReflectionProperty(
                $this->controller,
                'publicRouteProbeResults',
            ))->getValue($this->controller);
            self::assertArrayHasKey($firstRouteId, $results);
            self::assertFalse($results[$firstRouteId]['success']);
            self::assertArrayHasKey($secondRouteId, $results);
            self::assertFalse($results[$secondRouteId]['success']);
        } finally {
            \fclose($server);
        }
    }

    public function testPublicRouteProbeCovers2048MixedTlsAndHttpTargetsWithinMinute(): void
    {
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174039';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 71,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $snapshot = new \ReflectionMethod(
            $this->controller,
            'snapshotCertificate',
        );
        $sharedKey = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $sharedKey);
        $certificates = [];
        for ($group = 0; $group < 256; ++$group) {
            $wildcard = '*.group-' . $group . '.capacity-public.example.test';
            $source = $this->createCertificateWithPrivateKey(
                $project,
                $wildcard,
                'capacity-public-' . $group,
                $sharedKey,
            );
            $sourceDigest = \hash(
                'sha256',
                \hash_file('sha256', $source['cert'])
                    . ':' . \hash_file('sha256', $source['key']) . ':',
            );
            $certificates[$group] = $snapshot->invoke(
                $this->controller,
                $projectUuid,
                $project,
                $wildcard,
                $this->activeCertificateEnvelope($wildcard, $sourceDigest, [
                    'cert' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'capacity-public-' . $group
                            . '/fullchain.pem',
                    ],
                    'key' => [
                        'root_alias' => 'project_ssl',
                        'relative_path' => 'capacity-public-' . $group
                            . '/privkey.pem',
                    ],
                    'generation' => 1,
                ]),
            );
            self::assertSame(
                2,
                $certificates[$group]['snapshot_manifest_schema'],
            );
        }
        self::assertCount(
            256,
            \array_unique(\array_column($certificates, 'snapshot_digest')),
            'Capacity coverage must use many independent real certificate snapshots.',
        );
        $slowTls = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($slowTls, $error);
        $slowEndpoint = (string)\stream_socket_get_name($slowTls, false);
        $slowPort = (int)\substr(
            $slowEndpoint,
            (int)\strrpos($slowEndpoint, ':') + 1,
        );
        self::assertGreaterThanOrEqual(9502, $slowPort);
        $refusedHttp = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $refusedErrno,
            $refusedError,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($refusedHttp, $refusedError);
        $refusedEndpoint = (string)\stream_socket_get_name($refusedHttp, false);
        $refusedPort = (int)\substr(
            $refusedEndpoint,
            (int)\strrpos($refusedEndpoint, ':') + 1,
        );
        self::assertGreaterThanOrEqual(9502, $refusedPort);
        \fclose($refusedHttp);

        $previousProductionLimit = \getenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT');
        $previousTestLimit = \getenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
        $previousHighestOpenFd = \getenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD');
        \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT');
        \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
        \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=899');
        $backend = ['host' => '127.0.0.1', 'port' => 29600, 'weight' => 1];
        $identity = [
            'generation' => 1,
            'master_epoch' => 1,
            'launch_id' => \str_repeat('a', 32),
        ];
        $routes = [];
        for ($index = 0; $index < 2048; ++$index) {
            $certificateGroup = \intdiv($index, 2) % 256;
            $domain = 'route-' . $index . '.group-' . $certificateGroup
                . '.capacity-public.example.test';
            $routeId = \substr(\hash('sha256', 'public-route-' . $index), 0, 32);
            $instanceId = 'public-capacity-' . $index;
            $routeCertificate = $this->activeCertificateEnvelope(
                $domain,
                (string)$certificates[$certificateGroup]['source_digest'],
                $certificates[$certificateGroup],
            );
            $status = 'ACTIVE';
            $forceHttps = true;
            if ($index % 2 === 1) {
                $status = 'PENDING_CERTIFICATE';
                $forceHttps = false;
                $routeCertificate = $this->disabledCertificateEnvelope($domain);
            }
            $routes[$routeId] = [
                'route_id' => $routeId,
                'project_uuid' => $projectUuid,
                'domain' => $domain,
                'enrollment_security_generation' => 71,
                'domain_security_generation' => 0,
                'status' => $status,
                'force_https' => $forceHttps,
                'route_generation' => 1,
                'certificate' => $routeCertificate,
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
            ];
        }
        $state = $stateProperty->getValue($this->controller);
        $state['isolation_mode'] = false;
        $state['active_config_generation'] = 0;
        $state['active_routes'] = [];
        $state['public_http'] = $refusedPort;
        $state['public_https'] = $slowPort;
        $state['routes'] = $routes;
        foreach ($routes as $route) {
            $this->installCertificateFloor(
                $state,
                $projectUuid,
                (string)$route['domain'],
                (array)$route['certificate'],
            );
        }
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionProperty($this->controller, 'publicRouteProbeCursor'))
            ->setValue($this->controller, 0);
        (new \ReflectionProperty($this->controller, 'publicRouteProbeResults'))
            ->setValue($this->controller, []);
        (new \ReflectionProperty($this->controller, 'publicRouteProbeSetDigest'))
            ->setValue($this->controller, '');
        (new \ReflectionProperty(
            $this->controller,
            'publicCertificateSnapshotParseCount',
        ))->setValue($this->controller, 0);
        $probe = new \ReflectionMethod($this->controller, 'publicRoutesReachable');

        try {
            $started = \hrtime(true);
            $calls = 0;
            do {
                $probe->invoke(
                    $this->controller,
                    false,
                    \hrtime(true) / 1_000_000_000 + 4.0,
                    null,
                );
                ++$calls;
                $results = (new \ReflectionProperty(
                    $this->controller,
                    'publicRouteProbeResults',
                ))->getValue($this->controller);
            } while (\count($results) < 2048 && $calls < 14);
            $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
            self::assertCount(2048, $results);
            self::assertLessThan(60.0, $elapsed);
            self::assertLessThanOrEqual(12, $calls);
            self::assertLessThanOrEqual(
                60.0,
                ($calls - 1) * 5.0 + 4.0,
                'The low-FD sweep must close within the real five-second maintenance cadence.',
            );
            $peak = (new \ReflectionProperty(
                $this->controller,
                'lastConcurrentProbePeak',
            ))->getValue($this->controller);
            self::assertGreaterThan(1, $peak);
            self::assertLessThanOrEqual(60, $peak);
            $failed = \array_filter(
                $results,
                static fn (array $result): bool => !$result['success'],
            );
            self::assertCount(2048, $failed);
            self::assertSame(
                0,
                (new \ReflectionProperty(
                    $this->controller,
                    'publicCertificateSnapshotParseCount',
                ))->getValue($this->controller),
                'Hot route probes must consume publication-bound certificate facts without reparsing snapshot files.',
            );
        } finally {
            \fclose($slowTls);
            $previousProductionLimit === false
                ? \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT')
                : \putenv(
                    'WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=' . $previousProductionLimit,
                );
            $previousTestLimit === false
                ? \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT')
                : \putenv(
                    'WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT=' . $previousTestLimit,
                );
            $previousHighestOpenFd === false
                ? \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD')
                : \putenv(
                    'WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=' . $previousHighestOpenFd,
                );
        }
    }

    public function testConcurrentPublicTlsProbeKeepsCertificateHostAndNonceProof(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The concurrent TLS probe test requires pcntl.');
        }
        $project = $this->createProject();
        $projectUuid = '123e4567-e89b-42d3-a456-426614174040';
        $wildcard = '*.concurrent-public.example.test';
        $source = $this->createCertificate(
            $project,
            $wildcard,
            'concurrent-public',
        );
        $sourceDigest = \hash(
            'sha256',
            \hash_file('sha256', $source['cert'])
                . ':' . \hash_file('sha256', $source['key']) . ':',
        );
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 72,
            'certificate_roots' => [
                'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        $certificate = (new \ReflectionMethod(
            $this->controller,
            'snapshotCertificate',
        ))->invoke(
            $this->controller,
            $projectUuid,
            $project,
            $wildcard,
            $this->activeCertificateEnvelope($wildcard, $sourceDigest, [
                'cert' => [
                    'root_alias' => 'project_ssl',
                    'relative_path' => 'concurrent-public/fullchain.pem',
                ],
                'key' => [
                    'root_alias' => 'project_ssl',
                    'relative_path' => 'concurrent-public/privkey.pem',
                ],
                'generation' => 1,
            ]),
        );
        $context = \stream_context_create([
            'ssl' => [
                'local_cert' => $source['cert'],
                'local_pk' => $source['key'],
                'verify_peer' => false,
                'allow_self_signed' => true,
                'disable_compression' => true,
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
            ],
        ]);
        $server = \stream_socket_server(
            'tls://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);

        $domains = [
            'one.concurrent-public.example.test' => 'public-one',
            'two.concurrent-public.example.test' => 'public-two',
        ];
        $launchId = \str_repeat('b', 32);
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $remainingDomains = $domains;
            for ($accepted = 0; $accepted < 2; ++$accepted) {
                $client = @\stream_socket_accept($server, 3.0);
                if (!\is_resource($client)) {
                    exit(70);
                }
                \stream_set_timeout($client, 2);
                $request = '';
                while (!\str_contains($request, "\r\n\r\n")) {
                    $chunk = @\fread($client, 8192);
                    if (!\is_string($chunk) || $chunk === '') {
                        @\fclose($client);
                        exit(71);
                    }
                    $request .= $chunk;
                    if (\strlen($request) > 65536) {
                        @\fclose($client);
                        exit(72);
                    }
                }
                if (\preg_match(
                    '/\AGET \/__wls_gateway_sentinel\?nonce=([a-f0-9]{32}) HTTP\/1\.1\r\n/D',
                    $request,
                    $nonceMatch,
                ) !== 1
                    || \preg_match(
                        '/\r\nHost: ([a-z0-9.-]+)\r\n/D',
                        $request,
                        $hostMatch,
                    ) !== 1
                ) {
                    @\fclose($client);
                    exit(73);
                }
                $nonce = (string)$nonceMatch[1];
                $domain = (string)$hostMatch[1];
                $instanceId = $remainingDomains[$domain] ?? null;
                if (!\is_string($instanceId)) {
                    @\fclose($client);
                    exit(74);
                }
                unset($remainingDomains[$domain]);
                $body = \json_encode([
                    'instance' => $instanceId,
                    'launch_id' => $launchId,
                    'master_epoch' => 1,
                    'nonce' => $nonce,
                    'status' => 'healthy',
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $response = "HTTP/1.1 200 OK\r\n"
                    . 'Content-Length: ' . \strlen($body) . "\r\n"
                    . "Connection: close\r\n"
                    . "X-WLS-Project-UUID: {$projectUuid}\r\n"
                    . "X-WLS-Instance-ID: {$instanceId}\r\n"
                    . "X-WLS-Backend-Generation: 1\r\n"
                    . "X-WLS-Probe-Nonce: {$nonce}\r\n\r\n"
                    . $body;
                @\fwrite($client, $response);
                @\fclose($client);
            }
            @\fclose($server);
            exit(0);
        }

        $backend = ['host' => '127.0.0.1', 'port' => 29601, 'weight' => 1];
        $jobs = [];
        $expectationMethod = new \ReflectionMethod(
            $this->controller,
            'publicRouteProbeExpectation',
        );
        $state = $stateProperty->getValue($this->controller);
        $state['public_https'] = $port;
        $stateProperty->setValue($this->controller, $state);
        foreach ($domains as $domain => $instanceId) {
            $routeId = \substr(\hash('sha256', $domain), 0, 32);
            $routeCertificate = $this->activeCertificateEnvelope(
                $domain,
                (string)$certificate['source_digest'],
                $certificate,
            );
            $identity = [
                'generation' => 1,
                'master_epoch' => 1,
                'launch_id' => $launchId,
            ];
            $route = [
                'route_id' => $routeId,
                'project_uuid' => $projectUuid,
                'domain' => $domain,
                'status' => 'ACTIVE',
                'certificate' => $routeCertificate,
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
            ];
            $expectation = $expectationMethod->invoke(
                $this->controller,
                $route,
                false,
            );
            self::assertIsArray($expectation);
            $jobs[] = [
                'route' => $route,
                'kind' => 'https',
                'expectation' => $expectation,
            ];
        }
        try {
            $results = (new \ReflectionMethod(
                $this->controller,
                'probePublicRouteBatchConcurrent',
            ))->invoke(
                $this->controller,
                $jobs,
                false,
                \hrtime(true) / 1_000_000_000 + 3.0,
            );
            \pcntl_waitpid($pid, $status);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
            self::assertCount(2, $results);
            foreach ($results as $result) {
                self::assertTrue($result['success']);
                self::assertSame('', $result['failure_kind']);
            }
            self::assertSame(
                2,
                (new \ReflectionProperty(
                    $this->controller,
                    'lastConcurrentProbePeak',
                ))->getValue($this->controller),
            );
        } finally {
            @\fclose($server);
            if (@\posix_kill($pid, 0)) {
                @\posix_kill($pid, SIGTERM);
                @\pcntl_waitpid($pid, $ignored);
            }
        }
    }

    public function testTimedOutBackendProbeAdvancesWithoutStarvingLaterTarget(): void
    {
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        self::assertMatchesRegularExpression('/:[0-9]+\z/D', $endpoint);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);

        $projectUuid = '123e4567-e89b-42d3-a456-426614174037';
        $launchId = \str_repeat('d', 32);
        $edgeSecret = \str_repeat('a', 64);
        $slowIdentity = $this->signedBackendIdentity(
            $projectUuid,
            'slow-backend',
            1,
            1,
            $launchId,
            $edgeSecret,
        );
        $slowBackend = ['host' => '127.0.0.1', 'port' => $port, 'weight' => 1];
        $slowInstance = [
            'instance_id' => 'slow-backend',
            'generation' => 1,
            'digest' => \str_repeat('b', 64),
            'master_epoch' => 1,
            'launch_id' => $launchId,
            'backends' => [$slowBackend],
            'backend_identity' => $slowIdentity,
            'backend_healthy' => true,
            'status' => 'ACTIVE',
            'last_heartbeat' => \time(),
            'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
            'lease_boot_id' => $this->hostBootId(),
        ];
        $invalidIdentity = $slowIdentity;
        $invalidIdentity['instance_id'] = 'later-backend';
        $invalidIdentity['edge_capability_secret'] = 'invalid';
        $laterBackend = ['host' => '127.0.0.1', 'port' => 29537, 'weight' => 1];
        $laterInstance = [
            'instance_id' => 'later-backend',
            'generation' => 1,
            'digest' => \str_repeat('c', 64),
            'master_epoch' => 1,
            'launch_id' => $launchId,
            'backends' => [$laterBackend],
            'backend_identity' => $invalidIdentity,
            'backend_healthy' => false,
            'status' => 'ACTIVE',
            'last_heartbeat' => \time(),
            'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
            'lease_boot_id' => $this->hostBootId(),
        ];
        $pendingCertificate = [
            'state' => 'pending',
            'valid' => false,
            'pending' => true,
            'generation' => 0,
            'source_digest' => \hash(
                'sha256',
                "wls-pending-certificate\0backend-probe.example.test",
            ),
        ];
        $slowRouteId = \str_repeat('3', 32);
        $laterRouteId = \str_repeat('4', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['routes'] = [
            $slowRouteId => [
                'route_id' => $slowRouteId,
                'project_uuid' => $projectUuid,
                'domain' => 'backend-probe.example.test',
                'status' => 'PENDING_CERTIFICATE',
                'certificate' => $pendingCertificate,
                'instances' => ['slow-backend' => $slowInstance],
                'preferred_instance_id' => 'slow-backend',
                'instance_id' => 'slow-backend',
                'backends' => [$slowBackend],
                'backend_identity' => $slowIdentity,
                'backend_instances' => [
                    'slow-backend' => [
                        'instance_id' => 'slow-backend',
                        'backends' => [$slowBackend],
                        'backend_identity' => $slowIdentity,
                    ],
                ],
                'distribution_mode' => 'single',
            ],
            $laterRouteId => [
                'route_id' => $laterRouteId,
                'project_uuid' => $projectUuid,
                'domain' => 'backend-probe-later.example.test',
                'status' => 'PENDING_CERTIFICATE',
                'certificate' => $pendingCertificate,
                'instances' => ['later-backend' => $laterInstance],
                'preferred_instance_id' => '',
                'instance_id' => '',
                'backends' => [],
                'backend_identity' => [],
                'backend_instances' => [],
                'distribution_mode' => 'single',
            ],
        ];
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionProperty($this->controller, 'backendProbeCursor'))
            ->setValue($this->controller, 0);
        $probe = new \ReflectionMethod($this->controller, 'probeActiveBackends');

        try {
            self::assertTrue($probe->invoke($this->controller));
            $afterSlow = $stateProperty->getValue($this->controller);
            self::assertSame(
                'transport',
                $afterSlow['routes'][$slowRouteId]['instances']['slow-backend'][
                    'last_backend_probe_failure_kind'
                ] ?? '',
                'An attempted listener timeout must be concrete transport evidence.',
            );
            self::assertSame(
                0,
                (new \ReflectionProperty($this->controller, 'backendProbeCursor'))
                    ->getValue($this->controller),
                'The bounded concurrent sweep must finish both targets.',
            );
            $afterLater = $stateProperty->getValue($this->controller);
            self::assertSame(
                'identity',
                $afterLater['routes'][$laterRouteId]['instances']['later-backend'][
                    'last_backend_probe_failure_kind'
                ] ?? '',
                'The later target must not wait behind a timed-out listener.',
            );
        } finally {
            \fclose($server);
        }
    }

    public function testBackendProbeCovers2048MixedSlowAndRefusedTargetsWithinMinute(): void
    {
        $slowServer = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($slowServer, $error);
        $slowEndpoint = (string)\stream_socket_get_name($slowServer, false);
        $slowPort = (int)\substr(
            $slowEndpoint,
            (int)\strrpos($slowEndpoint, ':') + 1,
        );
        self::assertGreaterThanOrEqual(9502, $slowPort);
        $refusedServer = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $refusedErrno,
            $refusedError,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($refusedServer, $refusedError);
        $refusedEndpoint = (string)\stream_socket_get_name($refusedServer, false);
        $refusedPort = (int)\substr(
            $refusedEndpoint,
            (int)\strrpos($refusedEndpoint, ':') + 1,
        );
        self::assertGreaterThanOrEqual(9502, $refusedPort);
        \fclose($refusedServer);

        $previousProductionLimit = \getenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT');
        $previousTestLimit = \getenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
        \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT');
        \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $projectUuid = '123e4567-e89b-42d3-a456-426614174038';
        $launchId = \str_repeat('e', 32);
        $edgeSecret = \str_repeat('f', 64);
        $pendingCertificate = [
            'state' => 'pending',
            'valid' => false,
            'pending' => true,
            'generation' => 0,
            'source_digest' => '',
        ];
        $state = $stateProperty->getValue($this->controller);
        $routes = [];
        for ($index = 0; $index < 2048; ++$index) {
            $routeId = \substr(\hash('sha256', 'capacity-route-' . $index), 0, 32);
            $instanceId = 'capacity-' . $index;
            $domain = 'capacity-' . $index . '.example.test';
            $backend = [
                'host' => '127.0.0.1',
                'port' => $index % 2 === 0 ? $slowPort : $refusedPort,
                'weight' => 1,
            ];
            $identity = $this->signedBackendIdentity(
                $projectUuid,
                $instanceId,
                1,
                1,
                $launchId,
                $edgeSecret,
            );
            $instance = [
                'instance_id' => $instanceId,
                'generation' => 1,
                'digest' => \hash('sha256', 'capacity-instance-' . $index),
                'master_epoch' => 1,
                'launch_id' => $launchId,
                'backends' => [$backend],
                'backend_identity' => $identity,
                'backend_healthy' => true,
                'status' => 'ACTIVE',
                'last_heartbeat' => \time(),
                'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
                'lease_boot_id' => $this->hostBootId(),
            ];
            $certificate = $pendingCertificate;
            $certificate['source_digest'] = \hash(
                'sha256',
                "wls-pending-certificate\0{$domain}",
            );
            $routes[$routeId] = [
                'route_id' => $routeId,
                'project_uuid' => $projectUuid,
                'domain' => $domain,
                'status' => 'PENDING_CERTIFICATE',
                'certificate' => $certificate,
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
        $state['routes'] = $routes;
        $stateProperty->setValue($this->controller, $state);
        (new \ReflectionProperty($this->controller, 'backendProbeCursor'))
            ->setValue($this->controller, 0);
        $probe = new \ReflectionMethod($this->controller, 'probeActiveBackends');

        try {
            $started = \hrtime(true);
            $calls = 0;
            do {
                $probe->invoke($this->controller);
                ++$calls;
                $cursor = (new \ReflectionProperty(
                    $this->controller,
                    'backendProbeCursor',
                ))->getValue($this->controller);
            } while ($cursor !== 0 && $calls < 12);
            $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
            self::assertSame(0, $cursor, 'The complete 2048-target sweep must close.');
            self::assertLessThan(60.0, $elapsed);
            self::assertGreaterThanOrEqual(1, $calls);
            self::assertLessThanOrEqual(12, $calls);
            $peak = (new \ReflectionProperty(
                $this->controller,
                'lastConcurrentProbePeak',
            ))->getValue($this->controller);
            self::assertGreaterThan(1, $peak);
            self::assertLessThanOrEqual(512, $peak);
            $after = $stateProperty->getValue($this->controller);
            $covered = 0;
            foreach ($routes as $routeId => $route) {
                $instanceId = (string)$route['instance_id'];
                if ((string)(
                    $after['routes'][$routeId]['instances'][$instanceId][
                        'last_backend_probe_failure_kind'
                    ] ?? ''
                ) === 'transport') {
                    ++$covered;
                }
            }
            self::assertSame(2048, $covered);
        } finally {
            \fclose($slowServer);
            $previousProductionLimit === false
                ? \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT')
                : \putenv(
                    'WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=' . $previousProductionLimit,
                );
            $previousTestLimit === false
                ? \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT')
                : \putenv(
                    'WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT=' . $previousTestLimit,
                );
        }
    }

    public function testConcurrentProbeDescriptorFenceIsBoundedAndConfigurable(): void
    {
        $production = \getenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT');
        $test = \getenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
        $openFileLimit = \getenv('WLS_GATEWAY_TEST_OPEN_FILE_LIMIT');
        $openFileUsage = \getenv('WLS_GATEWAY_TEST_OPEN_FILE_USAGE');
        $highestOpenFd = \getenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD');
        $method = new \ReflectionMethod($this->controller, 'probeMaxInFlight');
        try {
            \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT');
            \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
            \putenv('WLS_GATEWAY_TEST_OPEN_FILE_LIMIT');
            \putenv('WLS_GATEWAY_TEST_OPEN_FILE_USAGE');
            \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=2');
            self::assertSame(512, $method->invoke($this->controller));

            \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=600');
            self::assertSame(600, $method->invoke($this->controller));

            \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=64');
            self::assertSame(
                64,
                $method->invoke($this->controller),
                'An administrator may lower the production batch fence.',
            );

            \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=64');
            \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT=17');
            self::assertSame(
                17,
                $method->invoke($this->controller),
                'Embedded tests may lower the fence without weakening production capacity.',
            );

            \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=769');
            \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
            self::assertSame(512, $method->invoke($this->controller));

            \putenv('WLS_GATEWAY_TEST_OPEN_FILE_LIMIT=192');
            \putenv('WLS_GATEWAY_TEST_OPEN_FILE_USAGE=32');
            self::assertSame(
                96,
                $method->invoke($this->controller),
                'RLIMIT minus live usage and the control/recovery reserve is authoritative.',
            );

            \putenv('WLS_GATEWAY_TEST_OPEN_FILE_LIMIT');
            \putenv('WLS_GATEWAY_TEST_OPEN_FILE_USAGE');
            \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=899');
            self::assertSame(
                60,
                $method->invoke($this->controller),
                'A high numeric descriptor must shrink the select-safe pool.',
            );
            \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=959');
            self::assertSame(
                0,
                $method->invoke($this->controller),
                'No select-safe descriptor is represented as DEFERRED capacity.',
            );
        } finally {
            $production === false
                ? \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT')
                : \putenv('WLS_GATEWAY_PROBE_MAX_IN_FLIGHT=' . $production);
            $test === false
                ? \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT')
                : \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT=' . $test);
            $openFileLimit === false
                ? \putenv('WLS_GATEWAY_TEST_OPEN_FILE_LIMIT')
                : \putenv('WLS_GATEWAY_TEST_OPEN_FILE_LIMIT=' . $openFileLimit);
            $openFileUsage === false
                ? \putenv('WLS_GATEWAY_TEST_OPEN_FILE_USAGE')
                : \putenv('WLS_GATEWAY_TEST_OPEN_FILE_USAGE=' . $openFileUsage);
            $highestOpenFd === false
                ? \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD')
                : \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=' . $highestOpenFd);
        }
    }

    public function testSelectDescriptorFenceDefersAndRecoversWithoutFailureEvidence(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The descriptor recovery test requires pcntl.');
        }
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        $identity = $this->signedBackendIdentity(
            '123e4567-e89b-42d3-a456-426614174047',
            'select-fence-recovery',
            1,
            1,
            \str_repeat('8', 32),
            \str_repeat('9', 64),
        );
        $pid = $this->forkAuthenticatedBackendProbeResponder(
            $server,
            $identity,
            $port,
            1,
        );
        $target = [[
            'key' => 'select-fence-recovery',
            'backends' => [[
                'host' => '127.0.0.1',
                'port' => $port,
                'weight' => 1,
            ]],
            'identity' => $identity,
        ]];
        $highestFd = \getenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD');
        try {
            $probe = new \ReflectionMethod(
                $this->controller,
                'probeBackendTargetBatchConcurrent',
            );
            \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=959');
            $deferred = $probe->invoke(
                $this->controller,
                $target,
                \hrtime(true) / 1_000_000_000 + 1.0,
            );
            self::assertSame('DEFERRED', $deferred['select-fence-recovery']['state']);
            self::assertSame(
                'deferred',
                $deferred['select-fence-recovery']['failure_kind'],
            );
            self::assertSame(
                0,
                (new \ReflectionProperty(
                    $this->controller,
                    'lastConcurrentProbePeak',
                ))->getValue($this->controller),
            );

            \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=2');
            $healthy = $probe->invoke(
                $this->controller,
                $target,
                \hrtime(true) / 1_000_000_000 + 2.0,
            );
            self::assertSame('HEALTHY', $healthy['select-fence-recovery']['state']);
            self::assertSame('', $healthy['select-fence-recovery']['failure_kind']);
            \pcntl_waitpid($pid, $status);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            $highestFd === false
                ? \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD')
                : \putenv('WLS_GATEWAY_TEST_HIGHEST_OPEN_FD=' . $highestFd);
            @\fclose($server);
            if (@\posix_kill($pid, 0)) {
                @\posix_kill($pid, SIGTERM);
                @\pcntl_waitpid($pid, $ignored);
            }
        }
    }

    public function testExpiredSharedProbeDeadlineDefersEveryUnstartedEndpoint(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $beforeState = $stateProperty->getValue($this->controller);
        $expiredDeadline = \hrtime(true) / 1_000_000_000 - 1.0;

        $publicResults = (new \ReflectionMethod(
            $this->controller,
            'probePublicRouteBatchConcurrent',
        ))->invoke(
            $this->controller,
            [
                [
                    'route' => ['route_id' => 'expired-public-a'],
                    'kind' => 'https',
                    'expectation' => ['domain' => 'expired-a.example.test'],
                ],
                [
                    'route' => ['route_id' => 'expired-public-b'],
                    'kind' => 'http',
                    'expectation' => ['domain' => 'expired-b.example.test'],
                ],
            ],
            false,
            $expiredDeadline,
        );
        self::assertSame(
            [
                'success' => false,
                'failure_kind' => 'deferred',
            ],
            $publicResults['expired-public-a'],
        );
        self::assertSame(
            [
                'success' => false,
                'failure_kind' => 'deferred',
            ],
            $publicResults['expired-public-b'],
        );

        $backendResults = (new \ReflectionMethod(
            $this->controller,
            'probeBackendTargetBatchConcurrent',
        ))->invoke(
            $this->controller,
            [
                [
                    'key' => 'expired-backend-a',
                    'backends' => [],
                    'identity' => [],
                ],
                [
                    'key' => 'expired-backend-b',
                    'backends' => [],
                    'identity' => [],
                ],
            ],
            $expiredDeadline,
        );
        self::assertSame(
            [
                'state' => 'DEFERRED',
                'failure_kind' => 'deferred',
            ],
            $backendResults['expired-backend-a'],
        );
        self::assertSame(
            [
                'state' => 'DEFERRED',
                'failure_kind' => 'deferred',
            ],
            $backendResults['expired-backend-b'],
        );

        $afterState = $stateProperty->getValue($this->controller);
        self::assertSame(
            $beforeState['failure_events'] ?? [],
            $afterState['failure_events'] ?? [],
            'Exhausted shared capacity is not recovery failure evidence.',
        );
    }

    public function testOverwideBackendTupleDoesNotStarveLaterHealthyTenant(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The backend fairness test requires pcntl.');
        }
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174043';
        $launchId = \str_repeat('1', 32);
        $secret = \str_repeat('2', 64);
        $laterIdentity = $this->signedBackendIdentity(
            $projectUuid,
            'later-healthy',
            1,
            1,
            $launchId,
            $secret,
        );
        $pid = $this->forkAuthenticatedBackendProbeResponder(
            $server,
            $laterIdentity,
            $port,
            1,
        );
        $backend = ['host' => '127.0.0.1', 'port' => $port, 'weight' => 1];
        $overwideIdentity = $this->signedBackendIdentity(
            $projectUuid,
            'overwide-first',
            1,
            1,
            $launchId,
            \str_repeat('3', 64),
        );
        $overwideBackends = [$backend, $backend, $backend];
        $instance = function (
            string $instanceId,
            array $backends,
            array $identity,
            bool $healthy,
        ): array {
            return [
                'instance_id' => $instanceId,
                'generation' => 1,
                'digest' => \hash('sha256', $instanceId),
                'master_epoch' => 1,
                'launch_id' => \str_repeat('1', 32),
                'backends' => $backends,
                'backend_identity' => $identity,
                'backend_healthy' => $healthy,
                'status' => 'ACTIVE',
                'last_heartbeat' => \time(),
                'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
                'lease_boot_id' => $this->hostBootId(),
            ];
        };
        $overwideInstance = $instance(
            'overwide-first',
            $overwideBackends,
            $overwideIdentity,
            true,
        );
        $laterInstance = $instance(
            'later-healthy',
            [$backend],
            $laterIdentity,
            false,
        );
        $pendingCertificate = [
            'state' => 'pending',
            'valid' => false,
            'pending' => true,
            'generation' => 0,
            'source_digest' => \hash(
                'sha256',
                "wls-pending-certificate\0backend-fairness.example.test",
            ),
        ];
        $route = static function (
            string $routeId,
            string $domain,
            string $instanceId,
            array $current,
            array $certificate,
        ) use ($projectUuid): array {
            return [
                'route_id' => $routeId,
                'project_uuid' => $projectUuid,
                'domain' => $domain,
                'status' => 'PENDING_CERTIFICATE',
                'certificate' => $certificate,
                'instances' => [$instanceId => $current],
                'preferred_instance_id' => $instanceId,
                'instance_id' => $instanceId,
                'backends' => $current['backends'],
                'backend_identity' => $current['backend_identity'],
                'backend_instances' => [
                    $instanceId => [
                        'instance_id' => $instanceId,
                        'backends' => $current['backends'],
                        'backend_identity' => $current['backend_identity'],
                    ],
                ],
                'distribution_mode' => 'single',
            ];
        };
        $firstRouteId = \str_repeat('a', 32);
        $laterRouteId = \str_repeat('b', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['routes'] = [
            $firstRouteId => $route(
                $firstRouteId,
                'backend-fairness.example.test',
                'overwide-first',
                $overwideInstance,
                $pendingCertificate,
            ),
            $laterRouteId => $route(
                $laterRouteId,
                'backend-fairness-later.example.test',
                'later-healthy',
                $laterInstance,
                $pendingCertificate,
            ),
        ];
        $stateProperty->setValue($this->controller, $state);
        $previousLimit = \getenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT');
        try {
            \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT=2');
            self::assertTrue(
                (new \ReflectionMethod($this->controller, 'probeActiveBackends'))
                    ->invoke($this->controller),
                'The overwide tuple remains deferred and requests another sweep.',
            );
            $after = $stateProperty->getValue($this->controller);
            self::assertArrayNotHasKey(
                'last_backend_probe_failure_kind',
                $after['routes'][$firstRouteId]['instances']['overwide-first'],
                'Local descriptor capacity is not backend failure evidence.',
            );
            self::assertTrue(
                $after['routes'][$laterRouteId]['instances']['later-healthy'][
                    'backend_healthy'
                ],
                'A later tenant must still receive its healthy proof.',
            );
            self::assertSame(
                '',
                $after['routes'][$laterRouteId]['instances']['later-healthy'][
                    'last_backend_probe_failure_kind'
                ],
            );
            self::assertGreaterThan(
                0,
                $after['routes'][$laterRouteId]['instances']['later-healthy'][
                    'last_backend_probe_success'
                ],
            );
            self::assertSame(
                0,
                (new \ReflectionProperty($this->controller, 'backendProbeCursor'))
                    ->getValue($this->controller),
            );
            \pcntl_waitpid($pid, $status);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            $previousLimit === false
                ? \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT')
                : \putenv('WLS_GATEWAY_TEST_PROBE_MAX_IN_FLIGHT=' . $previousLimit);
            @\fclose($server);
            if (@\posix_kill($pid, 0)) {
                @\posix_kill($pid, SIGTERM);
                @\pcntl_waitpid($pid, $ignored);
            }
        }
    }

    public function testMidBatchDescriptorPressureDefersOnlyAffectedTupleAndRecovers(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The descriptor-pressure test requires pcntl.');
        }
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $endpoint = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        $identity = $this->signedBackendIdentity(
            '123e4567-e89b-42d3-a456-426614174044',
            'descriptor-pressure',
            1,
            1,
            \str_repeat('4', 32),
            \str_repeat('5', 64),
        );
        $pid = $this->forkAuthenticatedBackendProbeResponder(
            $server,
            $identity,
            $port,
            3,
        );
        $backend = ['host' => '127.0.0.1', 'port' => $port, 'weight' => 1];
        $failure = \getenv('WLS_GATEWAY_TEST_PROBE_DESCRIPTOR_FAILURE_AFTER');
        try {
            \putenv('WLS_GATEWAY_TEST_PROBE_DESCRIPTOR_FAILURE_AFTER=1');
            $probe = new \ReflectionMethod(
                $this->controller,
                'probeBackendTargetBatchConcurrent',
            );
            $targets = [];
            foreach (['first', 'descriptor-deferred', 'later'] as $key) {
                $targets[] = [
                    'key' => $key,
                    'backends' => [$backend],
                    'identity' => $identity,
                ];
            }
            $results = $probe->invoke(
                $this->controller,
                $targets,
                \hrtime(true) / 1_000_000_000 + 2.0,
            );
            self::assertSame('HEALTHY', $results['first']['state']);
            self::assertSame('DEFERRED', $results['descriptor-deferred']['state']);
            self::assertSame('deferred', $results['descriptor-deferred']['failure_kind']);
            self::assertSame(
                'HEALTHY',
                $results['later']['state'],
                'A one-shot EMFILE must not starve a later tuple in the batch.',
            );

            \putenv('WLS_GATEWAY_TEST_PROBE_DESCRIPTOR_FAILURE_AFTER');
            $recovered = $probe->invoke(
                $this->controller,
                [[
                    'key' => 'descriptor-deferred',
                    'backends' => [$backend],
                    'identity' => $identity,
                ]],
                \hrtime(true) / 1_000_000_000 + 2.0,
            );
            self::assertSame('HEALTHY', $recovered['descriptor-deferred']['state']);
            self::assertSame('', $recovered['descriptor-deferred']['failure_kind']);
            \pcntl_waitpid($pid, $status);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            $failure === false
                ? \putenv('WLS_GATEWAY_TEST_PROBE_DESCRIPTOR_FAILURE_AFTER')
                : \putenv(
                    'WLS_GATEWAY_TEST_PROBE_DESCRIPTOR_FAILURE_AFTER=' . $failure,
                );
            @\fclose($server);
            if (@\posix_kill($pid, 0)) {
                @\posix_kill($pid, SIGTERM);
                @\pcntl_waitpid($pid, $ignored);
            }
        }
    }

    public function testConcurrentBackendProbeYieldsToAuthenticatedControlRequest(): void
    {
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174042',
            'concurrent-heartbeat',
            'concurrent-heartbeat.example.test',
        );
        (new \ReflectionMethod($this->controller, 'persistLeaseCheckpoint'))
            ->invoke($this->controller);
        $durable = new \ReflectionProperty(
            $this->controller,
            'durableLeaseMonotonic',
        );
        $durableMap = $durable->getValue($this->controller);
        foreach ($durableMap as $key => $_value) {
            $durableMap[$key] = \hrtime(true) / 1_000_000_000 - 31.0;
        }
        $durable->setValue($this->controller, $durableMap);
        $checkpointFile = $this->home . DIRECTORY_SEPARATOR
            . 'state/lease-checkpoint.json';
        $checkpointDigest = \hash_file('sha256', $checkpointFile);
        self::assertIsString($checkpointDigest);
        $controlSocket = $this->root . DIRECTORY_SEPARATOR
            . 'concurrent-probe-control.sock';
        $controlServerResource = \stream_socket_server(
            'unix://' . $controlSocket,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controlServerResource, $error);
        \stream_set_blocking($controlServerResource, false);
        $controlServer = new \ReflectionProperty($this->controller, 'controlServer');
        $controlServer->setValue($this->controller, $controlServerResource);
        $heartbeatPayload = [
            'project_uuid' => $fixture['project_uuid'],
            'project_generation' => $fixture['project_generation'],
            'instance_id' => $fixture['instance_id'],
            'instance_generation' => $fixture['instance_generation'],
            'instance_digest' => $fixture['instance_digest'],
            'master_epoch' => $fixture['master_epoch'],
            'launch_id' => $fixture['launch_id'],
            'gateway_epoch' => $fixture['gateway_epoch'],
            'host_boot_id' => $this->hostBootId(),
        ];
        $request = $this->signedRequest(
            'project',
            'heartbeat',
            $heartbeatPayload,
            $fixture['credential_id'],
            $fixture['credential_secret'],
        );
        $encoded = \json_encode(
            $request,
            JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $broker = \json_encode([
            'broker_schema' => 1,
            'action_protocol' => 2,
            'channel' => 'project',
            'uid' => (int)\posix_geteuid(),
            'gid' => (int)\posix_getegid(),
            'pid' => \getmypid(),
            'fencing_token' => $this->fencing,
            'payload_length' => \strlen($encoded),
        ], JSON_THROW_ON_ERROR) . "\n";
        $handshake = $this->brokerHandshake();
        $client = \stream_socket_client(
            'unix://' . $controlSocket,
            $clientErrno,
            $clientError,
            1.0,
        );
        self::assertIsResource($client, $clientError);
        \stream_set_timeout($client, 2);
        self::assertSame(
            \strlen($handshake['probe'] . $broker . $encoded),
            \fwrite($client, $handshake['probe'] . $broker . $encoded),
        );

        $slowServer = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $slowErrno,
            $slowError,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($slowServer, $slowError);
        $endpoint = (string)\stream_socket_get_name($slowServer, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174041';
        $instanceId = 'yielding-probe';
        $identity = $this->signedBackendIdentity(
            $projectUuid,
            $instanceId,
            1,
            1,
            \str_repeat('c', 32),
            \str_repeat('d', 64),
        );
        $backend = ['host' => '127.0.0.1', 'port' => $port, 'weight' => 1];
        $criticalSection = new \ReflectionProperty(
            $this->controller,
            'probeCriticalSectionDepth',
        );
        try {
            $criticalSection->setValue($this->controller, 1);
            $started = \hrtime(true);
            $results = (new \ReflectionMethod(
                $this->controller,
                'probeBackendTargetBatchConcurrent',
            ))->invoke(
                $this->controller,
                [[
                    'key' => \hash('sha256', 'yielding-probe'),
                    'backends' => [$backend],
                    'identity' => $identity,
                ]],
                \hrtime(true) / 1_000_000_000 + 2.0,
            );
            $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
            self::assertLessThan(2.0, $elapsed);
            self::assertSame('transport', \array_values($results)[0]['failure_kind']);
            self::assertSame($handshake['ready'], \fgets($client, 512));
            $response = \fgets($client, 4 * 1024 * 1024);
            self::assertIsString($response);
            $decoded = \json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($decoded['ok']);
            self::assertSame('heartbeat_deferred', $decoded['error']['code']);
            self::assertStringContainsString(
                'retry_after=1',
                $decoded['error']['message'],
            );
            self::assertSame(
                $checkpointDigest,
                \hash_file('sha256', $checkpointFile),
                'A stale nested heartbeat must not start an fsync checkpoint.',
            );
            self::assertTrue(
                (new \ReflectionProperty(
                    $this->controller,
                    'leaseCheckpointDirty',
                ))->getValue($this->controller),
            );
        } finally {
            $criticalSection->setValue($this->controller, 0);
            @\fclose($client);
            @\fclose($slowServer);
            @\fclose($controlServerResource);
            $controlServer->setValue($this->controller, null);
        }
    }

    public function testConcurrentBackendProbeServicesMixedControlAndRejectsMutationsWithinBound(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('The mixed control latency test requires pcntl.');
        }
        $fixture = $this->registerPendingCertificateLeaseForCheckpoint(
            '123e4567-e89b-42d3-a456-426614174045',
            'mixed-control',
            'mixed-control.example.test',
        );
        $heartbeatPayload = [
            'project_uuid' => $fixture['project_uuid'],
            'project_generation' => $fixture['project_generation'],
            'instance_id' => $fixture['instance_id'],
            'instance_generation' => $fixture['instance_generation'],
            'instance_digest' => $fixture['instance_digest'],
            'master_epoch' => $fixture['master_epoch'],
            'launch_id' => $fixture['launch_id'],
            'gateway_epoch' => $fixture['gateway_epoch'],
            'host_boot_id' => $this->hostBootId(),
        ];
        $requests = [
            'status' => $this->framedBrokerRequest('admin', $this->signedRequest(
                'admin',
                'status',
                [],
                'admin',
                $this->adminSecret,
            )),
            'heartbeat' => $this->framedBrokerRequest('project', $this->signedRequest(
                'project',
                'heartbeat',
                $heartbeatPayload,
                $fixture['credential_id'],
                $fixture['credential_secret'],
            )),
            'register' => $this->framedBrokerRequest('project', $this->signedRequest(
                'project',
                'register',
                ['project_uuid' => $fixture['project_uuid']],
                $fixture['credential_id'],
                $fixture['credential_secret'],
            )),
            'stop' => $this->framedBrokerRequest('admin', $this->signedRequest(
                'admin',
                'stop',
                ['confirm' => true, 'force' => true],
                'admin',
                $this->adminSecret,
            )),
        ];
        $controlSocket = $this->root . DIRECTORY_SEPARATOR
            . 'mixed-control-probe.sock';
        $controlServerResource = \stream_socket_server(
            'unix://' . $controlSocket,
            $controlErrno,
            $controlError,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controlServerResource, $controlError);
        \stream_set_blocking($controlServerResource, false);
        $controlServer = new \ReflectionProperty($this->controller, 'controlServer');
        $controlServer->setValue($this->controller, $controlServerResource);
        $responseFile = $this->root . DIRECTORY_SEPARATOR
            . 'mixed-control-responses.json';
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            @\fclose($controlServerResource);
            \usleep(30000);
            $responses = [];
            $allStarted = \microtime(true);
            foreach ($requests as $name => $frame) {
                $started = \microtime(true);
                $client = @\stream_socket_client(
                    'unix://' . $controlSocket,
                    $childErrno,
                    $childError,
                    1.0,
                );
                if (!\is_resource($client)) {
                    $responses[$name] = ['error' => $childError ?: (string)$childErrno];
                    continue;
                }
                \stream_set_timeout($client, 1);
                @\fwrite($client, $frame['wire']);
                $ready = @\fgets($client, 512);
                $line = @\fgets($client, 4 * 1024 * 1024);
                @\fclose($client);
                $responses[$name] = [
                    'elapsed_ms' => (\microtime(true) - $started) * 1000,
                    'ready' => $ready === $frame['ready'],
                    'action' => \is_string($line)
                        && \str_starts_with($line, "WLS-ACTION/2\t")
                            ? \rtrim($line, "\r\n")
                            : '',
                    'response' => \is_string($line)
                        && !\str_starts_with($line, "WLS-ACTION/2\t")
                            ? \json_decode($line, true)
                            : null,
                ];
            }
            $responses['_total_elapsed_ms'] = (\microtime(true) - $allStarted) * 1000;
            @\file_put_contents(
                $responseFile,
                \json_encode($responses, JSON_THROW_ON_ERROR),
            );
            exit(0);
        }

        $slowServer = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $slowErrno,
            $slowError,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($slowServer, $slowError);
        $endpoint = (string)\stream_socket_get_name($slowServer, false);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        self::assertGreaterThanOrEqual(9502, $port);
        $identity = $this->signedBackendIdentity(
            '123e4567-e89b-42d3-a456-426614174046',
            'mixed-control-slow-probe',
            1,
            1,
            \str_repeat('6', 32),
            \str_repeat('7', 64),
        );
        $criticalSection = new \ReflectionProperty(
            $this->controller,
            'probeCriticalSectionDepth',
        );
        try {
            $criticalSection->setValue($this->controller, 1);
            $probeResults = (new \ReflectionMethod(
                $this->controller,
                'probeBackendTargetBatchConcurrent',
            ))->invoke(
                $this->controller,
                [[
                    'key' => 'mixed-control-slow-probe',
                    'backends' => [[
                        'host' => '127.0.0.1',
                        'port' => $port,
                        'weight' => 1,
                    ]],
                    'identity' => $identity,
                ]],
                \hrtime(true) / 1_000_000_000 + 2.0,
            );
            self::assertSame(
                'transport',
                $probeResults['mixed-control-slow-probe']['failure_kind'],
            );
            \pcntl_waitpid($pid, $status);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
            $responses = \json_decode(
                (string)\file_get_contents($responseFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            foreach (['status', 'heartbeat', 'register', 'stop'] as $name) {
                self::assertTrue($responses[$name]['ready'], $name);
                self::assertSame('', $responses[$name]['action'], $name);
                self::assertLessThan(350.0, $responses[$name]['elapsed_ms'], $name);
                self::assertIsArray($responses[$name]['response'], $name);
            }
            self::assertLessThan(500.0, $responses['_total_elapsed_ms']);
            self::assertTrue($responses['status']['response']['ok']);
            self::assertArrayHasKey(
                'ready',
                $responses['status']['response']['payload'],
            );
            self::assertTrue($responses['heartbeat']['response']['ok']);
            self::assertFalse(
                $responses['heartbeat']['response']['payload']['re_register_required'],
            );
            foreach (['register', 'stop'] as $blocked) {
                self::assertFalse($responses[$blocked]['response']['ok']);
                self::assertSame(
                    'rejected',
                    $responses[$blocked]['response']['error']['code'],
                    \json_encode($responses[$blocked]['response']),
                );
                self::assertStringContainsString(
                    'retry_after=1',
                    $responses[$blocked]['response']['error']['message'],
                );
            }
        } finally {
            $criticalSection->setValue($this->controller, 0);
            @\fclose($slowServer);
            @\fclose($controlServerResource);
            $controlServer->setValue($this->controller, null);
            if (@\posix_kill($pid, 0)) {
                @\posix_kill($pid, SIGTERM);
                @\pcntl_waitpid($pid, $ignored);
            }
        }
    }

    public function testRollbackIntentRejectsHmacValidNonCanonicalMonotonicDeadline(): void
    {
        $runtimeGeneration = \str_repeat('d', 64);
        $manifest = $this->home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A' . DIRECTORY_SEPARATOR . 'manifest.json';
        $decoded = \json_decode(
            (string)\file_get_contents($manifest),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $decoded['runtime_generation'] = $runtimeGeneration;
        self::assertNotFalse(\file_put_contents(
            $manifest,
            \json_encode($decoded, JSON_THROW_ON_ERROR),
        ));

        $preparedAt = \time();
        $preparedMonotonic = \intdiv(\hrtime(true), 1_000_000);
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $payload = "WLS-UPGRADE/2\n"
            . 'host_id=' . $this->hostId . "\n"
            . "from=B\nto=A\n"
            . 'prepared_at=' . $preparedAt . "\n"
            . 'deadline=' . ($preparedAt + 300) . "\n"
            . 'runtime_generation=' . $runtimeGeneration . "\n"
            . 'host_boot_id=' . $hostBootId . "\n"
            . 'prepared_monotonic_ms=' . $preparedMonotonic . "\n"
            . 'activation_deadline_monotonic_ms='
                . ($preparedMonotonic + 299_999) . "\n"
            . 'rollback_deadline_monotonic_ms='
                . ($preparedMonotonic + 900_000) . "\n"
            . 'nonce=' . \str_repeat('a', 32) . "\n";
        $key = \hex2bin($this->adminSecret);
        self::assertIsString($key);
        try {
            $intent = $payload . 'signature='
                . \hash_hmac('sha256', $payload, $key) . "\n";
        } finally {
            \sodium_memzero($key);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Signed upgrade intent does not match the active rollback transaction.',
        );
        (new \ReflectionMethod($this->controller, 'validateUpgradeIntentForRollback'))
            ->invoke($this->controller, $intent, 'A', 'B');
    }

    public function testCorruptBinaryRetentionWindowRebuildsFullMonotonicDay(): void
    {
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $bootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $monotonicNow = \hrtime(true) / 1_000_000_000;
        $state = $stateProperty->getValue($this->controller);
        $state['binary_transaction'] = [
            'phase' => 'OBSERVING',
            'started_at' => \time() - 10,
            'started_at_monotonic' => $monotonicNow - 10.0,
            'healthy_since' => 0,
            'healthy_since_monotonic' => 0.0,
            'observation_boot_id' => $bootId,
            'retained_since_at' => \time() - 10,
            'retained_since_monotonic' => $monotonicNow - 10.0,
            'retain_previous_until' => \time() + 1,
            'retain_previous_until_monotonic' => $monotonicNow + 1.0,
            'retention_boot_id' => $bootId,
        ];
        $stateProperty->setValue($this->controller, $state);

        self::assertTrue(
            (new \ReflectionMethod($this->controller, 'reconcileBinaryObservationClock'))
                ->invoke($this->controller, $monotonicNow),
        );
        $rebuilt = $stateProperty->getValue($this->controller)['binary_transaction'];
        self::assertEqualsWithDelta(
            $monotonicNow,
            $rebuilt['retained_since_monotonic'],
            0.000001,
        );
        self::assertEqualsWithDelta(
            $monotonicNow + 86400.0,
            $rebuilt['retain_previous_until_monotonic'],
            0.000001,
        );
        self::assertSame($bootId, $rebuilt['retention_boot_id']);
    }

    public function testInitialRegistrationPublicationLeaseWindowIsIndependentAndBounded(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174093';
        $instanceId = 'instance-slow-first-publication';
        $routeId = \str_repeat('9', 32);
        $lease = $this->instanceLease($instanceId, 29119, 'stateless');
        $lease['last_heartbeat'] = \time() - 60;
        $lease['last_heartbeat_monotonic'] -= 60.0;

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'route_ids' => [$routeId],
        ];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => 'slow-publication.example.test',
            'status' => 'ACTIVE',
            'instances' => [$instanceId => $lease],
            'certificate' => ['valid' => true],
        ];
        $stateProperty->setValue($this->controller, $state);

        (new \ReflectionMethod($this->controller, 'beginRoutingMutation'))
            ->invoke($this->controller, 'register:' . $projectUuid . ':' . $instanceId);
        (new \ReflectionMethod($this->controller, 'recordPublicationLeaseCandidate'))
            ->invoke(
                $this->controller,
                $projectUuid,
                $instanceId,
                (int)$lease['generation'],
                (string)$lease['digest'],
                (int)$lease['master_epoch'],
                (string)$lease['launch_id'],
            );

        (new \ReflectionMethod($this->controller, 'expireLeases'))
            ->invoke($this->controller);
        $pending = $stateProperty->getValue($this->controller);
        self::assertSame(
            'ACTIVE',
            $pending['instances'][$projectUuid][$instanceId]['status'],
            'Only the exact pending registration may outlive the ordinary heartbeat TTL.',
        );
        self::assertSame('ACTIVE', $pending['routes'][$routeId]['status']);

        $publication = $publicationProperty->getValue($this->controller);
        $candidateKey = $projectUuid . ':' . $instanceId;
        $publication['lease_candidates'][$candidateKey]['started_monotonic']
            = (\hrtime(true) / 1_000_000_000) - 100.0;
        $publication['lease_candidates'][$candidateKey]['deadline_monotonic']
            = (\hrtime(true) / 1_000_000_000) - 1.0;
        $publicationProperty->setValue($this->controller, $publication);

        (new \ReflectionMethod($this->controller, 'expireLeases'))
            ->invoke($this->controller);
        $expired = $stateProperty->getValue($this->controller);
        self::assertSame('STALE', $expired['instances'][$projectUuid][$instanceId]['status']);
        self::assertSame('STALE', $expired['routes'][$routeId]['status']);
    }

    public function testHeartbeatCannotReactivateStaleOrDrainingRouting(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174034';
        $instanceId = 'instance-heartbeat';
        $routeId = \str_repeat('e', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29100, 'stateless');
        $lease['status'] = 'STALE';
        $lease['last_heartbeat'] = \time() - 100;
        $lease['drain_until'] = 12345;
        $lease['drain_until_monotonic'] = 12345.0;
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'instances' => [$instanceId => $lease],
            'status' => 'STALE',
        ];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, false);

        (new \ReflectionMethod($this->controller, 'touchInstanceLease'))->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            1,
            \str_repeat('c', 32),
            1,
        );

        $renewed = $stateProperty->getValue($this->controller);
        self::assertSame('STALE', $renewed['instances'][$projectUuid][$instanceId]['status']);
        self::assertSame(12345, $renewed['instances'][$projectUuid][$instanceId]['drain_until']);
        self::assertSame(
            'STALE',
            $renewed['routes'][$routeId]['instances'][$instanceId]['status'],
        );
        self::assertSame(
            12345,
            $renewed['routes'][$routeId]['instances'][$instanceId]['drain_until'],
        );
        self::assertFalse($dirtyProperty->getValue($this->controller));
        self::assertNull($publicationProperty->getValue($this->controller));
        self::assertGreaterThan(
            $lease['last_heartbeat'],
            $renewed['instances'][$projectUuid][$instanceId]['last_heartbeat'],
        );

        $renewed['projects'][$projectUuid] = ['generation' => 1];
        $stateProperty->setValue($this->controller, $renewed);
        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_generation' => 1,
                'instance_id' => $instanceId,
                'instance_generation' => 1,
                'instance_digest' => (string)$lease['digest'],
                'master_epoch' => 1,
                'launch_id' => \str_repeat('c', 32),
                'gateway_epoch' => (string)$renewed['epoch'],
                'host_boot_id' => $this->hostBootId(),
                '_authenticated_monotonic' => \hrtime(true) / 1_000_000_000,
                '_authenticated_timestamp' => \time(),
            ],
        );
        self::assertTrue($heartbeat['accepted']);
        self::assertTrue($heartbeat['re_register_required']);
    }

    public function testRegisterRequiresTheCurrentGatewayEpochBeforeEnrollmentLookup(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174097';
        $projectGeneration = 1;
        $requestDigest = \str_repeat('a', 64);
        $payload = [
            'project_uuid' => $projectUuid,
            'project_root' => $this->createProject(),
            'instance_id' => 'instance-missing-epoch',
            'project_generation' => $projectGeneration,
            'request_digest' => $requestDigest,
            'idempotency_key' => \substr(\hash(
                'sha256',
                $projectUuid . ':desired:' . $projectGeneration . ':' . $requestDigest,
            ), 0, 40),
            'instance_generation' => 1,
            'instance_digest' => \str_repeat('b', 64),
            'non_certificate_desired_digest' => \str_repeat('c', 64),
            'master_epoch' => 1,
            'launch_id' => \str_repeat('d', 32),
            'host_boot_id' => $this->hostBootId(),
        ];

        try {
            (new \ReflectionMethod($this->controller, 'register'))->invoke(
                $this->controller,
                $payload,
                false,
            );
            self::fail('A full registration must be fenced by the current gateway epoch.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'gateway epoch is stale or missing',
                $exception->getMessage(),
            );
        }
    }

    public function testHeartbeatRequestsReplayWhenInstanceDigestChangesWithoutTouchingLease(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174098';
        $instanceId = 'instance-capability-refresh';
        $routeId = \str_repeat('5', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29103, 'isolated');
        $lease['last_heartbeat'] = \time() - 20;
        $state['projects'][$projectUuid] = ['generation' => 1];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'instances' => [$instanceId => $lease],
            'status' => 'ACTIVE',
        ];
        $stateProperty->setValue($this->controller, $state);

        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_generation' => 1,
                'instance_id' => $instanceId,
                'instance_generation' => 1,
                'instance_digest' => \str_repeat('d', 64),
                'master_epoch' => 1,
                'launch_id' => \str_repeat('c', 32),
                'gateway_epoch' => (string)$state['epoch'],
                'host_boot_id' => $this->hostBootId(),
                '_authenticated_monotonic' => \hrtime(true) / 1_000_000_000,
                '_authenticated_timestamp' => \time(),
            ],
        );

        self::assertTrue($heartbeat['accepted']);
        self::assertTrue($heartbeat['re_register_required']);
        $unchanged = $stateProperty->getValue($this->controller);
        self::assertSame(
            $lease['last_heartbeat'],
            $unchanged['instances'][$projectUuid][$instanceId]['last_heartbeat'],
            'A changed digest must replay full registration before renewing the lease.',
        );
    }

    public function testHeartbeatRejectsAMissingInstanceDigestWithoutRefreshingLease(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceId = 'instance-missing-digest';
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29104, 'isolated');
        $lease['last_heartbeat'] = \time() - 20;
        $state['projects'][$projectUuid] = [
            'generation' => 1,
            'route_ids' => [],
        ];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $stateProperty->setValue($this->controller, $state);

        try {
            (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
                $this->controller,
                [
                    'project_uuid' => $projectUuid,
                    'project_generation' => 1,
                    'instance_id' => $instanceId,
                    'instance_generation' => 1,
                    'master_epoch' => 1,
                    'launch_id' => \str_repeat('c', 32),
                    'gateway_epoch' => (string)$state['epoch'],
                    'host_boot_id' => $this->hostBootId(),
                    '_authenticated_monotonic' => \hrtime(true) / 1_000_000_000,
                    '_authenticated_timestamp' => \time(),
                ],
            );
            self::fail('Heartbeat must prove the exact registered instance digest.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'instance digest is invalid',
                $exception->getMessage(),
            );
        }

        $unchanged = $stateProperty->getValue($this->controller);
        self::assertSame(
            $lease['last_heartbeat'],
            $unchanged['instances'][$projectUuid][$instanceId]['last_heartbeat'],
            'A digest-less heartbeat must not extend the instance lease.',
        );
    }

    public function testIdempotentRegistrationFastPathRequiresFullyActiveRouting(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174036';
        $instanceId = 'instance-rejoin';
        $domain = 'rejoin.example.test';
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29102, 'stateless');
        $state['active_config_generation'] = 1;
        $state['enrollments'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'security_generation' => 1,
        ];
        $state['projects'][$projectUuid] = [
            'project_uuid' => $projectUuid,
            'route_ids' => [$routeId],
        ];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'route_id' => $routeId,
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'enrollment_security_generation' => 1,
            'domain_security_generation' => 0,
            'route_generation' => 1,
            'force_https' => true,
            'force_root_to_www' => false,
            'status' => 'PENDING_CERTIFICATE',
            'backends' => $lease['backends'],
            'backend_instances' => [
                $instanceId => [
                    'instance_id' => $instanceId,
                    'backends' => $lease['backends'],
                    'backend_identity' => $lease['backend_identity'],
                ],
            ],
            'certificate' => $this->pendingCertificateEnvelope($domain),
            'instances' => [
                $instanceId => $lease + ['backend_healthy' => true],
            ],
        ];
        $state['active_routes'][$routeId] = $state['routes'][$routeId];
        $stateProperty->setValue($this->controller, $state);
        $fullyActive = new \ReflectionMethod(
            $this->controller,
            'registrationLeaseFullyActive',
        );
        self::assertTrue($fullyActive->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
        ));

        $state['routes'][$routeId]['status'] = 'STALE';
        $state['routes'][$routeId]['instances'][$instanceId]['status'] = 'STALE';
        $state['instances'][$projectUuid][$instanceId]['status'] = 'STALE';
        $stateProperty->setValue($this->controller, $state);
        self::assertFalse($fullyActive->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
        ));

        $state['routes'][$routeId]['status'] = 'PENDING_BACKEND';
        $state['routes'][$routeId]['instances'][$instanceId]['status'] = 'ACTIVE';
        $state['routes'][$routeId]['instances'][$instanceId]['backend_healthy'] = false;
        $state['instances'][$projectUuid][$instanceId]['status'] = 'ACTIVE';
        $stateProperty->setValue($this->controller, $state);
        self::assertFalse($fullyActive->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
        ));
    }

    public function testHeartbeatIgnoresDrainCountersUntilExactDrainPublication(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174035';
        $instanceId = 'instance-draining';
        $routeId = \str_repeat('f', 32);
        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $dirtyProperty = new \ReflectionProperty($this->controller, 'configDirty');
        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $state = $stateProperty->getValue($this->controller);
        $lease = $this->instanceLease($instanceId, 29101, 'master-counters');
        $drainStartedMonotonic = \hrtime(true) / 1_000_000_000 - 1.0;
        $drainStartedAt = \time() - 1;
        $drainOperationId = (new \ReflectionMethod(
            $this->controller,
            'drainOperationId',
        ))->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            1,
            1,
            \str_repeat('c', 32),
        );
        $lease['status'] = 'DRAINING';
        $lease['drain_operation_id'] = $drainOperationId;
        $lease['drain_seconds'] = 300;
        $lease['drain_started_at'] = $drainStartedAt;
        $lease['drain_started_monotonic'] = $drainStartedMonotonic;
        $lease['drain_until'] = $drainStartedAt + 300;
        $lease['drain_until_monotonic'] = $drainStartedMonotonic + 300.0;
        $state['projects'][$projectUuid] = [
            'generation' => 1,
            'route_ids' => [$routeId],
        ];
        $state['instances'][$projectUuid][$instanceId] = $lease;
        $state['routes'][$routeId] = [
            'project_uuid' => $projectUuid,
            'instances' => [$instanceId => $lease],
            'status' => 'DRAINING',
        ];
        $stateProperty->setValue($this->controller, $state);
        $dirtyProperty->setValue($this->controller, false);

        $heartbeat = (new \ReflectionMethod($this->controller, 'heartbeat'))->invoke(
            $this->controller,
            [
                'project_uuid' => $projectUuid,
                'project_generation' => 1,
                'instance_id' => $instanceId,
                'instance_generation' => 1,
                'instance_digest' => (string)$lease['digest'],
                'master_epoch' => 1,
                'launch_id' => \str_repeat('c', 32),
                'gateway_epoch' => (string)$state['epoch'],
                'host_boot_id' => $this->hostBootId(),
                '_authenticated_monotonic' => \hrtime(true) / 1_000_000_000,
                '_authenticated_timestamp' => \time(),
                'drain_operation_id' => $drainOperationId,
                'drain_counters' => [
                    'version' => 1,
                    'counters_known' => true,
                    'worker_count' => 2,
                    'reported_worker_count' => 2,
                    'active_requests' => 3,
                    'long_lived_connections' => 4,
                    'sse_connections' => 2,
                    'websocket_connections' => 1,
                    'http2_connections' => 2,
                ],
            ],
        );
        self::assertTrue(
            $heartbeat['re_register_required'],
            'A heartbeat may refresh counters but cannot replace a missing committed route closure.',
        );

        $renewed = $stateProperty->getValue($this->controller);
        self::assertArrayNotHasKey(
            'drain_counters',
            $renewed['instances'][$projectUuid][$instanceId],
            'Pre-publication counters cannot become drain completion authority.',
        );
        self::assertSame(
            'DRAINING',
            $renewed['routes'][$routeId]['instances'][$instanceId]['status'],
        );
        self::assertFalse($dirtyProperty->getValue($this->controller));
        self::assertNull($publicationProperty->getValue($this->controller));

        $statuses = (new \ReflectionMethod($this->controller, 'projectInstanceStatuses'))
            ->invoke($this->controller, $projectUuid);
        self::assertCount(1, $statuses);
        self::assertFalse($statuses[0]['counters_known']);
        self::assertFalse($statuses[0]['drain_complete']);
    }

    public function testDrainCounterCapabilityRequiresACompleteTypedHttp2Vector(): void
    {
        $method = new \ReflectionMethod($this->controller, 'completeDrainCounterVector');
        $complete = [
            'active_requests' => 1,
            'long_lived_connections' => 2,
            'sse_connections' => 1,
            'websocket_connections' => 1,
            'http2_connections' => 3,
        ];

        self::assertTrue($method->invoke($this->controller, $complete));
        self::assertFalse($method->invoke(
            $this->controller,
            \array_diff_key($complete, ['http2_connections' => true]),
        ));
        self::assertFalse($method->invoke(
            $this->controller,
            [...$complete, 'http2_connections' => '3'],
        ));
        self::assertFalse($method->invoke(
            $this->controller,
            [...$complete, 'sse_connections' => 3],
        ));
        self::assertFalse($method->invoke(
            $this->controller,
            [...$complete, 'active_requests' => -1],
        ));
    }

    public function testUntrustedSecurityMarkerOverridesOlderValidLedger(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174031';
        $project = $this->createProject();
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => ['untrusted-marker.example.test'],
                'capabilities' => [],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok'], \json_encode($enrollment));
        $ledger = $this->home . DIRECTORY_SEPARATOR . 'state/security-ledger.json';
        self::assertFileExists($ledger);
        self::assertNotFalse(\file_put_contents($ledger . '.untrusted', "injected failure\n"));

        $isolated = new \WlsEdgeGatewayController(
            $this->home,
            'unix://' . $this->home . DIRECTORY_SEPARATOR . 'runtime/run/marker.sock',
        );
        $isolatedState = (new \ReflectionProperty($isolated, 'state'))->getValue($isolated);
        self::assertFalse($isolatedState['security_ledger_valid']);
        self::assertSame([], $isolatedState['enrollments']);
        self::assertTrue($isolatedState['isolation_mode']);
        self::assertSame('SECURITY_LEDGER_UNTRUSTED', $isolatedState['health_state']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(
        string $channel,
        string $operation,
        array $payload,
        string $credentialId,
        string $secret,
        ?string $nonce = null,
        bool $tamperDigest = false,
        ?int $peerUid = null,
        ?float $monotonicTimestamp = null,
        ?int $brokerActionProtocol = 2,
        bool $tamperSignature = false,
    ): array {
        $this->lastBrokerActions = [];
        if ($operation === 'enroll') {
            $payload = $this->completeEnrollmentWireFacts($payload);
        }
        $request = [
            'protocol' => 'wls-edge/2',
            'channel' => $channel,
            'host_id' => $this->hostId,
            'credential_id' => $credentialId,
            'operation' => $operation,
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => $monotonicTimestamp
                ?? \hrtime(true) / 1_000_000_000,
            'nonce' => $nonce ?? \bin2hex(\random_bytes(16)),
            'payload' => $payload,
        ];
        $request['request_digest'] = \hash('sha256', GatewayClient::canonicalJson([
            'operation' => $operation,
            'payload' => $payload,
        ]));
        if ($tamperDigest) {
            $request['request_digest'] = \str_repeat('0', 64);
        }
        $request['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($request),
            $secret,
        );
        if ($tamperSignature) {
            $request['signature'] = \str_repeat('0', 64);
        }
        $encoded = \json_encode(
            $request,
            JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $peerUid ??= (int)\posix_geteuid();
        $brokerEnvelope = [
            'broker_schema' => 1,
            'channel' => $channel,
            'uid' => $peerUid,
            'gid' => (int)\posix_getegid(),
            'pid' => \getmypid(),
            'fencing_token' => $this->fencing,
            'payload_length' => \strlen($encoded),
        ];
        self::assertSame(1, $brokerEnvelope['broker_schema']);
        if ($brokerActionProtocol !== null) {
            $brokerEnvelope['action_protocol'] = $brokerActionProtocol;
            self::assertSame(2, $brokerEnvelope['action_protocol']);
        }
        self::assertContains($brokerEnvelope['channel'], ['admin', 'project']);
        self::assertGreaterThanOrEqual(0, $brokerEnvelope['uid']);
        self::assertGreaterThanOrEqual(0, $brokerEnvelope['gid']);
        self::assertGreaterThan(0, $brokerEnvelope['pid']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $brokerEnvelope['fencing_token'],
        );
        self::assertSame(\strlen($encoded), $brokerEnvelope['payload_length']);
        $broker = \json_encode($brokerEnvelope, JSON_THROW_ON_ERROR) . "\n";
        $handshake = $this->brokerHandshake();
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        $wire = $handshake['probe'] . $broker . $encoded;
        self::assertSame(\strlen($wire), \fwrite($sockets[0], $wire));
        $this->serveClient->invoke($this->controller, $sockets[1]);
        self::assertSame($handshake['ready'], \fgets($sockets[0], 512));
        $line = \fgets($sockets[0], 4 * 1024 * 1024);
        \fclose($sockets[0]);
        self::assertIsString($line);
        if (\str_starts_with($line, "WLS-ACTION/2\t")) {
            $this->lastBrokerActions = [\rtrim($line, "\r\n")];
            self::fail(
                'Test-mode Controller unexpectedly requested a Native Broker action: '
                    . $this->lastBrokerActions[0] . '.',
            );
        }
        return \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function signedRequest(
        string $channel,
        string $operation,
        array $payload,
        string $credentialId,
        string $secret,
    ): array {
        $request = [
            'protocol' => 'wls-edge/2',
            'channel' => $channel,
            'host_id' => $this->hostId,
            'credential_id' => $credentialId,
            'operation' => $operation,
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => \hrtime(true) / 1_000_000_000,
            'nonce' => \bin2hex(\random_bytes(16)),
            'payload' => $payload,
        ];
        $request['request_digest'] = \hash('sha256', GatewayClient::canonicalJson([
            'operation' => $operation,
            'payload' => $payload,
        ]));
        $request['signature'] = \hash_hmac(
            'sha256',
            GatewayClient::canonicalJson($request),
            $secret,
        );
        return $request;
    }

    /**
     * @param array<string,mixed> $request
     * @return array{wire:string,ready:string}
     */
    private function framedBrokerRequest(string $channel, array $request): array
    {
        $encoded = \json_encode(
            $request,
            JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $broker = \json_encode([
            'broker_schema' => 1,
            'action_protocol' => 2,
            'channel' => $channel,
            'uid' => (int)\posix_geteuid(),
            'gid' => (int)\posix_getegid(),
            'pid' => \getmypid(),
            'fencing_token' => $this->fencing,
            'payload_length' => \strlen($encoded),
        ], JSON_THROW_ON_ERROR) . "\n";
        $handshake = $this->brokerHandshake();
        return [
            'wire' => $handshake['probe'] . $broker . $encoded,
            'ready' => $handshake['ready'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function completeEnrollmentWireFacts(array $payload): array
    {
        if (isset($payload['request_digest'], $payload['idempotency_key'])
            || !\is_string($payload['project_uuid'] ?? null)
            || !\is_string($payload['project_root'] ?? null)
            || !\is_array($payload['certificate_roots'] ?? null)
            || !\is_array($payload['allowed_domains'] ?? null)
        ) {
            return $payload;
        }
        $certificateRoots = $payload['certificate_roots'];
        \ksort($certificateRoots, SORT_STRING);
        $allowedDomains = \array_values(\array_unique(\array_map(
            static fn (mixed $domain): string => \strtolower(\trim((string)$domain)),
            $payload['allowed_domains'],
        )));
        \sort($allowedDomains, SORT_STRING);
        $capabilities = \is_array($payload['capabilities'] ?? null)
            ? $payload['capabilities']
            : [];
        \ksort($capabilities, SORT_STRING);
        $facts = [
            'project_uuid' => \strtolower(\trim($payload['project_uuid'])),
            'project_root' => $payload['project_root'],
            'certificate_roots' => $certificateRoots,
            'allowed_domains' => $allowedDomains,
            'capabilities' => $capabilities,
        ];
        if (\array_key_exists('project_owner_uid', $payload)
            || \array_key_exists('project_owner_gid', $payload)
        ) {
            $facts['project_owner_uid'] = $payload['project_owner_uid'] ?? null;
            $facts['project_owner_gid'] = $payload['project_owner_gid'] ?? null;
        }
        $requestDigest = \hash('sha256', GatewayClient::canonicalJson($facts));
        $payload['request_digest'] = $requestDigest;
        $payload['idempotency_key'] = \substr(\hash(
            'sha256',
            $facts['project_uuid'] . ':enroll:' . $requestDigest,
        ), 0, 40);
        return $payload;
    }

    /** @return array{probe:string,ready:string} */
    private function brokerHandshake(): array
    {
        $key = \hex2bin($this->fencing);
        self::assertIsString($key);
        try {
            $nonce = \bin2hex(\random_bytes(32));
            return [
                'probe' => "WLS-BROKER-PROBE/1\t{$nonce}\t"
                    . \hash_hmac(
                        'sha256',
                        "WLS-BROKER-PROBE/1\nnonce={$nonce}\n",
                        $key,
                    ) . "\n",
                'ready' => "WLS-BROKER-READY/1\t"
                    . \hash_hmac(
                        'sha256',
                        "WLS-BROKER-READY/1\nnonce={$nonce}\n",
                        $key,
                    ) . "\n",
            ];
        } finally {
            \sodium_memzero($key);
        }
    }

    /** @param array<string,mixed> $response */
    private function assertResponseSignature(array $response, string $secret): void
    {
        $signature = (string)($response['signature'] ?? '');
        unset($response['signature']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $signature);
        self::assertSame(
            \hash_hmac('sha256', GatewayClient::canonicalJson($response), $secret),
            $signature,
        );
    }

    /**
     * Exercise the production registration validator and state builder, then
     * model the already-verified publication boundary without starting Nginx.
     *
     * @return array{
     *     project_uuid:string,
     *     project_generation:int,
     *     instance_id:string,
     *     instance_generation:int,
     *     instance_digest:string,
     *     master_epoch:int,
     *     launch_id:string,
     *     route_id:string,
     *     gateway_epoch:string,
     *     credential_id:string,
     *     credential_secret:string,
     *     register_payload:array<string,mixed>,
     *     route_generation:int
     * }
     */
    private function registerPendingCertificateLeaseForCheckpoint(
        string $projectUuid,
        string $instanceId,
        string $domain,
    ): array {
        $project = $this->createProject();
        $enrollment = $this->request(
            'admin',
            'enroll',
            [
                'project_uuid' => $projectUuid,
                'project_root' => $project,
                'certificate_roots' => [
                    'project_ssl' => $project . DIRECTORY_SEPARATOR . 'app/etc/ssl',
                ],
                'allowed_domains' => [$domain],
            ],
            'admin',
            $this->adminSecret,
        );
        self::assertTrue($enrollment['ok'], \json_encode($enrollment));
        $credential = (array)($enrollment['payload']['credential'] ?? []);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{32}\z/D',
            (string)($credential['credential_id'] ?? ''),
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)($credential['secret'] ?? ''),
        );

        $projectGeneration = 1;
        $instanceGeneration = 1;
        $masterEpoch = 1;
        $launchId = \str_repeat('c', 32);
        $routeId = $this->canonicalRouteId($projectUuid, $domain);
        // The closed loopback port deliberately exercises registration's
        // retryable transport-outage path. A pending certificate still keeps
        // HTTPS closed while the desired identity and lease closure are stored.
        $backend = ['host' => '127.0.0.1', 'port' => 65530, 'weight' => 1];
        $routePayload = [
            'route_id' => $routeId,
            'domain' => $domain,
            'force_https' => true,
            'force_root_to_www' => false,
            'root_to_www_target' => '',
            'backends' => [$backend],
            'backend_identity' => $this->signedBackendIdentity(
                $projectUuid,
                $instanceId,
                $instanceGeneration,
                $masterEpoch,
                $launchId,
                \str_repeat('a', 64),
            ),
            'certificate' => $this->pendingCertificateEnvelope($domain),
        ];
        $candidateRoute = (new \ReflectionMethod($this->controller, 'validateRoute'))
            ->invoke(
                $this->controller,
                $routePayload,
                $projectUuid,
                $project,
                $instanceId,
                $instanceGeneration,
                $masterEpoch,
                $launchId,
                \hrtime(true) / 1_000_000_000 + 5.0,
                true,
                true,
            );
        $projectDigest = (new \ReflectionMethod(
            $this->controller,
            'projectDesiredDigest',
        ))->invoke($this->controller, $projectUuid, $project, [$candidateRoute]);
        $instanceDigest = (new \ReflectionMethod(
            $this->controller,
            'instanceDesiredDigest',
        ))->invoke(
            $this->controller,
            $projectUuid,
            $instanceId,
            $instanceGeneration,
            [$candidateRoute],
        );
        $nonCertificateDigest = (new \ReflectionMethod(
            $this->controller,
            'nonCertificateDesiredDigest',
        ))->invoke($this->controller, $projectUuid, $project, [$candidateRoute]);
        $idempotencyKey = \substr(\hash(
            'sha256',
            $projectUuid . ':desired:' . $projectGeneration . ':' . $projectDigest,
        ), 0, 40);
        $state = (new \ReflectionProperty($this->controller, 'state'))
            ->getValue($this->controller);
        $registerPayload = [
            'project_uuid' => $projectUuid,
            'project_root' => $project,
            'instance_id' => $instanceId,
            'project_generation' => $projectGeneration,
            'request_digest' => $projectDigest,
            'idempotency_key' => $idempotencyKey,
            'instance_generation' => $instanceGeneration,
            'instance_digest' => $instanceDigest,
            'non_certificate_desired_digest' => $nonCertificateDigest,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'gateway_epoch' => (string)$state['epoch'],
            'host_boot_id' => $this->hostBootId(),
            'routes' => [$routePayload],
        ];

        $deferPublication = new \ReflectionProperty($this->controller, 'deferPublication');
        $requestOperation = new \ReflectionProperty($this->controller, 'requestOperation');
        $requestPrincipal = new \ReflectionProperty($this->controller, 'requestPrincipal');
        $deferPublication->setValue($this->controller, true);
        $requestOperation->setValue($this->controller, 'register');
        $requestPrincipal->setValue($this->controller, $projectUuid);
        $registered = (new \ReflectionMethod($this->controller, 'register'))->invoke(
            $this->controller,
            $registerPayload + [
                '_broker_peer' => [
                    'channel' => 'project',
                    'uid' => (int)\posix_geteuid(),
                    'gid' => (int)\posix_getegid(),
                    'pid' => \getmypid(),
                ],
            ],
            false,
        );
        self::assertFalse($registered['idempotent']);

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $route = $state['routes'][$routeId];
        $route['instances'][$instanceId]['backend_healthy'] = true;
        (new \ReflectionMethod($this->controller, 'selectRouteBackends'))
            ->invokeArgs($this->controller, [&$route]);
        self::assertSame('PENDING_CERTIFICATE', $route['status']);
        self::assertNotEmpty($route['backends']);
        $state['routes'][$routeId] = $route;
        $state['active_routes'][$routeId] = $route;
        $state['active_config_generation'] = \max(1, (int)$state['generation']);
        $state['active_config_digest'] = \hash(
            'sha256',
            'checkpoint-fixture:' . $projectUuid,
        );
        $state['pending_lkg_generation'] = $state['active_config_generation'];
        $state['pending_lkg_config_digest'] = $state['active_config_digest'];
        $state['pending_lkg_routes_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($state['active_routes']),
        );
        $state['isolation_mode'] = false;
        $state['operations'] = [];
        $stateProperty->setValue($this->controller, $state);

        $publicationProperty = new \ReflectionProperty($this->controller, 'publication');
        $publication = $publicationProperty->getValue($this->controller);
        self::assertIsArray($publication);
        $publication['phase'] = 'COMMITTED';
        $publication['candidate_generation'] = $state['active_config_generation'];
        $publication['candidate_digest'] = $state['active_config_digest'];
        $publicationProperty->setValue($this->controller, $publication);
        (new \ReflectionMethod($this->controller, 'completePublication'))
            ->invoke($this->controller);
        $deferPublication->setValue($this->controller, false);
        $requestOperation->setValue($this->controller, '');
        $requestPrincipal->setValue($this->controller, '');
        (new \ReflectionProperty($this->controller, 'configDirty'))
            ->setValue($this->controller, false);
        (new \ReflectionMethod($this->controller, 'persistState'))
            ->invoke($this->controller);
        self::assertTrue(
            (new \ReflectionMethod($this->controller, 'registrationLeaseFullyActive'))
                ->invoke($this->controller, $projectUuid, $instanceId),
        );

        return [
            'project_uuid' => $projectUuid,
            'project_generation' => $projectGeneration,
            'instance_id' => $instanceId,
            'instance_generation' => $instanceGeneration,
            'instance_digest' => $instanceDigest,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'route_id' => $routeId,
            'gateway_epoch' => (string)$state['epoch'],
            'credential_id' => (string)$credential['credential_id'],
            'credential_secret' => (string)$credential['secret'],
            'register_payload' => $registerPayload,
            'route_generation' => (int)$state['routes'][$routeId]['route_generation'],
        ];
    }

    private function createProject(): string
    {
        $project = $this->root . DIRECTORY_SEPARATOR . 'project-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($project . DIRECTORY_SEPARATOR . 'app/etc/ssl', 0700, true));
        return $project;
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

    /**
     * @return array{cert:string,key:string}
     */
    private function createCertificateWithPrivateKey(
        string $project,
        string $domain,
        string $name,
        \OpenSSLAsymmetricKey $key,
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
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $certificate = \openssl_csr_sign(
            $request,
            null,
            $key,
            30,
            $arguments,
            \random_int(1, PHP_INT_MAX),
        );
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

    private function canonicalRouteId(string $projectUuid, string $domain): string
    {
        return \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32);
    }

    private function hostBootId(): string
    {
        return (string)(new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
    }

    /**
     * @param array<string,mixed>|null $evidence
     * @return array<string,mixed>
     */
    private function signedBackendIdentity(
        string $projectUuid,
        string $instanceId,
        int $generation,
        int $masterEpoch,
        string $launchId,
        string $edgeSecret,
        string $sessionCapability = 'isolated',
        ?array $evidence = null,
    ): array {
        $identity = [
            'schema' => 'wls-backend-listener-identity/2',
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceId,
            'generation' => $generation,
            'master_pid' => \getmypid(),
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'listener_lease_id' => \substr(\hash(
                'sha256',
                $projectUuid . "\0" . $instanceId . "\0" . $generation . "\0" . $launchId,
            ), 0, 32),
            'edge_capability_digest' => \hash('sha256', $edgeSecret),
            'session_capability' => $sessionCapability,
            'edge_capability_secret' => $edgeSecret,
        ];
        if ($evidence !== null) {
            $identity['session_capability_evidence'] = $evidence;
        }
        return $this->sealBackendIdentity($identity);
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function sealBackendIdentity(array $identity): array
    {
        unset($identity['public_digest'], $identity['digest']);
        if (\is_array($identity['session_capability_evidence'] ?? null)) {
            $identity['session_capability_evidence_digest'] = \hash(
                'sha256',
                GatewayClient::canonicalJson($identity['session_capability_evidence']),
            );
        } else {
            unset($identity['session_capability_evidence_digest']);
        }
        $publicIdentity = $identity;
        unset($publicIdentity['edge_capability_secret']);
        $identity['public_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($publicIdentity),
        );
        $identity['digest'] = \hash('sha256', GatewayClient::canonicalJson($identity));
        return $identity;
    }

    /** @return array<string,mixed> */
    private function instanceLease(string $instanceId, int $port, string $capability): array
    {
        $hostBootId = (new \ReflectionProperty($this->controller, 'hostBootId'))
            ->getValue($this->controller);
        $identity = ['session_capability' => $capability];
        if ($capability === 'stateless') {
            $evidence = [
                'schema' => 'wls-stateless-capability/1',
                'runtime_source' => 'project_endpoint',
                'runtime_declared' => true,
                'instance_generation' => 1,
                'reason' => 'declared_stateless_runtime',
            ];
            $identity['session_capability_evidence'] = $evidence;
            $identity['session_capability_evidence_digest'] = \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            );
        } elseif ($capability === 'shared_session') {
            $evidence = [
                'schema' => 'wls-session-capability/1',
                'storage' => 'wls',
                'runtime_source' => 'project_shared_state',
                'runtime_registered' => true,
                'runtime_shared_service' => true,
                'host' => '127.0.0.1',
                'port' => 31998,
                'token_scope_digest' => \hash('sha256', 'session.token'),
                'probe' => 'healthy',
                'reason' => 'authenticated_session_runtime',
            ];
            $identity['session_capability_evidence'] = $evidence;
            $identity['session_capability_evidence_digest'] = \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            );
        }
        return [
            'instance_id' => $instanceId,
            'generation' => 1,
            'digest' => \str_repeat('b', 64),
            'master_epoch' => 1,
            'launch_id' => \str_repeat('c', 32),
            'backends' => [['host' => '127.0.0.1', 'port' => $port, 'weight' => 1]],
            'backend_identity' => $identity,
            'backend_healthy' => true,
            'status' => 'ACTIVE',
            'last_heartbeat' => \time(),
            'last_heartbeat_monotonic' => \hrtime(true) / 1_000_000_000,
            'lease_boot_id' => $hostBootId,
            'drain_until' => null,
            'drain_until_monotonic' => null,
        ];
    }

    /**
     * @param array<string,mixed> $references
     * @return array<string,mixed>
     */
    private function activeCertificateEnvelope(
        string $domain,
        string $sourceDigest,
        array $references,
        string $provider = 'self_signed',
        string $materialClass = 'self_signed',
    ): array {
        return \array_replace($references, [
            'state' => 'active',
            'pending' => false,
            'source_digest' => $sourceDigest,
            'trust_profile' => 'test',
            'provider' => $provider,
            'material_class' => $materialClass,
            'provenance_digest' => ProjectCertificateGenerationStore::provenanceDigest(
                $domain,
                $sourceDigest,
                'test',
                $provider,
                $materialClass,
            ),
        ]);
    }

    /** @return array<string,mixed> */
    private function pendingCertificateEnvelope(string $domain): array
    {
        $generation = 0;
        $sourceDigest = \hash(
            'sha256',
            "wls-pending-certificate\0" . $domain,
        );
        return [
            'state' => 'pending',
            'valid' => false,
            'pending' => true,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'trust_profile' => 'test',
            'provider' => 'none',
            'material_class' => 'none',
            'provenance_digest'
                => ProjectCertificateGenerationStore::inactiveProvenanceDigest(
                    $domain,
                    'pending',
                    $sourceDigest,
                    $generation,
                    'test',
                ),
            'cert' => [],
            'key' => [],
            'chain' => null,
            'snapshot_digest' => '',
            'cert_path' => '',
            'key_path' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function disabledCertificateEnvelope(
        string $domain,
        int $generation = 1,
    ): array {
        $sourceDigest = \hash(
            'sha256',
            "wls-disabled-certificate\0" . $domain . "\0" . $generation,
        );
        return [
            'state' => 'disabled',
            'valid' => false,
            'pending' => true,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'trust_profile' => 'test',
            'provider' => 'none',
            'material_class' => 'none',
            'provenance_digest'
                => ProjectCertificateGenerationStore::inactiveProvenanceDigest(
                    $domain,
                    'disabled',
                    $sourceDigest,
                    $generation,
                    'test',
                ),
            'snapshot_digest' => '',
            'cert_path' => '',
            'key_path' => '',
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $certificate
     */
    private function installCertificateFloor(
        array &$state,
        string $projectUuid,
        string $domain,
        array $certificate,
        int $routeGeneration = 1,
    ): void {
        $disabled = ($certificate['state'] ?? '') === 'disabled';
        $state['certificate_floors'][$projectUuid . '|' . $domain] = [
            'schema_version' => 2,
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'generation' => (int)$certificate['generation'],
            'source_digest' => (string)$certificate['source_digest'],
            'trust_profile' => (string)$certificate['trust_profile'],
            'provider' => (string)$certificate['provider'],
            'material_class' => (string)$certificate['material_class'],
            'provenance_digest' => (string)$certificate['provenance_digest'],
            'route_generation' => $routeGeneration,
            'revocation_generation' => $disabled
                ? (int)$certificate['generation']
                : 0,
            'revocation_source_digest' => $disabled
                ? (string)$certificate['source_digest']
                : '',
            'revocation_trust_profile' => $disabled
                ? (string)$certificate['trust_profile']
                : '',
            'revocation_provenance_digest' => $disabled
                ? (string)$certificate['provenance_digest']
                : '',
        ];
    }

    private function certificateAuthority(): string
    {
        $config = $this->root . DIRECTORY_SEPARATOR . 'openssl-ca.cnf';
        self::assertNotFalse(\file_put_contents(
            $config,
            <<<'CONFIG'
[ req ]
distinguished_name = req_distinguished_name
prompt = no
x509_extensions = v3_ca

[ req_distinguished_name ]
CN = WLS Controller Test Root
O = Weline Test

[ v3_ca ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
CONFIG
                . PHP_EOL,
        ));
        $options = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'x509_extensions' => 'v3_ca',
        ];
        $key = \openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = \openssl_csr_new([], $key, $options);
        self::assertNotFalse($csr);
        $certificate = \openssl_csr_sign(
            $csr,
            null,
            $key,
            3650,
            $options,
            1,
        );
        self::assertNotFalse($certificate);
        $pem = '';
        self::assertTrue(\openssl_x509_export($certificate, $pem, true));
        return \rtrim($pem) . "\n";
    }

    private function assignAvailablePublicPortsForRecovery(): void
    {
        $reservations = [];
        $ports = [];
        try {
            while (\count($ports) < 2) {
                $reservation = \stream_socket_server(
                    'tcp://127.0.0.1:0',
                    $errorCode,
                    $errorMessage,
                );
                self::assertIsResource(
                    $reservation,
                    'Unable to reserve a recovery test port: '
                        . $errorCode . ' ' . $errorMessage,
                );
                $name = (string)\stream_socket_get_name($reservation, false);
                self::assertMatchesRegularExpression('/:[1-9][0-9]*\z/D', $name);
                $port = (int)\substr($name, (int)\strrpos($name, ':') + 1);
                if (\in_array($port, $ports, true)) {
                    \fclose($reservation);
                    continue;
                }
                $reservations[] = $reservation;
                $ports[] = $port;
            }
        } finally {
            foreach ($reservations as $reservation) {
                \fclose($reservation);
            }
        }

        $stateProperty = new \ReflectionProperty($this->controller, 'state');
        $state = $stateProperty->getValue($this->controller);
        $state['public_http'] = $ports[0];
        $state['public_https'] = $ports[1];
        $stateProperty->setValue($this->controller, $state);

        $manifestFile = $this->home . DIRECTORY_SEPARATOR . 'slots/A/manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($manifestFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifest['listen_profile'] = 'ipv4-only';
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        ));
    }

    /** @return array{binary:string,runtime_generation:string} */
    private function createVerifiedRollbackSlotFixture(string $slot): array
    {
        self::assertContains($slot, ['A', 'B']);
        $slotDirectory = $this->home . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . $slot;
        $binaryDirectory = $slotDirectory . DIRECTORY_SEPARATOR . 'bin';
        if (!\is_dir($binaryDirectory)) {
            self::assertTrue(\mkdir($binaryDirectory, 0700, true));
        }
        $shareDirectory = $slotDirectory . DIRECTORY_SEPARATOR . 'share';
        if (!\is_dir($shareDirectory)) {
            self::assertTrue(\mkdir($shareDirectory, 0700, true));
        }
        $sourceBinary = $this->home . DIRECTORY_SEPARATOR . 'slots/A/bin/nginx';
        $binary = $binaryDirectory . DIRECTORY_SEPARATOR . 'nginx';
        self::assertTrue(\copy($sourceBinary, $binary));
        self::assertTrue(\chmod($binary, 0700));
        $binaryDigest = \hash_file('sha256', $binary);
        $binarySize = \filesize($binary);
        self::assertIsString($binaryDigest);
        self::assertIsInt($binarySize);
        self::assertGreaterThan(0, $binarySize);
        $trustBundleSource = $this->home . DIRECTORY_SEPARATOR
            . 'slots/A/share/ca-bundle.pem';
        $trustBundle = $shareDirectory . DIRECTORY_SEPARATOR . 'ca-bundle.pem';
        if (!\hash_equals($trustBundleSource, $trustBundle)) {
            self::assertTrue(\copy($trustBundleSource, $trustBundle));
            self::assertTrue(\chmod($trustBundle, 0644));
        }
        $trustBundleDigest = \hash_file('sha256', $trustBundle);
        $trustBundleSize = \filesize($trustBundle);
        self::assertIsString($trustBundleDigest);
        self::assertIsInt($trustBundleSize);

        $contract = (new \ReflectionClassConstant(
            \WlsEdgeGatewayController::class,
            'DURABLE_STATE_CONTRACT',
        ))->getValue();
        self::assertIsArray($contract);
        $manifest = [
            'schema_version' => 2,
            'role' => 'host_gateway',
            'slot' => $slot,
            'components' => [
                'bin/nginx' => [
                    'sha256' => $binaryDigest,
                    'size' => $binarySize,
                ],
                'share/ca-bundle.pem' => [
                    'sha256' => $trustBundleDigest,
                    'size' => $trustBundleSize,
                    'mode' => 0644,
                ],
            ],
            'capabilities' => [
                'certificate_public_trust_bundle' => true,
                'stable_launcher_rollback_target_proof' => true,
            ],
            'durable_state_contract' => $contract,
        ];
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            foreach ($value as $key => $child) {
                $value[$key] = $normalize($child);
            }
            if (!\array_is_list($value)) {
                \ksort($value, SORT_STRING);
            }
            return $value;
        };
        $runtimeGeneration = \hash(
            'sha256',
            \json_encode(
                $normalize($manifest),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        $manifest['runtime_generation'] = $runtimeGeneration;
        self::assertNotFalse(\file_put_contents(
            $slotDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        ));
        $verified = (new \ReflectionMethod(
            $this->controller,
            'verifiedRollbackTargetManifest',
        ))->invoke($this->controller, $slot);
        self::assertSame($runtimeGeneration, $verified['runtime_generation']);

        return [
            'binary' => $binary,
            'runtime_generation' => $runtimeGeneration,
        ];
    }

    /**
     * @param resource $server
     * @param array<string,mixed> $identity
     */
    private function forkAuthenticatedBackendProbeResponder(
        $server,
        array $identity,
        int $port,
        int $acceptCount,
    ): int {
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid !== 0) {
            return $pid;
        }
        for ($accepted = 0; $accepted < $acceptCount; ++$accepted) {
            $client = @\stream_socket_accept($server, 4.0);
            if (!\is_resource($client)) {
                exit(70);
            }
            \stream_set_timeout($client, 2);
            $request = '';
            while (!\str_contains($request, "\r\n\r\n")) {
                $chunk = @\fread($client, 8192);
                if (!\is_string($chunk) || $chunk === '') {
                    @\fclose($client);
                    exit(71);
                }
                $request .= $chunk;
                if (\strlen($request) > 65536) {
                    @\fclose($client);
                    exit(72);
                }
            }
            if (\preg_match('/[?&]nonce=([a-f0-9]{32}) /D', $request, $nonce) !== 1
                || \preg_match(
                    '/\r\nX-WLS-Edge-Token: ([a-f0-9]{64})\r\n/D',
                    $request,
                    $token,
                ) !== 1
                || !\hash_equals(
                    (string)$identity['edge_capability_secret'],
                    (string)$token[1],
                )
            ) {
                @\fclose($client);
                exit(73);
            }
            $attestation = [
                'schema' => 'wls-backend-attestation/1',
                'project_uuid' => (string)$identity['project_uuid'],
                'instance_id' => (string)$identity['instance_id'],
                'instance_generation' => (int)$identity['generation'],
                'master_epoch' => (int)$identity['master_epoch'],
                'launch_id' => (string)$identity['launch_id'],
                'listener_lease_id' => (string)$identity['listener_lease_id'],
                'edge_capability_digest' => (string)$identity['edge_capability_digest'],
                'backend_host' => '127.0.0.1',
                'backend_port' => $port,
                'session_capability' => (string)$identity['session_capability'],
                'session_capability_evidence_digest' => (string)(
                    $identity['session_capability_evidence_digest']
                        ?? \str_repeat('0', 64)
                ),
                'nonce' => (string)$nonce[1],
                'issued_at' => \time(),
            ];
            $key = \hex2bin((string)$identity['edge_capability_secret']);
            if (!\is_string($key)) {
                @\fclose($client);
                exit(74);
            }
            $attestation['signature'] = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($attestation),
                $key,
            );
            $body = \json_encode(
                ['backend_attestation' => $attestation],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $response = "HTTP/1.1 200 OK\r\n"
                . 'Content-Length: ' . \strlen($body) . "\r\n"
                . "Content-Type: application/json\r\n"
                . "Connection: close\r\n\r\n"
                . $body;
            $offset = 0;
            while ($offset < \strlen($response)) {
                $written = @\fwrite($client, \substr($response, $offset));
                if (!\is_int($written) || $written < 1) {
                    @\fclose($client);
                    exit(75);
                }
                $offset += $written;
            }
            @\fclose($client);
        }
        @\fclose($server);
        exit(0);
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
