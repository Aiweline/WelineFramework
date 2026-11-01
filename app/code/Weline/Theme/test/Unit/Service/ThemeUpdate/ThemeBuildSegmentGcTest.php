<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\PublishStateStore;
use Weline\Framework\Deploy\StaticBuildGc;

/**
 * Task 5：构建段 GC 必须按**每站 3 版窗口**保留，不能按"最近 N 代"或"代龄"猜。
 *
 * 背景（实测 2026-11-01）：`pub/static/` 下累积 46 个构建段，没有按窗口回收；
 * 而 `StaticBuildGc::prune()` 只认"当前代 + 最近 N 代 + 代龄"，
 * 不知道各站自己的 3 版窗口 —— A 站保留的段可能被 B 站发布后的 GC 删掉。
 */
final class ThemeBuildSegmentGcTest extends TestCase
{
    public function testWindowSegmentsComeFromPublishStateNotHeuristics(): void
    {
        // 契约：GC 的保留集必须能由 PublishStateStore 的 supported 推导出来
        $store = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Deploy/PublishStateStore.php');
        self::assertMatchesRegularExpression(
            '/function\s+(supportedDeployBuilds|keptDeployBuilds|activeDeployBuilds)\s*\(/',
            $store,
            'PublishStateStore 必须提供"窗口内 deploy_build 保留集"查询（供 GC 使用）'
        );
    }

    public function testStaticBuildGcKeepsWindowBuilds(): void
    {
        $gc = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Deploy/StaticBuildGc.php');
        self::assertStringContainsString(
            'PublishStateStore',
            $gc,
            'StaticBuildGc 必须把发布状态里的窗口代次并入保留集，而不是只按 mtime / keepLast 猜'
        );
    }

    public function testPruneDryRunNeverDeletesCurrentBuild(): void
    {
        $root = \sys_get_temp_dir() . '/static_gc_test_' . \uniqid('', true);
        foreach (['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc'] as $i => $build) {
            $dir = $root . '/' . $build;
            self::assertTrue(@\mkdir($dir, 0775, true) || \is_dir($dir));
            @\file_put_contents($dir . '/' . StaticBuildGcTestMarker::MARKER, '{}');
            @\touch($dir, \time() - (100 - $i * 10) * 3600);
        }
        try {
            $result = StaticBuildGc::prune($root, 0, 1, true);
            self::assertNotEmpty(
                $result['kept'] ?? [],
                '至少当前代与最近代必须保留（保留集非空）'
            );
            self::assertNotEmpty($result['deleted'], '更老的代应进入删除清单');
            // dry-run 不得真的删除
            self::assertDirectoryExists($root . '/bbbbbbbbbbbb');
        } finally {
            self::removeTree($root);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($path);
    }
}

/** @internal 只为拿 marker 常量，避免硬编码文件名。 */
final class StaticBuildGcTestMarker
{
    public const MARKER = \Weline\Framework\Deploy\StaticBuildId::BUILD_MARKER;
}
