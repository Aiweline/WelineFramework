<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\OptimizationListPaginator;

final class OptimizationListPaginatorTest extends TestCase
{
    public function testBuildsPaginationWithoutRequestUrlContext(): void
    {
        $result = (new OptimizationListPaginator())->page([
            ['id' => 20],
            ['id' => 19],
            ['id' => 18],
        ], 2, 2);

        self::assertSame([['id' => 20], ['id' => 19]], $result['items']);
        self::assertSame([
            'page' => 2,
            'page_size' => 2,
            'has_more' => true,
            'next_page' => 3,
            'previous_page' => 1,
        ], $result['pagination']);
    }
}
