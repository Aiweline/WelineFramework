<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

final class ProtocolEdgeRuntimeOwnershipTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupFiles = [];
    /** @var list<string> */
    private array $cleanupDirectories = [];

    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define(
                'BP',
                \rtrim(\dirname(__DIR__, 8), '/\\') . DIRECTORY_SEPARATOR,
            );
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        foreach ([
            'APP_PATH' => BP . 'app' . DS,
            'APP_ETC_PATH' => BP . 'app' . DS . 'etc' . DS,
            'PUB' => BP . 'pub' . DS,
            'VENDOR_PATH' => BP . 'vendor' . DS,
            'APP_CODE_PATH' => BP . 'app' . DS . 'code' . DS,
        ] as $name => $path) {
            if (!\defined($name)) {
                \define($name, $path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            if (\is_link($file) || \is_file($file)) {
                @\unlink($file);
            }
        }
        foreach (\array_reverse($this->cleanupDirectories) as $directory) {
            @\rmdir($directory);
        }
    }

    public function testRuntimeDirectoryRejectsSymbolicLinkTraversal(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link ownership rules are POSIX-specific.');
        }
        $instance = 'phpunit-edge-link-' . \bin2hex(\random_bytes(6));
        $directory = ProtocolEdgeRuntime::runtimeDirectory($instance);
        $target = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . $instance;
        self::assertTrue(@\mkdir($target, 0700));
        $this->cleanupDirectories[] = $target;
        self::assertTrue(@\symlink($target, $directory));
        $this->cleanupFiles[] = $directory;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');
        ProtocolEdgeRuntime::ensureTokenFile($instance);
    }

    public function testRootInvocationRestoresProjectOwnerOnRuntimeFacts(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            self::markTestSkipped('Root ownership repair requires a POSIX root test process.');
        }
        $project = @\lstat((string)BP);
        if (!\is_array($project)
            || !\is_int($project['uid'] ?? null)
            || !\is_int($project['gid'] ?? null)
            || (int)$project['uid'] === 0
        ) {
            self::markTestSkipped('The test project must be owned by a non-root project user.');
        }
        $instance = 'phpunit-edge-root-' . \bin2hex(\random_bytes(6));
        $directory = ProtocolEdgeRuntime::runtimeDirectory($instance);
        self::assertTrue(@\mkdir($directory, 0700));
        $this->cleanupDirectories[] = $directory;
        $before = @\lstat($directory);
        self::assertIsArray($before);
        self::assertSame(0, (int)$before['uid']);

        $token = ProtocolEdgeRuntime::ensureTokenFile($instance);
        $this->cleanupFiles[] = $token;
        $directoryState = @\lstat($directory);
        $tokenState = @\lstat($token);
        self::assertIsArray($directoryState);
        self::assertIsArray($tokenState);
        self::assertSame((int)$project['uid'], (int)$directoryState['uid']);
        self::assertSame((int)$project['gid'], (int)$directoryState['gid']);
        self::assertSame((int)$project['uid'], (int)$tokenState['uid']);
        self::assertSame((int)$project['gid'], (int)$tokenState['gid']);
        self::assertSame(0700, ((int)$directoryState['mode']) & 0777);
        self::assertSame(0600, ((int)$tokenState['mode']) & 0777);
    }
}
