<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;

final class SeoQueryProviderSiteEventHeatContractTest extends TestCase
{
    public function testSiteEventHeatExposesVisitorAvailabilityAndGscFallback(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 6) . '/extends/module/Weline_Framework/Query/SeoQueryProvider.php'
        );

        self::assertStringContainsString("'source' => 'visitor'", $source);
        self::assertStringContainsString("'source' => 'search_query'", $source);
        self::assertStringContainsString("'visitor_available' => \$visitorAvailable", $source);
        self::assertStringContainsString("'fallback' => !\$visitorAvailable && \$items !== [] ? 'search_query' : null", $source);
    }
}
