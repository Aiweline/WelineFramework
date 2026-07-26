<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelChannelHotTotalsService;
use Weline\Visitor\Service\PixelChannelTimelineService;

/**
 * B11：渠道详情事件轨迹时间线（事件序可见；无漏斗）。
 */
class PixelChannelTimelineServiceTest extends TestCore
{
    private PixelChannelTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelTimelineService(new PixelChannelHotTotalsService());
    }

    public function testSortByEventTimeAscKeepsStableOrder(): void
    {
        $events = $this->service->sortByEventTimeAsc([
            ['pixel_id' => 3, 'event' => 'cta_click', 'created_at' => '2026-07-25 10:02:00', 'session_id' => 's1'],
            ['pixel_id' => 1, 'event' => 'page_view', 'created_at' => '2026-07-25 10:00:00', 'session_id' => 's1'],
            ['pixel_id' => 2, 'event' => 'page_view', 'created_at' => '2026-07-25 10:01:00', 'session_id' => 's1'],
            ['pixel_id' => 4, 'event' => 'add_to_cart', 'created_at' => '2026-07-25 10:02:00', 'session_id' => 's1'],
        ]);

        self::assertSame(['page_view', 'page_view', 'cta_click', 'add_to_cart'], array_column($events, 'event'));
        self::assertSame([1, 2, 3, 4], array_column($events, 'pixel_id'));
    }

    public function testGroupBySessionOrdersSessionsNewestFirstAndKeepsInnerAsc(): void
    {
        $sessions = $this->service->groupBySession([
            ['pixel_id' => 10, 'event' => 'page_view', 'created_at' => '2026-07-24 09:00:00', 'session_id' => 'old'],
            ['pixel_id' => 11, 'event' => 'cta_click', 'created_at' => '2026-07-24 09:01:00', 'session_id' => 'old'],
            ['pixel_id' => 20, 'event' => 'page_view', 'created_at' => '2026-07-25 11:00:00', 'session_id' => 'new'],
            ['pixel_id' => 21, 'event' => 'purchase', 'created_at' => '2026-07-25 11:05:00', 'session_id' => 'new', 'value' => 9.5],
        ]);

        self::assertCount(2, $sessions);
        self::assertSame('new', $sessions[0]['session_id']);
        self::assertSame('old', $sessions[1]['session_id']);
        self::assertSame(['page_view', 'purchase'], array_column($sessions[0]['events'], 'event'));
        self::assertSame(['page_view', 'cta_click'], array_column($sessions[1]['events'], 'event'));
        self::assertSame(2, $sessions[0]['event_count']);
        self::assertSame('2026-07-25 11:00:00', $sessions[0]['started_at']);
        self::assertSame('2026-07-25 11:05:00', $sessions[0]['ended_at']);
    }

    public function testEmptySessionIdGetsOwnBucket(): void
    {
        $sessions = $this->service->groupBySession([
            ['pixel_id' => 1, 'event' => 'page_view', 'created_at' => '2026-07-25 08:00:00', 'session_id' => ''],
            ['pixel_id' => 2, 'event' => 'page_view', 'created_at' => '2026-07-25 08:01:00', 'session_id' => ''],
        ]);
        self::assertCount(2, $sessions, '无 session_id 的事件各自成桶，避免错误合并');
    }

    public function testBuildForChannelUsesInjectedRunnerAndSorts(): void
    {
        $seen = null;
        $result = $this->service->buildForChannel(
            ['code' => 'summer_sale', 'website_id' => 5],
            30,
            50,
            static function (string $code, ?int $websiteId, array $window, int $limit) use (&$seen): array {
                $seen = [$code, $websiteId, (int)$window['days'], $limit];

                return [
                    ['pixel_id' => 2, 'event' => 'cta_click', 'created_at' => '2026-07-25 12:01:00', 'session_id' => 's9'],
                    ['pixel_id' => 1, 'event' => 'page_view', 'created_at' => '2026-07-25 12:00:00', 'session_id' => 's9'],
                ];
            }
        );

        self::assertSame(['summer_sale', 5, 30, 50], $seen);
        self::assertSame(2, $result['event_count']);
        self::assertSame(1, $result['session_count']);
        self::assertSame(['page_view', 'cta_click'], array_column($result['events'], 'event'));
        self::assertSame('', $result['error']);
    }

    public function testNormalizeDaysAndLimit(): void
    {
        self::assertSame(7, $this->service->normalizeDays(99));
        self::assertSame(30, $this->service->normalizeDays(30));
        self::assertSame(100, $this->service->normalizeLimit(0));
        self::assertSame(500, $this->service->normalizeLimit(9999));
    }

    public function testSqlOrdersByEventTimeAsc(): void
    {
        $window = (new PixelChannelHotTotalsService())->resolveWindow(7);
        [$sql, $params] = $this->service->buildTimelineSql('summer_sale', 2, $window, 100);
        self::assertStringContainsString('channel_code', $sql);
        self::assertStringContainsString('ORDER BY', $sql);
        self::assertStringContainsString('ASC', $sql);
        self::assertStringContainsString('LIMIT 100', $sql);
        self::assertSame('summer_sale', $params[':channel_code']);
        self::assertSame(2, $params[':website_id']);
    }

    public function testDetailWiresTimelineWithoutFunnel(): void
    {
        $root = BP . '/app/code/Weline/Visitor';
        self::assertFileExists($root . '/Service/PixelChannelTimelineService.php');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/TrafficChannel.php');
        self::assertStringContainsString('PixelChannelTimelineService', $controller);
        self::assertStringContainsString('timeline_days', $controller);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/detail.phtml');
        self::assertStringContainsString('channel-timeline', $detail);
        self::assertStringContainsString('<lang>事件轨迹</lang>', $detail);
        self::assertStringContainsString('timeline_days', $detail);
        // B12 起详情含简化漏斗；仍非电商字典四步
        self::assertStringContainsString('channel-funnel', $detail);
        self::assertStringNotContainsString('view_item', $detail);
    }
}
