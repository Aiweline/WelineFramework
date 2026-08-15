<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;

final class GoogleSitemapAdapterQueryDimensionContractTest extends TestCase
{
    public function testSearchAnalyticsFetchesQueryDimensionRowsForWordCloud(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Adapter/GoogleSitemapAdapter.php'
        );

        self::assertStringContainsString("dimensions' => ['query']", $source);
        self::assertStringContainsString('fetchSearchQueryAnalytics', $source);
        self::assertStringContainsString("'search_queries'", $source);
        self::assertStringContainsString('rowLimit', $source);
    }
}
