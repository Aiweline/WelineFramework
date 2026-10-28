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
}
