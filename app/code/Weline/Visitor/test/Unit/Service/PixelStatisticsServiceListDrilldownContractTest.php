<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * C07：下钻 URL 契约 + 与 list 抽检（参数一致）。
 */
class PixelStatisticsServiceListDrilldownContractTest extends TestCore
{
    public function testListDrilldownQueryKeysAreStable(): void
    {
        self::assertSame([
            'websiteId',
            'event',
            'range',
            'startDate',
            'endDate',
            'channel_code',
            'traffic_type',
            'utm_source',
            'utm_medium',
            'utm_campaign',
        ], PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS);
    }

    public function testBuildQueryOnlyEmitsContractKeys(): void
    {
        $query = PixelStatisticsService::buildListDrilldownQuery([
            'websiteId' => '9',
            'event' => 'purchase',
            'range' => '7d',
            'channel_code' => 'summer',
            'traffic_type' => PixelChannel::TRAFFIC_PAID,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
            'page' => '2', // 非契约键，不得泄漏
            'foo' => 'bar',
        ]);

        foreach (\array_keys($query) as $key) {
            self::assertContains($key, PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS);
        }
        self::assertArrayNotHasKey('page', $query);
        self::assertArrayNotHasKey('foo', $query);
        self::assertSame('summer', $query['channel_code']);
        self::assertSame(PixelChannel::TRAFFIC_PAID, $query['traffic_type']);
    }

    public function testListFormAndControllerAcceptAllContractKeys(): void
    {
        $listTpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/list.phtml'
        );
        $controller = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Controller/Backend/PixelDashboard.php'
        );
        $filterStart = strpos($controller, 'function getDashboardRequestFilters');
        self::assertNotFalse($filterStart);
        $filterChunk = substr($controller, (int)$filterStart, 1500);

        foreach (PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS as $key) {
            self::assertStringContainsString('name="' . $key . '"', $listTpl, "list 表单缺少 {$key}");
            self::assertStringContainsString("'{$key}'", $filterChunk, "控制器未透传 {$key}");
        }
    }

    public function testDrilldownQueryRoundTripsThroughNormalize(): void
    {
        $query = PixelStatisticsService::buildListDrilldownQuery([
            'websiteId' => '42',
            'event' => 'page_view',
            'range' => '7d',
            'channel_code' => 'summer_sale',
            'traffic_type' => PixelChannel::TRAFFIC_SOCIAL,
            'utm_source' => 'meta',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'july',
        ]);

        $normalized = PixelStatisticsService::normalizeDashboardFilters($query);

        self::assertSame(42, $normalized['website_id']);
        self::assertSame('42', $normalized['website_id_raw']);
        self::assertSame('page_view', $normalized['event']);
        self::assertSame('7d', $normalized['range']);
        self::assertSame('summer_sale', $normalized['channel_code']);
        self::assertSame(PixelChannel::TRAFFIC_SOCIAL, $normalized['traffic_type']);
        self::assertSame('meta', $normalized['utm_source']);
        self::assertSame('paid_social', $normalized['utm_medium']);
        self::assertSame('july', $normalized['utm_campaign']);
    }

    public function testAllDrilldownSurfacesUseSharedBuilder(): void
    {
        $surfaces = [
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/index.phtml',
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/detail.phtml',
            BP . '/app/code/Weline/Visitor/view/templates/Backend/TrafficChannel/detail.phtml',
        ];
        foreach ($surfaces as $path) {
            $src = (string)\file_get_contents($path);
            self::assertStringContainsString(
                'buildListDrilldownQuery',
                $src,
                basename(dirname($path)) . '/' . basename($path) . ' 须走统一下钻构造器'
            );
            self::assertStringContainsString('pixel-dashboard/list', $src);
        }
    }

    public function testTrafficChannelDetailLinksListWithChannelCode(): void
    {
        $tpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/TrafficChannel/detail.phtml'
        );
        self::assertStringContainsString("'channel_code' => \$channelCode", $tpl);
        self::assertStringContainsString('像素事件列表', $tpl);
        self::assertStringContainsString('LIST_DRILLDOWN_QUERY_KEYS', (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        ));
    }

    public function testListSpotCheckChannelFilterReducesOrEqualsTotalWhenQueryable(): void
    {
        $base = PixelStatisticsService::getDashboardEventListPage(['range' => '7d'], 1, 20);
        if ($base['error'] !== '') {
            self::assertTrue(true, '热表未就绪，跳过抽检：' . $base['error']);

            return;
        }

        $channels = PixelStatisticsService::getDashboardChannelRows(['range' => '7d'], 5);
        if ($channels === []) {
            // 无 channel_code 时用不可能命中的码抽检契约仍可走通
            $query = PixelStatisticsService::buildListDrilldownQuery(['range' => '7d'], [
                'channel_code' => '__c07_no_such_channel__',
            ]);
            $filtered = PixelStatisticsService::getDashboardEventListPage($query, 1, 20);
            if ($filtered['error'] !== '') {
                self::assertStringContainsString('setup:upgrade', $filtered['error']);

                return;
            }
            self::assertSame(0, $filtered['total']);
            self::assertSame('__c07_no_such_channel__', $filtered['filters']['channel_code'] ?? null);

            return;
        }

        $code = (string)($channels[0]['channel_code'] ?? '');
        self::assertNotSame('', $code);
        $query = PixelStatisticsService::buildListDrilldownQuery(['range' => '7d'], [
            'channel_code' => $code,
            'traffic_type' => (string)($channels[0]['traffic_type'] ?? ''),
        ]);
        $filtered = PixelStatisticsService::getDashboardEventListPage($query, 1, 20);
        if ($filtered['error'] !== '') {
            self::assertStringContainsString('setup:upgrade', $filtered['error']);

            return;
        }

        self::assertSame($code, $filtered['filters']['channel_code'] ?? null);
        self::assertLessThanOrEqual($base['total'], $filtered['total']);
        foreach ($filtered['rows'] as $row) {
            self::assertSame($code, (string)($row['channel_code'] ?? ''));
        }
    }
}
