<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnUnitContractsPass().
 */
final class CdnUnitContractsTest extends TestCase
{
    public function testAllCdnUnitContractsPass(): void
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
            'EventDescriptionNoMultilineTranslate::testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls' => function () {
            $this->assert_EventDescriptionNoMultilineTranslate_testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls();
        },
        ];
    }

private function assert_EventDescriptionNoMultilineTranslate_testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/event.php');
    self::assertDoesNotMatchRegularExpression("/__\\([^)]*\\n/s", $src);
    self::assertStringContainsString('CDN 统一操作请求', $src);
    self::assertStringContainsString('doc', $src);
}
}
