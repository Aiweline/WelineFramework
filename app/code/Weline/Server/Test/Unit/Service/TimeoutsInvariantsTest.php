<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Timeouts;

/**
 * P1-7：超时分层的不变量锁定测试。
 *
 * 用途：
 *   - 任何人调整 Timeouts 常量时，CI 立即失败并给出具体违反项；
 *   - 等价于 "编译期契约"，比隐式运行期发现问题快数个数量级。
 */
final class TimeoutsInvariantsTest extends TestCase
{
    public function testDefaultConstantsPassAllInvariants(): void
    {
        // 不抛异常即通过；抛出会被 PHPUnit 自动标记为失败。
        Timeouts::assertInvariants();
        $this->assertTrue(true, '默认常量通过全部跨层不变量');
    }

    public function testWorkerConnectSelectDefaultStaysInConfiguredBounds(): void
    {
        $this->assertGreaterThanOrEqual(
            Timeouts::WORKER_CONNECT_SELECT_MIN_SEC,
            Timeouts::WORKER_CONNECT_SELECT_DEFAULT_SEC,
            '默认值不得低于下限'
        );
        $this->assertLessThanOrEqual(
            Timeouts::WORKER_CONNECT_SELECT_MAX_SEC,
            Timeouts::WORKER_CONNECT_SELECT_DEFAULT_SEC,
            '默认值不得超过上限'
        );
    }

    public function testWorkerConnectSelectDoesNotExceedSpinBudget(): void
    {
        $this->assertLessThanOrEqual(
            Timeouts::HANDLE_NEW_CONNECTION_SPIN_BUDGET_SEC,
            Timeouts::WORKER_CONNECT_SELECT_DEFAULT_SEC,
            '单次 connect select 超过主循环自旋预算会让整个 tick 堵塞'
        );
    }

    public function testMasterMissingGraceExceedsCheckIntervalTimesThreshold(): void
    {
        $minGrace = Timeouts::MASTER_PID_CHECK_INTERVAL_SEC
            * \max(Timeouts::MASTER_MISSING_COUNT_THRESHOLD, 3);
        $this->assertGreaterThanOrEqual(
            $minGrace,
            Timeouts::MASTER_MISSING_GRACE_SEC,
            '宽限不足会让偶发 tasklist 抖动误判 Master 死亡'
        );
    }

    public function testControlConnectTimeoutAtLeastMatchesDefaultReadTimeout(): void
    {
        $this->assertGreaterThanOrEqual(
            Timeouts::CONTROL_CMD_DEFAULT_READ_SEC,
            Timeouts::CONTROL_MIN_CONNECT_TIMEOUT_SEC,
            '连接超时不能比读超时短，否则还没连上就放弃'
        );
    }

    public function testWindowsHardTimeoutIsNotShorterThanLinux(): void
    {
        $this->assertGreaterThanOrEqual(
            Timeouts::STOP_IPC_HARD_LINUX_SEC,
            Timeouts::STOP_IPC_HARD_WIN_SEC,
            'Windows 进程回收普遍更慢，硬超时应 >= Linux'
        );
    }

    public function testWorkerStopTimeoutIsNotShorterThanStartTimeout(): void
    {
        $this->assertGreaterThanOrEqual(
            Timeouts::WORKER_START_TIMEOUT_SEC,
            Timeouts::WORKER_STOP_TIMEOUT_SEC,
            '缩容耗时不得短于扩容，避免出现旧 Worker 未退、新 Worker 未起的真空期'
        );
    }

    public function testPendingMaintenanceWaitIsBoundedByHealthyZeroWindow(): void
    {
        $this->assertLessThanOrEqual(
            Timeouts::HEALTHY_ZERO_MAINTENANCE_THRESHOLD_SEC * 2,
            Timeouts::PENDING_MAINTENANCE_WAIT_TIMEOUT_SEC,
            '队列等首字节时间过长会超过 healthy_zero 的窗口，维护页呈现反而滞后于系统判定'
        );
    }
}
