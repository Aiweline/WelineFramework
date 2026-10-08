<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Schedule\Linux\Crontab;
use Weline\Cron\Schedule\Schedule;

/**
 * 后台 getInstallationStatus 必须与 CLI cron:install 共用项目级 scope，
 * 否则会用裸 md5(Weline_Cron) 误报「cron未安装」。
 */
final class ProjectCronScopeContractTest extends TestCase
{
    public function testBareModuleScopeExpandsToProjectRoot(): void
    {
        $projectRoot = defined('BP') ? (string)BP : (string)getcwd();
        $projectRoot = realpath($projectRoot) ?: $projectRoot;
        $expected = 'Weline_Cron@' . $projectRoot;

        self::assertSame($expected, Schedule::projectCronScope('Weline_Cron'));
        self::assertSame($expected, Schedule::normalizeCronConfigScope('Weline_Cron'));
        self::assertSame($expected, Schedule::normalizeCronConfigScope(''));
    }

    public function testExplicitAtScopeIsPreserved(): void
    {
        $scope = 'Weline_Cron@/www/wwwroot/example.com';
        self::assertSame($scope, Schedule::normalizeCronConfigScope($scope));
    }

    public function testFallbackCronNameHashMatchesProjectScopeNotBareModule(): void
    {
        $projectScope = Schedule::projectCronScope('Weline_Cron');
        $projectHash = md5($projectScope);
        $bareHash = md5('Weline_Cron');

        self::assertNotSame($bareHash, $projectHash);
        self::assertSame(
            Schedule::cron_flag . '-' . $projectHash . '-' . Schedule::cron_flag,
            Schedule::cron_flag . '-' . md5($projectScope) . '-' . Schedule::cron_flag
        );
    }

    public function testCommentedCrontabLineIsNotActive(): void
    {
        $active = '*/1 * * * * sh /tmp/x-cron.sh # [Weline_Cron]';
        $commented = '# */1 * * * * sh /tmp/x-cron.sh # [Weline_Cron]';

        self::assertTrue(Crontab::isActiveWelineCronLine($active));
        self::assertFalse(Crontab::isActiveWelineCronLine($commented));
        self::assertFalse(Crontab::isActiveWelineCronLine(''));
        self::assertFalse(Crontab::isActiveWelineCronLine('0 * * * * echo hi'));
    }
}
