<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\Console\Server\Gateway\Promote;

final class PromoteOwnershipTest extends TestCase
{
    private string $root = '';
    /** @var list<string> */
    private array $instanceConfigFiles = [];

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-promote-ownership-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root . DIRECTORY_SEPARATOR . 'conf', 0700, true));
    }

    protected function tearDown(): void
    {
        foreach ($this->instanceConfigFiles as $file) {
            @\unlink($file);
            @\unlink($file . '.lock');
        }
        if ($this->root === '' || !\is_dir($this->root) || \is_link($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @\rmdir($item->getPathname())
                : @\unlink($item->getPathname());
        }
        @\rmdir($this->root);
    }

    public function testPromotionPersistsAutoEdgeIntentAndRollbackRestoresExactConfig(): void
    {
        if (!\defined('BP')) {
            \define(
                'BP',
                \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR,
            );
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        $instance = 'unit-promote-' . \bin2hex(\random_bytes(6));
        $directory = Env::VAR_DIR . 'server' . DS . 'config' . DS;
        if (!\is_dir($directory)) {
            self::assertTrue(\mkdir($directory, 0755, true));
        }
        $file = $directory . $instance . '.json';
        $this->instanceConfigFiles[] = $file;
        $original = \json_encode([
            'host' => '127.0.0.1',
            'port' => 28080,
            'edge_mode' => 'legacy',
            'edge_adapter' => 'nginx',
            'ssl_enabled' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        self::assertNotFalse(\file_put_contents($file, $original));
        self::assertTrue(\chmod($file, 0640));

        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $persist = new \ReflectionMethod(Promote::class, 'persistPromotedInstanceEdgeMode');
        $restore = new \ReflectionMethod(Promote::class, 'restoreSavedInstanceEdgeMode');
        $snapshot = $persist->invoke($command, $instance);
        $promoted = \json_decode((string)\file_get_contents($file), true);

        self::assertIsArray($snapshot);
        self::assertSame('auto', $promoted['edge_mode'] ?? null);
        self::assertSame('nginx', $promoted['edge_adapter'] ?? null);
        self::assertFalse($promoted['ssl_enabled'] ?? true);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0640, (int)\fileperms($file) & 0777);
        }

        $restore->invoke($command, $instance, $snapshot);
        self::assertSame($original, \file_get_contents($file));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0640, (int)\fileperms($file) & 0777);
        }
    }

    public function testRollbackOwnershipRestorationRejectsLinksBeforeChangingTree(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX ownership restoration is not used on Windows.');
        }
        $config = $this->root . DIRECTORY_SEPARATOR . 'conf'
            . DIRECTORY_SEPARATOR . 'nginx.conf';
        self::assertNotFalse(\file_put_contents($config, "events {}\n"));
        self::assertTrue(\symlink($config, $this->root . DIRECTORY_SEPARATOR . 'unsafe-link'));
        $command = (new \ReflectionClass(Promote::class))->newInstanceWithoutConstructor();
        $restore = new \ReflectionMethod(Promote::class, 'restoreProjectRuntimeOwnership');

        try {
            $restore->invoke(
                $command,
                $this->root,
                (int)\posix_getuid(),
                (int)\posix_getgid(),
            );
            self::fail('A symlink in the project Nginx runtime must block recursive ownership changes.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        }
        self::assertSame((int)\posix_getuid(), (int)\stat($config)['uid']);
        self::assertSame((int)\posix_getgid(), (int)\stat($config)['gid']);
    }
}
