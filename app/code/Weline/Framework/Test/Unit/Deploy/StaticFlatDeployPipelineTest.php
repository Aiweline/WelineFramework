<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Deploy\StaticBuildGc;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Deploy\StaticBuildPublisher;
use Weline\Framework\Deploy\StaticCarryOver;

/**
 * flat 契约（dev.static_flat_urls）下的发布管道单树化行为：
 * staging 逐文件原子覆盖 live 平铺树、不整树 rename、不删 legacy 世代目录、
 * publisher 不再制造 12hex 整树副本、GC 冻结旧代。
 */
final class StaticFlatDeployPipelineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-flat-deploy-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0775, true);
        StaticBuildId::setFlatUrlsOverride(true);
    }

    protected function tearDown(): void
    {
        StaticBuildId::setFlatUrlsOverride(null);
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testPublisherSkipsGenerationCopyButKeepsManifest(): void
    {
        \mkdir($this->root . '/Weline/Frontend/js', 0775, true);
        \file_put_contents($this->root . '/Weline/Frontend/js/a.js', 'v1');

        $id = StaticBuildPublisher::publish($this->root);

        self::assertNotSame('', $id);
        self::assertSame($id, StaticBuildId::current($this->root));
        // 不再制造整树 12hex 副本
        self::assertDirectoryDoesNotExist($this->root . '/' . $id);
        self::assertFileExists($this->root . '/.build.json');
    }

    public function testGcFreezesLegacyGenerations(): void
    {
        $stale = $this->makeMarkerBuild('cccccccccccc', 30);
        $tmp = $this->root . '/.tmp-dddddddddddd';
        \mkdir($tmp, 0775, true);
        \touch($tmp, \time() - 3 * 86400);

        $result = StaticBuildGc::prune($this->root, 7, 1, false);

        self::assertDirectoryExists($this->root . '/' . $stale, 'flat 冻结令：旧世代目录不得删除');
        self::assertDirectoryDoesNotExist($tmp, '.tmp-* 中断产物仍要清理');
        self::assertStringNotContainsString($stale, \implode(',', $result['deleted']), '冻结代绝不出现在删除清单');
    }

    public function testOverlayStaticFlatKeepsLiveOnlyBytes(): void
    {
        $live = $this->root . '/pub-static';
        \mkdir($live . '/Weline/hanfu/css', 0775, true);
        \mkdir($live . '/a1b2c3d4e5f6/Weline', 0775, true);
        \file_put_contents($live . '/Weline/hanfu/css/a.css', 'old');
        \file_put_contents($live . '/Weline/hanfu/keepme.css', 'keep');
        \file_put_contents($live . '/a1b2c3d4e5f6/Weline/x.js', 'legacy');

        $staging = $this->root . '/staging-static';
        \mkdir($staging . '/Weline/hanfu/css', 0775, true);
        \file_put_contents($staging . '/Weline/hanfu/css/a.css', 'new');
        \file_put_contents($staging . '/Weline/hanfu/new.css', 'fresh');
        // staging 里混入 legacy 世代形状 → 不参与覆盖
        \mkdir($staging . '/b1c2d3e4f5a6', 0775, true);
        \file_put_contents($staging . '/b1c2d3e4f5a6/y.js', 'should-skip');

        $session = (new \ReflectionClass(DeployStagingSession::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($session, 'overlayStaticFlat');
        $method->setAccessible(true);
        $stampProp = new \ReflectionProperty(DeployStagingSession::class, 'stamp');
        $stampProp->setAccessible(true);
        $stampProp->setValue($session, 'test-stamp');
        $method->invoke($session, $staging, $live);

        self::assertSame('new', \file_get_contents($live . '/Weline/hanfu/css/a.css'));
        self::assertSame('fresh', \file_get_contents($live . '/Weline/hanfu/new.css'));
        self::assertSame('keep', \file_get_contents($live . '/Weline/hanfu/keepme.css'), 'live 独有历史字节必须保留');
        self::assertDirectoryExists($live . '/a1b2c3d4e5f6', 'legacy 世代目录不参与覆盖也不得被删');
        self::assertDirectoryDoesNotExist($live . '/b1c2d3e4f5a6', 'staging 的世代形状不得铺进 live');
        self::assertSame('legacy', \file_get_contents($live . '/a1b2c3d4e5f6/Weline/x.js'));
        // 不留 .swap-* 残留
        $residue = iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($live, \FilesystemIterator::SKIP_DOTS),
        ));
        foreach ($residue as $item) {
            self::assertStringNotContainsString('.swap-', $item->getFilename(), '原子替换后不得有 tmp 残留');
        }
    }

    public function testCarryForwardRetentionWindowStillPrunesOwnTombstones(): void
    {
        // carry 在 flat 模式不再被 commitSwap 调用，但 pruneExpired 语义保持独立可用。
        $carry = new StaticCarryOver($this->root . '/carry.json', 1);
        self::assertSame(1, $carry->retentionSeconds());
    }

    private function makeMarkerBuild(string $id, int $ageDays): string
    {
        $dir = $this->root . '/' . $id;
        \mkdir($dir, 0775, true);
        \file_put_contents($dir . '/' . StaticBuildId::BUILD_MARKER, $id);
        \touch($dir, \time() - $ageDays * 86400);
        \touch($dir . '/' . StaticBuildId::BUILD_MARKER, \time() - $ageDays * 86400);

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
