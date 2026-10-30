<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\LongRunningPhpRuntime;

/**
 * opcache 盘缓存陈旧检测：源文件不存在或比 .bin 新时必须删掉，
 * 否则长驻进程会一直执行旧字节码（宿主机 validate_timestamps=Off 时的真实事故）。
 */
final class LongRunningPhpRuntimeFileCachePruneTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        // 必须落在项目根（BP）之下：prune 只允许删除本项目根内的缓存，
        // 项目外的路径一律按 orphan 保留（防软链/迁移误删）。
        $this->dir = \BP . 'generated' . \DIRECTORY_SEPARATOR . '.test_ocprune_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && \is_dir($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
            }
            @\rmdir($this->dir);
        }
    }

    public function testRemovesBinaryOlderThanSource(): void
    {
        $source = $this->dir . '/src/Foo.php';
        \mkdir(\dirname($source), 0775, true);
        \file_put_contents($source, '<?php class Foo {}');
        $bin = $this->writeBin($source, 'stale', \time() - 3600);
        \touch($source, \time());

        $result = LongRunningPhpRuntime::pruneStaleFileCache($this->dir);

        self::assertSame(1, $result['scanned']);
        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($bin);
    }

    public function testKeepsFreshBinary(): void
    {
        $source = $this->dir . '/src/Bar.php';
        \mkdir(\dirname($source), 0775, true);
        \file_put_contents($source, '<?php class Bar {}');
        $bin = $this->writeBin($source, 'fresh', \time());
        \touch($source, \time() - 3600);

        $result = LongRunningPhpRuntime::pruneStaleFileCache($this->dir);

        self::assertSame(0, $result['removed']);
        self::assertSame(1, $result['kept']);
        self::assertFileExists($bin);
    }

    public function testRemovesOrphanBinaryWithoutSource(): void
    {
        $orphan = $this->writeBin($this->dir . '/src/Deleted.php', 'orphan', \time());

        $result = LongRunningPhpRuntime::pruneStaleFileCache($this->dir);

        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($orphan);
    }

    public function testIsIdempotent(): void
    {
        $source = $this->dir . '/src/Baz.php';
        \mkdir(\dirname($source), 0775, true);
        \file_put_contents($source, '<?php class Baz {}');
        $bin = $this->writeBin($source, 'stale', \time() - 3600);
        \touch($source, \time());

        $first = LongRunningPhpRuntime::pruneStaleFileCache($this->dir);
        $second = LongRunningPhpRuntime::pruneStaleFileCache($this->dir);

        self::assertSame(1, $first['removed']);
        self::assertSame(0, $second['removed'], '第二次巡检必须幂等（0 删除）');
    }

    private function binPath(string $source): string
    {
        return $this->dir . \DIRECTORY_SEPARATOR . 'systemid' . \DIRECTORY_SEPARATOR
            . \ltrim($source, '/\\') . '.bin';
    }

    private function writeBin(string $source, string $payload, int $mtime): string
    {
        $bin = $this->binPath($source);
        \mkdir(\dirname($bin), 0775, true);
        \file_put_contents($bin, $payload);
        \touch($bin, $mtime);

        return $bin;
    }
}
