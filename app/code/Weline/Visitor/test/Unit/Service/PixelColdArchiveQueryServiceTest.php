<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DomainException;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelColdArchiveQueryService;

/**
 * G09：冷查强约束（无站/超窗拒绝；website_id=0 合法；强制分页）。
 */
final class PixelColdArchiveQueryServiceTest extends TestCase
{
    private PixelColdArchiveQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelColdArchiveQueryService();
    }

    public function testRejectsMissingWebsite(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cold archive query requires website_id');
        $this->service->normalizeQuery(['range' => '7d'], 1, 50);
    }

    public function testRejectsAllWebsiteAlias(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cold archive query requires website_id');
        $this->service->normalizeQuery(['websiteId' => 'all', 'range' => '7d'], 1, 50);
    }

    public function testAcceptsWebsiteIdZeroAsSystemDefault(): void
    {
        $normalized = $this->service->normalizeQuery([
            'website_id' => 0,
            'range' => '7d',
        ], 1, 50);

        self::assertSame(0, $normalized['website_id']);
        self::assertSame('0', $normalized['website_id_raw']);
        self::assertSame(7, $normalized['day_count']);
        self::assertSame(1, $normalized['page']);
        self::assertSame(50, $normalized['page_size']);
    }

    public function testRejectsNinetyDayPreset(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cold archive query window exceeds 31 days');
        $this->service->normalizeQuery([
            'websiteId' => 1,
            'range' => '90d',
        ], 1, 50);
    }

    public function testRejectsCustomWindowOver31Days(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cold archive query window exceeds 31 days');
        $this->service->normalizeQuery([
            'websiteId' => 2,
            'range' => 'custom',
            'startDate' => '2026-01-01',
            'endDate' => '2026-02-05',
        ], 1, 50);
    }

    public function testAcceptsExactly31DayWindow(): void
    {
        $normalized = $this->service->normalizeQuery([
            'websiteId' => 3,
            'range' => 'custom',
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31',
        ], 2, 20);

        self::assertSame(31, $normalized['day_count']);
        self::assertSame(3, $normalized['website_id']);
        self::assertSame(2, $normalized['page']);
        self::assertSame(20, $normalized['page_size']);
    }

    public function testPaginationClampedAndQueryPageUsesLoader(): void
    {
        [$page, $pageSize] = $this->service->normalizePagination(0, 9999);
        self::assertSame(1, $page);
        self::assertSame(PixelColdArchiveQueryService::MAX_PAGE_SIZE, $pageSize);

        $result = $this->service->queryPage(
            ['websiteId' => 1, 'range' => '7d'],
            1,
            50,
            static function (array $normalized): array {
                self::assertSame(1, $normalized['website_id']);
                self::assertLessThanOrEqual(PixelColdArchiveQueryService::MAX_WINDOW_DAYS, $normalized['day_count']);

                return [
                    'total' => 1,
                    'rows' => [[
                        'pixel_id' => 9,
                        'website_id' => 1,
                        'event' => 'page_view',
                        'created_at' => '2024-01-01 00:00:00',
                    ]],
                ];
            }
        );

        self::assertSame('', $result['error']);
        self::assertSame('cold', $result['source']);
        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['page_count']);
        self::assertSame(9, $result['rows'][0]['pixel_id']);
    }

    public function testQueryPageReturnsErrorWithoutThrowingOnMissingWebsite(): void
    {
        $result = $this->service->queryPage(['range' => '7d'], 1, 50);
        self::assertSame('cold archive query requires website_id', $result['error']);
        self::assertSame([], $result['rows']);
        self::assertSame('cold', $result['source']);
    }
}
