<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticBuildId;
use Weline\Framework\Deploy\StaticPublicSurface;

/**
 * 长缓存只允许给「内容寻址」的静态 URL：
 * - /static/{12位hex buildId}/...
 * - 或带 v= / weline_asset= / _weline_dev= 查询
 * 其余 /static/** 必须协商缓存，避免旧 FPC 引用被永久钉死。
 */
final class StaticCachePolicyTest extends TestCase
{
    public function testVersionedStaticUrlDetection(): void
    {
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/05561952395a/Weline/Frontend/js/a.js'));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/05561952395a/Weline/Frontend/js/a.js', ''));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/a.js', 'v=1.2.3'));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/a.js', 'a=1&weline_asset=abc'));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/a.js', '_weline_dev=dev'));

        // 模块命名空间（Weline / Aiweline / Codex）不是 12 位 hex，不能被误判成版本段
        self::assertFalse(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/a.js'));
        self::assertFalse(StaticPublicSurface::isVersionedStaticUrl('/static/Aiweline/Core/js/a.js'));
        self::assertFalse(StaticPublicSurface::isVersionedStaticUrl('/static/Weline/Frontend/js/a.js', 'foo=1'));
        // 非 /static 路径不参与该策略（media 保持既有长缓存）
        self::assertFalse(StaticPublicSurface::isVersionedStaticUrl('/pub/media/a.jpg', 'v=1'));
    }

    public function testThemeAssetUrlsCarryBuildGeneration(): void
    {
        $gateway = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Service/ThemeResourceGateway.php');
        self::assertStringContainsString('StaticBuildId::versionedPath', $gateway);

        $pack = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Service/ThemeHeadChromeCssPack.php');
        self::assertStringContainsString('StaticBuildId::versionedPath', $pack);
    }

    public function testContentAddressedAssetsNeedNoBuildPrefixAndStayLongCached(): void
    {
        $hash = \str_repeat('a', 64);
        $path = '/static/Weline/T/w/Weline/Theme/widget-assets/' . $hash . '.css';
        self::assertSame($path, StaticBuildId::versionedPath($path, 'abc123def456'));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl($path));

        $config = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php',
        );
        self::assertStringContainsString('[0-9a-f]{32,64}', $config);
    }

    public function testThemeUpdateGenerationRootsAreNotBuildPrefixed(): void
    {
        // 本次重构 tu-* 退场：旧代次根不再保留，统一换当前部署代次。
        $path = '/static/tu-20261021-193951-e0b9a1/Weline/hanfu/frontend/assets/images/ink/a.webp';
        self::assertSame(
            '/static/abc123def456/Weline/hanfu/frontend/assets/images/ink/a.webp',
            StaticBuildId::versionedPath($path, 'abc123def456'),
        );
    }

    public function testThemeGenerationPairDirectoriesAreLongCached(): void
    {
        $path = '/static/hanfu/v3/abc123def456/Weline/hanfu/frontend/assets/css/a.css';
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl($path));
        self::assertTrue(StaticPublicSurface::isVersionedStaticUrl('/static/hanfu/v3/abc123def456/'));
        // 非世代形状不能被误判（v{n} 后无 12 位部署段）
        self::assertFalse(StaticPublicSurface::isVersionedStaticUrl('/static/hanfu/v3/Weline/x.css'));
    }

    public function testVersionedPathReplacesDeploySegmentWithoutStacking(): void
    {
        // 部署段不同 → 替换，不叠加
        self::assertSame(
            '/static/bbb222ccc333/Weline/Frontend/js/a.js',
            StaticBuildId::versionedPath('/static/aaa111bbb222/Weline/Frontend/js/a.js', 'bbb222ccc333'),
        );
        // 主题对目录：保留 code/v{n}，只换部署段
        self::assertSame(
            '/static/hanfu/v3/bbb222ccc333/Weline/hanfu/a.css',
            StaticBuildId::versionedPath('/static/hanfu/v3/aaa111bbb222/Weline/hanfu/a.css', 'bbb222ccc333'),
        );
        // 主题对目录同部署段 → 原样
        self::assertSame(
            '/static/hanfu/v3/abc123def456/Weline/hanfu/a.css',
            StaticBuildId::versionedPath('/static/hanfu/v3/abc123def456/Weline/hanfu/a.css', 'abc123def456'),
        );
    }

    public function testVersionedPathPreservesTrailingSlash(): void
    {
        self::assertSame(
            '/static/abc123def456/Weline/hanfu/frontend/assets/images/',
            StaticBuildId::versionedPath('/static/Weline/hanfu/frontend/assets/images/', 'abc123def456'),
        );
    }

    public function testPolicyCacheKeysCarryPageGeneration(): void
    {
        $src = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Cache/KeyBuilder.php');
        self::assertStringContainsString("'page_generation'", $src);
        $ctx = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Cache/StorefrontCacheKeyContext.php');
        self::assertStringContainsString("'page_generation'", $ctx);
    }

    public function testRouterUsesVersionedStaticCachePolicy(): void
    {
        $src = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Router/Core.php');
        self::assertStringContainsString('isVersionedStaticUrl', $src);
        self::assertStringContainsString('must-revalidate', $src);
    }

    public function testWlsWorkerUsesVersionedStaticCachePolicy(): void
    {
        $src = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl_event.php');
        self::assertStringContainsString('isVersionedStaticUrl', $src);
        self::assertStringContainsString('must-revalidate', $src);
    }

    public function testManagedNginxUsesVersionedStaticCachePolicy(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php',
        );
        self::assertStringContainsString('wls_static_cache_control', $src);
        self::assertStringContainsString('wls_static_versioned', $src);
        self::assertStringContainsString('wls_static_arg_versioned', $src);
    }
}
