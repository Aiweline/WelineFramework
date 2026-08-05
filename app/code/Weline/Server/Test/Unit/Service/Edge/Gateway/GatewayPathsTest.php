<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayPathsTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
            \putenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-gateway-paths-'
            . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testProductionRootCannotBeOverridden(): void
    {
        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot override');
        (new GatewayPaths())->home();
    }

    public function testIsolatedTestRootRequiresHighExplicitPorts(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');
        $paths = new GatewayPaths();
        $expectedRoot = (string)\realpath(\dirname($this->root))
            . DIRECTORY_SEPARATOR . \basename($this->root);

        self::assertSame($expectedRoot, $paths->home());
        self::assertSame(21080, $paths->publicHttpPort());
        self::assertSame(21443, $paths->publicHttpsPort());
        self::assertStringStartsWith($expectedRoot, $paths->runDir());
        self::assertStringEndsWith('project.sock', $paths->projectSocketFile());
        self::assertStringEndsWith('admin.sock', $paths->adminSocketFile());
        $paths->ensureDirectories();
        self::assertDirectoryExists($paths->slotsDir());
        self::assertDirectoryDoesNotExist($paths->slotDir('A'));
        self::assertDirectoryDoesNotExist($paths->slotDir('B'));
    }

    public function testTestRootAndPrivilegedPortEscapeAreRejected(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=/var/lib/not-a-wls-test-root');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');
        try {
            (new GatewayPaths())->home();
            self::fail('Test roots outside the system temporary directory must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('temporary directory', $exception->getMessage());
        }

        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=80');
        try {
            (new GatewayPaths())->publicHttpPort();
            self::fail('Test mode must never bind a privileged public port.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('above 1024', $exception->getMessage());
        }
    }

    public function testFilesystemRootsCannotBecomeTheHostGatewayHome(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=21080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=21443');
        foreach ([
            '/',
            'C:\\',
            "\\\\server\\",
            "\\\\server\\share\\",
            "\\\\?\\C:\\",
            "\\\\?\\UNC\\server\\share\\",
            "\\\\?\\UNC\\server\\",
            "\\\\.\\C:\\",
            "\\\\?\\Volume{01234567-89ab-cdef-0123-456789abcdef}\\",
        ] as $filesystemRoot) {
            \putenv('WLS_GATEWAY_HOME=' . $filesystemRoot);
            try {
                (new GatewayPaths())->home();
                self::fail('A filesystem root must not become the gateway home.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'filesystem root',
                    \strtolower($exception->getMessage()),
                );
            }
        }
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
