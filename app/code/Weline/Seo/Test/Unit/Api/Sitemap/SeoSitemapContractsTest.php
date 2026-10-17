<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Api\Sitemap;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoSitemapContractsPass().
 */
final class SeoSitemapContractsTest extends TestCase
{
    public function testAllSeoSitemapContractsPass(): void
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
            'SitemapBakeSource::testInterfaceAndImplementationExist' => function () {
            $this->assert_SitemapBakeSource_testInterfaceAndImplementationExist();
        },
            'SitemapBakeSource::testRefreshCliResolvesViaSeoWebsiteDirectoryNotWebsitesModel' => function () {
            $this->assert_SitemapBakeSource_testRefreshCliResolvesViaSeoWebsiteDirectoryNotWebsitesModel();
        },
            'SitemapBakeSource::testModuleProvidesBakeSourceInterface' => function () {
            $this->assert_SitemapBakeSource_testModuleProvidesBakeSourceInterface();
        },
        ];
    }

private function assert_SitemapBakeSource_testInterfaceAndImplementationExist(): void
{
    $iface = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Api/Sitemap/SitemapBakeSourceInterface.php';
    $impl = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Api/Sitemap/SitemapBakeSource.php';
    self::assertFileExists($iface);
    self::assertFileExists($impl);
    $ifaceSrc = (string) \file_get_contents($iface);
    self::assertStringContainsString('refreshForBake', $ifaceSrc);
    self::assertStringContainsString('listActiveLocs', $ifaceSrc);
    $implSrc = (string) \file_get_contents($impl);
    self::assertStringContainsString('hard_gate', $implSrc);
    self::assertStringContainsString('getActiveUrls', $implSrc);
    self::assertStringNotContainsString('Weline\Websites\Model\\', $implSrc);
}

private function assert_SitemapBakeSource_testRefreshCliResolvesViaSeoWebsiteDirectoryNotWebsitesModel(): void
{
    $cli = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Console/Sitemap/Refresh.php';
    self::assertFileExists($cli);
    $src = (string) \file_get_contents($cli);
    self::assertStringContainsString('sitemap:refresh', $src);
    self::assertStringContainsString('SeoWebsiteDirectory', $src);
    self::assertStringNotContainsString('Weline\Websites\Model\Website', $src);
    self::assertStringContainsString('SitemapBakeSource', $src);
}

private function assert_SitemapBakeSource_testModuleProvidesBakeSourceInterface(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/etc/module.php';
    self::assertIsArray($module);
    $provides = $module['provides'] ?? [];
    self::assertArrayHasKey(\Weline\Seo\Api\Sitemap\SitemapBakeSourceInterface::class, $provides);
}
}
