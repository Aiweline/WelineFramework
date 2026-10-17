<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Query;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCompareQueryContractsPass().
 */
final class CompareQueryContractsTest extends TestCase
{
    public function testAllCompareQueryContractsPass(): void
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
            'CompareQueryProviderPageView::testQueryProviderExposesPageViewOperation' => function () {
            $this->assert_CompareQueryProviderPageView_testQueryProviderExposesPageViewOperation();
        },
        ];
    }

private function assert_CompareQueryProviderPageView_testQueryProviderExposesPageViewOperation(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Compare' . '/extends/module/Weline_Framework/Query/CompareQueryProvider.php');
    self::assertStringContainsString("'pageView'", $source);
    self::assertStringContainsString('pageViewPayload', $source);
    self::assertStringContainsString('ComparePagePresenter', $source);
}
}
