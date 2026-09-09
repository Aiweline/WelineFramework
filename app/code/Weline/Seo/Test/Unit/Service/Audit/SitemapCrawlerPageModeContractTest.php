<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Audit\SitemapCrawlerAuditService;

final class SitemapCrawlerPageModeContractTest extends TestCase
{
    public function testBeginPageModeAuditsOnlyRequestedUrl(): void
    {
        $service = new SitemapCrawlerAuditService();
        $job = $service->begin([
            'mode' => 'page',
            'pageUrl' => 'http://seo-page-mode.invalid/product/demo-hanfu',
            'startUrl' => 'http://seo-page-mode.invalid/product/demo-hanfu',
            'limit' => 1,
            'timeout' => 2,
        ]);

        self::assertSame('page', $job['mode'] ?? null);
        self::assertSame(['http://seo-page-mode.invalid/product/demo-hanfu'], $job['urls'] ?? null);
        self::assertSame(1, (int)($job['limit'] ?? 0));
        self::assertSame('page', $job['sampling']['mode'] ?? null);
        self::assertSame('http://seo-page-mode.invalid/product/demo-hanfu', $job['sampling']['pageUrl'] ?? null);

        $job = $service->advance($job, 2);
        $report = $service->toReport($job);
        self::assertSame('page', $report['crawl']['mode'] ?? null);
        self::assertSame('http://seo-page-mode.invalid/product/demo-hanfu', $report['crawl']['pageUrl'] ?? null);
        self::assertSame(1, (int)($report['crawl']['totalUrls'] ?? 0));
        self::assertContains(
            '当前为单页审计模式：只检测指定 URL（http://seo-page-mode.invalid/product/demo-hanfu），不读取 sitemap、不做结构抽样。',
            $report['assumptions'] ?? []
        );
    }

    public function testPanelSurfaceExposesCurrentUrlAuditControl(): void
    {
        $seoRoot = dirname(__DIR__, 4);
        $service = (string)file_get_contents($seoRoot . '/Service/Audit/SitemapCrawlerAuditService.php');
        $inspector = (string)file_get_contents($seoRoot . '/view/statics/seo-inspector/inspector.js');

        self::assertStringContainsString('beginPageAudit', $service);
        self::assertStringContainsString("mode' => 'page'", $service);
        self::assertStringContainsString('data-weline-page-audit', $inspector);
        self::assertStringContainsString('startCurrentUrlAudit', $inspector);
        self::assertStringContainsString('mode: "page"', $inspector);
        self::assertStringContainsString('当前页服务端检测', $inspector);
        self::assertStringContainsString('data-weline-tab="page"', $inspector);
        self::assertStringContainsString('data-weline-tab="rich"', $inspector);
        self::assertStringContainsString('renderPageAuditTab', $inspector);
        self::assertStringContainsString('renderRichResultsTab', $inspector);
        self::assertStringContainsString('data-weline-tool-url', $inspector);
        self::assertStringContainsString('data-weline-rich-local-test', $inspector);
        self::assertStringContainsString('startLocalRichResultsTest', $inspector);
        self::assertStringContainsString('buildLocalRichReport', $inspector);
        self::assertStringContainsString('analyzeLocalRichFromDocument', $inspector);
        self::assertStringContainsString('jsonLdReportPayload', $service);
    }
}
