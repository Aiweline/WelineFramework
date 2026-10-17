<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\DateTime;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDateTimeContractsPass().
 */
final class DateTimeContractsTest extends TestCase
{
    public function testAllDateTimeContractsPass(): void
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
            'ScheduleWindowUtcMigrator::testMigratorLoadsContributionProvidesNotHardcodedForeignModels' => function () {
            $this->assert_ScheduleWindowUtcMigrator_testMigratorLoadsContributionProvidesNotHardcodedForeignModels();
        },
            'ScheduleWindowUtcMigrator::testEnvResolvesDefaultThemeConfigProviderInterface' => function () {
            $this->assert_ScheduleWindowUtcMigrator_testEnvResolvesDefaultThemeConfigProviderInterface();
        },
        ];
    }

private function assert_ScheduleWindowUtcMigrator_testMigratorLoadsContributionProvidesNotHardcodedForeignModels(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/DateTime/ScheduleWindowUtcMigrator.php');
    self::assertStringContainsString('ScheduleWindowUtcMigrationContributionInterface', $src);
    self::assertStringContainsString('implementationsWithPrefix', $src);
    self::assertStringNotContainsString('Marketing\Model\Campaign\Campaign', $src);
    self::assertStringNotContainsString('Theme\Model\ThemeLayoutSchedule', $src);
    self::assertStringNotContainsString('Promotion\Model\PromotionActivityTheme', $src);
}

private function assert_ScheduleWindowUtcMigrator_testEnvResolvesDefaultThemeConfigProviderInterface(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/App/Env.php');
    self::assertStringContainsString('DefaultThemeConfigProviderInterface', $src);
    self::assertStringNotContainsString('Theme\Api\DefaultThemeInterface', $src);
}
}
