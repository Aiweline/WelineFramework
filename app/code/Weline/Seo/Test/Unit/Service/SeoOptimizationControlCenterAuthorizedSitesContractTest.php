<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoOptimizationControlCenterAuthorizedSitesContractTest extends TestCase
{
    public function testAuthorizedSitesUsesPublishedDirectoryInsteadOfBackendAreaQuery(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SeoOptimizationControlCenterService.php'
        );

        self::assertStringContainsString('$this->websiteDirectory->listWebsites()', $source);
        self::assertStringContainsString('fallbackWebsiteIds', $source);
        self::assertStringContainsString('listWebsiteIds', $source);
        self::assertStringContainsString("'sites_only'", $source);
        self::assertStringContainsString('seo.optimization.control-center.sites.v1', $source);
        self::assertStringNotContainsString("w_query('websites', 'getWebsiteList', [], 'backend')", $source);

        $provider = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/SeoOptimizationControlQueryProvider.php'
        );
        self::assertStringContainsString("'sites_only' => \$this->truthy", $provider);
        self::assertStringContainsString("['name' => 'sites_only', 'type' => 'bool'", $provider);
    }
}
