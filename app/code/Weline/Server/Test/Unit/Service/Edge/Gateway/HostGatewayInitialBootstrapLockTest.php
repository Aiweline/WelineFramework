<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

final class HostGatewayInitialBootstrapLockTest extends TestCase
{
    private string $root = '';

    /** @var array<string,string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork is required for the real cross-process lock proof.');
        }
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->previousEnvironment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-bootstrap-lock-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . '/gateway');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=18080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=18443');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testSecondProjectEntersOnlyAfterTheCompleteWinnerCallback(): void
    {
        $entered = $this->root . '/winner-entered';
        $complete = $this->root . '/winner-complete';
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            try {
                (new HostGatewayPackageManager(new GatewayPaths()))
                    ->withInitialBootstrapLock(
                        static function () use ($entered, $complete): void {
                            \file_put_contents($entered, "entered\n");
                            \usleep(200_000);
                            \file_put_contents($complete, "complete\n");
                        },
                        (\hrtime(true) / 1_000_000_000) + 2.0,
                    );
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }

        try {
            $waitDeadline = (\hrtime(true) / 1_000_000_000) + 1.0;
            while (!\is_file($entered)
                && (\hrtime(true) / 1_000_000_000) < $waitDeadline
            ) {
                \usleep(2_000);
            }
            self::assertFileExists($entered);
            $sawCompleteInsideLock = (new HostGatewayPackageManager(new GatewayPaths()))
                ->withInitialBootstrapLock(
                    static fn (): bool => \is_file($complete),
                    (\hrtime(true) / 1_000_000_000) + 2.0,
                );
            self::assertTrue($sawCompleteInsideLock);
        } finally {
            \pcntl_waitpid($pid, $status);
        }
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
    }

    public function testLockWaitCannotOutliveTheCallerAbsoluteDeadline(): void
    {
        $entered = $this->root . '/deadline-winner-entered';
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            try {
                (new HostGatewayPackageManager(new GatewayPaths()))
                    ->withInitialBootstrapLock(
                        static function () use ($entered): void {
                            \file_put_contents($entered, "entered\n");
                            \usleep(350_000);
                        },
                        (\hrtime(true) / 1_000_000_000) + 2.0,
                    );
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }

        try {
            $waitDeadline = (\hrtime(true) / 1_000_000_000) + 1.0;
            while (!\is_file($entered)
                && (\hrtime(true) / 1_000_000_000) < $waitDeadline
            ) {
                \usleep(2_000);
            }
            self::assertFileExists($entered);
            $callbackCalled = false;
            $started = \hrtime(true) / 1_000_000_000;
            try {
                (new HostGatewayPackageManager(new GatewayPaths()))
                    ->withInitialBootstrapLock(
                        static function () use (&$callbackCalled): void {
                            $callbackCalled = true;
                        },
                        $started + 0.08,
                    );
                self::fail('The second project crossed its bootstrap deadline.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('lock', \strtolower($exception->getMessage()));
            }
            $elapsed = (\hrtime(true) / 1_000_000_000) - $started;
            self::assertFalse($callbackCalled);
            self::assertLessThan(0.25, $elapsed);
        } finally {
            \pcntl_waitpid($pid, $status);
        }
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            return;
        }
        $entries = \scandir($path);
        if (!\is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($target) && !\is_link($target)) {
                $this->removeTree($target);
            } else {
                @\unlink($target);
            }
        }
        @\rmdir($path);
    }
}
