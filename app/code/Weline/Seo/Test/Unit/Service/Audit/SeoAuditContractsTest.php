<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Audit\SitemapCrawlerAuditService;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoAuditContractsPass().
 */
final class SeoAuditContractsTest extends TestCase
{
    public function testAllSeoAuditContractsPass(): void
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
            'SitemapCrawlerBatch::testBeginAdvanceCompletesAndCrawlKeepsContractVersion' => function () {
            $this->assert_SitemapCrawlerBatch_testBeginAdvanceCompletesAndCrawlKeepsContractVersion();
        },
            'SitemapCrawlerBatch::testPanelSurfaceUsesBatchedPollingContract' => function () {
            $this->assert_SitemapCrawlerBatch_testPanelSurfaceUsesBatchedPollingContract();
        },
            'SitemapCrawlerEeatStrict::testAuditStructuredDataContainsEeatStrictHelpers' => function () {
            $this->assert_SitemapCrawlerEeatStrict_testAuditStructuredDataContainsEeatStrictHelpers();
        },
            'SitemapCrawlerPageMode::testBeginPageModeAuditsOnlyRequestedUrl' => function () {
            $this->assert_SitemapCrawlerPageMode_testBeginPageModeAuditsOnlyRequestedUrl();
        },
            'SitemapCrawlerPageMode::testPanelSurfaceExposesCurrentUrlAuditControl' => function () {
            $this->assert_SitemapCrawlerPageMode_testPanelSurfaceExposesCurrentUrlAuditControl();
        },
        ];
    }

private function assert_SitemapCrawlerBatch_testBeginAdvanceCompletesAndCrawlKeepsContractVersion(): void
{
    $service = new SitemapCrawlerAuditService();
    $options = ['startUrl' => 'http://seo-batch-contract.invalid/', 'sitemapUrl' => 'http://seo-batch-contract.invalid/sitemap.xml', 'limit' => 3, 'timeout' => 2];
    $job = $service->begin($options);
    self::assertContains($job['status'] ?? null, ['running', 'completed'], 'begin must return a job status');
    self::assertIsArray($job['urls'] ?? null);
    self::assertSame(0, (int) ($job['cursor'] ?? -1));
    // Force a tiny same-origin sample so advance/finalize is exercised even when sitemap discovery is empty.
    if (($job['urls'] ?? []) === []) {
        $job['urls'] = ['http://seo-batch-contract.invalid/a', 'http://seo-batch-contract.invalid/b', 'http://seo-batch-contract.invalid/c'];
        $job['status'] = 'running';
        $job['cursor'] = 0;
    }
    $guard = 0;
    while (($job['status'] ?? '') === 'running') {
        $before = (int) ($job['cursor'] ?? 0);
        $job = $service->advance($job, 2);
        self::assertGreaterThanOrEqual($before, (int) ($job['cursor'] ?? 0));
        $guard++;
        self::assertLessThan(20, $guard, 'advance loop must terminate');
    }
    self::assertSame('completed', $job['status'] ?? null);
    $report = $service->toReport($job);
    self::assertSame('weline-seo-site-crawl/v1', $report['contractVersion'] ?? null);
    self::assertSame('completed', $report['crawl']['status'] ?? null);
    self::assertArrayHasKey('scanned', $report['crawl'] ?? []);
    self::assertArrayHasKey('totalUrls', $report['crawl'] ?? []);
    self::assertIsArray($report['pages'] ?? null);
    self::assertIsArray($report['issues'] ?? null);
    $crawlReport = $service->crawl($options);
    self::assertSame('weline-seo-site-crawl/v1', $crawlReport['contractVersion'] ?? null);
    self::assertSame('completed', $crawlReport['crawl']['status'] ?? null);
}

private function assert_SitemapCrawlerBatch_testPanelSurfaceUsesBatchedPollingContract(): void
{
    $seoRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $service = (string) file_get_contents($seoRoot . '/Service/Audit/SitemapCrawlerAuditService.php');
    $controller = (string) file_get_contents(dirname($seoRoot) . '/DeveloperWorkspace/Api/Rest/V1/Seo/Crawl.php');
    $inspector = (string) file_get_contents($seoRoot . '/view/statics/seo-inspector/inspector.js');
    $loader = (string) file_get_contents(dirname($seoRoot) . '/DeveloperWorkspace/view/statics/js/dev-tool-panel-loader.js');
    self::assertStringContainsString('function begin(', $service);
    self::assertStringContainsString('function advance(', $service);
    self::assertStringContainsString('function toReport(', $service);
    self::assertStringContainsString('SitemapAuditUrlSampler', $service);
    self::assertStringContainsString('DISCOVERY_LIMIT', $service);
    self::assertStringContainsString('xmlUrlsetEntries', $service);
    self::assertStringContainsString('isNonHtmlAssetUrl', $service);
    self::assertStringContainsString('buildDiscoveryGroups', $service);
    self::assertStringContainsString('BATCH_SIZE = 2', $controller);
    self::assertStringContainsString('pollSiteCrawlUntilDone', $inspector);
    self::assertStringContainsString('requestTimeoutMs: 25000', $inspector);
    self::assertStringContainsString('crawl.sampling', $inspector);
    self::assertStringContainsString('renderAffectedGroups', $inspector);
    self::assertStringContainsString('按发现页面', $inspector);
    self::assertStringContainsString('相关图片', $inspector);
    self::assertStringContainsString('requestTimeoutMs', $loader);
    self::assertStringContainsString('timeoutMs', $loader);
}

private function assert_SitemapCrawlerEeatStrict_testAuditStructuredDataContainsEeatStrictHelpers(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/Audit/SitemapCrawlerAuditService.php');
    self::assertStringContainsString('auditEeatStrictStructuredSignals', $source);
    self::assertStringContainsString('eeat_org_sameas', $source);
    self::assertStringContainsString('eeat_article_author_missing', $source);
    self::assertStringContainsString('eeat_article_author_shallow', $source);
    self::assertStringContainsString('Helpful Content', $source);
    self::assertStringContainsString('who_person_url_or_sameas', $source);
}

private function assert_SitemapCrawlerPageMode_testBeginPageModeAuditsOnlyRequestedUrl(): void
{
    $service = new SitemapCrawlerAuditService();
    $job = $service->begin(['mode' => 'page', 'pageUrl' => 'http://seo-page-mode.invalid/product/demo-hanfu', 'startUrl' => 'http://seo-page-mode.invalid/product/demo-hanfu', 'limit' => 1, 'timeout' => 2]);
    self::assertSame('page', $job['mode'] ?? null);
    self::assertSame(['http://seo-page-mode.invalid/product/demo-hanfu'], $job['urls'] ?? null);
    self::assertSame(1, (int) ($job['limit'] ?? 0));
    self::assertSame('page', $job['sampling']['mode'] ?? null);
    self::assertSame('http://seo-page-mode.invalid/product/demo-hanfu', $job['sampling']['pageUrl'] ?? null);
    $job = $service->advance($job, 2);
    $report = $service->toReport($job);
    self::assertSame('page', $report['crawl']['mode'] ?? null);
    self::assertSame('http://seo-page-mode.invalid/product/demo-hanfu', $report['crawl']['pageUrl'] ?? null);
    self::assertSame(1, (int) ($report['crawl']['totalUrls'] ?? 0));
    self::assertContains('当前为单页审计模式：只检测指定 URL（http://seo-page-mode.invalid/product/demo-hanfu），不读取 sitemap、不做结构抽样。', $report['assumptions'] ?? []);
}

private function assert_SitemapCrawlerPageMode_testPanelSurfaceExposesCurrentUrlAuditControl(): void
{
    $seoRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $service = (string) file_get_contents($seoRoot . '/Service/Audit/SitemapCrawlerAuditService.php');
    $inspector = (string) file_get_contents($seoRoot . '/view/statics/seo-inspector/inspector.js');
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
