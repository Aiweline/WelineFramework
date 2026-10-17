<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMaintenanceHelperContractsPass().
 */
final class MaintenanceHelperContractsTest extends TestCase
{
    public function testAllMaintenanceHelperContractsPass(): void
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
            'WlsMaintenanceSync::testCliToggleUsesFullDeploymentMaintenanceControl' => function () {
            $this->assert_WlsMaintenanceSync_testCliToggleUsesFullDeploymentMaintenanceControl();
        },
        ];
    }

private function assert_WlsMaintenanceSync_testCliToggleUsesFullDeploymentMaintenanceControl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Helper/WlsMaintenanceSync.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('RuntimeDeploymentControlInterface', $src, 'CLI maintenance sync must resolve RuntimeDeploymentControlInterface');
    self::assertStringContainsString('setMaintenanceMode(', $src, 'CLI maintenance sync must call full setMaintenanceMode');
    self::assertStringNotContainsString('setMaintenanceRoutingOnly(', $src, 'CLI must not use dispatcher_only routing sync on Direct topologies');
    self::assertStringNotContainsString('MaintenanceRoutingBroadcasterInterface', $src, 'CLI sync must not depend on routing-only broadcaster');
}
}
