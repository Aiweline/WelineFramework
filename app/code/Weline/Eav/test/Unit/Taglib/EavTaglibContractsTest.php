<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllEavTaglibContractsPass().
 */
final class EavTaglibContractsTest extends TestCase
{
    public function testAllEavTaglibContractsPass(): void
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
            'EavManagerTaglib::testEavManagerTaglibExposesEntityCodeAttribute' => function () {
            $this->assert_EavManagerTaglib_testEavManagerTaglibExposesEntityCodeAttribute();
        },
        ];
    }

private function assert_EavManagerTaglib_testEavManagerTaglibExposesEntityCodeAttribute(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Taglib/EavManager.php');
    self::assertStringContainsString("return 'eav:manager';", $source);
    self::assertStringContainsString("'entity-code'", $source);
    self::assertStringContainsString('templates/backend/Manager/surface.phtml', $source);
    self::assertStringContainsString('resolveEntityScope', $source);
    self::assertStringContainsString('tag_self_close_with_attrs', $source);
    self::assertStringContainsString('function document()', $source);
}
}
