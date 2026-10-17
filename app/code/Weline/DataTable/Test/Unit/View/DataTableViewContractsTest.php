<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDataTableViewContractsPass().
 */
final class DataTableViewContractsTest extends TestCase
{
    public function testAllDataTableViewContractsPass(): void
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
            'DataTableRowActionEvent::testRowEventActionsAcceptActionKeyFromRowActionsJson' => function () {
            $this->assert_DataTableRowActionEvent_testRowEventActionsAcceptActionKeyFromRowActionsJson();
        },
        ];
    }

private function assert_DataTableRowActionEvent_testRowEventActionsAcceptActionKeyFromRowActionsJson(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/DataTable' . '/view/statics/js/datatable-manager.js');
    self::assertStringContainsString('action.event || action.action || action.name', $source);
}
}
