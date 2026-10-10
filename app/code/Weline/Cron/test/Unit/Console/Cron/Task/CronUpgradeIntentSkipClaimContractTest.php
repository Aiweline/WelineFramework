<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Console\Cron\Task;

use PHPUnit\Framework\TestCase;

final class CronUpgradeIntentSkipClaimContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
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

    public function testRunSkipsClaimWhenUpgradeIntentActive(): void
    {
        $path = \BP . 'app/code/Weline/Cron/Console/Cron/Task/Run.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
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
