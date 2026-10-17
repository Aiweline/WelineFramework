<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Setup\Upgrade;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nSetupContractsPass().
 */
final class I18nSetupContractsTest extends TestCase
{
    public function testAllI18nSetupContractsPass(): void
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
            'NullSourceCoveringIndexUpgrade::testUpgradeDeclaresPartialCoveringIndexForNullSourceGlobalWords' => function () {
            $this->assert_NullSourceCoveringIndexUpgrade_testUpgradeDeclaresPartialCoveringIndexForNullSourceGlobalWords();
        },
        ];
    }

private function assert_NullSourceCoveringIndexUpgrade_testUpgradeDeclaresPartialCoveringIndexForNullSourceGlobalWords(): void
{
    $source = \file_get_contents((string) (new \ReflectionClass(Upgrade::class))->getFileName());
    self::assertIsString($source);
    self::assertSame('idx_locale_null_source_covering', Upgrade::NULL_SOURCE_COVERING_INDEX);
    self::assertStringContainsString('CREATE INDEX IF NOT EXISTS', $source);
    self::assertStringContainsString('INCLUDE (word, translate)', $source);
    self::assertStringContainsString("source_module IS NULL OR source_module = ''", $source);
    self::assertStringContainsString('pgsql', $source);
}
}
