<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayEmergencyBindingTest extends TestCase
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
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required for the native broker action test.');
        }
        $temporaryRoot = (string)\realpath(
            \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir(),
        );
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-neb-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $this->broker = $this->root . DIRECTORY_SEPARATOR . 'wls-gateway-broker';
        $source = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/Native/posix/'
            . 'wls_gateway_broker.c';
        $cflags = $this->runCommand(['pkg-config', '--cflags', 'libsodium']);
        $ldflags = $this->runCommand(['pkg-config', '--libs', 'libsodium']);
        self::assertSame(0, $cflags['code'], $cflags['output']);
        self::assertSame(0, $ldflags['code'], $ldflags['output']);
        $split = static fn (string $flags): array => \array_values(\array_filter(
            \preg_split('/\s+/', \trim($flags)) ?: [],
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
            '-pthread',
            ...$split($cflags['output']),
            $source,
            ...$split($ldflags['output']),
            ...(\PHP_OS_FAMILY === 'Darwin' ? ['-lproc'] : []),
            '-o',
            $this->broker,
        ]);
        self::assertSame(0, $compiled['code'], $compiled['output']);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testBindingIsPersistentIdempotentConflictSafeAndAdminOnly(): void
    {
        $home = $this->root . '/home';
        $trust = $home . '/trust';
        self::assertTrue(\mkdir($home . '/state', 0700, true));
        self::assertTrue(\mkdir($trust, 0700, true));
        $project = '123e4567-e89b-42d3-a456-426614174099';
        $transaction = \str_repeat('1', 32);
        $intent = \str_repeat('2', 64);
        $owner = (string)\posix_geteuid();
        $rootsDigest = \str_repeat('3', 64);
        $attestation = \str_repeat('4', 64);
        $securityLedger = \implode("\n", [
            "H\t{$transaction}\t{$intent}\tAUTH\t0\t1",
            "P\t{$project}\t{$transaction}\t{$intent}\t1\t{$owner}"
                . "\tproject_ssl\t00\t00\t1\tproject-object"
                . "\tcertificate-object\t{$attestation}",
            "C\t{$project}\t{$transaction}\t{$intent}\t1\t1\t{$rootsDigest}",
            '',
        ]);
        $securityFile = $trust . '/broker-security-v2.tsv';
        self::assertSame(
            \strlen($securityLedger),
            \file_put_contents($securityFile, $securityLedger),
        );
        self::assertTrue(\chmod($securityFile, 0600));

        $credential = \str_repeat('5', 32);
        $firstSecret = \str_repeat('6', 64);
        $secondSecret = \str_repeat('7', 64);
        $action = static function (int $generation, string $secret) use (
            $project,
            $transaction,
            $intent,
            $owner,
            $credential,
        ): string {
            return "WLS-ACTION/2\tEMERGENCY_BIND\t{$project}\t{$transaction}"
                . "\t{$intent}\t1\t{$owner}\t{$credential}\t{$generation}"
                . "\t{$secret}\n";
        };
        $actions = [
            ['admin', $action(1, $firstSecret), "WLS-ACTION/2\tOK\tEMERGENCY_BIND\t{$transaction}\t{$intent}\t1\t1\t"],
            ['admin', $action(1, $firstSecret), "WLS-ACTION/2\tOK\tEMERGENCY_BIND\t{$transaction}\t{$intent}\t1\t1\t"],
            ['admin', $action(2, $secondSecret), "WLS-ACTION/2\tOK\tEMERGENCY_BIND\t{$transaction}\t{$intent}\t1\t2\t"],
            ['admin', $action(2, $secondSecret), "WLS-ACTION/2\tOK\tEMERGENCY_BIND\t{$transaction}\t{$intent}\t1\t2\t"],
            ['admin', $action(1, $firstSecret), "WLS-ACTION/2\tERR\tBINDING_CONFLICT\tEMERGENCY_BIND\t{$transaction}\t{$intent}"],
            ['admin', \substr($action(2, $secondSecret), 0, -(\strlen($secondSecret) + 2)) . "\n", "WLS-ACTION/2\tERR\tDENIED\tEMERGENCY_BIND\t{$transaction}\t{$intent}"],
            ['project', $action(2, $secondSecret), "WLS-ACTION/2\tERR\tDENIED\tEMERGENCY_BIND\t{$transaction}\t{$intent}"],
            ['admin', $action(2, $secondSecret), "WLS-ACTION/2\tERR\tLEDGER_INVALID\tEMERGENCY_BIND\t{$transaction}\t{$intent}"],
        ];

        $adminSocket = $this->root . '/admin.sock';
        $projectSocket = $this->root . '/project.sock';
        $controllerSocket = $this->root . '/controller.sock';
        $lockFile = $this->root . '/broker.lock';
        $fencingFile = $trust . '/broker-fencing-token';
        $ackFile = $this->root . '/acks.log';
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
            foreach ($actions as [, $frame, $expected]) {
                $client = @\stream_socket_accept($controller, 5);
                if (!\is_resource($client)
                    || !$this->authenticateBrokerProbe($client, $fencingFile)
                    || !\is_string(@\fgets($client, 4096))
                    || !\is_string(@\fgets($client, 4096))
                    || @\fwrite($client, $frame) !== \strlen($frame)
                ) {
                    exit(70);
                }
                $ack = @\fgets($client, 4096);
                @\file_put_contents($ackFile, (string)$ack, FILE_APPEND);
                if (!\str_starts_with((string)$ack, $expected)) {
                    exit(71);
                }
                @\fwrite(
                    $client,
                    '{"protocol":"wls-edge/2","request_id":"bind","ok":true}' . "\n",
                );
                @\fclose($client);
            }
            @\fclose($controller);
            exit(0);
        }
        @\fclose($controller);

        $process = null;
        try {
            $log = $this->root . '/broker.log';
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
            foreach ($actions as $index => [$channel]) {
                if ($index === 7) {
                    $corrupt = "B\t{$project}\t{$transaction}\t{$intent}\t1"
                        . "\t{$owner}\t{$credential}\t2\t{$firstSecret}\n";
                    self::assertSame(
                        \strlen($corrupt),
                        \file_put_contents(
                            $trust . '/emergency-credentials-v1.tsv',
                            $corrupt,
                            FILE_APPEND,
                        ),
                    );
                }
                $socket = $channel === 'admin' ? $adminSocket : $projectSocket;
                $client = @\stream_socket_client(
                    'unix://' . $socket,
                    $errno,
                    $error,
                    2.0,
                );
                self::assertIsResource($client, $error);
                self::assertNotFalse(@\fwrite(
                    $client,
                    '{"request_id":"bind"}' . "\n",
                ));
                self::assertStringContainsString(
                    '"ok":true',
                    (string)@\fgets($client, 4096),
                    (string)@\file_get_contents($log) . "\n"
                        . (string)@\file_get_contents($ackFile),
                );
                @\fclose($client);
            }
            self::assertSame($controllerPid, \pcntl_waitpid($controllerPid, $status));
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status), (string)@\file_get_contents($ackFile));
            $bindingFile = $trust . '/emergency-credentials-v1.tsv';
            self::assertFileExists($bindingFile);
            self::assertSame(0600, \fileperms($bindingFile) & 0777);
            $records = \file($bindingFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($records);
            self::assertCount(3, $records);
            self::assertStringContainsString("\t1\t{$firstSecret}", $records[0]);
            self::assertStringContainsString("\t2\t{$secondSecret}", $records[1]);
            self::assertStringContainsString("\t2\t{$firstSecret}", $records[2]);
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

    /** @param list<string> $command @return array{code:int,output:string} */
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
            if (\file_exists($path)) return;
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        self::fail('Native broker socket did not appear: ' . $path);
    }

    /** @param resource $client */
    private function authenticateBrokerProbe($client, string $fencingFile): bool
    {
        \stream_set_timeout($client, 3);
        $deadline = \microtime(true) + 3.0;
        $fencing = '';
        do {
            $fencing = \trim((string)@\file_get_contents($fencingFile));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $fencing) === 1) break;
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        $probe = @\fgets($client, 512);
        if (\preg_match(
            '/\AWLS-BROKER-PROBE\/1\t([a-f0-9]{64})\t([a-f0-9]{64})\n\z/D',
            \is_string($probe) ? $probe : '',
            $matches,
        ) !== 1) return false;
        $key = \hex2bin($fencing);
        if (!\is_string($key) || \strlen($key) !== 32) return false;
        try {
            $nonce = (string)$matches[1];
            if (!\hash_equals(
                \hash_hmac('sha256', "WLS-BROKER-PROBE/1\nnonce={$nonce}\n", $key),
                (string)$matches[2],
            )) return false;
            $response = "WLS-BROKER-READY/1\t"
                . \hash_hmac('sha256', "WLS-BROKER-READY/1\nnonce={$nonce}\n", $key)
                . "\n";
            return @\fwrite($client, $response) === \strlen($response);
        } finally {
            \sodium_memzero($key);
        }
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) return;
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
