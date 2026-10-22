<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticBuildPublisher;
use Weline\Framework\Deploy\StaticIntegrityGuard;

/**
 * 发布后自愈：
 * - 不完整构建代（缺哨兵文件）会被删除并重新完整发布；
 * - 主题代次目录（tu-*）缺失文件会从规范静态树硬链接补齐。
 */
final class StaticIntegrityGuardTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-static-integrity-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0775, true);
        foreach (StaticIntegrityGuard::REQUIRED_BUILD_FILES as $relative) {
            $path = $this->root . '/' . $relative;
            \mkdir(\dirname($path), 0775, true);
            \file_put_contents($path, 'x');
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testHealsIncompleteCurrentBuild(): void
    {
        $broken = 'aaaaaaaaaaaa';
        \mkdir($this->root . '/' . $broken, 0775, true);
        \file_put_contents($this->root . '/' . $broken . '/.weline-build-id', $broken);
        \file_put_contents($this->root . '/.build.json', \json_encode(['build_id' => $broken]));

        $result = StaticIntegrityGuard::healBuild($this->root);

        self::assertTrue($result['rebuilt']);
        self::assertNotSame($broken, $result['build']);
        self::assertDirectoryDoesNotExist($this->root . '/' . $broken, '不完整代应被删除');
        foreach (StaticIntegrityGuard::REQUIRED_BUILD_FILES as $relative) {
            self::assertFileExists($this->root . '/' . $result['build'] . '/' . $relative);
        }
    }

    public function testCompleteBuildIsNotRebuilt(): void
    {
        $complete = StaticBuildPublisher::publish($this->root);
        $result = StaticIntegrityGuard::healBuild($this->root);

        self::assertFalse($result['rebuilt']);
        self::assertSame($complete, $result['build']);
    }

    public function testBackfillsGenerationFromCanonicalTree(): void
    {
        $generation = 'tu-20261022-031150-b04322';
        $genFile = $this->root . '/' . $generation . '/Weline/Frontend/js/weline.js';
        \mkdir(\dirname($genFile), 0775, true);
        \file_put_contents($genFile, 'stale');

        $result = StaticIntegrityGuard::backfillGeneration($this->root, $generation);

        self::assertGreaterThan(0, $result['copied']);
        self::assertFileExists($this->root . '/' . $generation . '/Weline/Frontend/js/weline-core.js');
        self::assertSame('stale', (string)\file_get_contents($genFile), '代次内已有文件不得覆盖');
    }

    public function testEmptyGenerationIsIgnored(): void
    {
        $result = StaticIntegrityGuard::backfillGeneration($this->root, '');
        self::assertSame(0, $result['copied']);
    }

    private function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($path);
    }
}
