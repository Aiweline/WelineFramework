<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * C05：index 流量板块 + pixel_channel name join。
 */
class PixelStatisticsServiceChannelRowsNameJoinTest extends TestCore
{
    public function testEnrichRowsWithChannelNamesKeepsFlatWhenMapEmpty(): void
    {
        $rows = PixelStatisticsService::enrichRowsWithChannelNames([
            [
                'channel_code' => 'summer_sale',
                'channel_name' => '夏季投放',
                'count' => 3,
            ],
            [
                'channel_code' => '',
                'count' => 1,
            ],
        ], null);

        self::assertSame('夏季投放', $rows[0]['channel_name']);
        self::assertSame('', $rows[1]['channel_name']);
    }

    public function testEnrichRowsPreferJoinedNameOverFlat(): void
    {
        // 无表时 map 为空，仍保留扁平名；仅验证 API 形状
        $rows = PixelStatisticsService::enrichRowsWithChannelNames([
            [
                'channel_code' => '__c05_no_such_code__',
                'channel_name' => 'flat-name',
                'traffic_type' => 'paid',
            ],
        ], 1);

        self::assertSame('flat-name', $rows[0]['channel_name']);
        self::assertSame('paid', $rows[0]['traffic_type']);
    }

    public function testGetDashboardChannelRowsShapeWhenQueryable(): void
    {
        $rows = PixelStatisticsService::getDashboardChannelRows(['range' => '7d'], 10);
        self::assertIsArray($rows);

        if ($rows !== []) {
            $first = $rows[0];
            self::assertArrayHasKey('channel_code', $first);
            self::assertArrayHasKey('channel_name', $first);
            self::assertArrayHasKey('traffic_type', $first);
            self::assertArrayHasKey('count', $first);
            self::assertArrayHasKey('active_users', $first);
            self::assertArrayHasKey('total_value', $first);
            self::assertNotSame('', (string)$first['channel_code']);
        }
    }

    public function testDashboardExposesChannelRowsAndNameJoinWiring(): void
    {
        $dashboard = PixelStatisticsService::getEventListeningDashboard(['range' => '7d']);
        self::assertArrayHasKey('channel_rows', $dashboard);
        self::assertIsArray($dashboard['channel_rows']);

        if ($dashboard['source_rows'] !== []) {
            self::assertArrayHasKey('channel_name', $dashboard['source_rows'][0]);
        }

        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        );
        self::assertStringContainsString('function getDashboardChannelRows', $src);
        self::assertStringContainsString('function enrichRowsWithChannelNames', $src);
        self::assertStringContainsString('function loadPixelChannelNameMap', $src);
        self::assertStringContainsString("'channel_rows' => self::getDashboardChannelRows", $src);
    }

    public function testIndexTemplateRendersTrafficChannelSectionWithName(): void
    {
        $tpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/index.phtml'
        );
        self::assertStringContainsString('<lang>流量渠道</lang>', $tpl);
        self::assertStringContainsString('<lang>渠道名称</lang>', $tpl);
        self::assertStringContainsString('$channelRows', $tpl);
        self::assertStringContainsString("\$row['channel_name']", $tpl);
        // C06：index 也可下钻 list
        self::assertStringContainsString('buildListDrilldownQuery', $tpl);
        self::assertStringContainsString('pixel-dashboard/list', $tpl);
    }

    public function testControllerAssignsChannelRows(): void
    {
        $controller = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Controller/Backend/PixelDashboard.php'
        );
        self::assertStringContainsString("'channel_rows'", $controller);
    }
}
