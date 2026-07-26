<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * A14：短窗读扁平列（utm/code 可见；不要求 name join）。
 */
class PixelStatisticsServiceShortWindowFlatAttributionTest extends TestCore
{
    public function testIsShortAttributionWindow(): void
    {
        self::assertTrue(PixelStatisticsService::isShortAttributionWindow(['range' => 'today']));
        self::assertTrue(PixelStatisticsService::isShortAttributionWindow(['range' => 'yesterday']));
        self::assertTrue(PixelStatisticsService::isShortAttributionWindow(['range' => '7d']));
        self::assertTrue(PixelStatisticsService::isShortAttributionWindow(['range' => 'custom', 'day_count' => 7]));
        self::assertFalse(PixelStatisticsService::isShortAttributionWindow(['range' => '30d', 'day_count' => 30]));
        self::assertFalse(PixelStatisticsService::isShortAttributionWindow(['range' => '90d', 'day_count' => 90]));
    }

    public function testSourceRowsHaveAttributionSignal(): void
    {
        self::assertFalse(PixelStatisticsService::sourceRowsHaveAttributionSignal([
            ['source' => 'direct', 'channel_code' => '', 'utm_source' => ''],
            ['source' => 'worker', 'channel_code' => ''],
        ]));
        self::assertTrue(PixelStatisticsService::sourceRowsHaveAttributionSignal([
            ['source' => 'newsletter/email', 'channel_code' => '', 'utm_source' => 'newsletter'],
        ]));
        self::assertTrue(PixelStatisticsService::sourceRowsHaveAttributionSignal([
            ['source' => 'direct', 'channel_code' => 'summer', 'utm_source' => ''],
        ]));
    }

    public function testShortWindowFlatSqlAndRecentExposeAttributionFields(): void
    {
        $stats = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        );
        self::assertStringContainsString('getDashboardSourceRowsFromFlatSql', $stats);
        self::assertStringContainsString('isShortAttributionWindow', $stats);
        self::assertStringContainsString('NULLIF({$utmSource}, \'\')', $stats);
        self::assertStringContainsString('NULLIF({$channelCode}, \'\')', $stats);
        self::assertStringContainsString("'channel_code' => (string)(\$resolved['channel_code']", $stats);
        self::assertStringContainsString("'utm_source' => (string)(\$resolved['utm_source']", $stats);

        $insight = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelAnalyticsInsightService.php'
        );
        self::assertStringContainsString("'channel_code' => \$parsed['channel_code']", $insight);
        self::assertStringContainsString("'utm_source' => (string)(\$parsed['detail']['utm']['source']", $insight);

        $tpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/index.phtml'
        );
        self::assertStringContainsString('<lang>渠道码</lang>', $tpl);
        self::assertStringContainsString("\$row['channel_code']", $tpl);
        self::assertStringContainsString("\$row['utm_source']", $tpl);
    }

    public function testDashboardRecentEventsExposeAttributionKeysWhenQueryable(): void
    {
        $dashboard = PixelStatisticsService::getEventListeningDashboard([
            'range' => '7d',
        ]);
        self::assertArrayHasKey('recent_events', $dashboard);
        self::assertArrayHasKey('source_rows', $dashboard);
        self::assertTrue(PixelStatisticsService::isShortAttributionWindow($dashboard['filters']));

        if ($dashboard['recent_events'] !== []) {
            $first = $dashboard['recent_events'][0];
            self::assertArrayHasKey('channel_code', $first);
            self::assertArrayHasKey('utm_source', $first);
            self::assertArrayHasKey('utm_medium', $first);
            self::assertArrayHasKey('utm_campaign', $first);
            self::assertArrayHasKey('traffic_type', $first);
            self::assertArrayHasKey('source', $first);
        }

        if ($dashboard['source_rows'] !== []) {
            $source = $dashboard['source_rows'][0];
            self::assertArrayHasKey('channel_code', $source);
            self::assertArrayHasKey('utm_source', $source);
            self::assertArrayHasKey('source', $source);
        }
    }
}
