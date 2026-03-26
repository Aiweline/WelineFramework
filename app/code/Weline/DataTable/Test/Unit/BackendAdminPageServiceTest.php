<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Service\BackendAdminPageService;

class BackendAdminPageServiceTest extends TestCase
{
    public function testDashboardDataContainsScenarioModelAndDocSummaries(): void
    {
        $service = new BackendAdminPageService();

        $data = $service->getDashboardData();

        self::assertSame(15, $data['summary']['scenario_count']);
        self::assertSame(8, $data['summary']['direct_demo_count']);
        self::assertSame(7, $data['summary']['compatibility_route_count']);
        self::assertSame(5, $data['summary']['model_count']);
        self::assertSame(5, $data['summary']['doc_count']);
        self::assertCount(15, $data['scenarios']);
        self::assertCount(5, $data['models']);
        self::assertCount(5, $data['docs']);
    }

    public function testDocumentationPageDataFallsBackToQuickstartWhenKeyIsUnknown(): void
    {
        $service = new BackendAdminPageService();

        $data = $service->getDocumentationPageData('unknown-doc-key');

        self::assertSame('quickstart', $data['selectedDoc']['key']);
        self::assertNotEmpty($data['selectedDoc']['content']);
    }

    public function testTagVerificationReportContainsExpectedSections(): void
    {
        $service = new BackendAdminPageService();

        $report = $service->getTagVerificationReport();

        self::assertArrayHasKey('summary', $report);
        self::assertArrayHasKey('sections', $report);
        self::assertArrayHasKey('attribute_inheritance', $report['sections']);
        self::assertArrayHasKey('auto_generation', $report['sections']);
        self::assertGreaterThan(0, $report['summary']['total_checks']);
    }

    public function testBackendLayoutCatalogAndFallbackResolutionAreStable(): void
    {
        $service = new BackendAdminPageService();

        $layoutCatalog = $service->getBackendLayoutCatalog(false);

        self::assertArrayHasKey('default', $layoutCatalog);
        self::assertArrayHasKey('1280', $layoutCatalog);
        self::assertArrayHasKey('1440', $layoutCatalog);
        self::assertArrayNotHasKey('blank', $layoutCatalog);
        self::assertSame('default.1440', $service->resolveBackendLayoutType('1440'));
        self::assertSame('default.default', $service->resolveBackendLayoutType('unsupported', false));
        self::assertSame('default', $service->normalizeBackendLayoutKey('unsupported', false));
    }
}
