<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelDetailReportTabService;
use Weline\Visitor\Service\Report\PixelEventValueReportService;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * D07a–D07f：detail 逐个挂载引擎 Tab（catalog 六个预设全部挂完），且 Tab 有数。
 */
final class PixelDetailReportTabServiceTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function sampleChannelRows(): array
    {
        return [
            ['channel_code' => 'summer_sale', 'traffic_type' => 'paid', 'utm_campaign' => 'summer', 'event' => 'page_view', 'value' => 0],
            ['channel_code' => 'summer_sale', 'traffic_type' => 'paid', 'utm_campaign' => 'summer', 'event' => 'purchase', 'value' => 88],
            ['channel_code' => 'brand_ads', 'traffic_type' => 'paid', 'utm_campaign' => 'brand', 'event' => 'add_to_cart', 'value' => 0],
            ['channel_code' => 'wechat', 'traffic_type' => 'social', 'utm_campaign' => 'wechat_push', 'event' => 'page_view', 'value' => 0],
            ['channel_code' => 'wechat', 'traffic_type' => 'social', 'utm_campaign' => 'wechat_push', 'event' => 'cta_click', 'value' => 3],
            ['channel_code' => 'wechat', 'traffic_type' => 'social', 'utm_campaign' => 'wechat_push', 'event' => 'add_to_cart', 'value' => 0],
            ['channel_code' => 'douyin', 'traffic_type' => 'social', 'utm_campaign' => 'dy_feed', 'event' => 'page_view', 'value' => 1],
            ['channel_code' => '', 'traffic_type' => 'direct', 'utm_campaign' => '', 'event' => 'page_view', 'value' => 0],
        ];
    }

    public function testMountedCodesCoverFullCatalog(): void
    {
        $service = new PixelDetailReportTabService();

        self::assertSame(
            [
                'pixel_channels',
                'pixel_traffic_type',
                'pixel_paid',
                'pixel_social',
                'pixel_event_value',
                'pixel_value_by_channel',
            ],
            $service->mountedCodes()
        );
        foreach ($service->mountedCodes() as $code) {
            self::assertTrue($service->isMounted($code), $code);
        }
        self::assertFalse($service->isMounted('pixel_unknown'));
        // 与 catalog enabled codes 顺序无关，集合一致
        $catalogCodes = (new \Weline\Visitor\Service\Report\PixelReportCatalog())->codes();
        self::assertSame([], array_diff($catalogCodes, $service->mountedCodes()));
        self::assertSame([], array_diff($service->mountedCodes(), $catalogCodes));
    }

    public function testBuildTabFromEventRowsHasChannelData(): void
    {
        $service = new PixelDetailReportTabService();
        $tab = $service->buildTabFromEventRows('pixel_channels', $this->sampleChannelRows());

        self::assertSame('pixel_channels', $tab['code']);
        self::assertSame('channel_code', $tab['dimension']);
        self::assertSame('', $tab['error']);
        self::assertNotEmpty($tab['rows']);

        $byCode = [];
        foreach ($tab['rows'] as $row) {
            $byCode[(string)$row['dimension_value']] = $row;
        }

        self::assertSame(3, $byCode['wechat']['events']);
        self::assertEqualsWithDelta(3.0, $byCode['wechat']['value_sum'], 0.0001);
        self::assertSame(2, $byCode['summer_sale']['events']);
        self::assertEqualsWithDelta(88.0, $byCode['summer_sale']['value_sum'], 0.0001);
        self::assertSame(1, $byCode['douyin']['events']);
        // 空 channel_code 也聚合为一桶
        self::assertArrayHasKey('', $byCode);
    }

    public function testPaidTabFiltersToPaidTrafficAndGroupsByCampaign(): void
    {
        $service = new PixelDetailReportTabService();
        $tab = $service->buildTabFromEventRows('pixel_paid', $this->sampleChannelRows());

        self::assertSame('pixel_paid', $tab['code']);
        self::assertSame('utm_campaign', $tab['dimension']);
        self::assertSame(['traffic_type' => 'paid'], $tab['filters']);
        self::assertSame('', $tab['error']);

        $byCampaign = [];
        foreach ($tab['rows'] as $row) {
            $byCampaign[(string)$row['dimension_value']] = $row;
        }

        // 只保留 traffic_type=paid：summer(2 events, 88) + brand(1 event)
        self::assertSame(['summer', 'brand'], array_keys($byCampaign));
        self::assertSame(2, $byCampaign['summer']['events']);
        self::assertEqualsWithDelta(88.0, $byCampaign['summer']['value_sum'], 0.0001);
        self::assertSame(1, $byCampaign['summer']['purchases']);
        self::assertSame(1, $byCampaign['brand']['events']);
        self::assertSame(1, $byCampaign['brand']['add_to_carts']);
        // social / direct 不得进入 paid Tab
        self::assertArrayNotHasKey('wechat_push', $byCampaign);
        self::assertArrayNotHasKey('', $byCampaign);
    }

    public function testPaidTabFetchesHotRowsOnlyOnce(): void
    {
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));
        $sample = $this->sampleChannelRows();
        $calls = 0;

        $service = new PixelDetailReportTabService();
        $tab = $service->buildTab(
            'pixel_paid',
            $from,
            $to,
            1,
            static function (array $ctx) use ($sample, &$calls): array {
                $calls++;
                TestCase::assertSame('utm_campaign', $ctx['dimension']);
                TestCase::assertSame(['traffic_type' => 'paid'], $ctx['filters']);

                return $sample;
            },
            50,
            $now
        );

        self::assertSame(1, $calls);
        self::assertSame('', $tab['error']);
        self::assertSame('summer', $tab['rows'][0]['dimension_value']);
        self::assertCount(2, $tab['rows']);
    }

    public function testSocialTabFiltersToSocialTrafficAndGroupsByChannel(): void
    {
        $service = new PixelDetailReportTabService();
        $tab = $service->buildTabFromEventRows('pixel_social', $this->sampleChannelRows());

        self::assertSame('pixel_social', $tab['code']);
        self::assertSame('channel_code', $tab['dimension']);
        self::assertSame(['traffic_type' => 'social'], $tab['filters']);
        self::assertSame('', $tab['error']);

        $byCode = [];
        foreach ($tab['rows'] as $row) {
            $byCode[(string)$row['dimension_value']] = $row;
        }

        // 只保留 traffic_type=social：wechat(3) + douyin(1)
        self::assertSame(['wechat', 'douyin'], array_keys($byCode));
        self::assertSame(3, $byCode['wechat']['events']);
        self::assertEqualsWithDelta(3.0, $byCode['wechat']['value_sum'], 0.0001);
        self::assertSame(1, $byCode['douyin']['events']);
        self::assertEqualsWithDelta(1.0, $byCode['douyin']['value_sum'], 0.0001);
        // paid / direct 不得进入 social Tab
        self::assertArrayNotHasKey('summer_sale', $byCode);
        self::assertArrayNotHasKey('brand_ads', $byCode);
        self::assertArrayNotHasKey('', $byCode);
    }

    public function testSocialTabFetchesHotRowsOnlyOnce(): void
    {
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));
        $sample = $this->sampleChannelRows();
        $calls = 0;

        $service = new PixelDetailReportTabService();
        $tab = $service->buildTab(
            'pixel_social',
            $from,
            $to,
            1,
            static function (array $ctx) use ($sample, &$calls): array {
                $calls++;
                TestCase::assertSame('channel_code', $ctx['dimension']);
                TestCase::assertSame(['traffic_type' => 'social'], $ctx['filters']);

                return $sample;
            },
            50,
            $now
        );

        self::assertSame(1, $calls);
        self::assertSame('', $tab['error']);
        self::assertSame('wechat', $tab['rows'][0]['dimension_value']);
        self::assertCount(2, $tab['rows']);
    }

    public function testBuildTabFromEventRowsHasTrafficTypeData(): void
    {
        $service = new PixelDetailReportTabService();
        $tab = $service->buildTabFromEventRows('pixel_traffic_type', $this->sampleChannelRows());

        self::assertSame('pixel_traffic_type', $tab['code']);
        self::assertSame('traffic_type', $tab['dimension']);
        self::assertSame('', $tab['error']);
        self::assertNotEmpty($tab['rows']);

        $byType = [];
        foreach ($tab['rows'] as $row) {
            $byType[(string)$row['dimension_value']] = $row;
        }

        self::assertSame(4, $byType['social']['events']);
        self::assertEqualsWithDelta(4.0, $byType['social']['value_sum'], 0.0001);
        self::assertSame(3, $byType['paid']['events']);
        self::assertEqualsWithDelta(88.0, $byType['paid']['value_sum'], 0.0001);
        self::assertSame(1, $byType['direct']['events']);
    }

    public function testEventValueTabGroupsByEventNameWithAvgValueFromD06(): void
    {
        $service = new PixelDetailReportTabService();
        $rows = $this->sampleChannelRows();
        $tab = $service->buildTabFromEventRows('pixel_event_value', $rows);

        self::assertSame('pixel_event_value', $tab['code']);
        self::assertSame('event_name', $tab['dimension']);
        self::assertSame([], $tab['filters']);
        self::assertSame('', $tab['error']);

        $byEvent = [];
        foreach ($tab['rows'] as $row) {
            $byEvent[(string)$row['dimension_value']] = $row;
        }

        self::assertSame(4, $byEvent['page_view']['events']);
        self::assertEqualsWithDelta(1.0, $byEvent['page_view']['value_sum'], 0.0001);
        self::assertEqualsWithDelta(0.25, $byEvent['page_view']['avg_value'], 0.0001);
        self::assertSame(1, $byEvent['purchase']['events']);
        self::assertEqualsWithDelta(88.0, $byEvent['purchase']['avg_value'], 0.0001);

        // 与 D06 事件价值服务同源：行内容一致（Tab 额外按事件数排序）
        $byDimension = static function (array $reportRows): array {
            $indexed = [];
            foreach ($reportRows as $row) {
                $indexed[(string)$row['dimension_value']] = $row;
            }
            ksort($indexed);

            return $indexed;
        };
        $d06Rows = (new PixelEventValueReportService())->aggregateEventRows($rows);
        self::assertSame($byDimension($d06Rows), $byDimension($tab['rows']));
    }

    public function testValueByChannelTabGroupsByChannelWithValueMetricsOnly(): void
    {
        $service = new PixelDetailReportTabService();
        $tab = $service->buildTabFromEventRows('pixel_value_by_channel', $this->sampleChannelRows());

        self::assertSame('pixel_value_by_channel', $tab['code']);
        self::assertSame('channel_code', $tab['dimension']);
        self::assertSame(['events', 'value_sum', 'valued_events'], $tab['metrics']);
        self::assertSame([], $tab['filters']);
        self::assertSame('', $tab['error']);

        $byCode = [];
        foreach ($tab['rows'] as $row) {
            $byCode[(string)$row['dimension_value']] = $row;
            // 渠道价值 Tab 不附 purchases/add_to_carts（catalog 指标更窄）
            self::assertArrayNotHasKey('purchases', $row);
            self::assertArrayNotHasKey('add_to_carts', $row);
            self::assertArrayNotHasKey('avg_value', $row);
        }

        self::assertSame(3, $byCode['wechat']['events']);
        self::assertEqualsWithDelta(3.0, $byCode['wechat']['value_sum'], 0.0001);
        self::assertSame(1, $byCode['wechat']['valued_events']);
        self::assertSame(2, $byCode['summer_sale']['events']);
        self::assertEqualsWithDelta(88.0, $byCode['summer_sale']['value_sum'], 0.0001);
        self::assertSame(1, $byCode['summer_sale']['valued_events']);
    }

    public function testBuildMountedTabsReturnsSixTabsWithData(): void
    {
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));
        $sample = $this->sampleChannelRows();
        $seen = [];

        $service = new PixelDetailReportTabService();
        $tabs = $service->buildMountedTabs(
            $from,
            $to,
            1,
            static function (array $ctx) use ($sample, &$seen): array {
                $seen[] = (string)$ctx['dimension'];
                TestCase::assertSame('hot', $ctx['route']['source']);

                return $sample;
            },
            50,
            $now
        );

        self::assertCount(6, $tabs);
        self::assertSame('pixel_channels', $tabs[0]['code']);
        self::assertSame('pixel_traffic_type', $tabs[1]['code']);
        self::assertSame('pixel_paid', $tabs[2]['code']);
        self::assertSame('pixel_social', $tabs[3]['code']);
        self::assertSame('pixel_event_value', $tabs[4]['code']);
        self::assertSame('pixel_value_by_channel', $tabs[5]['code']);
        self::assertSame('channel_code', $tabs[0]['dimension']);
        self::assertSame('traffic_type', $tabs[1]['dimension']);
        self::assertSame('utm_campaign', $tabs[2]['dimension']);
        self::assertSame('channel_code', $tabs[3]['dimension']);
        self::assertSame('event_name', $tabs[4]['dimension']);
        self::assertSame('channel_code', $tabs[5]['dimension']);
        foreach ($tabs as $tab) {
            self::assertNotEmpty($tab['rows'], $tab['code']);
            self::assertSame('', $tab['error'], $tab['code']);
        }
        self::assertSame(
            ['channel_code', 'traffic_type', 'utm_campaign', 'channel_code', 'event_name', 'channel_code'],
            $seen
        );
        // traffic_type：social(4) 排第一，paid(3) 次之
        self::assertSame('social', $tabs[1]['rows'][0]['dimension_value']);
        self::assertSame('paid', $tabs[1]['rows'][1]['dimension_value']);
        // paid Tab 只含付费 campaign
        self::assertSame('summer', $tabs[2]['rows'][0]['dimension_value']);
        self::assertCount(2, $tabs[2]['rows']);
        // social Tab 只含社媒 channel
        self::assertSame('wechat', $tabs[3]['rows'][0]['dimension_value']);
        self::assertCount(2, $tabs[3]['rows']);
        self::assertSame(['traffic_type' => 'social'], $tabs[3]['filters']);
        // event_value Tab 附派生均值
        self::assertSame('page_view', $tabs[4]['rows'][0]['dimension_value']);
        self::assertArrayHasKey('avg_value', $tabs[4]['rows'][0]);
        // value_by_channel：按渠道价值，指标更窄
        self::assertSame('wechat', $tabs[5]['rows'][0]['dimension_value']);
        self::assertSame(['events', 'value_sum', 'valued_events'], $tabs[5]['metrics']);
        self::assertArrayNotHasKey('avg_value', $tabs[5]['rows'][0]);
    }

    public function testLongRangeIsClampedToHotWindow(): void
    {
        $service = new PixelDetailReportTabService(router: new PixelQueryRouter(hotWindowDays: 7));
        $from = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));

        [$qFrom, $qTo, $clamped] = $service->clampToHotWindow($from, $to, $now);

        self::assertTrue($clamped);
        self::assertSame('2026-07-18 00:00:00', $qFrom->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-25 00:00:00', $qTo->format('Y-m-d H:i:s'));
    }

    public function testUnmountedCodeRejected(): void
    {
        $service = new PixelDetailReportTabService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report tab is not mounted');
        $service->buildTabFromEventRows('pixel_unknown_report', $this->sampleChannelRows());
    }

    public function testMissingProviderSurfacesErrorWithoutThrowing(): void
    {
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));

        $service = new PixelDetailReportTabService();
        $tab = $service->buildTab('pixel_traffic_type', $from, $to, 1, null, 50, $now);

        self::assertSame('pixel_traffic_type', $tab['code']);
        self::assertSame([], $tab['rows']);
        self::assertNotSame('', $tab['error']);
        self::assertStringContainsString('row provider', $tab['error']);
    }

    public function testDrilldownExtrasMapsDimensionToListFilter(): void
    {
        self::assertSame(
            ['channel_code' => 'wechat'],
            PixelDetailReportTabService::drilldownExtras('channel_code', 'wechat')
        );
        self::assertSame(
            ['traffic_type' => 'paid'],
            PixelDetailReportTabService::drilldownExtras('traffic_type', 'paid')
        );
        self::assertSame([], PixelDetailReportTabService::drilldownExtras('traffic_type', ''));
        self::assertSame([], PixelDetailReportTabService::drilldownExtras('unknown_dim', 'x'));
    }

    public function testDrilldownExtrasCarriesCatalogFiltersForPaidTab(): void
    {
        self::assertSame(
            ['traffic_type' => 'paid', 'utm_campaign' => 'summer'],
            PixelDetailReportTabService::drilldownExtras('utm_campaign', 'summer', ['traffic_type' => 'paid'])
        );
        // 未知 filter 字段被忽略，不污染下钻 query
        self::assertSame(
            ['channel_code' => 'wechat'],
            PixelDetailReportTabService::drilldownExtras('channel_code', 'wechat', ['unknown' => 'x'])
        );
    }

    public function testDrilldownExtrasCarriesCatalogFiltersForSocialTab(): void
    {
        self::assertSame(
            ['traffic_type' => 'social', 'channel_code' => 'wechat'],
            PixelDetailReportTabService::drilldownExtras('channel_code', 'wechat', ['traffic_type' => 'social'])
        );
    }
}
