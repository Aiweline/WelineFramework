<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllServerObserverContractsPass().
 */
final class ServerObserverContractsTest extends TestCase
{
    public function testAllServerObserverContractsPass(): void
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
            'ApplyOriginNoStoreWlsFpcBypass::testServerListensSharedCacheForbiddenAndMarksWlsFpcBypass' => function () {
            $this->assert_ApplyOriginNoStoreWlsFpcBypass_testServerListensSharedCacheForbiddenAndMarksWlsFpcBypass();
        },
            'CliCommandExecutedObserverSetupMaintenanceHeal::testSetupReloadReconcilesMaintenanceOff' => function () {
            $this->assert_CliCommandExecutedObserverSetupMaintenanceHeal_testSetupReloadReconcilesMaintenanceOff();
        },
        ];
    }

private function assert_ApplyOriginNoStoreWlsFpcBypass_testServerListensSharedCacheForbiddenAndMarksWlsFpcBypass(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $eventXml = (string) file_get_contents($root . '/etc/event.xml');
    $observer = (string) file_get_contents($root . '/Observer/ApplyOriginNoStoreWlsFpcBypass.php');
    self::assertStringContainsString('Weline_Framework::response::shared_cache_forbidden', $eventXml);
    self::assertStringContainsString('ApplyOriginNoStoreWlsFpcBypass', $eventXml);
    self::assertStringContainsString('STATUS_BYPASS', $observer);
    self::assertStringContainsString("setHeader('X-Weline-FPC', 'BYPASS')", $observer);
    self::assertStringContainsString("setHeader('X-Wls-Fpc-Status', 'BYPASS')", $observer);
    self::assertStringContainsString('X-Weline-Shared-Cache-Forbidden', $observer);
    self::assertStringContainsString('\bno-store\b', $observer);
}

private function assert_CliCommandExecutedObserverSetupMaintenanceHeal_testSetupReloadReconcilesMaintenanceOff(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Observer/CliCommandExecutedObserver.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('reconcileMaintenanceOffAfterSetupReload', $src);
    self::assertStringContainsString("str_starts_with(\$command, 'setup:')", $src);
    self::assertStringContainsString('setMaintenanceMode(false)', $src);
    self::assertStringContainsString('WLS 维护门禁已在代码重载后再次确认关闭', $src);
}
}
