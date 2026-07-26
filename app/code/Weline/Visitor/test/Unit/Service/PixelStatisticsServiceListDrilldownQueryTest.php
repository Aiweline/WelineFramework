<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * C06：detail/index 流量下钻 list（query 与 list 筛选字段对齐）。
 */
class PixelStatisticsServiceListDrilldownQueryTest extends TestCore
{
    public function testBuildListDrilldownQueryKeepsCanonicalKeys(): void
    {
        $query = PixelStatisticsService::buildListDrilldownQuery([
            'website_id_raw' => '12',
            'event' => 'page_view',
            'range' => '7d',
            'start_day' => '2026-07-01',
            'end_day' => '2026-07-07',
        ], [
            'channel_code' => 'summer_sale',
            'traffic_type' => 'paid',
        ]);

        self::assertSame('12', $query['websiteId']);
        self::assertSame('page_view', $query['event']);
        self::assertSame('7d', $query['range']);
        self::assertSame('summer_sale', $query['channel_code']);
        self::assertSame('paid', $query['traffic_type']);
        // 非 custom 不带日期
        self::assertArrayNotHasKey('startDate', $query);
        self::assertArrayNotHasKey('endDate', $query);
    }

    public function testBuildListDrilldownQueryCustomRangeKeepsDates(): void
    {
        $query = PixelStatisticsService::buildListDrilldownQuery([
            'range' => 'custom',
            'start_date' => '2026-07-01 00:00:00',
            'end_date' => '2026-07-10 23:59:59',
            'channelCode' => 'fb_ad',
        ]);

        self::assertSame('custom', $query['range']);
        self::assertSame('2026-07-01', $query['startDate']);
        self::assertSame('2026-07-10', $query['endDate']);
        self::assertSame('fb_ad', $query['channel_code']);
    }

    public function testBuildListDrilldownQueryDropsEmptyAndAllWebsite(): void
    {
        $query = PixelStatisticsService::buildListDrilldownQuery([
            'websiteId' => 'all',
            'event' => '',
            'range' => '30d',
            'channel_code' => '  ',
            'utm_source' => 'google',
        ]);

        self::assertArrayNotHasKey('websiteId', $query);
        self::assertArrayNotHasKey('event', $query);
        self::assertArrayNotHasKey('channel_code', $query);
        self::assertSame('google', $query['utm_source']);
        self::assertSame('30d', $query['range']);
    }

    public function testDetailTemplateWiresChannelDrilldownToList(): void
    {
        $tpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/detail.phtml'
        );
        self::assertStringContainsString('$channel_rows', $tpl);
        self::assertStringContainsString('$channelRows', $tpl);
        self::assertStringContainsString('流量渠道', $tpl);
        self::assertStringContainsString('buildListDrilldownQuery', $tpl);
        self::assertStringContainsString('pixel-dashboard/list', $tpl);
        // D07：引擎 Tab 下钻走 dimExtras（drilldownExtras），不再写死 'channel_code' => $code
        self::assertStringContainsString('drilldownExtras', $tpl);
        self::assertStringContainsString('$dimExtras', $tpl);
        self::assertStringContainsString("'websiteId' => (string)\$websiteId", $tpl);
        // 渠道汇总表仍展示 channel_code；行级下钻在引擎 Tab / index 流量表
        self::assertStringContainsString("\$code = (string)(\$row['channel_code'] ?? '')", $tpl);
    }

    public function testIndexTemplateWiresChannelDrilldownToList(): void
    {
        $tpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/index.phtml'
        );
        self::assertStringContainsString('buildListDrilldownQuery', $tpl);
        self::assertStringContainsString("'channel_code' => \$code", $tpl);
        self::assertStringContainsString('<lang>下钻</lang>', $tpl);
    }

    public function testDetailAssignsChannelRowsFromDashboard(): void
    {
        $controller = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Controller/Backend/PixelDashboard.php'
        );
        self::assertStringContainsString('assignDashboardData', $controller);
        self::assertStringContainsString("'channel_rows'", $controller);
        self::assertStringContainsString('function detail', $controller);
    }
}
