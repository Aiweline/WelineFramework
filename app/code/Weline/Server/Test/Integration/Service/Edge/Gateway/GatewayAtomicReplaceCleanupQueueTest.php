<?php

declare(strict_types=1);

namespace Weline\Server\Test\Integration\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayAtomicReplaceCleanupQueueTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('pcntl_fork')
            || !\function_exists('stream_socket_pair')
        ) {
            self::markTestSkipped(
                'The Controller sideband cleanup harness requires POSIX pcntl and socket pairs.'
            );
        }
        $temporaryRoot = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-cleanup-queue-'
            . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTwoFailedCleanupTokensRemainBoundAndBothRecover(): void
    {
        $controller = $this->createController();
        $stateProperty = new \ReflectionProperty($controller, 'state');
        $state = $stateProperty->getValue($controller);
        self::assertIsArray($state);
        $paths = [
            $this->root . DIRECTORY_SEPARATOR . 'home/state/gateway-state.json',
            $this->root . DIRECTORY_SEPARATOR . 'home/state/nonce.wal',
        ];
        $digests = [\str_repeat('a', 64), \str_repeat('b', 64)];
        $tokens = [\str_repeat('1', 16), \str_repeat('2', 16)];
        $queue = [];
        foreach ([0, 1] as $index) {
            $cleanupId = \hash(
                'sha256',
                $paths[$index] . "\0" . $digests[$index] . "\0" . $tokens[$index],
            );
            $queue[] = [
                'path' => $paths[$index],
                'sha256' => $digests[$index],
                'size' => 4096 + $index,
                'mode' => 0600,
                'mode_text' => '0600',
                'token' => $tokens[$index],
                'cleanup_id' => $cleanupId,
                'attempts' => 0,
                'retry_boot_id' => '',
                'next_retry_monotonic' => 0.0,
                'created_at' => \time(),
            ];
        }
        $state['recovery']['atomic_replace_cleanup_pending'] = $queue;
        $stateProperty->setValue($controller, $state);

        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        (new \ReflectionProperty($controller, 'brokerExchange'))
            ->setValue($controller, $sockets[0]);
        (new \ReflectionProperty($controller, 'activeBrokerPeer'))->setValue(
            $controller,
            ['channel' => 'admin', 'action_protocol' => 2],
        );
        $wireLog = $this->root . DIRECTORY_SEPARATOR . 'cleanup-sideband.log';
        $child = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $child);
        if ($child === 0) {
            @\fclose($sockets[0]);
            for ($attempt = 0; $attempt < 4; ++$attempt) {
                $line = @\fgets($sockets[1], 65_536);
                if (!\is_string($line)) {
                    exit(70);
                }
                @\file_put_contents($wireLog, $line, FILE_APPEND);
                $fields = \explode("\t", \rtrim($line, "\r\n"));
                if (\count($fields) !== 7
                    || !\hash_equals('WLS-ACTION/2', (string)$fields[0])
                    || !\hash_equals('ATOMIC_REPLACE_CLEANUP', (string)$fields[1])
                    || !\hash_equals('0600', (string)$fields[5])
                ) {
                    exit(71);
                }
                $response = $attempt < 2
                    ? "WLS-ACTION/2\tERR\tATOMIC_REPLACE_CLEANUP_FAILED"
                        . "\tATOMIC_REPLACE_CLEANUP\t-\t-\n"
                    : "WLS-ACTION/2\tOK\tATOMIC_REPLACE_CLEANUP\t-\t-\t"
                        . $fields[3] . "\t" . $fields[4] . "\t" . $fields[5] . "\n";
                if (@\fwrite($sockets[1], $response) !== \strlen($response)) {
                    exit(72);
                }
            }
            @\fclose($sockets[1]);
            exit(0);
        }
        @\fclose($sockets[1]);

        $retry = new \ReflectionMethod(
            $controller,
            'retryPendingAtomicReplaceCleanup',
        );
        try {
            $retry->invoke($controller, true, 2, null);
            $failedState = $stateProperty->getValue($controller);
            $failedQueue = $failedState['recovery']['atomic_replace_cleanup_pending']
                ?? null;
            self::assertIsArray($failedQueue);
            self::assertCount(2, $failedQueue);
            self::assertSame([1, 1], \array_column($failedQueue, 'attempts'));
            self::assertSame($tokens, \array_column($failedQueue, 'token'));

            $retry->invoke($controller, true, 2, null);
            $recoveredState = $stateProperty->getValue($controller);
            self::assertArrayNotHasKey(
                'atomic_replace_cleanup_pending',
                $recoveredState['recovery'],
            );
            self::assertSame($child, \pcntl_waitpid($child, $status));
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
            $lines = \file($wireLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            self::assertCount(4, $lines);
            foreach ($lines as $line) {
                self::assertStringContainsString(
                    "WLS-ACTION/2\tATOMIC_REPLACE_CLEANUP\t",
                    $line,
                );
                self::assertStringContainsString("\t0600\t", $line);
            }
        } finally {
            @\fclose($sockets[0]);
            if ($child > 0) {
                \pcntl_waitpid($child, $ignored, WNOHANG);
            }
        }
    }

    public function testCommitAmbiguousIsAcceptedOnlyAfterStableAfterImageProof(): void
    {
        $controller = $this->createController();
        $target = $this->root . DIRECTORY_SEPARATOR
            . 'home/state/gateway-state.json';
        self::assertSame(8, \file_put_contents($target, 'previous'));
        self::assertTrue(\chmod($target, 0600));
        $contents = '{"committed":true}';
        $digest = \hash('sha256', $contents);
        $token = \str_repeat('3', 16);
        $backup = $target . '.wls-replace-backup-' . $digest . '-' . $token;
        self::assertSame(8, \file_put_contents($backup, 'previous'));
        self::assertTrue(\chmod($backup, 0600));
        self::assertSame(\strlen($contents), \file_put_contents($target, $contents));
        self::assertTrue(\chmod($target, 0600));

        $reconcile = new \ReflectionMethod(
            $controller,
            'reconcileAmbiguousNativeAtomicReplace',
        );
        self::assertTrue($reconcile->invoke($controller, $target, $contents, 0600));
        $matches = new \ReflectionMethod(
            $controller,
            'atomicWriteCommittedAfterImageMatches',
        );
        self::assertTrue($matches->invoke($controller, $target, $contents, 0600));
        self::assertSame($digest, \hash_file('sha256', $target));
        self::assertFileExists($backup);

        self::assertSame(9, \file_put_contents($target, 'tampered!'));
        self::assertFalse($reconcile->invoke($controller, $target, $contents, 0600));
    }

    private function createController(): \WlsEdgeGatewayController
    {
        $home = $this->root . DIRECTORY_SEPARATOR . 'home';
        $state = $home . DIRECTORY_SEPARATOR . 'state';
        $trust = $home . DIRECTORY_SEPARATOR . 'trust';
        $slot = $home . DIRECTORY_SEPARATOR . 'slots/A';
        $run = $home . DIRECTORY_SEPARATOR . 'runtime/run';
        self::assertTrue(\mkdir($state, 0700, true));
        self::assertTrue(\mkdir($trust, 0750, true));
        self::assertTrue(\mkdir($slot . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($run, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'host-id',
            \bin2hex(\random_bytes(16)),
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'admin.token',
            \bin2hex(\random_bytes(32)),
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'active-slot',
            "A\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $trust . DIRECTORY_SEPARATOR . 'broker-fencing-token',
            \bin2hex(\random_bytes(32)),
        ));
        self::assertNotFalse(\file_put_contents(
            $slot . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode([
                'slot' => 'A',
                'test_mode' => true,
                'release_ready' => false,
                'implementation_level' => 'wls-2.0',
                'security_profile' => 'native-broker-v1',
                'runtime_generation' => 'cleanup-test-runtime',
                'components' => [],
            ], JSON_THROW_ON_ERROR),
        ));
        $nginx = $slot . DIRECTORY_SEPARATOR . 'bin/nginx';
        self::assertNotFalse(\file_put_contents($nginx, "#!/bin/sh\nexit 0\n"));
        self::assertTrue(\chmod($nginx, 0700));
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
        return new \WlsEdgeGatewayController(
            $home,
            'unix://' . $run . DIRECTORY_SEPARATOR . 'controller.sock',
        );
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
