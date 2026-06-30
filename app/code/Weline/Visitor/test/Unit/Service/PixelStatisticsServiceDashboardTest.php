<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\PixelStatisticsService;

class PixelStatisticsServiceDashboardTest extends TestCore
{
    /** @var int[] */
    private array $pixelIds = [];

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->pixelIds)) as $pixelId) {
            try {
                ObjectManager::make(Pixel::class)->load($pixelId)->delete();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testEventListeningDashboardAggregatesAcrossSites(): void
    {
        $event = 'dashboard_test_' . str_replace('.', '_', uniqid('', true));
        $siteA = 910001;
        $siteB = 910002;

        $this->createPixel($siteA, $event, 120, '198.51.100.10');
        $this->createPixel($siteB, $event, 80, '198.51.100.11');

        $dashboard = PixelStatisticsService::getEventListeningDashboard([
            'event' => $event,
            'range' => '30d',
        ]);

        self::assertSame(2, (int)$dashboard['summary']['total_events']);
        self::assertSame(2, (int)$dashboard['summary']['active_sites']);
        self::assertSame(2, (int)$dashboard['summary']['active_users']);
        self::assertSame($event, $dashboard['event_rows'][0]['event'] ?? null);
        self::assertSame(2, (int)($dashboard['event_rows'][0]['count'] ?? 0));

        $siteIds = array_map(static fn(array $row): int => (int)$row['website_id'], $dashboard['site_rows']);
        self::assertContains($siteA, $siteIds);
        self::assertContains($siteB, $siteIds);
    }

    public function testEventListeningDashboardKeepsWebsiteZeroDistinctFromAllSites(): void
    {
        $event = 'dashboard_zero_site_' . str_replace('.', '_', uniqid('', true));
        $otherSite = 910003;

        $this->createPixel(0, $event, 30, '203.0.113.20');
        $this->createPixel($otherSite, $event, 70, '203.0.113.21');

        $dashboard = PixelStatisticsService::getEventListeningDashboard([
            'websiteId' => '0',
            'event' => $event,
            'range' => '30d',
        ]);

        self::assertSame(0, $dashboard['filters']['website_id']);
        self::assertSame('0', $dashboard['filters']['website_id_raw']);
        self::assertSame(1, (int)$dashboard['summary']['total_events']);
        self::assertSame(1, (int)$dashboard['summary']['active_sites']);
        self::assertSame(0, (int)($dashboard['site_rows'][0]['website_id'] ?? -1));
    }

    public function testNormalizeDashboardFiltersRejectsInvalidCustomDateRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PixelStatisticsService::normalizeDashboardFilters([
            'range' => 'custom',
            'startDate' => '2026-01-02',
            'endDate' => '2026-01-01',
        ]);
    }

    private function createPixel(int $websiteId, string $event, int $value, string $ip): Pixel
    {
        $pixel = ObjectManager::make(Pixel::class);
        $pixel->setData([
            'url' => 'https://example.test/' . $event,
            'module' => 'Weline_Visitor',
            'name' => $event,
            'event' => $event,
            'value' => $value,
            'lang' => 'zh-CN',
            'currency' => 'CNY',
            'website_id' => $websiteId,
            'source' => 'unit-test',
            'referer' => 'https://example.test/ref',
            'user_id' => 0,
            'user_agent' => 'PHPUnit Pixel Dashboard',
            'ip' => $ip,
            'browser_info' => '{}',
            'cron_deal' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ])->save();

        $this->pixelIds[] = $pixel->getId();

        return $pixel;
    }
}
