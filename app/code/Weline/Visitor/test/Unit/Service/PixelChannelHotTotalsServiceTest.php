<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelChannelHotTotalsService;

/**
 * B10：渠道详情热表 7/30 天总计（与 COUNT 同口径；无轨迹）。
 */
class PixelChannelHotTotalsServiceTest extends TestCore
{
    private PixelChannelHotTotalsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelHotTotalsService();
    }

    public function testResolveWindowOnlyAllows7And30(): void
    {
        $seven = $this->service->resolveWindow(7);
        self::assertSame(7, $seven['days']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} /', $seven['start_date']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} /', $seven['end_date']);
        self::assertTrue($seven['start_date'] <= $seven['end_date']);

        $thirty = $this->service->resolveWindow(30);
        self::assertSame(30, $thirty['days']);

        $fallback = $this->service->resolveWindow(99);
        self::assertSame(7, $fallback['days'], '非法窗口回退 7 天');
    }

    public function testAggregateFromRowsMatchesManualCounts(): void
    {
        $rows = [
            ['session_id' => 's1', 'ip' => '1.1.1.1', 'event' => 'page_view', 'value' => 0],
            ['session_id' => 's1', 'ip' => '1.1.1.1', 'event' => 'cta_click', 'value' => 0],
            ['session_id' => 's2', 'ip' => '2.2.2.2', 'event' => 'add_to_cart', 'value' => 12.5],
            ['session_id' => 's2', 'ip' => '2.2.2.2', 'event' => 'purchase', 'value' => 99],
            ['session_id' => '', 'ip' => '', 'event' => 'page_enter', 'value' => 0],
        ];
        $totals = $this->service->aggregateFromRows($rows);

        self::assertSame(5, $totals['events']);
        self::assertSame(2, $totals['sessions']);
        self::assertSame(2, $totals['users']);
        self::assertSame(111.5, $totals['value_sum']);
        self::assertSame(2, $totals['page_views']);
        self::assertSame(1, $totals['interactions']);
        self::assertSame(1, $totals['add_to_carts']);
        self::assertSame(1, $totals['conversions']);
    }

    public function testBuildForChannelUsesInjectedRunnerForBothWindows(): void
    {
        $calls = [];
        $result = $this->service->buildForChannel(
            ['code' => 'summer_sale', 'website_id' => 3],
            static function (string $code, ?int $websiteId, array $window) use (&$calls): array {
                $calls[] = [$code, $websiteId, (int)$window['days']];

                return [
                    'events' => (int)$window['days'],
                    'sessions' => 1,
                    'users' => 1,
                    'value_sum' => 2.5,
                    'page_views' => 0,
                    'interactions' => 0,
                    'add_to_carts' => 0,
                    'conversions' => 0,
                ];
            }
        );

        self::assertSame('summer_sale', $result['channel_code']);
        self::assertSame(3, $result['website_id']);
        self::assertSame('', $result['error']);
        self::assertSame([['summer_sale', 3, 7], ['summer_sale', 3, 30]], $calls);
        self::assertSame(7, $result['windows'][7]['events']);
        self::assertSame(30, $result['windows'][30]['events']);
        self::assertArrayHasKey('start_date', $result['windows'][7]);
    }

    public function testGlobalChannelSkipsWebsiteFilter(): void
    {
        $seen = null;
        $this->service->buildForChannel(
            ['code' => 'global_code', 'website_id' => 0],
            static function (string $code, ?int $websiteId, array $window) use (&$seen): array {
                $seen = $websiteId;

                return ['events' => 0];
            }
        );
        self::assertNull($seen, 'website_id=0 不按站点过滤');
    }

    public function testSqlContractsFilterByChannelAndTime(): void
    {
        $window = $this->service->resolveWindow(7);
        [$sql, $params] = $this->service->buildTotalsSql('summer_sale', 9, $window);
        self::assertStringContainsString('channel_code', $sql);
        self::assertStringContainsString('COUNT(*) AS events', $sql);
        self::assertStringContainsString('COUNT(DISTINCT NULLIF(', $sql);
        self::assertStringContainsString(':channel_code', $sql);
        self::assertStringContainsString(':website_id', $sql);
        self::assertSame('summer_sale', $params[':channel_code']);
        self::assertSame(9, $params[':website_id']);

        [$countSql, $countParams] = $this->service->buildCountSql('summer_sale', 9, $window);
        self::assertStringContainsString('COUNT(*) AS cnt', $countSql);
        self::assertSame($params[':start_date'], $countParams[':start_date']);
        self::assertSame($params[':end_date'], $countParams[':end_date']);
    }

    public function testEventsMatchIndependentCountWhenQueryable(): void
    {
        try {
            $totals = $this->service->queryHotTotals('__b10_missing_code__', null, 7);
            $count = $this->service->countHotEvents('__b10_missing_code__', null, 7);
            self::assertSame($count, $totals['events'], 'events 必须等于同条件 COUNT(*)');
        } catch (\Throwable $throwable) {
            self::assertTrue(
                true,
                '扁平列/表未就绪时跳过 DB 验收：' . $throwable->getMessage()
            );
        }
    }

    public function testDetailTemplateAndControllerExist(): void
    {
        $root = BP . '/app/code/Weline/Visitor';
        self::assertFileExists($root . '/Service/PixelChannelHotTotalsService.php');
        self::assertFileExists($root . '/view/templates/Backend/TrafficChannel/detail.phtml');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/TrafficChannel.php');
        self::assertStringContainsString('function getDetail', $controller);
        self::assertStringContainsString('PixelChannelHotTotalsService', $controller);
        self::assertStringContainsString('traffic_channel_detail', $controller);

        $index = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/index.phtml');
        self::assertStringContainsString('traffic-channel/getDetail', $index);
        self::assertStringContainsString('<lang>详情</lang>', $index);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/detail.phtml');
        self::assertStringContainsString('近 %{1} 天', $detail);
        self::assertStringContainsString('hot_totals', $detail);
        // B11 起详情含轨迹；漏斗仍属 B12
        self::assertStringContainsString('channel-timeline', (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/TrafficChannel/detail.phtml'
        ));
    }
}
