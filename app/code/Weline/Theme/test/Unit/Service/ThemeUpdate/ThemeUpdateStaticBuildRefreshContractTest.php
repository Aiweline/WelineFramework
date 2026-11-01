<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;

final class ThemeUpdateStaticBuildRefreshContractTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return \array_merge([
            'batch_id' => 'tu-20261028-044625-56ca87',
            'static_ns' => 'tu-20261028-044625-56ca87',
            'entity_root' => 'tu-20261028-044625-56ca87',
            'theme_prefix' => 'Weline/hanfu',
            'theme_id' => 3,
            'epoch' => 225,
            'pair_segment' => 'hanfu/v276',
            'static_build' => '87d75b385c01',
        ], $overrides);
    }

    public function testNoPointerSkips(): void
    {
        self::assertSame(
            ['changed' => false, 'static_build' => '', 'reason' => 'no_pointer'],
            ThemeUpdateBatchService::planInPlaceStaticBuild(null, 'abcdef123456')
        );
        self::assertSame(
            'no_pointer',
            ThemeUpdateBatchService::planInPlaceStaticBuild(['theme_prefix' => 'Weline/hanfu'], 'abcdef123456')['reason']
        );
    }

    public function testMissingThemePrefixSkips(): void
    {
        $plan = ThemeUpdateBatchService::planInPlaceStaticBuild($this->row(['theme_prefix' => '']), 'abcdef123456');
        self::assertFalse($plan['changed']);
        self::assertSame('no_theme_prefix', $plan['reason']);
    }

    public function testEmptyFingerprintSkips(): void
    {
        $plan = ThemeUpdateBatchService::planInPlaceStaticBuild($this->row(), '');
        self::assertFalse($plan['changed']);
        self::assertSame('empty_tree', $plan['reason']);
    }

    public function testSameFingerprintIsIdempotent(): void
    {
        $plan = ThemeUpdateBatchService::planInPlaceStaticBuild($this->row(['static_build' => 'feedfacecafe']), 'FEEDFACECAFE');
        self::assertFalse($plan['changed']);
        self::assertSame('unchanged', $plan['reason']);
        self::assertSame('feedfacecafe', $plan['static_build']);
    }

    public function testNewFingerprintUpdates(): void
    {
        $plan = ThemeUpdateBatchService::planInPlaceStaticBuild($this->row(), '0123456789ab');
        self::assertTrue($plan['changed']);
        self::assertSame('updated', $plan['reason']);
        self::assertSame('0123456789ab', $plan['static_build']);
        // 只输出这三个键，调用方据此只改 static_build，不动 batch_id/epoch。
        self::assertSame(['changed', 'static_build', 'reason'], \array_keys($plan));
    }

    /**
     * 回归（2026-11-01 事故）：连续两次 theme:update、静态树没变时，
     * 第二次必须「什么也不换」——否则每次发布都会把所有缓存打掉。
     *
     * 用真实目录走「算指纹 → 决策」全链路（纯函数，不写指针/Env）。
     */
    public function testRepeatedPublishWithUnchangedTreeIsIdempotent(): void
    {
        $root = \sys_get_temp_dir() . '/weline-idem-' . \uniqid('', true);
        \mkdir($root . '/theme-head', 0775, true);
        \file_put_contents($root . '/theme-head/abc123.css', 'body{color:red}');
        \file_put_contents($root . '/keep.css', 'p{margin:0}');

        try {
            $row = $this->row();
            // 第一次发布：指纹与指针上的旧部署号不同 → 换代。
            $fp1 = \Weline\Theme\Service\ThemeUpdate\ThemeStaticTreeFingerprint::compute($root);
            $first = ThemeUpdateBatchService::planInPlaceStaticBuild($row, $fp1);
            self::assertTrue($first['changed']);
            $row['static_build'] = $first['static_build'];

            // 第二次发布：发布过程重写了内容寻址文件（只动 mtime，文件名不变）→ 必须 unchanged。
            \touch($root . '/theme-head/abc123.css', \time() + 5);
            $fp2 = \Weline\Theme\Service\ThemeUpdate\ThemeStaticTreeFingerprint::compute($root);
            self::assertSame($fp1, $fp2);
            $second = ThemeUpdateBatchService::planInPlaceStaticBuild($row, $fp2);
            self::assertFalse($second['changed']);
            self::assertSame('unchanged', $second['reason']);
            self::assertSame($first['static_build'], $second['static_build']);

            // 第三次发布：真的改了内容 → 必须换代，且 epoch/batch 不动（决策函数不碰它们）。
            \file_put_contents($root . '/keep.css', 'p{margin:1px}');
            $fp3 = \Weline\Theme\Service\ThemeUpdate\ThemeStaticTreeFingerprint::compute($root);
            self::assertNotSame($fp2, $fp3);
            $third = ThemeUpdateBatchService::planInPlaceStaticBuild($row, $fp3);
            self::assertTrue($third['changed']);
            self::assertSame('tu-20261028-044625-56ca87', $row['batch_id']);
            self::assertSame(225, $row['epoch']);
        } finally {
            foreach ([$root . '/theme-head/abc123.css', $root . '/keep.css'] as $file) {
                @\unlink($file);
            }
            @\rmdir($root . '/theme-head');
            @\rmdir($root);
        }
    }
}
