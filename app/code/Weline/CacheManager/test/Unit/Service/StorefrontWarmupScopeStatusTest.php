<?php
declare(strict_types=1);

namespace Weline\CacheManager\Service;

use Weline\Framework\Test\TestCore;

/** 后台范围切换：全部=聚合 + 逐站列表；某站=单站明细；未知 scope 回退全部。 */
final class StorefrontWarmupScopeStatusTest extends TestCore
{
    public function testAggregateSumsPerSiteOnce(): void
    {
        $result = StorefrontWarmupAdminService::aggregateSites([
            ['code' => 'default', 'name' => '默认站', 'host' => 'a.test',
             'progress' => ['done' => 3, 'total' => 10, 'percent' => 30.0, 'remaining' => 7],
             'totals' => ['ok' => 3, 'skip' => 0, 'fail' => 0], 'verdict' => ['state' => 'queued']],
            ['code' => 'grocery', 'name' => 'Grocery', 'host' => 'b.test',
             'progress' => ['done' => 5, 'total' => 10, 'percent' => 50.0, 'remaining' => 5],
             'totals' => ['ok' => 5, 'skip' => 1, 'fail' => 2], 'verdict' => ['state' => 'running']],
        ]);

        self::assertSame(8, $result['progress']['done']);
        self::assertSame(20, $result['progress']['total']);
        self::assertSame(40.0, $result['progress']['percent']);
        self::assertSame(12, $result['progress']['remaining']);
        self::assertSame(8, $result['totals']['ok']);
        self::assertSame(1, $result['totals']['skip']);
        self::assertSame(2, $result['totals']['fail']);
        self::assertSame('running', $result['verdict']['state']);
        self::assertCount(2, $result['sites']);
    }

    public function testAggregateHandlesEmptySiteList(): void
    {
        $result = StorefrontWarmupAdminService::aggregateSites([]);

        self::assertSame(0, $result['progress']['done']);
        self::assertSame(0, $result['progress']['total']);
        self::assertSame(0.0, $result['progress']['percent']);
        self::assertSame('never', $result['verdict']['state']);
        self::assertSame([], $result['sites']);
    }

    public function testScopeOptionsStartWithAll(): void
    {
        $options = StorefrontWarmupAdminService::scopeOptions([
            ['code' => 'default', 'name' => '默认站', 'host' => 'a.test', 'ledger_key' => 'default'],
            ['code' => 'grocery', 'name' => 'Grocery', 'host' => 'b.test', 'ledger_key' => 'grocery'],
        ]);

        self::assertSame('all', $options[0]['value']);
        self::assertSame('default', $options[1]['value']);
        self::assertSame('grocery', $options[2]['value']);
        self::assertSame('默认站', $options[1]['label']);
    }

    public function testNormalizeScopeFallsBackToAllForUnknown(): void
    {
        $catalog = new \Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupScopeCatalog();

        self::assertSame('all', StorefrontWarmupAdminService::normalizeScope('', $catalog));
        self::assertSame('all', StorefrontWarmupAdminService::normalizeScope('all', $catalog));
        self::assertSame('all', StorefrontWarmupAdminService::normalizeScope('does-not-exist', $catalog));
        self::assertSame('default', StorefrontWarmupAdminService::normalizeScope('default', $catalog));
    }
}
