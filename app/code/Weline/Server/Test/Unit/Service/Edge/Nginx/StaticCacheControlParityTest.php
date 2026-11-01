<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;

/**
 * Task 6：边缘（托管 Nginx）与服务端静态缓存头必须同规则。
 *
 * 2026-11-01 用户决定：静态资源统一 **1 年强缓存**（不再区分命名路由/内容寻址），
 * 版本隔离由路径段承担；发布时由发布流程清 CDN 与 FPC。
 *
 * 本测试锁定：nginx map 的 default **不得**回退成 `max-age=0`，
 * 且生成器（ManagedNginxConfigWriter）与落盘 nginx.conf 必须一致。
 */
final class StaticCacheControlParityTest extends TestCase
{
    private const ONE_YEAR_IMMUTABLE = 'public, max-age=31536000, immutable';

    public function testNginxMapUsesOneYearForAllStatics(): void
    {
        $conf = (string)\file_get_contents(BP . 'var/server/nginx/conf/nginx.conf');
        self::assertNotSame('', $conf, 'nginx.conf 不可读');

        self::assertMatchesRegularExpression(
            '/map "\$wls_static_versioned\$wls_static_arg_versioned" \$wls_static_cache_control \{\s*default "'
            . \preg_quote(self::ONE_YEAR_IMMUTABLE, '/') . '";/',
            $conf,
            '静态缓存头 default 必须是 1 年 immutable（与服务端一致）'
        );
        self::assertStringNotContainsString(
            'default "public, max-age=0, must-revalidate";',
            $conf,
            '静态缓存头不得回退成 max-age=0'
        );
    }

    public function testGeneratorMatchesOnDiskConfig(): void
    {
        $writer = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php'
        );
        self::assertNotSame('', $writer, 'ManagedNginxConfigWriter.php 不可读');
        self::assertStringContainsString(
            '"~1" "' . self::ONE_YEAR_IMMUTABLE . '";',
            $writer,
            '生成器必须保留 1 年 immutable 分支'
        );
        self::assertStringNotContainsString(
            'default "public, max-age=0, must-revalidate";',
            $writer,
            '生成器不得回退成 max-age=0'
        );
    }

    public function testWlsServerSideUsesOneYear(): void
    {
        $worker = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
        self::assertMatchesRegularExpression(
            '/\$maxAge\s*=\s*31536000;/',
            $worker,
            'worker 静态缓存必须为 1 年'
        );
    }
}
