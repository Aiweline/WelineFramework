<?php

declare(strict_types=1);

namespace Weline\Location\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllLocationViewContractsPass().
 */
final class LocationViewContractsTest extends TestCase
{
    public function testAllLocationViewContractsPass(): void
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
            'HeaderLocationSelectorSolo::testLocationHookIsTheOnlyHeaderLocationSelector' => function () {
            $this->assert_HeaderLocationSelectorSolo_testLocationHookIsTheOnlyHeaderLocationSelector();
        },
        ];
    }

private function assert_HeaderLocationSelectorSolo_testLocationHookIsTheOnlyHeaderLocationSelector(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Location';
    $locationHook = $moduleRoot . '/view/hooks/header-location-selector.phtml';
    $geoHook = dirname($moduleRoot) . '/Geo/view/hooks/header-location-selector.phtml';
    $source = (string) file_get_contents($locationHook);
    self::assertFileExists($locationHook);
    self::assertFileDoesNotExist($geoHook);
    self::assertMatchesRegularExpression('/@hook-solo\s+true/', $source);
    self::assertStringContainsString('querySelectorAll(\'[data-geo-location-selector]\')', $source);
    self::assertStringContainsString('event.stopPropagation()', $source);
    self::assertStringContainsString('data-action="select-location"', $source);
    self::assertStringContainsString('location-selector-dropdown', $source);
}
}
