<?php

declare(strict_types=1);

namespace Weline\Taglib\Test\Unit\Console;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllTaglibConsoleContractsPass().
 */
final class TaglibConsoleContractsTest extends TestCase
{
    public function testAllTaglibConsoleContractsPass(): void
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
            'TaglibCollectPhpSuffix::testCollectStripsPhpSuffixWithoutRtrimCharacterMask' => function () {
            $this->assert_TaglibCollectPhpSuffix_testCollectStripsPhpSuffixWithoutRtrimCharacterMask();
        },
        ];
    }

private function assert_TaglibCollectPhpSuffix_testCollectStripsPhpSuffixWithoutRtrimCharacterMask(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Taglib' . '/Console/Taglib/Collect.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringNotContainsString("rtrim(\$tag, '.php')", $src);
    self::assertStringContainsString("str_ends_with(\$tag, '.php')", $src);
    self::assertStringContainsString('substr($tag, 0, -4)', $src);
}
}
