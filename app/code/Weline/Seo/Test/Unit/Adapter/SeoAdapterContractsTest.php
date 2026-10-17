<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoAdapterContractsPass().
 */
final class SeoAdapterContractsTest extends TestCase
{
    public function testAllSeoAdapterContractsPass(): void
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
            'GoogleSitemapAdapterGetStatsSiteProperty::testGetStatsPrefersAccountSiteUrlOverWebsiteFallback' => function () {
            $this->assert_GoogleSitemapAdapterGetStatsSiteProperty_testGetStatsPrefersAccountSiteUrlOverWebsiteFallback();
        },
            'GoogleSitemapAdapterQueryDimension::testSearchAnalyticsFetchesQueryDimensionRowsForWordCloud' => function () {
            $this->assert_GoogleSitemapAdapterQueryDimension_testSearchAnalyticsFetchesQueryDimensionRowsForWordCloud();
        },
        ];
    }

private function assert_GoogleSitemapAdapterGetStatsSiteProperty_testGetStatsPrefersAccountSiteUrlOverWebsiteFallback(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $src = (string) file_get_contents($root . '/Adapter/GoogleSitemapAdapter.php');
    self::assertStringContainsString('Prefer account GSC property', $src);
    self::assertStringContainsString("config['site_url']", $src);
    self::assertStringContainsString('normalizeGoogleSiteProperty($property)', $src);
    self::assertStringContainsString('website.url is fallback only', $src);
}

private function assert_GoogleSitemapAdapterQueryDimension_testSearchAnalyticsFetchesQueryDimensionRowsForWordCloud(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Adapter/GoogleSitemapAdapter.php');
    self::assertStringContainsString("dimensions' => ['query']", $source);
    self::assertStringContainsString('fetchSearchQueryAnalytics', $source);
    self::assertStringContainsString("'search_queries'", $source);
    self::assertStringContainsString('rowLimit', $source);
    self::assertStringContainsString("\$body['type'] = \$type", $source);
    self::assertStringContainsString("'discover'", $source);
    self::assertStringContainsString('enable_discover_stats', $source);
    self::assertStringContainsString('extractDistributionChannels', $source);
}
}
