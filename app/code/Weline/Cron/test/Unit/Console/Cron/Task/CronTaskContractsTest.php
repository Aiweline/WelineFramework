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
}
