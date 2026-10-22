<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticBuildGc;

/**
 * 旧构建代 GC：只删带 marker 的版本目录，保留当前代、最近 N 代与保留窗口内的代；
 * 目录 mtime 作为代龄；`.tmp-*` 视为中断产物。
 */
final class StaticBuildGcTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-static-gc-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testPrunesOnlyExpiredBuildDirectories(): void
    {
        $current = $this->makeBuild('aaaaaaaaaaaa', 0);
        $recent = $this->makeBuild('bbbbbbbbbbbb', 2);
        $expired = $this->makeBuild('cccccccccccc', 30);
        $tmp = $this->root . '/.tmp-dddddddddddd';
        \mkdir($tmp, 0775, true);
        \touch($tmp, \time() - 3 * 86400);
        $legacy = $this->root . '/Weline';
        \mkdir($legacy, 0775, true);
        \file_put_contents($legacy . '/a.js', 'x');
        \file_put_contents($this->root . '/.build.json', \json_encode(['build_id' => $current]));

        $result = StaticBuildGc::prune($this->root, 7, 1, false);

        self::assertDirectoryExists($this->root . '/' . $current);
        self::assertDirectoryExists($this->root . '/' . $recent);
        self::assertDirectoryDoesNotExist($this->root . '/' . $expired);
        self::assertDirectoryDoesNotExist($tmp);
        self::assertDirectoryExists($legacy, 'legacy 非版本目录不得删除');
        self::assertContains($expired, $result['deleted']);
        self::assertContains($current, $result['kept']);

        // 当前代即使超龄也必须保留
        \touch($this->root . '/' . $current, \time() - 90 * 86400);
        StaticBuildGc::prune($this->root, 7, 1, false);
        self::assertDirectoryExists($this->root . '/' . $current);
    }

    public function testDryRunDeletesNothing(): void
    {
        $this->makeBuild('aaaaaaaaaaaa', 0);
        $expired = $this->makeBuild('cccccccccccc', 30);
        \file_put_contents($this->root . '/.build.json', \json_encode(['build_id' => 'aaaaaaaaaaaa']));

        $result = StaticBuildGc::prune($this->root, 7, 1, true);

        self::assertDirectoryExists($this->root . '/' . $expired);
        self::assertContains($expired, $result['deleted']);
    }

    public function testZeroKeepDaysPrunesAllButCurrentAndLastN(): void
    {
        $ids = ['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc', 'dddddddddddd'];
        foreach ($ids as $index => $id) {
            $this->makeBuild($id, 0);
            // 用秒级 mtime 保证“最近 N 代”顺序确定
            \touch($this->root . '/' . $id, \time() - $index * 60);
        }
        \file_put_contents($this->root . '/.build.json', \json_encode(['build_id' => $ids[0]]));

        $result = StaticBuildGc::prune($this->root, 0, 2, false);

        self::assertDirectoryExists($this->root . '/' . $ids[0], '当前代必须保留');
        self::assertDirectoryExists($this->root . '/' . $ids[1]);
        self::assertDirectoryDoesNotExist($this->root . '/' . $ids[3], '超出最近 N 代应删除');
        self::assertContains($ids[3], $result['deleted']);
    }

    private function makeBuild(string $id, int $ageDays): string
    {
        $dir = $this->root . '/' . $id;
        \mkdir($dir, 0775, true);
        \file_put_contents($dir . '/.weline-build-id', $id);
        \file_put_contents($dir . '/a.js', $id);
        $mtime = \time() - $ageDays * 86400;
        \touch($dir, $mtime);
        \touch($dir . '/.weline-build-id', $mtime);
        \touch($dir . '/a.js', $mtime);

        return $id;
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
