<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * C04：导出含归因列，且与 list 共用筛选/WHERE。
 */
class PixelStatisticsServiceExportAttributionTest extends TestCore
{
    public function testExportColumnsContainAttributionFields(): void
    {
        $columns = PixelStatisticsService::EXPORT_COLUMNS;

        foreach ([
            'pixel_id',
            'created_at',
            'website_id',
            'event',
            'session_id',
            'channel_code',
            'channel_name',
            'traffic_type',
            'utm_source',
            'utm_medium',
            'utm_campaign',
        ] as $column) {
            self::assertContains($column, $columns, $column . ' 必须在导出列内');
        }

        // 旧导出字段保持兼容
        foreach (['url', 'referer', 'source', 'value', 'currency', 'browser_info', 'cron_deal'] as $column) {
            self::assertContains($column, $columns, $column . ' 不得从导出中丢失');
        }

        self::assertSame($columns, \array_values(\array_unique($columns)), '导出列不得重复');
        self::assertSame(10000, PixelStatisticsService::EXPORT_MAX_ROWS);
    }

    public function testExportReturnsShapeAndRespectsColumnOrder(): void
    {
        $result = PixelStatisticsService::getDashboardEventExportRows(['range' => '7d'], 50);

        self::assertArrayHasKey('rows', $result);
        self::assertArrayHasKey('columns', $result);
        self::assertArrayHasKey('filters', $result);
        self::assertArrayHasKey('error', $result);
        self::assertSame(PixelStatisticsService::EXPORT_COLUMNS, $result['columns']);

        if ($result['error'] === '' && $result['rows'] !== []) {
            $first = $result['rows'][0];
            self::assertSame(
                PixelStatisticsService::EXPORT_COLUMNS,
                \array_keys($first),
                '导出行键顺序须与 EXPORT_COLUMNS 一致'
            );
            self::assertLessThanOrEqual(50, \count($result['rows']));
        }
    }

    public function testExportRejectsInvalidFiltersWithoutThrowing(): void
    {
        $result = PixelStatisticsService::getDashboardEventExportRows([
            'range' => '7d',
            'traffic_type' => 'not_a_real_type',
        ]);

        self::assertNotSame('', $result['error']);
        self::assertSame([], $result['rows']);
        self::assertSame([], $result['filters']);
    }

    public function testExportSharesDashboardWhereWithList(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        );
        $start = strpos($src, 'function getDashboardEventExportRows');
        self::assertNotFalse($start);
        $chunk = substr($src, (int)$start, 5000);

        self::assertStringContainsString('buildDashboardWhere', $chunk);
        self::assertStringContainsString('hasPixelAttributionColumns', $chunk);
        self::assertStringContainsString('PixelAttributionRowResolver', $chunk);
        self::assertStringContainsString('EXPORT_COLUMNS', $chunk);
    }

    public function testExportControllerAndListUiWireExport(): void
    {
        $controller = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Controller/Backend/PixelDashboard.php'
        );
        self::assertStringContainsString('getDashboardEventExportRows', $controller);
        self::assertStringContainsString('getDashboardRequestFilters', $controller);
        // 不再逐列裸查模型
        self::assertStringNotContainsString("\$defaultHeaders", $controller);

        $listTpl = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/view/templates/Backend/PixelDashboard/list.phtml'
        );
        self::assertStringContainsString('pixel-dashboard/export', $listTpl);
        self::assertStringContainsString("'format' => 'csv'", $listTpl);
        self::assertStringContainsString('$queryBase', $listTpl);
    }
}
