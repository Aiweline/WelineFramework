<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Server;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerServerContractsPass().
 */
final class ServerServerContractsTest extends TestCase
{
    public function testAllServerServerContractsPass(): void
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
            'ServerReloadRefreshesManagedNginx::testWaitModeRefreshManagedNginxAfterWorkerReload' => function () {
            $this->assert_ServerReloadRefreshesManagedNginx_testWaitModeRefreshManagedNginxAfterWorkerReload();
        },
            'ServerReloadRefreshesManagedNginx::testManagedNginxServiceExposesLightweightRunningSnapshot' => function () {
            $this->assert_ServerReloadRefreshesManagedNginx_testManagedNginxServiceExposesLightweightRunningSnapshot();
        },
        ];
    }

private function assert_ServerReloadRefreshesManagedNginx_testWaitModeRefreshManagedNginxAfterWorkerReload(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Console/Server/Reload.php');
    self::assertStringContainsString('refreshManagedNginxAfterWorkerReload', $source);
    self::assertStringContainsString('ManagedNginxService::fromEnv()', $source);
    self::assertStringContainsString('server:nginx:reload', $source);
    self::assertStringContainsString('runtimeRunningSnapshot', $source);
    self::assertStringContainsString("['light_verification' => true]", $source);
    self::assertStringContainsString('adviseSharedSidecarWireRotationIfNeeded', $source);
    self::assertStringNotContainsString('->doctorSnapshot(', $source);
    $executePos = \strpos($source, 'function execute(');
    $refreshPos = \strpos($source, 'refreshManagedNginxAfterWorkerReload');
    self::assertNotFalse($executePos);
    self::assertNotFalse($refreshPos);
    self::assertGreaterThan($executePos, $refreshPos);
    self::assertStringContainsString('if ($exitCode === 0) {' . "\n" . '                $this->refreshManagedNginxAfterWorkerReload();' . "\n" . '                $this->adviseSharedSidecarWireRotationIfNeeded();', $source);
}

private function assert_ServerReloadRefreshesManagedNginx_testManagedNginxServiceExposesLightweightRunningSnapshot(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Nginx/ManagedNginxService.php');
    self::assertStringContainsString('function runtimeRunningSnapshot', $source);
    self::assertStringContainsString('light_verification', $source);
}
}
