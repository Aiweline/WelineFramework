<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendModelContractsPass().
 */
final class BackendModelContractsTest extends TestCase
{
    public function testAllBackendModelContractsPass(): void
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
            'BackendUserConfigDeviceFailClosed::testSessIdFallbackIsRestrictedToAnAbsentDeviceRegistry' => function () {
            $this->assert_BackendUserConfigDeviceFailClosed_testSessIdFallbackIsRestrictedToAnAbsentDeviceRegistry();
        },
        ];
    }

private function assert_BackendUserConfigDeviceFailClosed_testSessIdFallbackIsRestrictedToAnAbsentDeviceRegistry(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Model/BackendUserConfig.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('resolveDetailed(AuthenticatedDeviceRegistryInterface::class)', $source);
    self::assertStringContainsString('RuntimeProviderResolution::NOT_CONFIGURED', $source);
    $failClosedGuard = strpos($source, 'if (!$this->legacySessionLookupAllowed())');
    $legacyQuery = strpos($source, 'BackendUser::schema_fields_sess_id');
    self::assertNotFalse($failClosedGuard);
    self::assertNotFalse($legacyQuery);
    self::assertLessThan($legacyQuery, $failClosedGuard);
}
}
