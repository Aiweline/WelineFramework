<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;

/**
 * flat 契约（dev.static_flat_urls）下的主题 promote 落盘目标：
 * 原地覆盖 pub/static 平铺权威树，不制造世代路径目录、不删除旧引用字节。
 */
final class ThemeUpdatePromoteFlatTargetTest extends TestCase
{
    private string $pubStatic = '';

    protected function setUp(): void
    {
        $this->pubStatic = \sys_get_temp_dir() . '/weline-flat-promote-' . \bin2hex(\random_bytes(4));
        \mkdir($this->pubStatic . '/Weline/hanfu/frontend/assets/css', 0775, true);
        \mkdir($this->pubStatic . '/Weline/Theme/ui', 0775, true);
        \mkdir($this->pubStatic . '/Weline/Frontend/js', 0775, true);
        \file_put_contents($this->pubStatic . '/Weline/hanfu/frontend/assets/css/a.css', 'old');
    }

    protected function tearDown(): void
    {
        StaticBuildId::setFlatUrlsOverride(null);
        $this->rrmdir($this->pubStatic);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testFlatMirrorTargetKeepsThemeOwnedAndSkipsForeign(): void
    {
        StaticBuildId::setFlatUrlsOverride(true);
        // flatMirrorTarget 不触碰注入依赖，反射实例即可。
        $service = (new \ReflectionClass(ThemeUpdateBatchService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'flatMirrorTarget');
        $method->setAccessible(true);

        self::assertSame(
            $this->pubStatic . '/Weline/hanfu/frontend/assets/css/a.css',
            str_replace('\\', '/', (string)$method->invoke($service, $this->pubStatic, 'Weline/hanfu/frontend/assets/css/a.css', 'hanfu')),
        );
        self::assertStringEndsWith(
            '/Weline/Theme/view/theme/frontend/x.css',
            (string)$method->invoke($service, $this->pubStatic, 'Weline/Theme/view/theme/frontend/x.css', 'hanfu'),
        );
        // 非主题自有子树不发（模块 flat 树由 deploy:upgrade 负责）
        self::assertNull($method->invoke($service, $this->pubStatic, 'Weline/Frontend/js/b.js', 'hanfu'));
        // 越权路径拒绝
        self::assertNull($method->invoke($service, $this->pubStatic, '../escape/a.css', 'hanfu'));
        self::assertNull($method->invoke($service, $this->pubStatic, 'Weline/../x/a.css', 'hanfu'));
    }

    public function testBackfillThemePairIsNoopInFlatMode(): void
    {
        StaticBuildId::setFlatUrlsOverride(true);
        self::assertSame(0, ThemeUpdateBatchService::backfillThemePairForNewDeploy($this->pubStatic, 'abc123def456'));
        // 旧模式仍会走补代次逻辑（此处无指针行，返回 0 但不得致命）
        StaticBuildId::setFlatUrlsOverride(false);
        self::assertSame(0, ThemeUpdateBatchService::backfillThemePairForNewDeploy($this->pubStatic, 'abc123def456'));
    }

    public function testRetireKeepsPinnedBuildAndDropsOtherBuilds(): void
    {
        StaticBuildId::setFlatUrlsOverride(false);
        $deploy = 'abc123def456';
        $pinned = 'fedcba654321';
        $stale = '001122334455';
        foreach ([$deploy, $pinned, $stale] as $build) {
            $dir = $this->pubStatic . '/hanfu/v276/' . $build;
            \mkdir($dir, 0775, true);
            \file_put_contents($dir . '/a.css', $build);
        }

        $removed = ThemeUpdateBatchService::retireStaleThemePairDirs(
            $this->pubStatic,
            'hanfu',
            276,
            $deploy,
            [$pinned],
        );

        self::assertSame(1, $removed);
        self::assertDirectoryExists($this->pubStatic . '/hanfu/v276/' . $deploy);
        self::assertDirectoryExists($this->pubStatic . '/hanfu/v276/' . $pinned, '指针上的 pinned 段不得被部署换版删掉');
        self::assertDirectoryDoesNotExist($this->pubStatic . '/hanfu/v276/' . $stale);
    }

    public function testMirrorSourceUsesFlatRootShape(): void
    {
        // 结构性断言：promote 镜像在 flat 模式下以 pubStatic 为 deployRoot，且跳过 retire。
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeUpdate/ThemeUpdateBatchService.php',
        );
        self::assertStringContainsString('StaticBuildId::isFlatMode()', $src);
        self::assertStringContainsString('$deployRoot = $flatMode' . "\n" . '            ? $pubStatic', $src);
        self::assertStringContainsString('if (!$flatMode) {', $src);
    }
}
