<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service\Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Cdn\BudgetedPurgeAdapter;
use Weline\Theme\Service\Cdn\CdnPurgeAdapterInterface;
use Weline\Theme\Service\Cdn\PurgeBatchPlanner;
use Weline\Theme\Service\Cdn\PurgeBudgetGate;

/**
 * P4 的离线部分：预算闸门 + 观测记录。时钟注入，不 sleep、不触网。
 */
class PurgeBudgetGateTest extends TestCase
{
    public function testBudgetLimitsPerWindowAndRestoresNextWindow(): void
    {
        $now = 1000;
        $gate = new PurgeBudgetGate(1, 10, static function () use (&$now): int {
            return $now;
        });

        self::assertTrue($gate->allow('a.test'), '窗口内第 1 次允许');
        self::assertFalse($gate->allow('a.test'), '窗口内第 2 次超预算');

        $now += 10; // 进入下一个 10 秒窗口
        self::assertTrue($gate->allow('a.test'), '新窗口恢复额度');
    }

    public function testBudgetIsPerZone(): void
    {
        $gate = new PurgeBudgetGate(1, 60, static fn(): int => 500);

        self::assertTrue($gate->allow('a.test'));
        self::assertFalse($gate->allow('a.test'));
        self::assertTrue($gate->allow('b.test'), '其他 zone 不受 a.test 配额影响');
    }

    public function testExhaustedBudgetIsFailureNotSilentSuccess(): void
    {
        $inner = new class implements CdnPurgeAdapterInterface {
            public int $calls = 0;

            public function purge(string $zone, array $targets): bool
            {
                $this->calls++;

                return true;
            }
        };
        $gate = new PurgeBudgetGate(1, 60, static fn(): int => 900);
        $adapter = new BudgetedPurgeAdapter($inner, $gate);

        self::assertTrue($adapter->purge('a.test', ['/']));
        self::assertFalse($adapter->purge('a.test', ['/']), '预算耗尽必须返回失败');
        self::assertSame(1, $inner->calls, '预算耗尽时不再打 CDN');

        $observations = $adapter->observations();
        self::assertSame('purge', $observations[0]['stage']);
        self::assertSame('budget_exhausted', $observations[1]['reason']);
    }

    public function testPlannerLeavesResumePointWhenBudgetExhausted(): void
    {
        $inner = new class implements CdnPurgeAdapterInterface {
            public function purge(string $zone, array $targets): bool
            {
                return true;
            }
        };
        $adapter = new BudgetedPurgeAdapter($inner, new PurgeBudgetGate(1, 3600, static fn(): int => 7));
        $planner = new PurgeBatchPlanner();
        $plan = $planner->plan(
            ['shared' => false, 'exact' => ['a', 'b', 'c'], 'static' => false, 'prefixes' => []],
            ['site.test'],
            1,
        );
        self::assertCount(3, $plan);

        $result = $planner->run($plan, $adapter);

        self::assertSame(1, $result['done'], '第 1 批成功');
        self::assertSame(1, $result['failed'], '第 2 批被预算挡下');
        self::assertSame(1, $result['resumeFrom'], '留下续跑点');
    }
}
