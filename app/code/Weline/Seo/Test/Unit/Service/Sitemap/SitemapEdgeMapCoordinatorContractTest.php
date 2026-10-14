<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Sitemap;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Sitemap\SitemapEdgeMapCoordinator;
use Weline\Seo\Service\WebSitemapData;

final class SitemapEdgeMapCoordinatorContractTest extends TestCase
{
    public function testCoordinatorHooksAfterCanonicalPublishAndPurgesCdn(): void
    {
        $web = (string)\file_get_contents(
            BP . '/app/code/Weline/Seo/Service/WebSitemapData.php'
        );
        $coord = (string)\file_get_contents(
            BP . '/app/code/Weline/Seo/Service/Sitemap/SitemapEdgeMapCoordinator.php'
        );
        self::assertStringContainsString('SitemapEdgeMapCoordinator', $web);
        self::assertStringContainsString('afterCanonicalPublished', $web);
        self::assertStringContainsString('edgeMapCoordinator', $web);
        self::assertStringContainsString('purgeCdnUrls', $coord);
        self::assertStringContainsString("purge(\$domainId, 'urls'", $coord);
        self::assertStringContainsString('rebuildAll', $coord);
        self::assertStringContainsString('buildSpecFromWebsites', $coord);
        self::assertStringContainsString(WebSitemapData::SITEMAP_DIR, $coord);
        self::assertStringContainsString('sub_path 挂载只认', $coord);
        self::assertStringContainsString("\$subPath === '' && \$host !== ''", $coord);
        self::assertTrue(\class_exists(SitemapEdgeMapCoordinator::class));
    }
}
