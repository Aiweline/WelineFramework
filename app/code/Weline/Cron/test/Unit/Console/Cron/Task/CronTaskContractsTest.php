<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Console\Cron\Task;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Console\Cron\Task\Run;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCronTaskContractsPass().
 */
final class CronTaskContractsTest extends TestCase
{
    public function testAllCronTaskContractsPass(): void
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
            'CronTaskRunWarmup::testManagedChildWarmsConfigAndNamespaceBeforeExecute' => function () {
            $this->assert_CronTaskRunWarmup_testManagedChildWarmsConfigAndNamespaceBeforeExecute();
        },
            'CronUpgradeIntentSkipClaim::testRunSkipsClaimWhenUpgradeIntentActive' => function () {
            $this->setUpBeforeClass_CronUpgradeIntentSkipClaim();
            $this->assert_CronUpgradeIntentSkipClaim_testRunSkipsClaimWhenUpgradeIntentActive();
        },
            'CronTaskPreflight::testPreflightRunsBeforeClaimAndFailsOpen' => function () {
            $this->setUpBeforeClass_CronUpgradeIntentSkipClaim();
            $this->assert_CronTaskPreflight_testPreflightRunsBeforeClaimAndFailsOpen();
        },
            'CronChildReap::testDispatchLoopReapsExitedChildren' => function () {
            $this->setUpBeforeClass_CronUpgradeIntentSkipClaim();
            $this->assert_CronChildReap_testDispatchLoopReapsExitedChildren();
        },
            'CronCpuBudget::testTaskCpuColumnsPersistedByCollect' => function () {
            $this->assert_CronCpuBudget_testTaskCpuColumnsPersistedByCollect();
        },
            'CronCpuBudget::testChildExecutionRunsWithinGovernor' => function () {
            $this->assert_CronCpuBudget_testChildExecutionRunsWithinGovernor();
        },
            'CronCpuBudget::testCronExpressionIsMemoizedPerExpression' => function () {
            $this->assert_CronCpuBudget_testCronExpressionIsMemoizedPerExpression();
        },
        ];
    }

private function assert_CronTaskRunWarmup_testManagedChildWarmsConfigAndNamespaceBeforeExecute(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cron' . '/Console/Cron/Task/Run.php');
    self::assertStringContainsString('warmupBackgroundProcessCaches($task)', $src);
    self::assertStringContainsString('function warmupBackgroundProcessCaches', $src);
    self::assertStringContainsString('warmupModuleMaps($modules)', $src);
    self::assertStringContainsString('prefetchProcessVector(', $src);
    self::assertTrue(class_exists(Run::class));
}

private function setUpBeforeClass_CronUpgradeIntentSkipClaim(): void
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

private function assert_CronUpgradeIntentSkipClaim_testRunSkipsClaimWhenUpgradeIntentActive(): void
{
    $path = \BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('SetupUpgradeIntent', $src);
    self::assertStringContainsString('SetupUpgradeIntent::isActive()', $src);
    self::assertStringContainsString('系统升级意图活跃，本次跳过派发计划任务且未启动子进程。', $src);
    // Must guard before claimTaskLaunch (including force path).
    $activePos = strpos($src, 'SetupUpgradeIntent::isActive()');
    $claimPos = strpos($src, 'claimTaskLaunch(');
    self::assertNotFalse($activePos);
    self::assertNotFalse($claimPos);
    self::assertLessThan($claimPos, $activePos);
}

private function assert_CronTaskPreflight_testPreflightRunsBeforeClaimAndFailsOpen(): void
{
    $ifacePath = \BP . 'app/code/Weline/Cron/CronTaskPreflightInterface.php';
    self::assertFileExists($ifacePath);
    $ifaceSrc = (string) file_get_contents($ifacePath);
    self::assertStringContainsString('interface CronTaskPreflightInterface', $ifaceSrc);
    self::assertStringContainsString('public function hasPendingWork(): bool;', $ifaceSrc);

    $src = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php');
    self::assertStringContainsString('CronTaskPreflightInterface', $src);
    self::assertStringContainsString('function preflightSkipNote', $src);
    self::assertStringContainsString("getConfig('cron.preflight'", $src);

    // 必须在 claimTaskLaunch 之前判定，否则「无活」任务仍会先写一次调度记录。
    $preflightPos = strpos($src, '$this->preflightSkipNote(');
    $claimPos = strpos($src, '$this->claimTaskLaunch(');
    self::assertNotFalse($preflightPos);
    self::assertNotFalse($claimPos);
    self::assertLessThan($claimPos, $preflightPos);

    // fail-open：前置检查自身异常时必须按「有活」处理，不得漏跑任务。
    $start = strpos($src, 'private function preflightSkipNote(');
    $end = strpos($src, 'private function isPreflightEnabled(');
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $methodSrc = substr($src, $start, $end - $start);
    self::assertStringContainsString('$instance->hasPendingWork()', $methodSrc);
    self::assertStringContainsString('catch (\Throwable)', $methodSrc);
    self::assertStringContainsString('return null;', $methodSrc);
    self::assertStringNotContainsString('return false;', $methodSrc);
}

private function assert_CronChildReap_testDispatchLoopReapsExitedChildren(): void
{
    $src = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php');
    self::assertStringContainsString('function reapExitedChildren', $src);
    self::assertStringContainsString('pcntl_waitpid(-1, $status, WNOHANG)', $src);

    // 必须在派发循环内回收，否则父进程运行期间僵尸一直堆到退出。
    $loopPos = strpos($src, 'foreach ($tasks as $key => $taskModel) {');
    $reapPos = strpos($src, '$this->reapExitedChildren();');
    self::assertNotFalse($loopPos);
    self::assertNotFalse($reapPos);
    self::assertGreaterThan($loopPos, $reapPos);
}

private function assert_CronCpuBudget_testTaskCpuColumnsPersistedByCollect(): void
{
    $modelSrc = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Model/CronTask.php');
    self::assertStringContainsString("schema_fields_CPU_COUNT = 'cpu_count'", $modelSrc);
    self::assertStringContainsString("schema_fields_CPU_PERCENT = 'cpu_percent'", $modelSrc);
    // 列默认值必须是 0 = 未声明：接口常量 1/30 只是最终兜底，落成行内默认值会让统一配置失效。
    self::assertStringContainsString("#[Col('int', default: 0, comment: 'CPU 个数（0=跟随统一配置）')]", $modelSrc);
    self::assertStringContainsString("#[Col('int', default: 0, comment: 'CPU 上限百分比（0=跟随统一配置）')]", $modelSrc);

    $collectSrc = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Console/Cron/Task/Collect.php');
    self::assertStringContainsString('CronTask::schema_fields_CPU_COUNT => $this->resolveCpuCount($taskObject)', $collectSrc);
    self::assertStringContainsString('CronTask::schema_fields_CPU_PERCENT => $this->resolveCpuPercent($taskObject)', $collectSrc);
    // 未实现能力接口的老任务必须写 0（未声明），不能写接口常量把配置默认值钉死。
    self::assertStringContainsString('$taskObject instanceof CronTaskCpuBudgetInterface', $collectSrc);
    self::assertMatchesRegularExpression(
        '/private function resolveCpuCount\(object \$taskObject\): int\s*\{[^}]*?return 0;/s',
        $collectSrc,
        'resolveCpuCount 必须在未实现接口时返回 0',
    );
    self::assertMatchesRegularExpression(
        '/private function resolveCpuPercent\(object \$taskObject\): int\s*\{[^}]*?return 0;/s',
        $collectSrc,
        'resolveCpuPercent 必须在未实现接口时返回 0',
    );
}

private function assert_CronCpuBudget_testChildExecutionRunsWithinGovernor(): void
{
    $src = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php');
    self::assertStringContainsString('function executeWithinCpuBudget', $src);
    self::assertStringContainsString('$this->executeWithinCpuBudget($task, $instance, $sseManual)', $src);
    // 业务体必须经治理器执行，禁止继续裸调。
    self::assertStringContainsString('$governor->run($budget', $src);
    self::assertStringContainsString('CronCpuBudgetPolicy::class', $src);
    self::assertStringContainsString('CronCpuGovernor::class', $src);
    self::assertStringContainsString('CronTask::schema_fields_CPU_COUNT', $src);
    self::assertStringContainsString('CronTask::schema_fields_CPU_PERCENT', $src);

    $governorSrc = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Service/CronCpuGovernor.php');
    // 计量口径只允许本进程；\getrusage(1) 会掺子进程 CPU，必须不出现实际调用。
    self::assertStringContainsString('\getrusage(0)', $governorSrc);
    self::assertStringNotContainsString('\getrusage(1)', $governorSrc);
    self::assertStringContainsString("'ru_utime.tv_sec'", $governorSrc);
    self::assertStringContainsString("'ru_stime.tv_sec'", $governorSrc);
    // 累计式公式 + 动态单次睡眠上限。
    self::assertStringContainsString('$used / $budget - $wall', $governorSrc);
    self::assertStringContainsString('MIN_SLEEP_CAP_SECONDS', $governorSrc);
    self::assertStringContainsString('2.0 / $budget', $governorSrc);
    // 闹钟必须先查占用、用完归还，且不得与宿主抢。
    self::assertStringContainsString('pcntl_alarm(0)', $governorSrc);
    self::assertStringContainsString('pcntl_signal_get_handler', $governorSrc);
    self::assertStringContainsString('pcntl_alarm(self::TICK_SECONDS)', $governorSrc);
    self::assertStringContainsString('finally {', $governorSrc);
}

private function assert_CronCpuBudget_testCronExpressionIsMemoizedPerExpression(): void
{
    $src = (string) file_get_contents(\BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php');
    self::assertStringContainsString('function resolveCronExpression', $src);
    self::assertStringContainsString('$this->resolveCronExpression(', $src);
    // 无业务时不重复解析表达式：每条任务不再各自 new CronExpression。
    self::assertStringNotContainsString('new CronExpression($taskModel->getData(\'cron_time\'))', $src);
    self::assertStringContainsString('cronExpressionCache', $src);
}
}
