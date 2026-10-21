<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Deploy\StaticBuildPublisher;

/**
 * 版本化静态根目录：/static/{buildId}/<path>
 *
 * - buildId 由内容决定：内容不变 → 同一个 id（幂等，不无谓失效缓存）；
 * - 版本目录用硬链接/复制生成，旧代不删除；
 * - 版本目录自身不参与下一轮 buildId 计算。
 */
final class StaticBuildPublisherTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-static-build-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/Weline/Frontend/js', 0775, true);
        \file_put_contents($this->root . '/Weline/Frontend/js/a.js', 'console.log("one");');
        \mkdir($this->root . '/Weline/Theme/css', 0775, true);
        \file_put_contents($this->root . '/Weline/Theme/css/a.css', 'body{color:red}');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testPublishIsContentAddressedIdempotentAndKeepsOldGenerations(): void
    {
        $first = StaticBuildPublisher::publish($this->root);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/', $first);
        self::assertFileExists($this->root . '/.build.json');
        self::assertFileExists($this->root . '/' . $first . '/Weline/Frontend/js/a.js');
        self::assertFileExists($this->root . '/' . $first . '/Weline/Theme/css/a.css');
        // legacy 原路径必须保留，供旧 HTML 继续工作
        self::assertFileExists($this->root . '/Weline/Frontend/js/a.js');

        $manifest = \json_decode((string)\file_get_contents($this->root . '/.build.json'), true);
        self::assertSame($first, $manifest['build_id'] ?? null);
        self::assertSame($first, StaticBuildId::current($this->root));

        // 内容不变 → 同 id（不制造无意义的缓存失效）
        self::assertSame($first, StaticBuildPublisher::publish($this->root));

        // 内容变化 → 新 id，旧代仍在盘上
        \file_put_contents($this->root . '/Weline/Frontend/js/a.js', 'console.log("two");');
        $second = StaticBuildPublisher::publish($this->root);
        self::assertNotSame($first, $second);
        self::assertFileExists($this->root . '/' . $first . '/Weline/Frontend/js/a.js');
        self::assertFileExists($this->root . '/' . $second . '/Weline/Frontend/js/a.js');
        self::assertSame($second, StaticBuildId::current($this->root));
    }

    public function testBuildIdDoesNotHashVersionedTrees(): void
    {
        $first = StaticBuildPublisher::publish($this->root);
        // 版本目录里的内容不应把下一轮 id 带偏
        self::assertSame($first, StaticBuildPublisher::computeBuildId($this->root));
    }

    public function testVersionedPathIsStableAndNonDestructive(): void
    {
        self::assertSame(
            '/static/abc123def456/Weline/Frontend/js/a.js',
            StaticBuildId::versionedPath('/static/Weline/Frontend/js/a.js', 'abc123def456'),
        );
        // 已带当前版本 → 不重复加
        self::assertSame(
            '/static/abc123def456/Weline/Frontend/js/a.js',
            StaticBuildId::versionedPath('/static/abc123def456/Weline/Frontend/js/a.js', 'abc123def456'),
        );
        // 无版本 → 原样（未发布时不能破坏旧路径）
        self::assertSame(
            '/static/Weline/Frontend/js/a.js',
            StaticBuildId::versionedPath('/static/Weline/Frontend/js/a.js', ''),
        );
        // 非 /static 路径不处理
        self::assertSame(
            '/Weline/Frontend/view/statics/js/a.js',
            StaticBuildId::versionedPath('/Weline/Frontend/view/statics/js/a.js', 'abc123def456'),
        );
        // 查询串保留
        self::assertSame(
            '/static/abc123def456/Weline/Frontend/js/a.js?v=1',
            StaticBuildId::versionedPath('/static/Weline/Frontend/js/a.js?v=1', 'abc123def456'),
        );
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
