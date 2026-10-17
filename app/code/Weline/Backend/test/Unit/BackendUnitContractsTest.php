<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendUnitContractsPass().
 */
final class BackendUnitContractsTest extends TestCase
{
    public function testAllBackendUnitContractsPass(): void
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
            'BackendUserContextProviderWarmupIsolation::testWarmupIdentityIsGuardedByTheCurrentRequestMarker' => function () {
            $this->assert_BackendUserContextProviderWarmupIsolation_testWarmupIdentityIsGuardedByTheCurrentRequestMarker();
        },
        ];
    }

private function assert_BackendUserContextProviderWarmupIsolation_testWarmupIdentityIsGuardedByTheCurrentRequestMarker(): void
{
    $source = (string) \file_get_contents(BP . '/app/code/Weline/Backend/Service/BackendUserContextProvider.php');
    $guard = \strpos($source, 'BackendWarmupContext::isInternalWarmupRequest($request)');
    $warmupRead = \strpos($source, 'BackendWarmupContext::currentUser()');
    $sessionRead = \strpos($source, 'createBackendSession()->getUser()');
    self::assertIsInt($guard);
    self::assertIsInt($warmupRead);
    self::assertIsInt($sessionRead);
    self::assertLessThan($warmupRead, $guard);
    self::assertLessThan($sessionRead, $warmupRead);
}
}
