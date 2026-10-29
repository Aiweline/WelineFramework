<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCronServiceContractsPass().
 */
final class CronServiceContractsTest extends TestCase
{
    public function testAllCronServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ManagedChildAdmission::testSoftAdmissionRemovedFromRunDispatcher' => function () {
            $this->setUpBeforeClass_ManagedChildAdmission();
            $this->assert_ManagedChildAdmission_testSoftAdmissionRemovedFromRunDispatcher();
        },
            'CronCpuBudgetPolicy::testDefaultsMatchInterfaceConstants' => function () {
            $this->setUpBeforeClass_ManagedChildAdmission();
            $this->assert_CronCpuBudgetPolicy_testDefaultsMatchInterfaceConstants();
        },
            'CronCpuBudgetPolicy::testResolveClampsAndFallsBack' => function () {
            $this->setUpBeforeClass_ManagedChildAdmission();
            $this->assert_CronCpuBudgetPolicy_testResolveClampsAndFallsBack();
        },
            'CronCpuGovernor::testSleepFormulaConvergesToTarget' => function () {
            $this->setUpBeforeClass_ManagedChildAdmission();
            $this->assert_CronCpuGovernor_testSleepFormulaConvergesToTarget();
        },
            'CronCpuGovernor::testDegradesWithoutStealingHostAlarm' => function () {
            $this->setUpBeforeClass_ManagedChildAdmission();
            $this->assert_CronCpuGovernor_testDegradesWithoutStealingHostAlarm();
        },
        ];
    }

private function assert_CronCpuBudgetPolicy_testDefaultsMatchInterfaceConstants(): void
{
    // 不注入 reader：纯默认值路径，不需要数据库或请求上下文。
    $policy = new \Weline\Cron\Service\CronCpuBudgetPolicy(null);
    self::assertSame(1, $policy->defaultCpuCount());
    self::assertSame(30, $policy->defaultCpuPercent());
    self::assertTrue($policy->enforce());
    self::assertSame(\Weline\Cron\CronTaskCpuBudgetInterface::DEFAULT_CPU_COUNT, $policy->defaultCpuCount());
    self::assertSame(\Weline\Cron\CronTaskCpuBudgetInterface::DEFAULT_CPU_PERCENT, $policy->defaultCpuPercent());
}

private function assert_CronCpuBudgetPolicy_testResolveClampsAndFallsBack(): void
{
    $policy = new \Weline\Cron\Service\CronCpuBudgetPolicy(null);

    // 任务行未声明（0）→ 回落默认 1 / 30，额度 0.3 核当量。
    $resolved = $policy->resolve(0, 0);
    self::assertSame(1, $resolved['count']);
    self::assertSame(30, $resolved['percent']);
    self::assertEqualsWithDelta(0.3, $resolved['budget'], 1e-9);

    // 越界值必须夹紧，不能把非法值直接乘进额度。
    $clamped = $policy->resolve(999, 999);
    self::assertSame(\Weline\Cron\Service\CronCpuBudgetPolicy::MAX_CPU_COUNT, $clamped['count']);
    self::assertSame(100, $clamped['percent']);

    // 负数按未声明处理。
    $negative = $policy->resolve(-5, -5);
    self::assertSame(1, $negative['count']);
    self::assertSame(30, $negative['percent']);

    // 2 个 CPU × 30% = 0.6 核当量（口径自洽：不是 30% 总量）。
    $twoCores = $policy->resolve(2, 30);
    self::assertSame(2, $twoCores['count']);
    self::assertEqualsWithDelta(0.6, $twoCores['budget'], 1e-9);

    // enforce 总开关可被显式覆盖。
    self::assertFalse($policy->resolve(1, 30, false)['enforce']);

    // 配置中心键名必须与策略常量逐字一致（配置页 embed 用同一字面）。
    self::assertSame('cron/cpu/default_count', \Weline\Cron\Service\CronCpuBudgetPolicy::KEY_DEFAULT_COUNT);
    self::assertSame('cron/cpu/default_percent', \Weline\Cron\Service\CronCpuBudgetPolicy::KEY_DEFAULT_PERCENT);
    self::assertSame('cron/cpu/enforce', \Weline\Cron\Service\CronCpuBudgetPolicy::KEY_ENFORCE);
}

private function assert_CronCpuGovernor_testSleepFormulaConvergesToTarget(): void
{
    $governor = new \Weline\Cron\Service\CronCpuGovernor();

    // 动态上限：额度越低单次可睡越久，但低额度不再用固定 5s（那会让 10% 冲到 15%）。
    self::assertEqualsWithDelta(20.0, $governor::dynamicCap(0.1), 1e-9);
    self::assertEqualsWithDelta(20.0 / 3.0, $governor::dynamicCap(0.3), 1e-9);
    self::assertEqualsWithDelta(5.0, $governor::dynamicCap(1.2), 1e-9);
    self::assertEqualsWithDelta(5.0, $governor::dynamicCap(0.0), 1e-9);

    // 已达标不睡：本机实测 10% 跑完 4.8s CPU / 47.3s 墙钟 → need = 48.0 - 47.32 < 0。
    self::assertEqualsWithDelta(
        0.0,
        $governor::plannedSleepSeconds(4.80, 48.10, 0.1, $governor::dynamicCap(0.1)),
        1e-9
    );
    // 恰好达标也不睡（need = 0 的边界）。
    self::assertEqualsWithDelta(
        0.0,
        $governor::plannedSleepSeconds(4.80, 48.00, 0.1, $governor::dynamicCap(0.1)),
        1e-9
    );
    // 超额才睡，且被动态上限夹住（不会一口气睡到失联）：
    // need = 5.0/0.1 - 5.0 = 45.0，cap = 20.0 → 只能睡 20.0。
    self::assertEqualsWithDelta(
        20.0,
        $governor::plannedSleepSeconds(5.0, 5.0, 0.1, $governor::dynamicCap(0.1)),
        1e-9
    );
    // 30% 额度下 2.0s CPU / 1.0s 墙钟 → need = 6.6667 - 1.0 = 5.6667（未触到 cap 6.6667）。
    self::assertEqualsWithDelta(
        6.6666666666667 - 1.0,
        $governor::plannedSleepSeconds(2.0, 1.0, 0.3, $governor::dynamicCap(0.3)),
        1e-9
    );
    // 预算为 0 不得除零。
    self::assertEqualsWithDelta(0.0, $governor::plannedSleepSeconds(1.0, 1.0, 0.0, 5.0), 1e-9);

    // 自计量必须真的能取到 CPU（getrusage(0) 可用性）。
    self::assertGreaterThanOrEqual(0.0, $governor->selfCpuSeconds());

    // 能力判定必须返回可读结论，不能空。
    $capability = $governor::capability();
    self::assertIsBool($capability['available']);
    self::assertArrayHasKey('reason', $capability);
}

private function assert_CronCpuGovernor_testDegradesWithoutStealingHostAlarm(): void
{
    $governor = new \Weline\Cron\Service\CronCpuGovernor();

    // enforce=false 必须原样执行、不装闹钟，并回报降级原因；但仍要计量（cpu/wall/budget）。
    $degraded = null;
    $ran = false;
    $result = $governor->run(
        ['count' => 1, 'percent' => 30, 'budget' => 0.3, 'enforce' => false],
        static function () use (&$ran): string {
            $ran = true;
            $x = 0.0;
            $until = microtime(true) + 0.35;
            while (microtime(true) < $until) {
                $x += sqrt($x + 1.0);
            }

            return 'ran';
        },
        static function (string $reason) use (&$degraded): void {
            $degraded = $reason;
        },
    );
    self::assertSame('ran', $result);
    self::assertTrue($ran);
    self::assertIsString($degraded);
    self::assertStringContainsString('限速', (string) $degraded);
    // 总开关关闭也必须仍可观测：不允许退回「完全不计量」的裸 return $work()。
    $measured = $governor->lastStats();
    self::assertSame(0, $measured['ticks']);
    self::assertEqualsWithDelta(0.0, $measured['slept'], 1e-9);
    self::assertArrayHasKey('cpu', $measured);
    self::assertArrayHasKey('wall', $measured);
    self::assertArrayHasKey('budget', $measured);
    self::assertGreaterThan(0.0, $measured['cpu']);
    self::assertEqualsWithDelta(0.3, $measured['budget'], 1e-9);
    self::assertStringNotContainsString('只计算额度不节流', (string) file_get_contents(
        \BP . 'app/code/Weline/Cron/extends/module/Weline_SystemConfig/Config/backend/cron.phtml',
    ));

    // 宿主已占用闹钟时不得抢用：预置 60s 闹钟，跑完必须仍是原状。
    $hostAlarm = 60;
    \pcntl_alarm($hostAlarm);
    $prevHandler = \pcntl_signal_get_handler(\SIGALRM);
    $degraded2 = null;
    $result2 = $governor->run(
        ['count' => 1, 'percent' => 30, 'budget' => 0.3, 'enforce' => true],
        static fn (): string => 'ok',
        static function (string $reason) use (&$degraded2): void {
            $degraded2 = $reason;
        },
    );
    self::assertSame('ok', $result2);
    self::assertIsString($degraded2);
    self::assertStringContainsString('闹钟', (string) $degraded2);
    self::assertSame($prevHandler, \pcntl_signal_get_handler(\SIGALRM));
    // 归还宿主闹钟：剩余时间必须仍在 (0, 60] 内。
    $remaining = \pcntl_alarm(0);
    self::assertGreaterThan(0, $remaining);
    self::assertLessThanOrEqual($hostAlarm, $remaining);

    // 预算非正必须降级而不是除零。
    $degraded3 = null;
    self::assertSame('zero', $governor->run(
        ['count' => 1, 'percent' => 0, 'budget' => 0.0, 'enforce' => true],
        static fn (): string => 'zero',
        static function (string $reason) use (&$degraded3): void {
            $degraded3 = $reason;
        },
    ));
    self::assertIsString($degraded3);
}

private function setUpBeforeClass_ManagedChildAdmission(): void
{
    parent::setUpBeforeClass();
    if (!\defined('BP')) {
        $dir = __DIR__;
        while ($dir !== \dirname($dir)) {
            if (\is_file($dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
                \define('BP', $dir . DIRECTORY_SEPARATOR);
                break;
            }
            $dir = \dirname($dir);
        }
    }
    self::assertTrue(\defined('BP'), 'BP must resolve to repository root');
}

private function assert_ManagedChildAdmission_testSoftAdmissionRemovedFromRunDispatcher(): void
{
    $path = \BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringNotContainsString('ManagedChildAdmission', $src);
    self::assertStringNotContainsString('hasAvailableSlot', $src);
    self::assertStringNotContainsString('releaseClaimedLaunchForAdmission', $src);
    self::assertStringNotContainsString('受管计划任务并发已达上限', $src);
    self::assertStringContainsString('claimTaskLaunch(', $src);
    self::assertStringContainsString('requiresManagedChildSerialization', $src);
    self::assertStringContainsString('no soft', $src);
    self::assertFileDoesNotExist(\BP . 'app/code/Weline/Cron/Service/ManagedChildAdmission.php');
    self::assertFileDoesNotExist(\BP . 'app/code/Weline/Cron/Service/HostCapacityProbe.php');
}
}
