<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Memory\MemoryPressureController;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerMemoryContractsPass().
 */
final class ServerMemoryContractsTest extends TestCase
{
    public function testAllServerMemoryContractsPass(): void
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
            'MemoryPressureServeFloor::testStartupExplicitCountAndEmergencyFlagDefaults' => function () {
            $this->assert_MemoryPressureServeFloor_testStartupExplicitCountAndEmergencyFlagDefaults();
        },
        ];
    }

private function assert_MemoryPressureServeFloor_testStartupExplicitCountAndEmergencyFlagDefaults(): void
{
    $controller = new MemoryPressureController();
    self::assertSame(0, $controller->getStartupExplicitCount());
    $controller->setStartupExplicitCount(2);
    self::assertSame(2, $controller->getStartupExplicitCount());
    // Default: must not break serve floor without explicit opt-in.
    self::assertFalse($controller->allowsEmergencyScaleBelowStartupExplicit());
}
}
