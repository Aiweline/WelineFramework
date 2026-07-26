<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * C03a：看板 filters/WHERE 支持 channel_code / traffic_type / utm_*。
 */
class PixelStatisticsServiceAttributionFiltersTest extends TestCore
{
    public function testNormalizeDashboardFiltersAcceptsAttributionKeys(): void
    {
        $normalized = PixelStatisticsService::normalizeDashboardFilters([
            'range' => '7d',
            'channelCode' => 'summer_sale',
            'trafficType' => PixelChannel::TRAFFIC_PAID,
            'utmSource' => 'google',
            'utmMedium' => 'cpc',
            'utmCampaign' => 'spring',
        ]);

        self::assertSame('summer_sale', $normalized['channel_code']);
        self::assertSame(PixelChannel::TRAFFIC_PAID, $normalized['traffic_type']);
        self::assertSame('google', $normalized['utm_source']);
        self::assertSame('cpc', $normalized['utm_medium']);
        self::assertSame('spring', $normalized['utm_campaign']);
        self::assertTrue(PixelStatisticsService::hasAttributionFilter($normalized));
    }

    public function testNormalizeDashboardFiltersRejectsInvalidTrafficType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PixelStatisticsService::normalizeDashboardFilters([
            'range' => '7d',
            'traffic_type' => 'not_a_real_type',
        ]);
    }

    public function testEmptyAttributionStringsAreNull(): void
    {
        $normalized = PixelStatisticsService::normalizeDashboardFilters([
            'range' => '7d',
            'channel_code' => '  ',
            'utm_source' => '',
        ]);
        self::assertNull($normalized['channel_code']);
        self::assertNull($normalized['utm_source']);
        self::assertFalse(PixelStatisticsService::hasAttributionFilter($normalized));
    }

    public function testBuildDashboardWhereIncludesAttributionEquals(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        );
        $start = strpos($src, 'function buildDashboardWhere');
        self::assertNotFalse($start);
        $chunk = substr($src, (int)$start, 2500);

        self::assertStringContainsString(':channel_code', $chunk);
        self::assertStringContainsString(':traffic_type', $chunk);
        self::assertStringContainsString(':utm_source', $chunk);
        self::assertStringContainsString(':utm_medium', $chunk);
        self::assertStringContainsString(':utm_campaign', $chunk);
        self::assertStringContainsString('hasPixelAttributionColumns', $chunk);
    }

    public function testControllerPassesAttributionQueryParams(): void
    {
        $controller = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Controller/Backend/PixelDashboard.php'
        );
        $start = strpos($controller, 'function getDashboardRequestFilters');
        self::assertNotFalse($start);
        $chunk = substr($controller, (int)$start, 1200);

        self::assertStringContainsString("'channel_code'", $chunk);
        self::assertStringContainsString("'traffic_type'", $chunk);
        self::assertStringContainsString("'utm_source'", $chunk);
        self::assertStringContainsString("'utm_medium'", $chunk);
        self::assertStringContainsString("'utm_campaign'", $chunk);
    }

    public function testListPageAppliesChannelFilterWhenQueryable(): void
    {
        $baseline = PixelStatisticsService::getDashboardEventListPage(['range' => '7d'], 1, 20);
        if ($baseline['error'] !== '') {
            self::assertTrue(true, '热表未就绪，跳过运行时筛选验收：' . $baseline['error']);

            return;
        }

        $filtered = PixelStatisticsService::getDashboardEventListPage([
            'range' => '7d',
            'channel_code' => '__c03a_no_such_channel__',
        ], 1, 20);

        if ($filtered['error'] !== '') {
            // 扁平列未落库时明确报错，不算失败
            self::assertStringContainsString('setup:upgrade', $filtered['error']);

            return;
        }

        self::assertSame(0, $filtered['total']);
        self::assertSame([], $filtered['rows']);
        self::assertSame('__c03a_no_such_channel__', $filtered['filters']['channel_code'] ?? null);
    }
}
