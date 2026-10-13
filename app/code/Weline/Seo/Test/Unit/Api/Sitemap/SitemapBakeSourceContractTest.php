<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Api\Sitemap;

use PHPUnit\Framework\TestCase;

final class SitemapBakeSourceContractTest extends TestCase
{
    public function testInterfaceAndImplementationExist(): void
    {
        $iface = \dirname(__DIR__, 4) . '/Api/Sitemap/SitemapBakeSourceInterface.php';
        $impl = \dirname(__DIR__, 4) . '/Api/Sitemap/SitemapBakeSource.php';
        self::assertFileExists($iface);
        self::assertFileExists($impl);
        $ifaceSrc = (string)\file_get_contents($iface);
        self::assertStringContainsString('refreshForBake', $ifaceSrc);
        self::assertStringContainsString('listActiveLocs', $ifaceSrc);
        $implSrc = (string)\file_get_contents($impl);
        self::assertStringContainsString('hard_gate', $implSrc);
        self::assertStringContainsString('getActiveUrls', $implSrc);
        self::assertStringNotContainsString('Weline\\Websites\\Model\\', $implSrc);
    }

    public function testRefreshCliResolvesViaSeoWebsiteDirectoryNotWebsitesModel(): void
    {
        $cli = \dirname(__DIR__, 4) . '/Console/Sitemap/Refresh.php';
        self::assertFileExists($cli);
        $src = (string)\file_get_contents($cli);
        self::assertStringContainsString('sitemap:refresh', $src);
        self::assertStringContainsString('SeoWebsiteDirectory', $src);
        self::assertStringNotContainsString('Weline\\Websites\\Model\\Website', $src);
        self::assertStringContainsString('SitemapBakeSource', $src);
    }

    public function testModuleProvidesBakeSourceInterface(): void
    {
        $module = include \dirname(__DIR__, 4) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertArrayHasKey(
            \Weline\Seo\Api\Sitemap\SitemapBakeSourceInterface::class,
            $provides
        );
    }
}
