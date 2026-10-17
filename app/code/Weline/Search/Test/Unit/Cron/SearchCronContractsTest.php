<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Search\Cron\ProviderIndexRebuild;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSearchCronContractsPass().
 */
final class SearchCronContractsTest extends TestCase
{
    public function testAllSearchCronContractsPass(): void
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
            'ProviderIndexRebuildCron::testCronImplementsInterfaceAndSchedule' => function () {
            $this->assert_ProviderIndexRebuildCron_testCronImplementsInterfaceAndSchedule();
        },
        ];
    }

private function assert_ProviderIndexRebuildCron_testCronImplementsInterfaceAndSchedule(): void
{
    $cron = new ProviderIndexRebuild();
    self::assertInstanceOf(CronTaskInterface::class, $cron);
    self::assertSame('search_provider_index_rebuild', $cron->execute_name());
    self::assertSame('*/15 * * * *', $cron->cron_time());
    self::assertGreaterThanOrEqual(60, $cron->unlock_timeout());
}
}
