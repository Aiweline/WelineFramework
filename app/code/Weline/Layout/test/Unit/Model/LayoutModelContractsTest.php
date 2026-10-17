<?php

declare(strict_types=1);

namespace Weline\Layout\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllLayoutModelContractsPass().
 */
final class LayoutModelContractsTest extends TestCase
{
    public function testAllLayoutModelContractsPass(): void
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
            'LayoutScheduleExpiredQuery::testExpiredActiveSchedulesUsesIsNotNullNotEmptyStringBind' => function () {
            $this->assert_LayoutScheduleExpiredQuery_testExpiredActiveSchedulesUsesIsNotNullNotEmptyStringBind();
        },
        ];
    }

private function assert_LayoutScheduleExpiredQuery_testExpiredActiveSchedulesUsesIsNotNullNotEmptyStringBind(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Layout' . '/Model/LayoutSchedule.php');
    self::assertStringContainsString("function getExpiredActiveSchedules()", $source);
    self::assertStringContainsString("IS NOT NULL", $source);
    self::assertStringNotContainsString("schema_fields_END_TIME, '', '!='", $source);
    self::assertStringNotContainsString("END_TIME, '', '!='", $source);
}
}
