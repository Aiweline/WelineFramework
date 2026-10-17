<?php

declare(strict_types=1);

namespace Weline\Api\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllApiSetupContractsPass().
 */
final class ApiSetupContractsTest extends TestCase
{
    public function testAllApiSetupContractsPass(): void
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
            'ApiInstallWhitelistFactory::testInstallUsesInterfaceNotDoubleFactoryCreate' => function () {
            $this->assert_ApiInstallWhitelistFactory_testInstallUsesInterfaceNotDoubleFactoryCreate();
        },
        ];
    }

private function assert_ApiInstallWhitelistFactory_testInstallUsesInterfaceNotDoubleFactoryCreate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Api' . '/Setup/Install.php');
    self::assertStringContainsString('WhitelistServiceInterface::class', $src);
    self::assertStringNotContainsString('WhitelistServiceInterfaceFactory::class)->create()', $src);
    self::assertStringNotContainsString('WhitelistServiceInterfaceFactory::class)->create(', $src);
}
}
