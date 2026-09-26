<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Storefront listing pager: sliding window + ellipsis so large page counts
 * do not emit hundreds of numbered links.
 *
 * Prev/next labels stay Chinese source strings. Templates must resolve them
 * with WidgetI18n::label at render time — controller-time __() can lag path locale.
 */
final class StorefrontListingPager
{
    public const DEFAULT_WINDOW = 5;

    public function __construct(
        private readonly StorefrontCategoryListingFilter $listingFilter = new StorefrontCategoryListingFilter(),
    ) {
    }

    /**
     * @param array<string, scalar|null> $baseParams Query params shared by every page link (price/sort/…).
     * @param callable(int):array<string, scalar|null>|null $paramsForPage
     *        Optional override; default merges page into $baseParams (omit page=1).
     * @return list<array{
     *     type:string,
     *     page?:int,
     *     url?:string,
     *     selected?:bool,
     *     disabled?:bool,
     *     label?:string
     * }>
     */
    public function buildPageOptions(
        string $baseUrl,
        int $currentPage,
        int $totalPages,
        array $baseParams = [],
        int $window = self::DEFAULT_WINDOW,
        bool $includePrevNext = true,
        ?callable $paramsForPage = null,
    ): array {
        $totalPages = max(1, $totalPages);
        $currentPage = max(1, min($currentPage, $totalPages));
        $window = max(1, $window);

        if ($totalPages <= 1) {
            return [];
        }

        $resolveParams = $paramsForPage ?? static function (int $page) use ($baseParams): array {
            $params = $baseParams;
            if ($page > 1) {
                $params['page'] = $page;
            } else {
                unset($params['page']);
            }

            return $params;
        };

        $pageItem = function (int $page) use ($baseUrl, $currentPage, $resolveParams): array {
            return [
                'type' => 'page',
                'page' => $page,
                'url' => $this->listingFilter->buildListingUrl($baseUrl, $resolveParams($page)),
                'selected' => $page === $currentPage,
                'label' => (string)$page,
            ];
        };

        $options = [];

        if ($includePrevNext) {
            $prev = max(1, $currentPage - 1);
            $options[] = [
                'type' => 'prev',
                'page' => $prev,
                'url' => $this->listingFilter->buildListingUrl($baseUrl, $resolveParams($prev)),
                'disabled' => $currentPage <= 1,
                'label' => '上一页',
            ];
        }

        // Small totals: show every page (no ellipsis noise).
        if ($totalPages <= $window + 2) {
            for ($p = 1; $p <= $totalPages; $p++) {
                $options[] = $pageItem($p);
            }
        } else {
            $half = (int)floor($window / 2);
            $start = max(1, $currentPage - $half);
            $end = min($totalPages, $start + $window - 1);
            if ($end - $start + 1 < $window) {
                $start = max(1, $end - $window + 1);
            }

            // Keep first/last anchors outside the sliding window.
            if ($start > 1) {
                $options[] = $pageItem(1);
                if ($start > 2) {
                    $options[] = [
                        'type' => 'ellipsis',
                        'label' => '…',
                    ];
                }
            }

            for ($p = $start; $p <= $end; $p++) {
                $options[] = $pageItem($p);
            }

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) {
                    $options[] = [
                        'type' => 'ellipsis',
                        'label' => '…',
                    ];
                }
                $options[] = $pageItem($totalPages);
            }
        }

        if ($includePrevNext) {
            $next = min($totalPages, $currentPage + 1);
            $options[] = [
                'type' => 'next',
                'page' => $next,
                'url' => $this->listingFilter->buildListingUrl($baseUrl, $resolveParams($next)),
                'disabled' => $currentPage >= $totalPages,
                'label' => '下一页',
            ];
        }

        return $options;
    }
}
