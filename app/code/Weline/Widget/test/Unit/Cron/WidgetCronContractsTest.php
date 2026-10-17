<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetCronContractsPass().
 */
final class WidgetCronContractsTest extends TestCase
{
    public function testAllWidgetCronContractsPass(): void
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
            'WidgetRegistryRefreshCron::testCronReusesWidgetRegistryRefreshService' => function () {
            $this->assert_WidgetRegistryRefreshCron_testCronReusesWidgetRegistryRefreshService();
        },
            'WidgetRegistryRefreshMemory::testCronRaisesMemoryCeilingForSolidifyCompile' => function () {
            $this->assert_WidgetRegistryRefreshMemory_testCronRaisesMemoryCeilingForSolidifyCompile();
        },
        ];
    }

private function assert_WidgetRegistryRefreshCron_testCronReusesWidgetRegistryRefreshService(): void
{
    $cron = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Cron/WidgetRegistryRefresh.php');
    self::assertStringContainsString('WidgetRegistryRefreshService', $cron);
    self::assertStringContainsString('refreshService->refresh', $cron);
    self::assertStringContainsString("'cron_widget_registry_refresh'", $cron);
    self::assertStringContainsString("execute_name(): string", $cron);
    self::assertStringContainsString("'widget_registry_refresh'", $cron);
    self::assertStringContainsString("'20 * * * *'", $cron);
    self::assertStringContainsString('unlock_timeout', $cron);
    self::assertStringNotContainsString('WidgetScanner', $cron);
    self::assertStringNotContainsString("__('", $cron);
    $refresh = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Service/WidgetRegistryRefreshService.php');
    self::assertStringContainsString('refreshWithReport', $refresh);
    self::assertStringContainsString('Weline_Widget::widget_install_after', $refresh);
}

private function assert_WidgetRegistryRefreshMemory_testCronRaisesMemoryCeilingForSolidifyCompile(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Cron/WidgetRegistryRefresh.php');
    self::assertStringContainsString("ini_set('memory_limit', '512M')", $source);
    self::assertStringContainsString('finally', $source);
}
}
