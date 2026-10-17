<?php

declare(strict_types=1);

namespace Weline\WarmCache\Test\Unit\Console;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWarmCacheConsoleContractsPass().
 */
final class WarmCacheConsoleContractsTest extends TestCase
{
    public function testAllWarmCacheConsoleContractsPass(): void
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
            'CacheWarmCommandRegistry::testPoolAndListOptionsUseTheCentralWarmerRegistry' => function () {
            $this->assert_CacheWarmCommandRegistry_testPoolAndListOptionsUseTheCentralWarmerRegistry();
        },
            'CacheWarmCommandRegistry::testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn' => function () {
            $this->assert_CacheWarmCommandRegistry_testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn();
        },
        ];
    }

private const COMMAND_CacheWarmCommandRegistry = __DIR__ . '/../../../Console/Cache/Warm.php';

private function assert_CacheWarmCommandRegistry_testPoolAndListOptionsUseTheCentralWarmerRegistry(): void
{
    $source = file_get_contents(self::COMMAND_CacheWarmCommandRegistry);
    self::assertIsString($source);
    self::assertStringContainsString('CacheWarmerRegistry', $source);
    self::assertStringContainsString('warmUp($pool', $source);
    self::assertStringContainsString("isset(\$data['pool'])", $source);
    self::assertStringContainsString("\$args['pool']", $source);
    self::assertStringContainsString("isset(\$data['list'])", $source);
}

private function assert_CacheWarmCommandRegistry_testDoesNotSoftPullThemeStorefrontFpcWarmerFqcn(): void
{
    $source = file_get_contents(self::COMMAND_CacheWarmCommandRegistry);
    self::assertIsString($source);
    self::assertStringNotContainsString('StorefrontFpcWarmer', $source);
    self::assertStringContainsString('CacheWarmerRegistry', $source);
}
}
