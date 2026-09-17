<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Stage;

use PHPUnit\Framework\TestCase;

final class ModuleSetupStageProgressContractTest extends TestCase
{
    public function testCommitPrintsPerModuleProgress(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Setup/Stage/ModuleSetupStage.php'
        );

        self::assertStringContainsString('ModuleSetup [%{i}/%{total}] Install %{module}', $src);
        self::assertStringContainsString('ModuleSetup [%{i}/%{total}] Upgrade %{module}', $src);
        self::assertStringContainsString('模块安装/升级阶段开始', $src);
        self::assertStringContainsString('flushCli', $src);
    }

    public function testUpgradePrintsBridgeAfterFileMigrations(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Setup/Console/Setup/Upgrade.php'
        );

        self::assertStringContainsString('模块文件迁移检查结束，进入 ModuleSetup', $src);
        self::assertStringContainsString('待执行任务 %{2} 个', $src);
    }
}
