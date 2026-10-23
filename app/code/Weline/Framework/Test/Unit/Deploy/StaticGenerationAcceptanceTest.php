<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Deploy\StaticBuildGc;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Deploy\StaticBuildPublisher;
use Weline\Framework\Deploy\StaticPublicSurface;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 静态代次端到端验收（不依赖线上服务）：
 *
 * 1. 发布 v1 → 生成 {buildId1}，页面可用版本化 URL 取到 v1；
 * 2. 修改静态 → 发布 v2 → buildId 变化，新 URL 是 v2；
 * 3. 旧代目录仍在（旧 FPC 页面不会 404，也不会读到新字节）；
 * 4. 内容哈希文件不加构建前缀，且被判为「已版本化」；
 * 5. legacy 路径与版本化路径的缓存判定分开；
 * 6. GC 只清理窗口外的旧代，当前代永远保留；
 * 7. FPC 键带 static_generation。
 */
final class StaticGenerationAcceptanceTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-static-acceptance-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/Weline/Frontend/js', 0775, true);
        \mkdir($this->root . '/Weline/Theme/widget-assets', 0775, true);
        \file_put_contents($this->root . '/Weline/Frontend/js/app.js', 'console.log("v1");');
        \file_put_contents(
            $this->root . '/Weline/Theme/widget-assets/' . \str_repeat('a', 64) . '.css',
            'body{color:red}',
        );
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
            }
            @\rmdir($this->root);
        }
    }

    public function testPublishMutateRepublishKeepsOldGenerationAndSplitsCachePolicy(): void
    {
        // 1) 发布 v1
        $id1 = StaticBuildPublisher::publish($this->root);
        self::assertSame($id1, StaticBuildId::current($this->root));
        self::assertSame('console.log("v1");', \file_get_contents($this->root . '/' . $id1 . '/Weline/Frontend/js/app.js'));
        self::assertSame(
            '/static/' . $id1 . '/Weline/Frontend/js/app.js',
            StaticBuildId::versionedPath('/static/Weline/Frontend/js/app.js', $id1),
            '普通模块静态必须带构建代次',
        );

        // 2) 改静态 → 再发布（按发布器约定：临时文件 + 原子替换，不能原地截断硬链接）
        $legacyFile = $this->root . '/Weline/Frontend/js/app.js';
        $tmpFile = $legacyFile . '.tmp-' . \bin2hex(\random_bytes(4));
        \file_put_contents($tmpFile, 'console.log("v2");');
        \rename($tmpFile, $legacyFile);
        $id2 = StaticBuildPublisher::publish($this->root);
        self::assertNotSame($id1, $id2, '内容变化必须换构建号');
        self::assertSame($id2, StaticBuildId::current($this->root));

        // 3) 新 URL 是 v2；旧代目录保留 v1（旧 FPC 页面不会读错/404）
        self::assertSame('console.log("v2");', \file_get_contents($this->root . '/' . $id2 . '/Weline/Frontend/js/app.js'));
        self::assertDirectoryExists($this->root . '/' . $id1);
        self::assertSame('console.log("v1");', \file_get_contents($this->root . '/' . $id1 . '/Weline/Frontend/js/app.js'));
        // legacy 树跟随最新内容
        self::assertSame('console.log("v2");', \file_get_contents($this->root . '/Weline/Frontend/js/app.js'));

        // 4) 内容哈希文件免前缀，且算已版本化
        $hashUrl = '/static/Weline/Theme/widget-assets/' . \str_repeat('a', 64) . '.css';
        self::assertSame($hashUrl, StaticBuildId::versionedPath($hashUrl, $id2));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl($hashUrl));

        // 5) 缓存判定：版本化 → 长缓存；legacy → 协商
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/' . $id2 . '/Weline/Frontend/js/app.js'));
        self::assertFalse(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/app.js'));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/app.js', 'v=1'));
    }

    public function testGcKeepsCurrentAndRecentButDropsExpiredGeneration(): void
    {
        $current = StaticBuildPublisher::publish($this->root);
        $expired = $this->root . '/cccccccccccc';
        \mkdir($expired, 0775, true);
        \file_put_contents($expired . '/.weline-build-id', 'cccccccccccc');
        \touch($expired, \time() - 30 * 86400);

        $result = StaticBuildGc::prune($this->root, 7, 1, false);

        self::assertDirectoryExists($this->root . '/' . $current);
        self::assertDirectoryDoesNotExist($expired);
        self::assertContains('cccccccccccc', $result['deleted']);
        self::assertContains($current, $result['kept']);
    }

    public function testStorefrontKeyDimensionsCarryPageGeneration(): void
    {
        $identity = ScopeIdentity::channel(7, 'shop_a', 'default', 'default', ScopeIdentity::MODE_NORMAL);
        $fingerprint = \hash('sha256', 'ns');
        $context = new StorefrontCacheKeyContext($identity, 'zh_Hans_CN', 'CNY', $fingerprint, $fingerprint, true);
        $dimensions = $context->keyDimensions();

        self::assertArrayHasKey('page_generation', $dimensions);
        self::assertNotSame('', (string)$dimensions['page_generation']);
        self::assertMatchesRegularExpression(
            '/' . \preg_quote(StaticBuildId::current(), '/') . '(?:~e\d+)?$/',
            (string)$dimensions['page_generation'],
        );
    }
}
