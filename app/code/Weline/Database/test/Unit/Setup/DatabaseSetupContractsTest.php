<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Database\Setup\Install;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Service\SetupScriptContractValidator;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDatabaseSetupContractsPass().
 */
final class DatabaseSetupContractsTest extends TestCase
{
    public function testAllDatabaseSetupContractsPass(): void
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
            'DatabaseInstall::testInstalledScriptPassesTheFrameworkPreflightContract' => function () {
            $this->assert_DatabaseInstall_testInstalledScriptPassesTheFrameworkPreflightContract();
        },
        ];
    }

private function assert_DatabaseInstall_testInstalledScriptPassesTheFrameworkPreflightContract(): void
{
    self::assertInstanceOf(InstallInterface::class, new Install());
    $module = new Module(['name' => 'Weline_Database', 'base_path' => BP . 'app/code/Weline/Database/', 'namespace_path' => 'Weline\Database']);
    (new SetupScriptContractValidator())->assertInstallContract($module);
    self::assertSame('Weline\Database', $module->getNamespacePath());
}
}
