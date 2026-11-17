<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupScopeCatalog;

/**
 * 多站点预热范围：站点行 → 逐站 Host 目标；纯函数可测，不依赖 DB / WLS。
 */
final class StorefrontWarmupScopeCatalogTest extends TestCase
{
    public function testFromRowsBuildsDefaultPlusNamedSites(): void
    {
        $rows = [
            ['website_id' => 0, 'name' => '长安汉服', 'code' => 'default', 'url' => 'http://127.0.0.1:8800'],
            ['website_id' => 544, 'name' => '邻里杂货铺', 'code' => 'grocery', 'url' => 'https://grocery.test.weline.com'],
            ['website_id' => 550, 'name' => 'PlayingInChina', 'code' => 'playinginchina', 'url' => 'https://playinginchina.test.weline.com'],
        ];

        $scopes = StorefrontWarmupScopeCatalog::fromRows($rows, 'p05113ef3.test.weline.com');

        self::assertSame(
            ['default', 'grocery', 'playinginchina'],
            \array_column($scopes, 'code')
        );
        self::assertSame('p05113ef3.test.weline.com', $scopes[0]['host']);
        self::assertSame('grocery.test.weline.com', $scopes[1]['host']);
        self::assertSame('default', $scopes[0]['ledger_key']);
        self::assertSame('grocery', $scopes[1]['ledger_key']);
    }

    public function testFromRowsNormalizesHostAndDropsLoopback(): void
    {
        $rows = [
            ['website_id' => 1, 'code' => 'a', 'name' => 'A', 'url' => 'https://GROCERY.Test.Weline.com:443/shop/'],
            ['website_id' => 2, 'code' => 'b', 'name' => 'B', 'url' => 'http://127.0.0.1:8800'],
            ['website_id' => 3, 'code' => 'c', 'name' => 'C', 'url' => 'http://localhost'],
            ['website_id' => 4, 'code' => 'd', 'name' => 'D', 'url' => ''],
            ['website_id' => 5, 'code' => 'e', 'name' => 'E', 'url' => 'https://amayum.com/'],
        ];

        $scopes = StorefrontWarmupScopeCatalog::fromRows($rows, 'p05113ef3.test.weline.com');

        self::assertSame(['default', 'a', 'e'], \array_column($scopes, 'code'));
        self::assertSame('grocery.test.weline.com', $scopes[1]['host']);
        self::assertSame('amayum.com', $scopes[2]['host']);
    }

    public function testFromRowsKeepsSingleDefaultWhenDefaultHostMissing(): void
    {
        $scopes = StorefrontWarmupScopeCatalog::fromRows([], '');

        self::assertCount(1, $scopes);
        self::assertSame('default', $scopes[0]['code']);
        self::assertSame('', $scopes[0]['host']);
        self::assertSame('default', $scopes[0]['ledger_key']);
    }

    public function testFromRowsDeduplicatesByCode(): void
    {
        $rows = [
            ['website_id' => 0, 'code' => 'default', 'name' => '默认', 'url' => 'http://127.0.0.1:8800'],
            ['website_id' => 9, 'code' => 'default', 'name' => '再来', 'url' => 'https://dupe.test.weline.com'],
            ['website_id' => 10, 'code' => 'grocery', 'name' => 'Grocery', 'url' => 'https://grocery.test.weline.com'],
        ];

        $scopes = StorefrontWarmupScopeCatalog::fromRows($rows, 'p05113ef3.test.weline.com');

        self::assertSame(['default', 'grocery'], \array_column($scopes, 'code'));
    }

    public function testFromRowsRejectsEmptyCodeRows(): void
    {
        $rows = [
            ['website_id' => 7, 'code' => '', 'name' => '无 code', 'url' => 'https://x.test.weline.com'],
            ['website_id' => 8, 'code' => 'ok', 'name' => 'OK', 'url' => 'https://y.test.weline.com'],
        ];

        $scopes = StorefrontWarmupScopeCatalog::fromRows($rows, 'p05113ef3.test.weline.com');

        self::assertSame(['default', 'ok'], \array_column($scopes, 'code'));
    }
}
