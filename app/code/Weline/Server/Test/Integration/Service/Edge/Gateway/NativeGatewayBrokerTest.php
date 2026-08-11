<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;

final class NativeGatewayBrokerTest extends TestCase
{
    private string $root = '';
    private string $broker = '';

    protected function setUp(): void
    {
        if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_INTEGRATION') !== '1') {
            self::markTestSkipped('Set WLS_RUN_NATIVE_GATEWAY_INTEGRATION=1 for native broker integration.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The POSIX broker integration is not a Windows binary test.');
        }
        $temporaryRoot = (string)\realpath(
            \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir()
        );
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-ngb-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $this->broker = $this->root . DIRECTORY_SEPARATOR . 'wls-gateway-broker';
        $source = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Service'
            . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway'
            . DIRECTORY_SEPARATOR . 'Native' . DIRECTORY_SEPARATOR . 'posix'
            . DIRECTORY_SEPARATOR . 'wls_gateway_broker.c';
        self::assertFileExists($source);
        $sodiumCflags = $this->runCommand(['pkg-config', '--cflags', 'libsodium']);
        self::assertSame(0, $sodiumCflags['code'], $sodiumCflags['output']);
        $sodiumLdflags = $this->runCommand(['pkg-config', '--libs', 'libsodium']);
        self::assertSame(0, $sodiumLdflags['code'], $sodiumLdflags['output']);
        $sodiumCompileFlags = \array_values(\array_filter(
            \preg_split('/\s+/', \trim($sodiumCflags['output'])) ?: [],
            static fn (string $flag): bool => $flag !== '',
        ));
        $sodiumLinkFlags = \array_values(\array_filter(
            \preg_split('/\s+/', \trim($sodiumLdflags['output'])) ?: [],
            static fn (string $flag): bool => $flag !== '',
        ));
        $command = [
            'cc',
            '-std=c11',
            \PHP_OS_FAMILY === 'Darwin' ? '-D_DARWIN_C_SOURCE' : '-D_GNU_SOURCE',
            '-Wall',
            '-Wextra',
            '-Werror',
            '-fstack-protector-strong',
            '-pthread',
            ...$sodiumCompileFlags,
            $source,
            ...$sodiumLinkFlags,
            ...(\PHP_OS_FAMILY === 'Darwin' ? ['-lproc'] : []),
            '-o',
            $this->broker,
        ];
        $result = $this->runCommand($command);
        self::assertSame(0, $result['code'], $result['output']);
        self::assertTrue(\is_executable($this->broker));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testSelfTestAndNoFollowSnapshotKeepPreviousDestination(): void
    {
        $selfTest = $this->runCommand([$this->broker, '--self-test']);
        self::assertSame(0, $selfTest['code'], $selfTest['output']);

        $sourceRoot = $this->root . DIRECTORY_SEPARATOR . 'source';
        $destinationRoot = $this->root . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(\mkdir($sourceRoot, 0700));
        self::assertTrue(\mkdir($destinationRoot, 0700));
        self::assertSame(19, \file_put_contents(
            $sourceRoot . DIRECTORY_SEPARATOR . 'certificate.pem',
            'project-certificate',
        ));
        self::assertSame(15, \file_put_contents(
            $destinationRoot . DIRECTORY_SEPARATOR . 'active.pem',
            'previous-active',
        ));
        $copied = $this->snapshot(
            $sourceRoot,
            'certificate.pem',
            $destinationRoot,
            'active.pem',
        );
        self::assertSame(0, $copied['code'], $copied['output']);
        self::assertSame(
            'project-certificate',
            \file_get_contents($destinationRoot . DIRECTORY_SEPARATOR . 'active.pem'),
        );

        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(15, \file_put_contents(
                $destinationRoot . DIRECTORY_SEPARATOR . 'active.pem',
                'previous-active',
            ));
            self::assertTrue(\symlink(
                '/etc/hosts',
                $sourceRoot . DIRECTORY_SEPARATOR . 'escape.pem',
            ));
            $rejected = $this->snapshot(
                $sourceRoot,
                'escape.pem',
                $destinationRoot,
                'active.pem',
            );
            self::assertNotSame(0, $rejected['code']);
            self::assertSame(
                'previous-active',
                \file_get_contents($destinationRoot . DIRECTORY_SEPARATOR . 'active.pem'),
            );
        }
    }

    public function testDualChannelsBindKernelPeerIdentityAndOneFencingToken(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker channel test.');
        }
        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'broker.lock';
        $trust = $this->root . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\mkdir($trust, 0700));
        $fencingFile = $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token';
        $evidenceFile = $this->root . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            for ($index = 0; $index < 2; $index++) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)) {
                    exit(20);
                }
                if (!$this->authenticateBrokerProbe($client, $fencingFile)) {
                    exit(21);
                }
                $header = @\fgets($client, 4096);
                $payload = @\fgets($client, 4096);
                if (!\is_string($header) || !\is_string($payload)) {
                    exit(22);
                }
                \file_put_contents(
                    $evidenceFile,
                    \trim($header) . PHP_EOL,
                    FILE_APPEND | LOCK_EX,
                );
                @\fwrite($client, '{"protocol":"wls-edge/2","request_id":"native-test","ok":true}' . "\n");
                @\fclose($client);
            }
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        try {
            $log = $this->root . DIRECTORY_SEPARATOR . 'broker.log';
            $process = \proc_open([
                $this->broker,
                '--serve',
                '--admin-socket',
                $adminSocket,
                '--project-socket',
                $projectSocket,
                '--controller-socket',
                $controllerSocket,
                '--lock-file',
                $lockFile,
                '--fencing-file',
                $fencingFile,
                '--home',
                $this->root,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $this->waitForSocket($adminSocket);
            $this->waitForSocket($projectSocket);
            self::assertSame(0600, \fileperms($adminSocket) & 0777);
            self::assertSame(0622, \fileperms($projectSocket) & 0777);

            foreach ([$adminSocket, $projectSocket] as $socket) {
                $client = @\stream_socket_client('unix://' . $socket, $errno, $error, 2.0);
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite($client, '{"request_id":"native-test"}' . "\n"));
                $response = @\fgets($client, 4096);
                self::assertIsString(
                    $response,
                    'No broker response for ' . $socket . ': ' . (string)@\file_get_contents($log),
                );
                self::assertStringContainsString('"ok":true', $response);
                @\fclose($client);
            }

            $waited = \pcntl_waitpid($controllerPid, $controllerStatus);
            self::assertSame($controllerPid, $waited);
            self::assertTrue(\pcntl_wifexited($controllerStatus));
            self::assertSame(0, \pcntl_wexitstatus($controllerStatus));
            $lines = \file($evidenceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            self::assertCount(2, $lines);
            $headers = \array_map(
                static fn (string $line): array => \json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                $lines,
            );
            self::assertSame(['admin', 'project'], \array_column($headers, 'channel'));
            self::assertSame((int)\posix_geteuid(), (int)$headers[0]['uid']);
            self::assertSame((int)\posix_geteuid(), (int)$headers[1]['uid']);
            self::assertSame(2, (int)$headers[0]['action_protocol']);
            self::assertSame(2, (int)$headers[1]['action_protocol']);
            self::assertGreaterThan(0, (int)$headers[0]['pid']);
            self::assertSame($headers[0]['fencing_token'], $headers[1]['fencing_token']);
            self::assertMatchesRegularExpression(
                '/\A[a-f0-9]{64}\z/D',
                (string)$headers[0]['fencing_token'],
            );
        } finally {
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if ($controllerPid > 0) {
                \pcntl_waitpid($controllerPid, $ignored, WNOHANG);
            }
        }
    }

    public function testAdminStopActionUsesTheSharedPackageLockBeforePublishing(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker action test.');
        }
        $home = $this->root . DIRECTORY_SEPARATOR . 'stop-home';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\mkdir($trust, 0700, true));
        $token = \bin2hex(\random_bytes(32));
        $hostId = \bin2hex(\random_bytes(16));
        $epoch = \bin2hex(\random_bytes(16));
        self::assertSame(64, \file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            $token,
        ));
        self::assertSame(32, \file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));

        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'stop-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'stop-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'stop-controller.sock';
        $brokerLock = $this->root . DIRECTORY_SEPARATOR . 'stop-broker.lock';
        $fencingFile = $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);
        $action = "WLS-ACTION/2\tSTOP\t{$epoch}\n";

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            foreach (["WLS-ACTION/2\tERR\tBUSY\tSTOP\t-\t-", "WLS-ACTION/2\tOK\tSTOP\t-\t-"] as $expected) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)) exit(80);
                if (!$this->authenticateBrokerProbe($client, $fencingFile)
                    || !\is_string(@\fgets($client, 4096))
                    || !\is_string(@\fgets($client, 4096))
                    || @\fwrite($client, $action) !== \strlen($action)
                ) {
                    exit(81);
                }
                $ack = @\fgets($client, 4096);
                if (!\str_starts_with((string)$ack, $expected)) exit(82);
                @\fwrite(
                    $client,
                    '{"protocol":"wls-edge/2","request_id":"stop","ok":true}' . "\n",
                );
                @\fclose($client);
            }
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        $packageLock = null;
        try {
            $log = $this->root . DIRECTORY_SEPARATOR . 'stop-broker.log';
            $process = \proc_open([
                $this->broker,
                '--serve',
                '--admin-socket',
                $adminSocket,
                '--project-socket',
                $projectSocket,
                '--controller-socket',
                $controllerSocket,
                '--lock-file',
                $brokerLock,
                '--fencing-file',
                $fencingFile,
                '--home',
                $home,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $this->waitForSocket($adminSocket);

            $trigger = function () use ($adminSocket, $log): void {
                $client = @\stream_socket_client(
                    'unix://' . $adminSocket,
                    $errno,
                    $error,
                    2.0,
                );
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite($client, '{"request_id":"stop"}' . "\n"));
                self::assertStringContainsString(
                    '"ok":true',
                    (string)@\fgets($client, 4096),
                    (string)@\file_get_contents($log),
                );
                @\fclose($client);
            };

            $packageLock = @\fopen(
                $trust . DIRECTORY_SEPARATOR . 'package-install.lock',
                'c+b',
            );
            self::assertIsResource($packageLock);
            self::assertTrue(\flock($packageLock, LOCK_EX | LOCK_NB));
            $trigger();
            self::assertFileDoesNotExist(
                $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
            );
            self::assertTrue(\flock($packageLock, LOCK_UN));
            self::assertTrue(\fclose($packageLock));
            $packageLock = null;

            $trigger();
            $intent = \file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'admin-stopped.intent',
            );
            self::assertIsString($intent);
            self::assertMatchesRegularExpression(
                '/\AWLS-ADMIN-STOPPED\/1\n'
                    . 'host_id=' . $hostId . '\n'
                    . 'epoch=' . $epoch . '\n'
                    . 'at=[0-9]+\n'
                    . 'nonce=[a-f0-9]{32}\n'
                    . 'signature=[a-f0-9]{64}\n\z/D',
                $intent,
            );
            [$payload, $signature] = \explode('signature=', $intent, 2);
            self::assertSame(
                \hash_hmac('sha256', $payload, \hex2bin($token)),
                \trim($signature),
            );

            self::assertSame($controllerPid, \pcntl_waitpid($controllerPid, $status));
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            if (\is_resource($packageLock)) {
                @\flock($packageLock, LOCK_UN);
                @\fclose($packageLock);
            }
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if ($controllerPid > 0) {
                \pcntl_waitpid($controllerPid, $ignored, WNOHANG);
            }
        }
    }

    public function testBrokerSidebandAuthorizesAndSnapshotsOnlyEnrolledProjectSource(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker action test.');
        }
        $home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $snapshots = $home . DIRECTORY_SEPARATOR . 'snapshot-candidates-v2';
        $project = $this->root . DIRECTORY_SEPARATOR . 'project';
        $certificateRoot = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl';
        $digest = \str_repeat('a', 64);
        $privateDigest = \str_repeat('b', 64);
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0700, true));
        self::assertTrue(\mkdir($snapshots . DIRECTORY_SEPARATOR . $digest, 0700, true));
        self::assertTrue(\mkdir(
            $snapshots . DIRECTORY_SEPARATOR . $privateDigest,
            0700,
            true,
        ));
        self::assertTrue(\mkdir($certificateRoot, 0700, true));
        self::assertSame(18, \file_put_contents(
            $certificateRoot . DIRECTORY_SEPARATOR . 'source.pem',
            'project-owned-cert',
        ));
        self::assertTrue(\chmod($certificateRoot . DIRECTORY_SEPARATOR . 'source.pem', 0600));
        $privateKey = $certificateRoot . DIRECTORY_SEPARATOR . 'source-key.pem';
        self::assertSame(19, \file_put_contents($privateKey, 'project-private-key'));
        self::assertTrue(\chmod($privateKey, \PHP_OS_FAMILY === 'Darwin' ? 0600 : 0644));
        if (\PHP_OS_FAMILY === 'Darwin') {
            $acl = $this->runCommand([
                '/bin/chmod',
                '+a',
                'everyone allow read',
                $privateKey,
            ]);
            self::assertSame(0, $acl['code'], $acl['output']);
        }

        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'action-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'action-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'action-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'action-broker.lock';
        $fencingFile = $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $transactionId = \bin2hex(\random_bytes(16));
        $intentDigest = \hash('sha256', 'native-broker-auth-intent');
        $projectStatus = \stat((string)\realpath($project));
        $certificateStatus = \stat((string)\realpath($certificateRoot));
        self::assertIsArray($projectStatus);
        self::assertIsArray($certificateStatus);
        $projectObject = \dechex((int)$projectStatus['dev']) . '-'
            . \dechex((int)$projectStatus['ino']);
        $certificateObject = \dechex((int)$certificateStatus['dev']) . '-'
            . \dechex((int)$certificateStatus['ino']);
        $attestation = \hash('sha256', $projectUuid . "\n"
            . $transactionId . "\n" . $intentDigest . "\n1\n"
            . (string)\posix_geteuid() . "\nproject_ssl\n"
            . $projectObject . "\n" . $certificateObject . "\n");
        $rootsDigest = \hash(
            'sha256',
            "project_ssl\t1\t{$projectObject}\t{$certificateObject}\t{$attestation}\n",
        );

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            $actions = [
                ['WLS-ACTION/2' . "\t" . \implode("\t", [
                    'AUTH_PREPARE',
                    $projectUuid,
                    $transactionId,
                    $intentDigest,
                    (string)\posix_geteuid(),
                    'project_ssl',
                    \bin2hex((string)\realpath($project)),
                    \bin2hex((string)\realpath($certificateRoot)),
                    '1',
                    '0',
                ]) . "\n", true],
                ['WLS-ACTION/2' . "\t" . \implode("\t", [
                    'AUTH_COMMIT',
                    $projectUuid,
                    $transactionId,
                    $intentDigest,
                    '1',
                    $rootsDigest,
                ]) . "\n", true],
                ['WLS-ACTION/2' . "\t" . \implode("\t", [
                    'SNAP',
                    $projectUuid,
                    $transactionId,
                    $intentDigest,
                    'project_ssl',
                    \bin2hex('source.pem'),
                    $digest,
                    'source-cert.pem',
                ]) . "\n", true],
                ['WLS-ACTION/2' . "\t" . \implode("\t", [
                    'SNAP',
                    $projectUuid,
                    $transactionId,
                    $intentDigest,
                    'project_ssl',
                    \bin2hex('source-key.pem'),
                    $privateDigest,
                    'source-key.pem',
                ]) . "\n", false],
            ];
            foreach ($actions as [$action, $expectedSuccess]) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)) exit(30);
                if (!$this->authenticateBrokerProbe($client, $fencingFile)
                    || !\is_string(@\fgets($client, 4096))
                    || !\is_string(@\fgets($client, 4096))
                    || @\fwrite($client, $action) !== \strlen($action)
                ) {
                    exit(31);
                }
                $ack = @\fgets($client, 4096);
                @\file_put_contents(
                    $this->root . DIRECTORY_SEPARATOR . 'rollback-actions.log',
                    (string)$ack,
                    FILE_APPEND,
                );
                if (($expectedSuccess
                        && !\str_starts_with((string)$ack, "WLS-ACTION/2\tOK\t"))
                    || (!$expectedSuccess
                        && !\str_starts_with((string)$ack, "WLS-ACTION/2\tERR\t"))
                ) {
                    exit(32);
                }
                @\fwrite($client, '{"protocol":"wls-edge/2","request_id":"action","ok":true}' . "\n");
                @\fclose($client);
            }
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        try {
            $log = $this->root . DIRECTORY_SEPARATOR . 'action-broker.log';
            $process = \proc_open([
                $this->broker,
                '--serve',
                '--admin-socket',
                $adminSocket,
                '--project-socket',
                $projectSocket,
                '--controller-socket',
                $controllerSocket,
                '--lock-file',
                $lockFile,
                '--fencing-file',
                $fencingFile,
                '--home',
                $home,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $this->waitForSocket($adminSocket);
            $this->waitForSocket($projectSocket);
            foreach ([$adminSocket, $adminSocket, $projectSocket, $projectSocket] as $socket) {
                $client = @\stream_socket_client('unix://' . $socket, $errno, $error, 2.0);
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite($client, '{"request_id":"action"}' . "\n"));
                self::assertStringContainsString(
                    '"ok":true',
                    (string)@\fgets($client, 4096),
                    (string)@\file_get_contents($log) . "\n"
                        . (string)@\file_get_contents(
                            $this->root . DIRECTORY_SEPARATOR . 'rollback-actions.log',
                        ),
                );
                @\fclose($client);
            }
            self::assertSame(
                'project-owned-cert',
                \file_get_contents(
                    $snapshots . DIRECTORY_SEPARATOR . $digest
                    . DIRECTORY_SEPARATOR . 'source-cert.pem'
                ),
            );
            self::assertFileDoesNotExist(
                $snapshots . DIRECTORY_SEPARATOR . $privateDigest
                . DIRECTORY_SEPARATOR . 'source-key.pem',
            );
            $registry = $trust . DIRECTORY_SEPARATOR . 'broker-security-v2.tsv';
            self::assertFileExists($registry);
            self::assertSame(0600, \fileperms($registry) & 0777);
            $waited = \pcntl_waitpid($controllerPid, $controllerStatus);
            self::assertSame($controllerPid, $waited);
            self::assertTrue(\pcntl_wifexited($controllerStatus));
            self::assertSame(0, \pcntl_wexitstatus($controllerStatus));
        } finally {
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if ($controllerPid > 0) {
                \pcntl_waitpid($controllerPid, $ignored, WNOHANG);
            }
        }
    }

    public function testAtomicReplaceStreamsPayloadBeyondControlFrameAndCleansBoundBackup(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker action test.');
        }
        $home = $this->root . DIRECTORY_SEPARATOR . 'atomic-home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0700, true));
        $target = $state . DIRECTORY_SEPARATOR . 'nonce.wal';
        $temporary = $target . '.tmp-' . \bin2hex(\random_bytes(6));
        $payloadBytes = 5 * 1024 * 1024 + 17;
        $payload = \substr(
            \str_repeat("nonce-record\n", (int)\ceil($payloadBytes / 13)),
            0,
            $payloadBytes,
        );
        self::assertSame($payloadBytes, \strlen($payload));
        self::assertSame(8, \file_put_contents($target, 'previous'));
        self::assertSame($payloadBytes, \file_put_contents($temporary, $payload));
        self::assertTrue(\chmod($target, 0600));
        self::assertTrue(\chmod($temporary, 0600));
        $digest = \hash('sha256', $payload);

        // Persisted native evidence for a crash after replacement commit but
        // before Controller cleanup state could be checkpointed.
        $committedTarget = $state . DIRECTORY_SEPARATOR . 'lease-checkpoint.json';
        self::assertSame(15, \file_put_contents($committedTarget, 'committed-state'));
        self::assertTrue(\chmod($committedTarget, 0600));
        $committedDigest = \hash_file('sha256', $committedTarget);
        self::assertIsString($committedDigest);
        $committedOrphan = $committedTarget . '.wls-replace-backup-'
            . $committedDigest . '-' . \bin2hex(\random_bytes(8));
        self::assertSame(16, \file_put_contents($committedOrphan, 'previous-version'));
        self::assertTrue(\chmod($committedOrphan, 0600));

        // POSIX link+directory-fsync happens before rename. A crash in that
        // window leaves the target and backup as the same two-link inode.
        $precommitTarget = $state . DIRECTORY_SEPARATOR . 'publication-current.json';
        self::assertSame(15, \file_put_contents($precommitTarget, 'precommit-state'));
        self::assertTrue(\chmod($precommitTarget, 0600));
        $precommitOrphan = $precommitTarget . '.wls-replace-backup-'
            . \hash('sha256', 'future-publication') . '-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\link($precommitTarget, $precommitOrphan));
        self::assertSame(2, (int)(\stat($precommitTarget)['nlink'] ?? 0));

        $cleanupToken = \bin2hex(\random_bytes(8));
        $cleanupBackup = $target . '.wls-replace-backup-'
            . $digest . '-' . $cleanupToken;

        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'atomic-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'atomic-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'atomic-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'atomic-broker.lock';
        $fencingFile = $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token';
        $ackFile = $this->root . DIRECTORY_SEPARATOR . 'atomic-actions.log';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);
        $actions = [
            'WLS-ACTION/2' . "\t" . \implode("\t", [
                'ATOMIC_REPLACE',
                \bin2hex($temporary),
                \bin2hex($target),
                $digest,
                (string)$payloadBytes,
                '0600',
            ]) . "\n",
            'WLS-ACTION/2' . "\t" . \implode("\t", [
                'ATOMIC_REPLACE_CLEANUP',
                \bin2hex($target),
                $digest,
                (string)$payloadBytes,
                '0600',
                $cleanupToken,
            ]) . "\n",
        ];

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            foreach ($actions as $index => $action) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)) exit(90);
                if (!$this->authenticateBrokerProbe($client, $fencingFile)
                    || !\is_string(@\fgets($client, 4096))
                    || !\is_string(@\fgets($client, 4096))
                    || @\fwrite($client, $action) !== \strlen($action)
                ) {
                    exit(91);
                }
                $ack = @\fgets($client, 4096);
                @\file_put_contents($ackFile, (string)$ack, FILE_APPEND);
                $expectedOpcode = $index === 0
                    ? 'ATOMIC_REPLACE'
                    : 'ATOMIC_REPLACE_CLEANUP';
                if (!\str_starts_with(
                    (string)$ack,
                    "WLS-ACTION/2\tOK\t{$expectedOpcode}\t-\t-\t{$digest}\t{$payloadBytes}\t0600",
                )) {
                    exit(92);
                }
                @\fwrite(
                    $client,
                    '{"protocol":"wls-edge/2","request_id":"atomic","ok":true}' . "\n",
                );
                @\fclose($client);
            }
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        try {
            $log = $this->root . DIRECTORY_SEPARATOR . 'atomic-broker.log';
            $process = \proc_open([
                $this->broker,
                '--serve',
                '--admin-socket',
                $adminSocket,
                '--project-socket',
                $projectSocket,
                '--controller-socket',
                $controllerSocket,
                '--lock-file',
                $lockFile,
                '--fencing-file',
                $fencingFile,
                '--home',
                $home,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $this->waitForSocket($adminSocket);
            self::assertFileDoesNotExist($committedOrphan);
            self::assertFileDoesNotExist($precommitOrphan);
            self::assertSame(1, (int)(\stat($precommitTarget)['nlink'] ?? 0));
            foreach ($actions as $index => $_) {
                $client = @\stream_socket_client(
                    'unix://' . $adminSocket,
                    $errno,
                    $error,
                    2.0,
                );
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite(
                    $client,
                    '{"request_id":"atomic"}' . "\n",
                ));
                self::assertStringContainsString(
                    '"ok":true',
                    (string)@\fgets($client, 4096),
                    (string)@\file_get_contents($log),
                );
                @\fclose($client);
                if ($index === 0) {
                    self::assertSame(
                        15,
                        \file_put_contents($cleanupBackup, 'retained-backup'),
                    );
                    self::assertTrue(\chmod($cleanupBackup, 0600));
                }
            }
            self::assertFileDoesNotExist($temporary);
            self::assertFileDoesNotExist($cleanupBackup);
            self::assertSame($payloadBytes, \filesize($target));
            self::assertSame($digest, \hash_file('sha256', $target));
            self::assertSame(0600, \fileperms($target) & 0777);
            $acks = \file($ackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($acks);
            self::assertCount(2, $acks);
            self::assertSame($controllerPid, \pcntl_waitpid($controllerPid, $status));
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if ($controllerPid > 0) {
                \pcntl_waitpid($controllerPid, $ignored, WNOHANG);
            }
        }
    }

    public function testAtomicReplaceRecoveryRejectsAmbiguousBackupBeforeServing(): void
    {
        $home = $this->root . DIRECTORY_SEPARATOR . 'ambiguous-atomic-home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0700, true));
        $target = $state . DIRECTORY_SEPARATOR . 'gateway-state.json';
        self::assertSame(14, \file_put_contents($target, 'current-target'));
        self::assertTrue(\chmod($target, 0600));
        $backup = $target . '.wls-replace-backup-'
            . \hash('sha256', 'different-future-target') . '-'
            . \bin2hex(\random_bytes(8));
        self::assertSame(15, \file_put_contents($backup, 'previous-target'));
        self::assertTrue(\chmod($backup, 0600));

        $result = $this->runCommand([
            $this->broker,
            '--serve',
            '--admin-socket',
            $this->root . DIRECTORY_SEPARATOR . 'ambiguous-admin.sock',
            '--project-socket',
            $this->root . DIRECTORY_SEPARATOR . 'ambiguous-project.sock',
            '--controller-socket',
            $this->root . DIRECTORY_SEPARATOR . 'ambiguous-controller.sock',
            '--lock-file',
            $this->root . DIRECTORY_SEPARATOR . 'ambiguous-broker.lock',
            '--fencing-file',
            $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token',
            '--home',
            $home,
        ]);

        self::assertNotSame(0, $result['code'], $result['output']);
        self::assertStringContainsString(
            'atomic replace backup recovery refused startup',
            $result['output'],
        );
        self::assertSame('current-target', \file_get_contents($target));
        self::assertFileExists($backup);

        $malformedHome = $this->root . DIRECTORY_SEPARATOR
            . 'malformed-atomic-home';
        $malformedState = $malformedHome . DIRECTORY_SEPARATOR . 'state';
        $malformedTrust = $malformedHome . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\mkdir($malformedState, 0700, true));
        self::assertTrue(\mkdir($malformedTrust, 0700, true));
        $malformed = $malformedState . DIRECTORY_SEPARATOR
            . 'gateway-state.json.wls-replace-backup-'
            . \str_repeat('A', 64) . '-' . \str_repeat('4', 16);
        self::assertSame(9, \file_put_contents($malformed, 'malformed'));
        self::assertTrue(\chmod($malformed, 0600));
        $malformedResult = $this->runCommand([
            $this->broker,
            '--serve',
            '--admin-socket',
            $this->root . DIRECTORY_SEPARATOR . 'malformed-admin.sock',
            '--project-socket',
            $this->root . DIRECTORY_SEPARATOR . 'malformed-project.sock',
            '--controller-socket',
            $this->root . DIRECTORY_SEPARATOR . 'malformed-controller.sock',
            '--lock-file',
            $this->root . DIRECTORY_SEPARATOR . 'malformed-broker.lock',
            '--fencing-file',
            $malformedTrust . DIRECTORY_SEPARATOR . 'broker-fencing-token',
            '--home',
            $malformedHome,
        ]);
        self::assertNotSame(0, $malformedResult['code'], $malformedResult['output']);
        self::assertStringContainsString(
            'atomic replace backup recovery refused startup',
            $malformedResult['output'],
        );
        self::assertFileExists($malformed);
    }

    public function testSlowRequestDoesNotBlockStatusAndDisconnectedClientCannotKillBroker(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker concurrency test.');
        }
        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'concurrent-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'concurrent-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'concurrent-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'concurrent-broker.lock';
        $trust = $this->root . DIRECTORY_SEPARATOR . 'trust';
        self::assertTrue(\mkdir($trust, 0700));
        $fencingFile = $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token';
        $slowAccepted = $this->root . DIRECTORY_SEPARATOR . 'slow-accepted';
        $slowResponded = $this->root . DIRECTORY_SEPARATOR . 'slow-responded';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            $slow = @\stream_socket_accept($controller, 5);
            if (!\is_resource($slow)
                || !$this->authenticateBrokerProbe($slow, $fencingFile)
                || !\is_string(@\fgets($slow, 4096))
                || !\is_string(@\fgets($slow, 4096))) {
                exit(40);
            }
            \file_put_contents($slowAccepted, '1');

            $fast = @\stream_socket_accept($controller, 5);
            if (!\is_resource($fast)
                || !$this->authenticateBrokerProbe($fast, $fencingFile)
                || !\is_string(@\fgets($fast, 4096))
                || !\is_string(@\fgets($fast, 4096))
                || @\fwrite($fast, '{"request_id":"fast","ok":true}' . "\n") === false) {
                exit(41);
            }
            @\fclose($fast);
            \usleep(300_000);
            if (@\fwrite($slow, '{"request_id":"slow","ok":true}' . "\n") === false) {
                exit(42);
            }
            @\fclose($slow);
            \file_put_contents($slowResponded, '1');

            $liveness = @\stream_socket_accept($controller, 5);
            if (!\is_resource($liveness)
                || !$this->authenticateBrokerProbe($liveness, $fencingFile)
                || !\is_string(@\fgets($liveness, 4096))
                || !\is_string(@\fgets($liveness, 4096))
                || @\fwrite($liveness, '{"request_id":"alive","ok":true}' . "\n") === false) {
                exit(43);
            }
            @\fclose($liveness);
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        try {
            $log = $this->root . DIRECTORY_SEPARATOR . 'concurrent-broker.log';
            $process = \proc_open([
                $this->broker,
                '--serve',
                '--admin-socket',
                $adminSocket,
                '--project-socket',
                $projectSocket,
                '--controller-socket',
                $controllerSocket,
                '--lock-file',
                $lockFile,
                '--fencing-file',
                $fencingFile,
                '--home',
                $this->root,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $this->waitForSocket($adminSocket);
            $this->waitForSocket($projectSocket);

            $slow = @\stream_socket_client('unix://' . $adminSocket, $errno, $error, 2.0);
            self::assertIsResource($slow, $error);
            self::assertNotFalse(@\fwrite($slow, '{"request_id":"slow"}' . "\n"));
            $this->waitForFile($slowAccepted);
            @\fclose($slow);

            $fastStarted = \microtime(true);
            $fast = @\stream_socket_client('unix://' . $adminSocket, $errno, $error, 2.0);
            self::assertIsResource($fast, $error);
            \stream_set_timeout($fast, 2);
            self::assertNotFalse(@\fwrite($fast, '{"request_id":"fast"}' . "\n"));
            self::assertStringContainsString('"request_id":"fast"', (string)@\fgets($fast, 4096));
            self::assertLessThan(1.0, \microtime(true) - $fastStarted);
            @\fclose($fast);

            $this->waitForFile($slowResponded);
            \usleep(100_000);
            $status = \proc_get_status($process);
            self::assertTrue($status['running'] ?? false, (string)@\file_get_contents($log));

            $alive = @\stream_socket_client('unix://' . $projectSocket, $errno, $error, 2.0);
            self::assertIsResource($alive, $error);
            self::assertNotFalse(@\fwrite($alive, '{"request_id":"alive"}' . "\n"));
            self::assertStringContainsString('"request_id":"alive"', (string)@\fgets($alive, 4096));
            @\fclose($alive);

            $waited = \pcntl_waitpid($controllerPid, $controllerStatus);
            self::assertSame($controllerPid, $waited);
            self::assertTrue(\pcntl_wifexited($controllerStatus));
            self::assertSame(0, \pcntl_wexitstatus($controllerStatus));
        } finally {
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if ($controllerPid > 0) {
                \pcntl_waitpid($controllerPid, $ignored, WNOHANG);
            }
        }
    }

    public function testControllerRestartRetainsSocketsForIsolationReplayAndRouteDegradedAdmission(): void
    {
        if (!\function_exists('posix_getpwuid') || !\function_exists('posix_kill')) {
            self::markTestSkipped('POSIX identity and signals are required for controller restart.');
        }
        $account = \posix_getpwuid(\posix_geteuid());
        self::assertIsArray($account);
        $controllerUser = (string)($account['name'] ?? '');
        self::assertNotSame('', $controllerUser);
        $dataPlaneUser = '';
        foreach (\PHP_OS_FAMILY === 'Darwin'
            ? ['_nobody', 'daemon']
            : ['nobody', 'daemon'] as $candidateUser
        ) {
            $candidate = @\posix_getpwnam($candidateUser);
            if (\is_array($candidate)
                && (int)($candidate['uid'] ?? 0) > 0
                && (int)($candidate['uid'] ?? 0) !== \posix_geteuid()
            ) {
                $dataPlaneUser = $candidateUser;
                break;
            }
        }
        self::assertNotSame('', $dataPlaneUser);
        self::assertTrue(\chgrp($this->root, \posix_getegid()));

        $home = $this->root . DIRECTORY_SEPARATOR . 'restart-home';
        self::assertTrue(\mkdir($home, 0700));
        self::assertTrue(\mkdir($home . DIRECTORY_SEPARATOR . 'trust', 0700));
        $adminToken = \bin2hex(\random_bytes(32));
        $hostId = \bin2hex(\random_bytes(16));
        $adminTokenFile = $home . DIRECTORY_SEPARATOR . 'trust/admin.token';
        $hostIdFile = $home . DIRECTORY_SEPARATOR . 'trust/host-id';
        self::assertSame(64, \file_put_contents($adminTokenFile, $adminToken));
        self::assertSame(32, \file_put_contents($hostIdFile, $hostId));
        self::assertTrue(\chmod($adminTokenFile, 0600));
        self::assertTrue(\chmod($hostIdFile, 0600));
        $runtimeGeneration = \str_repeat('0', 64);
        $slotBin = $home . DIRECTORY_SEPARATOR . 'slots/A/bin';
        self::assertTrue(\mkdir($slotBin, 0700, true));
        $slotPhp = $slotBin . DIRECTORY_SEPARATOR . 'php';
        self::assertTrue(\copy(\PHP_BINARY, $slotPhp));
        self::assertTrue(\chmod($slotPhp, 0700));
        $slotNginx = $slotBin . DIRECTORY_SEPARATOR . 'nginx';
        self::assertTrue(\copy(\PHP_BINARY, $slotNginx));
        self::assertTrue(\chmod($slotNginx, 0700));
        $slotNginxCanonical = \realpath($slotNginx);
        self::assertIsString($slotNginxCanonical);
        self::assertNotFalse(\file_put_contents(
            $home . DIRECTORY_SEPARATOR . 'slots/A/manifest.json',
            \json_encode(
                ['runtime_generation' => $runtimeGeneration],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        ));
        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'restart-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'restart-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'restart-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'restart-broker.lock';
        $fencingFile = $home . DIRECTORY_SEPARATOR . 'trust/broker-fencing-token';
        $pidFile = $home . DIRECTORY_SEPARATOR . 'controller-pids.log';
        $controllerScript = $this->root . DIRECTORY_SEPARATOR . 'restart-controller.php';
        $controllerSource = <<<'PHP'
<?php
declare(strict_types=1);
$home = '';
$endpoint = '';
$fencingFile = '';
$hostBootId = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--home=')) {
        $home = substr($argument, 7);
    } elseif (str_starts_with($argument, '--broker-internal=unix://')) {
        $endpoint = substr($argument, strlen('--broker-internal=unix://'));
    } elseif (str_starts_with($argument, '--broker-fencing-file=')) {
        $fencingFile = substr($argument, strlen('--broker-fencing-file='));
    } elseif (str_starts_with($argument, '--host-boot-id=')) {
        $hostBootId = substr($argument, strlen('--host-boot-id='));
    }
}
if ($home === '' || $endpoint === '' || $fencingFile === ''
    || preg_match('/\A[a-f0-9]{64}\z/D', $hostBootId) !== 1
) {
    exit(64);
}
function authenticateBroker($client, string $fencingFile): bool
{
    $fencing = trim((string)@file_get_contents($fencingFile));
    $probe = @fgets($client, 512);
    if (preg_match(
        '/\AWLS-BROKER-PROBE\/1\t([a-f0-9]{64})\t([a-f0-9]{64})\n\z/D',
        is_string($probe) ? $probe : '',
        $matches,
    ) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $fencing) !== 1) {
        return false;
    }
    $key = hex2bin($fencing);
    if (!is_string($key) || strlen($key) !== 32) {
        return false;
    }
    try {
        $nonce = (string)$matches[1];
        if (!hash_equals(
            hash_hmac('sha256', "WLS-BROKER-PROBE/1\nnonce={$nonce}\n", $key),
            (string)$matches[2],
        )) {
            return false;
        }
        $response = "WLS-BROKER-READY/1\t"
            . hash_hmac('sha256', "WLS-BROKER-READY/1\nnonce={$nonce}\n", $key)
            . "\n";
        $offset = 0;
        while ($offset < strlen($response)) {
            $written = @fwrite($client, substr($response, $offset));
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }
        return true;
    } finally {
        sodium_memzero($key);
    }
}
function canonicalJson(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (!is_array($item)) {
            return $item;
        }
        if (!array_is_list($item)) {
            ksort($item, SORT_STRING);
        }
        foreach ($item as $key => $child) {
            $item[$key] = $normalize($child);
        }
        return $item;
    };
    return (string)json_encode(
        $normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    );
}
$adminToken = trim((string)@file_get_contents(
    $home . DIRECTORY_SEPARATOR . 'trust/admin.token',
));
$hostId = trim((string)@file_get_contents(
    $home . DIRECTORY_SEPARATOR . 'trust/host-id',
));
$manifest = json_decode((string)@file_get_contents(
    $home . DIRECTORY_SEPARATOR . 'slots/A/manifest.json',
), true);
$runtimeGeneration = is_array($manifest)
    ? (string)($manifest['runtime_generation'] ?? '')
    : '';
if (preg_match('/\A[a-f0-9]{64}\z/D', $adminToken) !== 1
    || preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1
    || preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
) {
    exit(66);
}
@unlink($endpoint);
$server = stream_socket_server(
    'unix://' . $endpoint,
    $errorNumber,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
);
if (!is_resource($server)) {
    exit(65);
}
file_put_contents(
    $home . DIRECTORY_SEPARATOR . 'controller-pids.log',
    getmypid() . PHP_EOL,
    FILE_APPEND | LOCK_EX,
);
while (true) {
    $client = @stream_socket_accept($server, 5);
    if (!is_resource($client)) {
        continue;
    }
    if (!authenticateBroker($client, $fencingFile)) {
        @fclose($client);
        continue;
    }
    $header = @fgets($client, 4096);
    if (!is_string($header)) {
        @fclose($client);
        continue;
    }
    $requestLine = @fgets($client, 4096);
    $request = is_string($requestLine) ? json_decode($requestLine, true) : null;
    $requestId = is_array($request) ? (string)($request['request_id'] ?? '') : '';
    if (is_array($request)
        && ($request['operation'] ?? null) === 'bootstrap'
        && $requestId !== ''
    ) {
        $epoch = str_repeat('1', 32);
        $admissionState = trim((string)@file_get_contents(
            $home . DIRECTORY_SEPARATOR . 'bootstrap-admission-state',
        ));
        if (!in_array($admissionState, [
            'HEALTHY',
            'ISOLATION_REPLAY',
            'ROUTE_DEGRADED',
        ], true)) {
            $admissionState = 'HEALTHY';
        }
        $healthy = $admissionState === 'HEALTHY';
        $isolation = $admissionState === 'ISOLATION_REPLAY';
        $healthState = $healthy
            ? 'HEALTHY'
            : ($isolation ? 'STATE_REBUILD' : 'ROUTE_DEGRADED');
        $recoveryStage = $healthy
            ? 'NONE'
            : ($isolation ? 'STATE_REBUILD' : 'ROUTE_DEGRADED');
        $payload = [
            'ready' => $healthy,
            'generation' => 1,
            'active_config_generation' => 1,
            'publication_generation' => 1,
            'gateway_epoch' => $epoch,
            'controller_epoch' => $epoch,
            'active_slot' => 'A',
            'runtime_generation' => $runtimeGeneration,
            'host_boot_id' => $hostBootId,
            'upgrade_intent_sha256' => str_repeat('0', 64),
            'upgrade_intent_nonce' => str_repeat('0', 32),
            'data_plane' => 'RUNNING',
            'recovery_pending' => !$healthy,
            'admission_state' => $admissionState,
            'promotion_eligible' => $healthy,
            'guardian_continuity_healthy' => true,
            'control_plane_ready' => true,
            'isolation_mode' => $isolation,
            'health_state' => $healthState,
            'recovery_stage' => $recoveryStage,
            'route_failure_kind' => $admissionState === 'ROUTE_DEGRADED'
                ? 'backend_transport'
                : '',
        ];
        $response = [
            'protocol' => 'wls-edge/2',
            'request_id' => $requestId,
            'ok' => true,
            'epoch' => $epoch,
            'payload' => $payload,
        ];
        $response['signature'] = hash_hmac(
            'sha256',
            canonicalJson($response),
            $adminToken,
        );
        $responseLine = json_encode($response, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $responseOffset = 0;
        while ($responseOffset < strlen($responseLine)) {
            $responseWritten = @fwrite($client, substr($responseLine, $responseOffset));
            if (!is_int($responseWritten) || $responseWritten < 1) {
                exit(67);
            }
            $responseOffset += $responseWritten;
        }
        @fclose($client);
        continue;
    }
    @fwrite(
        $client,
        json_encode([
            'protocol' => 'wls-edge/2',
            'request_id' => $requestId,
            'ok' => is_string($header) && $requestId !== '',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );
    @fclose($client);
}
PHP;
        self::assertSame(
            \strlen($controllerSource),
            \file_put_contents($controllerScript, $controllerSource),
        );

        $nginxProcess = \proc_open([
            $slotNginx,
            '-r',
            'sleep(120);',
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ], $nginxPipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($nginxProcess);
        $nginxStatus = \proc_get_status($nginxProcess);
        $nginxPid = (int)($nginxStatus['pid'] ?? 0);
        self::assertGreaterThan(0, $nginxPid);
        $nginxIdentityDeadline = \microtime(true) + 3.0;
        do {
            $nginxIdentityFields = [];
            $nginxIdentity = $this->runCommand([
                $this->broker,
                '--process-identity-self-test',
                (string)$nginxPid,
            ]);
            $nginxIdentityMatches = $nginxIdentity['code'] === 0
                && \preg_match(
                    '/\AWLS-PROCESS-IDENTITY\/1\npid=(\d+)\n'
                        . 'start_id=(\d+)\nexecutable=([^\r\n]+)\z/D',
                    $nginxIdentity['output'],
                    $nginxIdentityFields,
                ) === 1;
            if (!$nginxIdentityMatches) {
                \usleep(10_000);
            }
        } while (!$nginxIdentityMatches && \microtime(true) < $nginxIdentityDeadline);
        self::assertSame(0, $nginxIdentity['code'], $nginxIdentity['output']);
        self::assertTrue($nginxIdentityMatches, $nginxIdentity['output']);
        self::assertSame($nginxPid, (int)$nginxIdentityFields[1]);
        self::assertSame($slotNginxCanonical, (string)$nginxIdentityFields[3]);
        $zeroDigest = \str_repeat('0', 64);
        $processAttestation = "WLS-PROCESS-ATTEST/3\n"
            . 'pid=' . $nginxPid . "\n"
            . 'start_id=' . (string)$nginxIdentityFields[2] . "\n"
            . 'binary_digest=' . (string)\hash_file('sha256', $slotNginx) . "\n"
            . 'runtime_generation=' . $runtimeGeneration . "\n"
            . 'config_digest=' . $zeroDigest . "\n"
            . 'config_path_digest=' . $zeroDigest . "\n"
            . "publication_generation=1\n"
            . "fence_kind=ACTIVE\n"
            . "candidate_transaction_id=-\n"
            . "candidate_phase=ACTIVE\n"
            . 'candidate_fence_digest=' . $zeroDigest . "\n";
        $processAttestationFile = $home
            . DIRECTORY_SEPARATOR . 'trust/process-attestation.receipt';
        self::assertSame(
            \strlen($processAttestation),
            \file_put_contents($processAttestationFile, $processAttestation),
        );
        self::assertTrue(\chmod($processAttestationFile, 0600));

        $log = $this->root . DIRECTORY_SEPARATOR . 'restart-broker.log';
        $process = \proc_open([
            $this->broker,
            '--serve',
            '--admin-socket',
            $adminSocket,
            '--project-socket',
            $projectSocket,
            '--controller-socket',
            $controllerSocket,
            '--lock-file',
            $lockFile,
            '--fencing-file',
            $fencingFile,
            '--php',
            $slotPhp,
            '--controller',
            $controllerScript,
            '--home',
            $home,
            '--controller-user',
            $controllerUser,
            '--data-plane-user',
            $dataPlaneUser,
            '--active-slot',
            'A',
            '--runtime-generation',
            $runtimeGeneration,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        try {
            $deadline = \microtime(true) + 3.0;
            while (!\file_exists($adminSocket) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists(
                $adminSocket,
                (string)@\file_get_contents($log),
            );
            $firstPids = $this->waitForControllerPids($pidFile, 1, $log);
            $brokerStatus = \proc_get_status($process);
            $brokerPid = (int)($brokerStatus['pid'] ?? 0);
            self::assertGreaterThan(0, $brokerPid);
            $socketStatus = \lstat($adminSocket);
            self::assertIsArray($socketStatus);
            $socketInode = (int)$socketStatus['ino'];
            self::assertGreaterThan(0, $socketInode);

            $firstControllerPid = $firstPids[0];
            self::assertSame(16, \file_put_contents(
                $home . DIRECTORY_SEPARATOR . 'bootstrap-admission-state',
                'ISOLATION_REPLAY',
            ));
            self::assertTrue(\posix_kill($firstControllerPid, SIGTERM));
            $controllerPids = $this->waitForControllerPids($pidFile, 2, $log);
            self::assertNotSame($firstControllerPid, $controllerPids[1]);

            $after = \proc_get_status($process);
            self::assertTrue($after['running'] ?? false, (string)@\file_get_contents($log));
            self::assertSame($brokerPid, (int)($after['pid'] ?? 0));
            $afterSocket = \lstat($adminSocket);
            self::assertIsArray($afterSocket);
            self::assertSame($socketInode, (int)$afterSocket['ino']);

            $client = @\stream_socket_client(
                'unix://' . $adminSocket,
                $errorNumber,
                $errorMessage,
                2.0,
            );
            self::assertIsResource($client, $errorMessage);
            self::assertNotFalse(@\fwrite(
                $client,
                '{"request_id":"after-controller-restart"}' . "\n",
            ));
            $response = (string)@\fgets($client, 4096);
            @\fclose($client);
            self::assertStringContainsString(
                '"request_id":"after-controller-restart"',
                $response,
            );
            self::assertStringContainsString('"ok":true', $response);

            self::assertSame(14, \file_put_contents(
                $home . DIRECTORY_SEPARATOR . 'bootstrap-admission-state',
                'ROUTE_DEGRADED',
            ));
            self::assertTrue(\posix_kill($controllerPids[1], SIGTERM));
            $thirdPids = $this->waitForControllerPids($pidFile, 3, $log);
            self::assertNotSame($controllerPids[1], $thirdPids[2]);
            $afterRouteDegraded = \proc_get_status($process);
            self::assertTrue(
                $afterRouteDegraded['running'] ?? false,
                (string)@\file_get_contents($log),
            );
            self::assertSame($brokerPid, (int)($afterRouteDegraded['pid'] ?? 0));
            $routeDegradedSocket = \lstat($adminSocket);
            self::assertIsArray($routeDegradedSocket);
            self::assertSame($socketInode, (int)$routeDegradedSocket['ino']);
        } finally {
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if (\is_resource($nginxProcess)) {
                $nginxStatus = \proc_get_status($nginxProcess);
                if ($nginxStatus['running'] ?? false) {
                    \posix_kill((int)$nginxStatus['pid'], SIGTERM);
                }
                \proc_close($nginxProcess);
            }
        }
    }

    public function testRollbackRequestPublicationIsLockedBoundAndRecoveryEvidenceSafe(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the rollback action test.');
        }
        $home = $this->root . DIRECTORY_SEPARATOR . 'rollback-home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $snapshots = $home . DIRECTORY_SEPARATOR . 'snapshots';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0700, true));
        self::assertTrue(\mkdir($snapshots, 0700, true));

        $hostId = \bin2hex(\random_bytes(16));
        $adminSecret = \random_bytes(32);
        $preparedAt = \time() - 5;
        $requestedMonotonic = \intdiv(\hrtime(true), 1_000_000);
        $preparedMonotonic = $requestedMonotonic - 5_000;
        self::assertGreaterThan(0, $preparedMonotonic);
        $hostBootId = GatewayHostBootIdentity::current();
        $runtimeGeneration = \str_repeat('d', 64);
        $intentNonce = \bin2hex(\random_bytes(16));
        $requestNonce = \bin2hex(\random_bytes(16));
        $intentPayload = "WLS-UPGRADE/2\n"
            . 'host_id=' . $hostId . "\n"
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
            . 'nonce=' . $intentNonce . "\n";
        $intent = $intentPayload . 'signature='
            . \hash_hmac('sha256', $intentPayload, $adminSecret) . "\n";
        $intentDigest = \hash('sha256', $intent);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex($adminSecret),
        ));
        \sodium_memzero($adminSecret);
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $upgradeState = "WLS-UPGRADE-STATE/3\n"
            . 'intent_sha256=' . $intentDigest . "\n"
            . 'intent_nonce=' . $intentNonce . "\n"
            . "from=B\nto=A\n"
            . 'runtime_generation=' . $runtimeGeneration . "\n"
            . 'boot_id=' . $hostBootId . "\n"
            . "phase=PREPARED\nattempts=0\n"
            . 'prepared_monotonic_ms=' . $preparedMonotonic . "\n"
            . "observation_started_monotonic_ms=0\n"
            . "observation_deadline_monotonic_ms=0\n"
            . 'total_deadline_monotonic_ms='
                . ($preparedMonotonic + 900_000) . "\n";

        $request = $state . DIRECTORY_SEPARATOR . 'upgrade-rollback.request';
        $expectedRequest = "WLS-UPGRADE-ROLLBACK/3\n"
            . 'intent_sha256=' . $intentDigest . "\n"
            . 'intent_nonce=' . $intentNonce . "\n"
            . "from=A\nto=B\n"
            . 'host_boot_id=' . $hostBootId . "\n"
            . 'requested_monotonic_ms=' . $requestedMonotonic . "\n"
            . 'request_nonce=' . $requestNonce . "\n";
        $action = 'WLS-ACTION/2' . "\t" . \implode("\t", [
            'ROLLBACK_REQUEST',
            $requestNonce,
            $intentDigest,
            $intentNonce,
            'A',
            'B',
            (string)$requestedMonotonic,
        ]) . "\n";
        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'rollback-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'rollback-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'rollback-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'rollback-broker.lock';
        $fencingFile = $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            foreach ([false, true, true, false] as $expectedSuccess) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)) exit(70);
                if (!$this->authenticateBrokerProbe($client, $fencingFile)
                    || !\is_string(@\fgets($client, 4096))
                    || !\is_string(@\fgets($client, 4096))
                    || @\fwrite($client, $action) !== \strlen($action)
                ) {
                    exit(71);
                }
                $ack = @\fgets($client, 4096);
                if (($expectedSuccess
                        && !\str_starts_with(
                            (string)$ack,
                            "WLS-ACTION/2\tOK\tROLLBACK_REQUEST\t",
                        ))
                    || (!$expectedSuccess
                        && !\str_starts_with(
                            (string)$ack,
                            "WLS-ACTION/2\tERR\tROLLBACK_REQUEST_",
                        ))
                ) {
                    @\fwrite(
                        $client,
                        \json_encode([
                            'protocol' => 'wls-edge/2',
                            'request_id' => 'rollback',
                            'ok' => false,
                            'ack' => (string)$ack,
                        ], JSON_UNESCAPED_SLASHES) . "\n",
                    );
                    @\fclose($client);
                    exit(72);
                }
                @\fwrite(
                    $client,
                    '{"protocol":"wls-edge/2","request_id":"rollback","ok":true}' . "\n",
                );
                @\fclose($client);
            }
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        try {
            $log = $this->root . DIRECTORY_SEPARATOR . 'rollback-broker.log';
            $process = \proc_open([
                $this->broker,
                '--serve',
                '--admin-socket',
                $adminSocket,
                '--project-socket',
                $projectSocket,
                '--controller-socket',
                $controllerSocket,
                '--lock-file',
                $lockFile,
                '--fencing-file',
                $fencingFile,
                '--home',
                $home,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $deadline = \microtime(true) + 3.0;
            while (!\file_exists($projectSocket) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists(
                $projectSocket,
                (string)@\file_get_contents($log),
            );
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'active-slot',
                "A\n",
            ));
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
                $intent,
            ));
            self::assertNotFalse(\file_put_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade-state',
                $upgradeState,
            ));

            $trigger = function () use ($projectSocket, $log): void {
                $client = @\stream_socket_client(
                    'unix://' . $projectSocket,
                    $errno,
                    $error,
                    2.0,
                );
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite(
                    $client,
                    '{"request_id":"rollback"}' . "\n",
                ));
                self::assertStringContainsString(
                    '"ok":true',
                    (string)@\fgets($client, 4096),
                    (string)@\file_get_contents($log) . "\n"
                        . (string)@\file_get_contents(
                            $this->root . DIRECTORY_SEPARATOR . 'rollback-actions.log',
                        ),
                );
                @\fclose($client);
            };

            $packageLock = @\fopen(
                $trust . DIRECTORY_SEPARATOR . 'package-install.lock',
                'c+b',
            );
            self::assertIsResource($packageLock);
            self::assertTrue(\flock($packageLock, LOCK_EX | LOCK_NB));
            $trigger();
            self::assertFileDoesNotExist($request);
            self::assertTrue(\flock($packageLock, LOCK_UN));
            self::assertTrue(\fclose($packageLock));

            $trigger();
            self::assertSame($expectedRequest, \file_get_contents($request));

            $backup = $request . '.wls-backup-' . \str_repeat('c', 16);
            self::assertTrue(\copy($request, $backup));
            $trigger();
            self::assertFileDoesNotExist($backup);
            self::assertSame($expectedRequest, \file_get_contents($request));

            self::assertTrue(\copy($request, $backup));
            self::assertNotFalse(\file_put_contents($request, "corrupt\n"));
            $trigger();
            self::assertSame("corrupt\n", \file_get_contents($request));
            self::assertSame($expectedRequest, \file_get_contents($backup));
            self::assertSame($intent, \file_get_contents(
                $trust . DIRECTORY_SEPARATOR . 'upgrade.intent',
            ));

            self::assertSame($controllerPid, \pcntl_waitpid($controllerPid, $status));
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            if (\is_resource($process)) {
                $status = \proc_get_status($process);
                if ($status['running'] ?? false) {
                    \posix_kill((int)$status['pid'], SIGTERM);
                }
                \proc_close($process);
            }
            if ($controllerPid > 0) {
                \pcntl_waitpid($controllerPid, $ignored, WNOHANG);
            }
        }
    }

    /** @return array{code:int,output:string} */
    private function snapshot(
        string $sourceRoot,
        string $sourceRelative,
        string $destinationRoot,
        string $destinationRelative,
    ): array {
        return $this->runCommand([
            $this->broker,
            '--snapshot',
            '--source-root',
            $sourceRoot,
            '--source-relative',
            $sourceRelative,
            '--destination-root',
            $destinationRoot,
            '--destination-relative',
            $destinationRelative,
        ]);
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

    private function waitForSocket(string $path): void
    {
        $deadline = \microtime(true) + 3.0;
        do {
            if (\file_exists($path)) {
                return;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        self::fail('Native broker socket did not appear: ' . $path);
    }

    private function waitForFile(string $path): void
    {
        $deadline = \microtime(true) + 3.0;
        do {
            if (\is_file($path)) {
                return;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        self::fail('Native broker evidence did not appear: ' . $path);
    }

    /** @param resource $client */
    private function authenticateBrokerProbe($client, string $fencingFile): bool
    {
        if (!\is_resource($client)) {
            return false;
        }
        \stream_set_timeout($client, 3);
        $deadline = \microtime(true) + 3.0;
        $fencing = '';
        do {
            $contents = @\file_get_contents($fencingFile);
            if (\is_string($contents)) {
                $fencing = \trim($contents);
            }
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $fencing) === 1) {
                break;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        $probe = @\fgets($client, 512);
        if (\preg_match(
            '/\AWLS-BROKER-PROBE\/1\t([a-f0-9]{64})\t([a-f0-9]{64})\n\z/D',
            \is_string($probe) ? $probe : '',
            $matches,
        ) !== 1) {
            return false;
        }
        $key = \hex2bin($fencing);
        if (!\is_string($key) || \strlen($key) !== 32) {
            return false;
        }
        try {
            $nonce = (string)$matches[1];
            $expected = \hash_hmac(
                'sha256',
                "WLS-BROKER-PROBE/1\nnonce={$nonce}\n",
                $key,
            );
            if (!\hash_equals($expected, (string)$matches[2])) {
                return false;
            }
            $response = "WLS-BROKER-READY/1\t"
                . \hash_hmac(
                    'sha256',
                    "WLS-BROKER-READY/1\nnonce={$nonce}\n",
                    $key,
                ) . "\n";
            $offset = 0;
            while ($offset < \strlen($response)) {
                $written = @\fwrite($client, \substr($response, $offset));
                if (!\is_int($written) || $written < 1) {
                    return false;
                }
                $offset += $written;
            }
            return true;
        } finally {
            \sodium_memzero($key);
        }
    }

    /**
     * @return list<int>
     */
    private function waitForControllerPids(
        string $path,
        int $expected,
        ?string $diagnosticLog = null,
    ): array
    {
        $deadline = \microtime(true) + 10.0;
        do {
            $lines = \is_file($path)
                ? \file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                : [];
            $pids = \is_array($lines)
                ? \array_values(\array_filter(
                    \array_map('intval', $lines),
                    static fn (int $pid): bool => $pid > 0,
                ))
                : [];
            if (\count($pids) >= $expected) {
                return $pids;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        $message = 'Native broker did not start '
            . $expected
            . ' controller generation(s).';
        if ($diagnosticLog !== null && \is_file($diagnosticLog)) {
            $message .= "\n" . (string)@\file_get_contents($diagnosticLog);
        }
        self::fail($message);
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
