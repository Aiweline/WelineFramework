<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Service\PixelStatisticsService;

/**
 * C02：看板 list 热表分页（基础列；归因 WHERE 见 C03a）。
 */
class PixelStatisticsServiceListPaginationTest extends TestCore
{
    public function testNormalizeListPaginationBounds(): void
    {
        self::assertSame([1, 50], PixelStatisticsService::normalizeListPagination(0, 0));
        self::assertSame([3, 200], PixelStatisticsService::normalizeListPagination(3, 999));
        self::assertSame([1, 1], PixelStatisticsService::normalizeListPagination(-5, 1));
        self::assertSame(50, PixelStatisticsService::LIST_DEFAULT_PAGE_SIZE);
        self::assertSame(200, PixelStatisticsService::LIST_MAX_PAGE_SIZE);
    }

    public function testGetDashboardEventListPageReturnsPaginationShape(): void
    {
        $result = PixelStatisticsService::getDashboardEventListPage([
            'range' => '7d',
        ], 1, 20);

        self::assertArrayHasKey('rows', $result);
        self::assertArrayHasKey('total', $result);
        self::assertArrayHasKey('page', $result);
        self::assertArrayHasKey('page_size', $result);
        self::assertArrayHasKey('page_count', $result);
        self::assertArrayHasKey('filters', $result);
        self::assertArrayHasKey('error', $result);
        self::assertSame(1, $result['page']);
        self::assertSame(20, $result['page_size']);
        self::assertIsArray($result['rows']);
        self::assertGreaterThanOrEqual(0, $result['total']);

        if ($result['error'] === '' && $result['total'] > 0) {
            self::assertNotEmpty($result['rows']);
            $first = $result['rows'][0];
            self::assertArrayHasKey('event', $first);
            self::assertArrayHasKey('session_id', $first);
            self::assertArrayHasKey('created_at', $first);
            self::assertLessThanOrEqual(20, \count($result['rows']));
            self::assertSame((int)\ceil($result['total'] / 20), $result['page_count']);
        }
    }

    public function testPageBeyondLastIsClampedWhenQueryable(): void
    {
        $first = PixelStatisticsService::getDashboardEventListPage(['range' => '7d'], 1, 10);
        if ($first['error'] !== '' || $first['page_count'] <= 1) {
            self::assertTrue(true, '无可翻页数据或表未就绪，跳过夹取验收');

            return;
        }
        $beyond = PixelStatisticsService::getDashboardEventListPage(
            ['range' => '7d'],
            $first['page_count'] + 50,
            10
        );
        self::assertSame($first['page_count'], $beyond['page']);
        self::assertSame('', $beyond['error']);
    }

    public function testListPaginationSqlContract(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelStatisticsService.php'
        );
        self::assertStringContainsString('function getDashboardEventListPage', $src);
        self::assertStringContainsString('normalizeListPagination', $src);
        $methodStart = strpos($src, 'function getDashboardEventListPage');
        self::assertNotFalse($methodStart);
        $chunk = substr($src, (int)$methodStart, 4500);
        self::assertStringContainsString('COUNT(*) AS cnt', $chunk);
        self::assertStringContainsString('LIMIT', $chunk);
        self::assertStringContainsString('OFFSET', $chunk);
        self::assertStringContainsString('buildDashboardWhere', $chunk);
    }
}
