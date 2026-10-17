<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoQueryContractsPass().
 */
final class SeoQueryContractsTest extends TestCase
{
    public function testAllSeoQueryContractsPass(): void
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
            'SeoQueryProviderSiteEventHeat::testSiteEventHeatExposesVisitorAvailabilityAndGscFallback' => function () {
            $this->assert_SeoQueryProviderSiteEventHeat_testSiteEventHeatExposesVisitorAvailabilityAndGscFallback();
        },
        ];
    }

private function assert_SeoQueryProviderSiteEventHeat_testSiteEventHeatExposesVisitorAvailabilityAndGscFallback(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/extends/module/Weline_Framework/Query/SeoQueryProvider.php');
    self::assertStringContainsString("'source' => 'visitor'", $source);
    self::assertStringContainsString("'source' => 'search_query'", $source);
    self::assertStringContainsString("'visitor_available' => \$visitorAvailable", $source);
    self::assertStringContainsString("'fallback' => !\$visitorAvailable && \$items !== [] ? 'search_query' : null", $source);
}
}
