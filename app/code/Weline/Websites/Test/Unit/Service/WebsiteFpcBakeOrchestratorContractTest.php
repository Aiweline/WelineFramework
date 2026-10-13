<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class WebsiteFpcBakeOrchestratorContractTest extends TestCase
{
    public function testOrchestratorSoftPullsSeoFacadeOnly(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/WebsiteFpcBakeOrchestrator.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('SitemapBakeSource', $src);
        self::assertStringContainsString('class_exists', $src);
        self::assertStringNotContainsString('use Weline\\Seo\\Service\\', $src);
        self::assertStringNotContainsString('use Weline\\Seo\\Model\\', $src);
        self::assertStringContainsString('SitemapFpcBakeEngine', $src);
        self::assertStringContainsString('FpcOfflineBakeExporter', $src);
        self::assertStringContainsString('StorefrontWebsiteCodeResolverInterface', $src);
    }

    public function testCliCommandExistsWithAlias(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Website/Fpc/Bake.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('website:fpc:bake', $src);
        self::assertStringContainsString('website:sitemap-fpc-bake', $src);
        self::assertStringContainsString('WebsiteFpcBakeOrchestrator', $src);
    }

    public function testSeoIsOptionalNotRequired(): void
    {
        $module = include \dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        $requires = $module['requires'] ?? [];
        $optional = $module['optional'] ?? [];
        self::assertArrayNotHasKey('Weline_Seo', $requires);
        self::assertArrayHasKey('Weline_Seo', $optional);
    }
}
