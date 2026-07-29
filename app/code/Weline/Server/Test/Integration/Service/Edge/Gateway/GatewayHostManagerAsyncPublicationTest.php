<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPlatformServiceInstaller;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

final class GatewayHostManagerAsyncPublicationTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('pcntl_fork')
            || !\function_exists('stream_socket_server')
        ) {
            self::markTestSkipped('The asynchronous publication transport test requires POSIX sockets.');
        }
        foreach (['WLS_GATEWAY_TEST_MODE', 'WLS_GATEWAY_HOME'] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wha-'
            . \bin2hex(\random_bytes(4));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'home');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testProjectPollsAuthenticatedOperationUntilCommitted(): void
    {
        $paths = new GatewayPaths();
        $paths->ensureDirectories();
        $hostId = \bin2hex(\random_bytes(16));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $credentialId = \bin2hex(\random_bytes(16));
        $secret = \bin2hex(\random_bytes(32));
        $operationId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents($paths->hostIdFile(), $hostId));

        $projectRoot = $this->root . DIRECTORY_SEPARATOR . 'project';
        self::assertTrue(\mkdir($projectRoot, 0700, true));
        $credentials = new GatewayCredentialStore($paths, $projectRoot);
        $credentials->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'secret' => $secret,
        ], $projectUuid);

        $server = \stream_socket_server(
            'unix://' . $paths->projectSocketFile(),
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $childPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $childPid);
        if ($childPid === 0) {
            $client = @\stream_socket_accept($server, 5);
            if (!\is_resource($client)) {
                exit(20);
            }
            $line = @\fgets($client, 4 * 1024 * 1024);
            $request = \is_string($line) ? \json_decode($line, true) : null;
            if (!\is_array($request)
                || (string)($request['operation'] ?? '') !== 'operation-status'
                || (string)($request['payload']['operation_id'] ?? '') !== $operationId
                || (string)($request['payload']['project_uuid'] ?? '') !== $projectUuid
            ) {
                exit(21);
            }
            $response = [
                'protocol' => GatewayPaths::PROTOCOL,
                'request_id' => (string)$request['request_id'],
                'ok' => true,
                'payload' => [
                    'operation_id' => $operationId,
                    'project_uuid' => $projectUuid,
                    'state' => 'COMMITTED',
                    'active_generation' => 73,
                ],
            ];
            $response['signature'] = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($response),
                $secret,
            );
            @\fwrite(
                $client,
                \json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
            @\fclose($client);
            @\fclose($server);
            exit(0);
        }
        self::assertGreaterThan(0, $childPid);
        @\fclose($server);

        try {
            $progressCalls = 0;
            $client = new GatewayClient($paths, 1.0, $credentials);
            $host = new GatewayHostManager(
                $paths,
                $client,
                new HostGatewayPackageManager($paths),
                new GatewayPlatformServiceInstaller($paths),
                static function () use (&$progressCalls): void {
                    ++$progressCalls;
                },
            );
            $await = new \ReflectionMethod($host, 'awaitPublication');
            $result = $await->invoke(
                $host,
                [
                    'ok' => true,
                    'payload' => [
                        'operation_id' => $operationId,
                        'operation' => ['state' => 'PENDING_PUBLICATION'],
                    ],
                ],
                false,
                $projectUuid,
                2.0,
            );

            self::assertSame($operationId, $result['operation_id']);
            self::assertSame('COMMITTED', $result['operation']['state']);
            self::assertSame(73, $result['operation']['active_generation']);
            self::assertSame($projectUuid, $result['operation']['project_uuid']);
            self::assertGreaterThan(0, $progressCalls);
        } finally {
            $waited = \pcntl_waitpid($childPid, $status);
            self::assertSame($childPid, $waited);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        }
    }

    public function testProjectRetriesTransientEmptyStatusResponseUntilCommitted(): void
    {
        $paths = new GatewayPaths();
        $paths->ensureDirectories();
        $hostId = \bin2hex(\random_bytes(16));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174098';
        $credentialId = \bin2hex(\random_bytes(16));
        $secret = \bin2hex(\random_bytes(32));
        $operationId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents($paths->hostIdFile(), $hostId));

        $projectRoot = $this->root . DIRECTORY_SEPARATOR . 'retry-project';
        self::assertTrue(\mkdir($projectRoot, 0700, true));
        $credentials = new GatewayCredentialStore($paths, $projectRoot);
        $credentials->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'secret' => $secret,
        ], $projectUuid);

        $server = \stream_socket_server(
            'unix://' . $paths->projectSocketFile(),
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $childPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $childPid);
        if ($childPid === 0) {
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $client = @\stream_socket_accept($server, 5);
                if (!\is_resource($client)) {
                    exit(30 + $attempt);
                }
                $line = @\fgets($client, 4 * 1024 * 1024);
                $request = \is_string($line) ? \json_decode($line, true) : null;
                if (!\is_array($request)
                    || (string)($request['operation'] ?? '') !== 'operation-status'
                    || (string)($request['payload']['operation_id'] ?? '') !== $operationId
                ) {
                    exit(32 + $attempt);
                }
                if ($attempt === 0) {
                    @\fclose($client);
                    continue;
                }
                if ($attempt === 1) {
                    $response = [
                        'protocol' => GatewayPaths::PROTOCOL,
                        'request_id' => (string)$request['request_id'],
                        'ok' => false,
                        'epoch' => '',
                        'payload' => [],
                        'error' => [
                            'code' => 'rejected',
                            'message' => 'Gateway request rate limit exceeded; retry_after=1.',
                        ],
                    ];
                    $response['signature'] = \hash_hmac(
                        'sha256',
                        GatewayClient::canonicalJson($response),
                        $secret,
                    );
                    @\fwrite(
                        $client,
                        \json_encode(
                            $response,
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) . "\n",
                    );
                    @\fclose($client);
                    continue;
                }
                $response = [
                    'protocol' => GatewayPaths::PROTOCOL,
                    'request_id' => (string)$request['request_id'],
                    'ok' => true,
                    'payload' => [
                        'operation_id' => $operationId,
                        'project_uuid' => $projectUuid,
                        'state' => 'COMMITTED',
                        'active_generation' => 74,
                    ],
                ];
                $response['signature'] = \hash_hmac(
                    'sha256',
                    GatewayClient::canonicalJson($response),
                    $secret,
                );
                @\fwrite(
                    $client,
                    \json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                );
                @\fclose($client);
            }
            @\fclose($server);
            exit(0);
        }
        self::assertGreaterThan(0, $childPid);
        @\fclose($server);

        try {
            $client = new GatewayClient($paths, 0.25, $credentials);
            $host = new GatewayHostManager(
                $paths,
                $client,
                new HostGatewayPackageManager($paths),
                new GatewayPlatformServiceInstaller($paths),
            );
            $result = (new \ReflectionMethod($host, 'awaitPublication'))->invoke(
                $host,
                [
                    'ok' => true,
                    'payload' => [
                        'operation_id' => $operationId,
                        'operation' => ['state' => 'PENDING_PUBLICATION'],
                    ],
                ],
                false,
                $projectUuid,
                3.0,
            );
            self::assertSame('COMMITTED', $result['operation']['state']);
            self::assertSame(74, $result['operation']['active_generation']);
        } finally {
            $waited = \pcntl_waitpid($childPid, $status);
            self::assertSame($childPid, $waited);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        }
    }

    public function testProjectRetriesIdenticalMutationEnvelopeAfterLostOrRateLimitedResponse(): void
    {
        $paths = new GatewayPaths();
        $paths->ensureDirectories();
        $hostId = \bin2hex(\random_bytes(16));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174097';
        $credentialId = \bin2hex(\random_bytes(16));
        $secret = \bin2hex(\random_bytes(32));
        self::assertNotFalse(\file_put_contents($paths->hostIdFile(), $hostId));

        $projectRoot = $this->root . DIRECTORY_SEPARATOR . 'mutation-retry-project';
        self::assertTrue(\mkdir($projectRoot, 0700, true));
        $credentials = new GatewayCredentialStore($paths, $projectRoot);
        $credentials->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'secret' => $secret,
        ], $projectUuid);

        $server = \stream_socket_server(
            'unix://' . $paths->projectSocketFile(),
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $operationId = \bin2hex(\random_bytes(16));
        $payload = [
            'project_uuid' => $projectUuid,
            'project_generation' => 7,
            'request_digest' => \str_repeat('4', 64),
            'idempotency_key' => $projectUuid . ':instance:7',
        ];
        $childPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $childPid);
        if ($childPid === 0) {
            $firstRequest = null;
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                $client = @\stream_socket_accept($server, 5);
                if (!\is_resource($client)) {
                    exit(40 + $attempt);
                }
                $line = @\fgets($client, 4 * 1024 * 1024);
                $request = \is_string($line) ? \json_decode($line, true) : null;
                if (!\is_array($request)
                    || (string)($request['operation'] ?? '') !== 'register'
                ) {
                    exit(42 + $attempt);
                }
                $firstRequest ??= $request;
                if (GatewayClient::canonicalJson($request['payload'] ?? [])
                    !== GatewayClient::canonicalJson($firstRequest['payload'] ?? [])
                ) {
                    exit(44);
                }
                if ($attempt === 0) {
                    @\fclose($client);
                    continue;
                }
                if ($attempt === 1) {
                    $response = [
                        'protocol' => GatewayPaths::PROTOCOL,
                        'request_id' => (string)$request['request_id'],
                        'ok' => false,
                        'epoch' => '',
                        'payload' => [],
                        'error' => [
                            'code' => 'unauthorized',
                            'message' => 'Gateway wall clock is untrusted; security-sensitive mutation rejected.',
                        ],
                    ];
                    $response['signature'] = \hash_hmac(
                        'sha256',
                        GatewayClient::canonicalJson($response),
                        $secret,
                    );
                    @\fwrite(
                        $client,
                        \json_encode(
                            $response,
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) . "\n",
                    );
                    @\fclose($client);
                    continue;
                }
                if ($attempt === 2) {
                    $response = [
                        'protocol' => GatewayPaths::PROTOCOL,
                        'request_id' => (string)$request['request_id'],
                        'ok' => false,
                        'epoch' => '',
                        'payload' => [],
                        'error' => [
                            'code' => 'rejected',
                            'message' => 'Gateway request rate limit exceeded; retry_after=1.',
                        ],
                    ];
                    $response['signature'] = \hash_hmac(
                        'sha256',
                        GatewayClient::canonicalJson($response),
                        $secret,
                    );
                    @\fwrite(
                        $client,
                        \json_encode(
                            $response,
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) . "\n",
                    );
                    @\fclose($client);
                    continue;
                }
                if ($attempt === 3) {
                    $response = [
                        'protocol' => GatewayPaths::PROTOCOL,
                        'request_id' => (string)$request['request_id'],
                        'ok' => false,
                        'epoch' => '',
                        'payload' => [],
                        'error' => [
                            'code' => 'rejected',
                            'message' => 'Gateway publication is active; retry_after=1.',
                        ],
                    ];
                    $response['signature'] = \hash_hmac(
                        'sha256',
                        GatewayClient::canonicalJson($response),
                        $secret,
                    );
                    @\fwrite(
                        $client,
                        \json_encode(
                            $response,
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) . "\n",
                    );
                    @\fclose($client);
                    continue;
                }
                $response = [
                    'protocol' => GatewayPaths::PROTOCOL,
                    'request_id' => (string)$request['request_id'],
                    'ok' => true,
                    'payload' => [
                        'operation_id' => $operationId,
                        'operation' => [
                            'operation_id' => $operationId,
                            'state' => 'PENDING_PUBLICATION',
                        ],
                    ],
                ];
                $response['signature'] = \hash_hmac(
                    'sha256',
                    GatewayClient::canonicalJson($response),
                    $secret,
                );
                @\fwrite(
                    $client,
                    \json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        . "\n",
                );
                @\fclose($client);
            }
            @\fclose($server);
            exit(0);
        }
        self::assertGreaterThan(0, $childPid);
        @\fclose($server);

        try {
            $host = new GatewayHostManager(
                $paths,
                new GatewayClient($paths, 0.25, $credentials),
                new HostGatewayPackageManager($paths),
                new GatewayPlatformServiceInstaller($paths),
            );
            $response = (new \ReflectionMethod($host, 'idempotentProjectMutation'))->invoke(
                $host,
                'register',
                $payload,
                5.0,
            );
            self::assertSame($operationId, $response['payload']['operation_id']);
            self::assertSame(
                'PENDING_PUBLICATION',
                $response['payload']['operation']['state'],
            );
        } finally {
            $waited = \pcntl_waitpid($childPid, $status);
            self::assertSame($childPid, $waited);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        }
    }

    public function testStartupStatusRetriesTransientEmptyResponse(): void
    {
        $paths = new GatewayPaths();
        $paths->ensureDirectories();
        $hostId = \bin2hex(\random_bytes(16));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174096';
        $credentialId = \bin2hex(\random_bytes(16));
        $secret = \bin2hex(\random_bytes(32));
        self::assertNotFalse(\file_put_contents($paths->hostIdFile(), $hostId));

        $projectRoot = $this->root . DIRECTORY_SEPARATOR . 'status-retry-project';
        self::assertTrue(\mkdir($projectRoot, 0700, true));
        $credentials = new GatewayCredentialStore($paths, $projectRoot);
        $credentials->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => $credentialId,
            'secret' => $secret,
        ], $projectUuid);

        $server = \stream_socket_server(
            'unix://' . $paths->projectSocketFile(),
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($server, $error);
        $childPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $childPid);
        if ($childPid === 0) {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $client = @\stream_socket_accept($server, 5);
                if (!\is_resource($client)) {
                    exit(50 + $attempt);
                }
                $line = @\fgets($client, 4 * 1024 * 1024);
                $request = \is_string($line) ? \json_decode($line, true) : null;
                if (!\is_array($request)
                    || (string)($request['operation'] ?? '') !== 'own-status'
                    || (string)($request['payload']['project_uuid'] ?? '') !== $projectUuid
                ) {
                    exit(52 + $attempt);
                }
                if ($attempt === 0) {
                    @\fclose($client);
                    continue;
                }
                $response = [
                    'protocol' => GatewayPaths::PROTOCOL,
                    'request_id' => (string)$request['request_id'],
                    'ok' => true,
                    'payload' => [
                        'ready' => true,
                        'protocol' => GatewayPaths::PROTOCOL,
                        'protocol_min' => 2,
                        'protocol_max' => 2,
                        'supervisor_ready' => true,
                        'epoch' => \str_repeat('a', 32),
                    ],
                ];
                $response['signature'] = \hash_hmac(
                    'sha256',
                    GatewayClient::canonicalJson($response),
                    $secret,
                );
                @\fwrite(
                    $client,
                    \json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        . "\n",
                );
                @\fclose($client);
            }
            @\fclose($server);
            exit(0);
        }
        self::assertGreaterThan(0, $childPid);
        @\fclose($server);

        try {
            $host = new GatewayHostManager(
                $paths,
                new GatewayClient($paths, 0.25, $credentials),
                new HostGatewayPackageManager($paths),
                new GatewayPlatformServiceInstaller($paths),
            );
            $status = $host->status(2.0);
            self::assertTrue($status['ok']);
            self::assertTrue($status['ready']);
            self::assertSame(GatewayPaths::PROTOCOL, $status['protocol']);
            self::assertSame(\str_repeat('a', 32), $status['epoch']);
        } finally {
            $waited = \pcntl_waitpid($childPid, $status);
            self::assertSame($childPid, $waited);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        }
    }

    public function testForcedDrainSummaryDoesNotDoubleCountLongLivedRequests(): void
    {
        $summary = (new \ReflectionMethod(GatewayHostManager::class, 'forcedDrainSummary'))
            ->invoke(null, [
                'counters_known' => true,
                'active_requests' => 3,
                'long_lived_connections' => 2,
            ]);
        self::assertSame(3, $summary['forced_connections']);
        self::assertTrue($summary['forced_connections_known']);
        self::assertSame(3, $summary['forced_active_requests']);
        self::assertSame(2, $summary['forced_long_lived_connections']);

        $unknown = (new \ReflectionMethod(GatewayHostManager::class, 'forcedDrainSummary'))
            ->invoke(null, [
                'counters_known' => false,
                'active_requests' => 7,
                'long_lived_connections' => 5,
            ]);
        self::assertSame(0, $unknown['forced_connections']);
        self::assertFalse($unknown['forced_connections_known']);
        self::assertSame(7, $unknown['forced_active_requests']);
        self::assertSame(5, $unknown['forced_long_lived_connections']);
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @\rmdir($path);
    }
}
