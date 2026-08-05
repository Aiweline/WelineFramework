<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Websites\Extends\Module\Weline_Framework\Query\WebsitesQueryProvider;

final class WebsitesQueryProviderPaginationTest extends TestCase
{
    public function testTwoThousandRowsUseTwoOrmSafePages(): void
    {
        $calls = [];
        $rows = $this->fetch(2000, static function (int $page, int $pageSize) use (&$calls): array {
            $calls[] = [$page, $pageSize];
            return \array_fill(0, $pageSize, ['page' => $page]);
        });

        self::assertSame([[1, 1000], [2, 1000]], $calls);
        self::assertCount(2000, $rows);
    }

    public function testRemainderUsesOnlyRequestedPageSize(): void
    {
        $calls = [];
        $rows = $this->fetch(1500, static function (int $page, int $pageSize) use (&$calls): array {
            $calls[] = [$page, $pageSize];
            return \array_fill(0, $pageSize, ['page' => $page]);
        });

        self::assertSame([[1, 1000], [2, 500]], $calls);
        self::assertCount(1500, $rows);
    }

    public function testShortPageTerminatesWithoutAnExtraQuery(): void
    {
        $calls = [];
        $rows = $this->fetch(2000, static function (int $page, int $pageSize) use (&$calls): array {
            $calls[] = [$page, $pageSize];
            return \array_fill(0, 37, ['page' => $page]);
        });

        self::assertSame([[1, 1000]], $calls);
        self::assertCount(37, $rows);
    }

    /**
     * @param callable(int, int): array $fetchPage
     * @return array<int, mixed>
     */
    private function fetch(int $limit, callable $fetchPage): array
    {
        $method = new ReflectionMethod(WebsitesQueryProvider::class, 'fetchRowsInBoundedPages');
        return $method->invoke(null, $limit, $fetchPage);
    }
}
