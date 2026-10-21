<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
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
