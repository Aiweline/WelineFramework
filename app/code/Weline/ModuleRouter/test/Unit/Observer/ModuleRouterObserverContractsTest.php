<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\ModuleRouter\Observer\ProcessUrlBefore;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllModuleRouterObserverContractsPass().
 */
final class ModuleRouterObserverContractsTest extends TestCase
{
    public function testAllModuleRouterObserverContractsPass(): void
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
            'ProcessUrlBeforeSearchBypass::testStorefrontSearchIsNotBypassedSoSearchRouterCanOwnIt' => function () {
            $this->assert_ProcessUrlBeforeSearchBypass_testStorefrontSearchIsNotBypassedSoSearchRouterCanOwnIt();
        },
        ];
    }

private function assert_ProcessUrlBeforeSearchBypass_testStorefrontSearchIsNotBypassedSoSearchRouterCanOwnIt(): void
{
    $method = new ReflectionMethod(ProcessUrlBefore::class, 'shouldBypassNormalizedPath');
    self::assertFalse($method->invoke(null, 'search'));
    self::assertFalse($method->invoke(null, 'search/frontend'));
    self::assertFalse($method->invoke(null, 'wishlist'));
    self::assertFalse($method->invoke(null, 'compare'));
    self::assertTrue($method->invoke(null, 'cart'));
    self::assertTrue($method->invoke(null, 'static/theme.css'));
}
}
