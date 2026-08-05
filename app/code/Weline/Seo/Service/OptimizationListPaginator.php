<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/**
 * Builds transport-only pagination. It deliberately has no request, URL or
 * form-renderer dependency so CLI and scheduler callers are safe.
 */
final class OptimizationListPaginator
{
    /**
     * The input contains one extra look-ahead record from the database.
     *
     * @param list<array<string,mixed>> $items
     * @return array{items:list<array<string,mixed>>,pagination:array<string,int|bool|null>}
     */
    public function page(array $items, int $page, int $pageSize): array
    {
        $page = \max(1, $page);
        $pageSize = \max(1, $pageSize);
        $hasMore = \count($items) > $pageSize;

        return [
            'items' => \array_values(\array_slice($items, 0, $pageSize)),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $page + 1 : null,
                'previous_page' => $page > 1 ? $page - 1 : null,
            ],
        ];
    }
}
