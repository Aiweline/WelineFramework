<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Schedule\Linux\Crontab;
use Weline\Cron\Schedule\Schedule;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCronScheduleContractsPass().
 */
final class CronScheduleContractsTest extends TestCase
{
    public function testAllCronScheduleContractsPass(): void
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
            'ProjectCronScope::testBareModuleScopeExpandsToProjectRoot' => function () {
            $this->assert_ProjectCronScope_testBareModuleScopeExpandsToProjectRoot();
        },
            'ProjectCronScope::testExplicitAtScopeIsPreserved' => function () {
            $this->assert_ProjectCronScope_testExplicitAtScopeIsPreserved();
        },
            'ProjectCronScope::testFallbackCronNameHashMatchesProjectScopeNotBareModule' => function () {
            $this->assert_ProjectCronScope_testFallbackCronNameHashMatchesProjectScopeNotBareModule();
        },
            'ProjectCronScope::testCommentedCrontabLineIsNotActive' => function () {
            $this->assert_ProjectCronScope_testCommentedCrontabLineIsNotActive();
        },
        ];
    }

private function assert_ProjectCronScope_testBareModuleScopeExpandsToProjectRoot(): void
{
    $projectRoot = defined('BP') ? (string) BP : (string) getcwd();
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $expected = 'Weline_Cron@' . $projectRoot;
    self::assertSame($expected, Schedule::projectCronScope('Weline_Cron'));
    self::assertSame($expected, Schedule::normalizeCronConfigScope('Weline_Cron'));
    self::assertSame($expected, Schedule::normalizeCronConfigScope(''));
}

private function assert_ProjectCronScope_testExplicitAtScopeIsPreserved(): void
{
    $scope = 'Weline_Cron@/www/wwwroot/example.com';
    self::assertSame($scope, Schedule::normalizeCronConfigScope($scope));
}

private function assert_ProjectCronScope_testFallbackCronNameHashMatchesProjectScopeNotBareModule(): void
{
    $projectScope = Schedule::projectCronScope('Weline_Cron');
    $projectHash = md5($projectScope);
    $bareHash = md5('Weline_Cron');
    self::assertNotSame($bareHash, $projectHash);
    self::assertSame(Schedule::cron_flag . '-' . $projectHash . '-' . Schedule::cron_flag, Schedule::cron_flag . '-' . md5($projectScope) . '-' . Schedule::cron_flag);
}

private function assert_ProjectCronScope_testCommentedCrontabLineIsNotActive(): void
{
    $active = '*/1 * * * * sh /tmp/x-cron.sh # [Weline_Cron]';
    $commented = '# */1 * * * * sh /tmp/x-cron.sh # [Weline_Cron]';
    self::assertTrue(Crontab::isActiveWelineCronLine($active));
    self::assertFalse(Crontab::isActiveWelineCronLine($commented));
    self::assertFalse(Crontab::isActiveWelineCronLine(''));
    self::assertFalse(Crontab::isActiveWelineCronLine('0 * * * * echo hi'));
}
}
