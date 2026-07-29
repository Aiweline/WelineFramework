<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

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
        $fencingFile = $this->root . DIRECTORY_SEPARATOR . 'fencing';
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
                $header = @\fgets($client, 4096);
                $payload = @\fgets($client, 4096);
                if (!\is_string($header) || !\is_string($payload)) {
                    exit(21);
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
            self::assertSame(1, (int)$headers[0]['action_protocol']);
            self::assertSame(1, (int)$headers[1]['action_protocol']);
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

    public function testBrokerSidebandAuthorizesAndSnapshotsOnlyEnrolledProjectSource(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker action test.');
        }
        $home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $snapshots = $home . DIRECTORY_SEPARATOR . 'snapshots';
        $project = $this->root . DIRECTORY_SEPARATOR . 'project';
        $certificateRoot = $project . DIRECTORY_SEPARATOR . 'app/etc/ssl';
        $digest = \str_repeat('a', 64);
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0700, true));
        self::assertTrue(\mkdir($snapshots . DIRECTORY_SEPARATOR . $digest, 0700, true));
        self::assertTrue(\mkdir($certificateRoot, 0700, true));
        self::assertSame(18, \file_put_contents(
            $certificateRoot . DIRECTORY_SEPARATOR . 'source.pem',
            'project-owned-cert',
        ));
        self::assertTrue(\chmod($certificateRoot . DIRECTORY_SEPARATOR . 'source.pem', 0600));

        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'action-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'action-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'action-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'action-broker.lock';
        $fencingFile = $this->root . DIRECTORY_SEPARATOR . 'action-fencing';
        $controller = \stream_socket_server(
            'unix://' . $controllerSocket,
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($controller, $error);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';

        $controllerPid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $controllerPid);
        if ($controllerPid === 0) {
            $actions = [
                'WLS-ACTION/1' . "\t" . \implode("\t", [
                    'AUTH',
                    $projectUuid,
                    '7',
                    (string)\posix_geteuid(),
                    'project_ssl',
                    \bin2hex((string)\realpath($project)),
                    \bin2hex((string)\realpath($certificateRoot)),
                ]) . "\n",
                'WLS-ACTION/1' . "\t" . \implode("\t", [
                    'SNAP',
                    $projectUuid,
                    '7',
                    'project_ssl',
                    \bin2hex('source.pem'),
                    $digest,
                    'source-cert.pem',
                ]) . "\n",
            ];
            foreach ($actions as $action) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)) exit(30);
                if (!\is_string(@\fgets($client, 4096))
                    || !\is_string(@\fgets($client, 4096))
                    || @\fwrite($client, $action) !== \strlen($action)
                ) {
                    exit(31);
                }
                $ack = @\fgets($client, 4096);
                if ($ack !== "WLS-ACTION/1\tOK\n") exit(32);
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
            foreach ([$adminSocket, $projectSocket] as $socket) {
                $client = @\stream_socket_client('unix://' . $socket, $errno, $error, 2.0);
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite($client, '{"request_id":"action"}' . "\n"));
                self::assertStringContainsString('"ok":true', (string)@\fgets($client, 4096));
                @\fclose($client);
            }
            self::assertSame(
                'project-owned-cert',
                \file_get_contents(
                    $snapshots . DIRECTORY_SEPARATOR . $digest
                    . DIRECTORY_SEPARATOR . 'source-cert.pem'
                ),
            );
            $registry = $trust . DIRECTORY_SEPARATOR . 'broker-enrollments.tsv';
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

    public function testSlowRequestDoesNotBlockStatusAndDisconnectedClientCannotKillBroker(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker concurrency test.');
        }
        $adminSocket = $this->root . DIRECTORY_SEPARATOR . 'concurrent-admin.sock';
        $projectSocket = $this->root . DIRECTORY_SEPARATOR . 'concurrent-project.sock';
        $controllerSocket = $this->root . DIRECTORY_SEPARATOR . 'concurrent-controller.sock';
        $lockFile = $this->root . DIRECTORY_SEPARATOR . 'concurrent-broker.lock';
        $fencingFile = $this->root . DIRECTORY_SEPARATOR . 'concurrent-fencing';
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
                || !\is_string(@\fgets($slow, 4096))
                || !\is_string(@\fgets($slow, 4096))) {
                exit(40);
            }
            \file_put_contents($slowAccepted, '1');

            $fast = @\stream_socket_accept($controller, 5);
            if (!\is_resource($fast)
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
