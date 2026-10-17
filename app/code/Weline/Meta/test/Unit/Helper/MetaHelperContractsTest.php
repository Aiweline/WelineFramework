<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMetaHelperContractsPass().
 */
final class MetaHelperContractsTest extends TestCase
{
    public function testAllMetaHelperContractsPass(): void
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
            'MetaTranslationNaturalKey::testLoadAndMutatePathsUseNaturalKeyHelper' => function () {
            $this->assert_MetaTranslationNaturalKey_testLoadAndMutatePathsUseNaturalKeyHelper();
        },
        ];
    }

private function assert_MetaTranslationNaturalKey_testLoadAndMutatePathsUseNaturalKeyHelper(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Helper/MetaTranslation.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('whereNaturalKey(', $src);
    self::assertStringContainsString('function loadRow(', $src);
    self::assertMatchesRegularExpression('/private static function loadRow\(\s*string \$metaIdentify/s', $src);
    self::assertStringNotContainsString('loadRow($metaId,', $src);
}
}
