<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Audit\SitemapCrawlerAuditService;

final class SitemapCrawlerBatchContractTest extends TestCase
{
    public function testBeginAdvanceCompletesAndCrawlKeepsContractVersion(): void
    {
        $service = new SitemapCrawlerAuditService();
        $options = [
            'startUrl' => 'http://seo-batch-contract.invalid/',
            'sitemapUrl' => 'http://seo-batch-contract.invalid/sitemap.xml',
            'limit' => 3,
            'timeout' => 2,
        ];

        $job = $service->begin($options);
        self::assertContains($job['status'] ?? null, ['running', 'completed'], 'begin must return a job status');
        self::assertIsArray($job['urls'] ?? null);
        self::assertSame(0, (int)($job['cursor'] ?? -1));

        // Force a tiny same-origin sample so advance/finalize is exercised even when sitemap discovery is empty.
        if (($job['urls'] ?? []) === []) {
            $job['urls'] = [
                'http://seo-batch-contract.invalid/a',
                'http://seo-batch-contract.invalid/b',
                'http://seo-batch-contract.invalid/c',
            ];
            $job['status'] = 'running';
            $job['cursor'] = 0;
        }

        $guard = 0;
        while (($job['status'] ?? '') === 'running') {
            $before = (int)($job['cursor'] ?? 0);
            $job = $service->advance($job, 2);
            self::assertGreaterThanOrEqual($before, (int)($job['cursor'] ?? 0));
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

    public function testPanelSurfaceUsesBatchedPollingContract(): void
    {
        $seoRoot = dirname(__DIR__, 4);
        $service = (string)file_get_contents($seoRoot . '/Service/Audit/SitemapCrawlerAuditService.php');
        $controller = (string)file_get_contents(
            dirname($seoRoot) . '/DeveloperWorkspace/Api/Rest/V1/Seo/Crawl.php'
        );
        $inspector = (string)file_get_contents($seoRoot . '/view/statics/seo-inspector/inspector.js');
        $loader = (string)file_get_contents(
            dirname($seoRoot) . '/DeveloperWorkspace/view/statics/js/dev-tool-panel-loader.js'
        );

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
}
