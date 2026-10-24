<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerScaler;

/**
 * RED-first：worker 实例编号必须取「历史最大编号 + 1」单调递增，
 * 不得由「当前存活实例数 + 1」推算。
 *
 * 线上证据（changanhanfu.com，2026-10-25 00:21）：
 *   - `server:status` 出现 `HTTP Worker #1100 ○ 已停止`（正常编号 1–8），
 *     且 PID 46999 已不存在（/proc/46999 缺失、ps 空），属登记残留；
 *   - nginx error.log 累计 `upstream prematurely closed` 1335 次，
 *     源站 20 次采样 1–4 次 503（列表页多图并发放大为用户可见占位图）。
 *
 * 根因：WorkerScaler::scaleUp() 用 `count($currentWorkers) + 1` 推导下一个
 * 编号。当 registry 中存在未清理的已停止实例（幽灵登记）时，count 被抬高，
 * 编号跳跃；且两次扩容之间若存活数下降，编号会被复用。
 */
final class WorkerScalerInstanceIdTest extends TestCase
{
    /**
     * @dataProvider provideCountsAndExpectedNextId
     */
    public function testNextInstanceIdIsMonotonicMaxPlusOne(array $existingIds, int $expectedNext): void
    {
        $method = new \ReflectionMethod(WorkerScaler::class, 'nextInstanceId');
        $method->setAccessible(true);

        // 构造 registry 视图：existingIds 为「当前登记的全部实例编号」，
        // 其中可能包含已停止的幽灵实例（WorkerScaler 只按 role 计数，无法区分）。
        $actual = $method->invoke(null, $existingIds);

        self::assertSame(
            $expectedNext,
            $actual,
            sprintf(
                '登记编号 [%s] 时，下一个编号必须是 %d（历史最大+1），实际 %d',
                implode(',', $existingIds),
                $expectedNext,
                $actual,
            ),
        );
    }

    public static function provideCountsAndExpectedNextId(): iterable
    {
        // 常规：1..8 全存活 → 9
        yield 'healthy 1..8' => [[1, 2, 3, 4, 5, 6, 7, 8], 9];
        // 幽灵场景 A：8 个存活 + 残留的 1100 → 下一个必须 1101，而不是 9
        // （9 与历史编号冲突，会登记出第二个 #9）
        yield 'ghost 1100 present' => [[1, 2, 3, 4, 5, 6, 7, 8, 1100], 1101];
        // 幽灵场景 B：残留空洞（1..6, 1100）→ 1101
        yield 'sparse with ghost' => [[1, 2, 3, 4, 5, 6, 1100], 1101];
        // 缩容后：6/7/8 已被 removeInstance 正常清理、registry 无痕迹 → 6 合法
        // （真正的 bug 场景是「已停止实例残留登记」，由上面两条幽灵用例锁定）
        yield 'after clean scale-down' => [[1, 2, 3, 4, 5], 6];
        // 空登记 → 1
        yield 'empty registry' => [[], 1];
    }

    public function testHelperMethodExistsOnScalerClass(): void
    {
        // 防止实现方把逻辑内联进 scaleUp() 而不留可测入口：
        // 允许任意实现，但必须存在（或经 scaleUp 间接可测）。
        // 此处只锁「nextInstanceId 方法存在」这一最小契约。
        self::assertTrue(
            method_exists(WorkerScaler::class, 'nextInstanceId')
                || method_exists(WorkerScaler::class, 'allocateNextInstanceId')
                || method_exists(WorkerScaler::class, 'nextWorkerInstanceId'),
            'WorkerScaler 必须提供可测的下一个实例编号推导入口',
        );
    }
}
