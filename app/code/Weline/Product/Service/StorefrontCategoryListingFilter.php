<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Theme\Helper\WidgetI18n;

/**
 * Category storefront listing: price buckets + sort applied to offer rows.
 */
final class StorefrontCategoryListingFilter
{
    public const PRICE_0_99 = '0-99';
    public const PRICE_100_299 = '100-299';
    public const PRICE_300_UP = '300-up';

    public const SORT_DEFAULT = 'default';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORT_NAME_ASC = 'name_asc';

    /**
     * @return list<array{code:string,label:string,min:int,max:int|null}>
     */
    public function priceBuckets(): array
    {
        return [
            [
                'code' => self::PRICE_0_99,
                'label' => WidgetI18n::label('0 - 99 元'),
                'min' => 0,
                'max' => 9900,
            ],
            [
                'code' => self::PRICE_100_299,
                'label' => WidgetI18n::label('100 - 299 元'),
                'min' => 10000,
                'max' => 29900,
            ],
            [
                'code' => self::PRICE_300_UP,
                'label' => WidgetI18n::label('300 元以上'),
                'min' => 30000,
                'max' => null,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<array{code:string,label:string,min:int,max:int|null,count:int}>
     */
    public function priceBucketsWithCounts(array $offers): array
    {
        $buckets = [];
        foreach ($this->priceBuckets() as $bucket) {
            $bucket['count'] = 0;
            $buckets[] = $bucket;
        }

        foreach ($offers as $offer) {
            if (!empty($offer['quote_only'])) {
                continue;
            }
            $minor = max(0, (int)($offer['unit_price_minor'] ?? 0));
            foreach ($buckets as &$bucket) {
                $max = $bucket['max'];
                if ($minor >= $bucket['min'] && ($max === null || $minor <= $max)) {
                    $bucket['count']++;
                    break;
                }
            }
            unset($bucket);
        }

        return $buckets;
    }

    public function normalizePriceBucket(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $allowed = [
            self::PRICE_0_99,
            self::PRICE_100_299,
            self::PRICE_300_UP,
        ];

        return in_array($raw, $allowed, true) ? $raw : '';
    }

    public function normalizeSort(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $allowed = [
            self::SORT_DEFAULT,
            self::SORT_PRICE_ASC,
            self::SORT_PRICE_DESC,
            self::SORT_NAME_ASC,
        ];

        return in_array($raw, $allowed, true) ? $raw : self::SORT_DEFAULT;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public function apply(array $offers, string $priceBucket, string $sort): array
    {
        $priceBucket = $this->normalizePriceBucket($priceBucket);
        $sort = $this->normalizeSort($sort);

        if ($priceBucket !== '') {
            $matched = null;
            foreach ($this->priceBuckets() as $bucket) {
                if ($bucket['code'] === $priceBucket) {
                    $matched = $bucket;
                    break;
                }
            }
            if ($matched !== null) {
                $offers = array_values(array_filter(
                    $offers,
                    static function (array $offer) use ($matched): bool {
                        if (!empty($offer['quote_only'])) {
                            return false;
                        }
                        $minor = max(0, (int)($offer['unit_price_minor'] ?? 0));
                        $max = $matched['max'];

                        return $minor >= $matched['min'] && ($max === null || $minor <= $max);
                    }
                ));
            }
        }

        if ($sort === self::SORT_PRICE_ASC || $sort === self::SORT_PRICE_DESC) {
            $dir = $sort === self::SORT_PRICE_ASC ? 1 : -1;
            usort(
                $offers,
                static function (array $a, array $b) use ($dir): int {
                    $pa = max(0, (int)($a['unit_price_minor'] ?? 0));
                    $pb = max(0, (int)($b['unit_price_minor'] ?? 0));

                    return ($pa <=> $pb) * $dir;
                }
            );
        } elseif ($sort === self::SORT_NAME_ASC) {
            usort(
                $offers,
                static function (array $a, array $b): int {
                    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                }
            );
        }

        return array_values($offers);
    }

    public function defaultPageSize(): int
    {
        return 24;
    }

    public function normalizePage(int|string|null $raw): int
    {
        $page = (int)$raw;

        return $page > 0 ? $page : 1;
    }

    public function normalizePageSize(int|string|null $raw): int
    {
        $size = (int)$raw;
        if ($size <= 0) {
            $size = $this->defaultPageSize();
        }

        return max(1, min(48, $size));
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array{
     *     items:list<array<string,mixed>>,
     *     page:int,
     *     page_size:int,
     *     total:int,
     *     total_pages:int
     * }
     */
    public function paginate(array $offers, int|string|null $page, int|string|null $pageSize = null): array
    {
        $page = $this->normalizePage($page);
        $pageSize = $this->normalizePageSize($pageSize);
        $total = count($offers);
        $totalPages = max(1, (int)ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $pageSize;

        return [
            'items' => array_slice(array_values($offers), $offset, $pageSize),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function buildListingUrl(string $categoryUrl, array $params): string
    {
        $base = trim($categoryUrl);
        if ($base === '') {
            $base = '/categories';
        }
        if (!preg_match('#^https?://#i', $base) && ($base === '' || $base[0] !== '/')) {
            $base = '/' . ltrim($base, '/');
        }

        $query = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $text = trim((string)$value);
            if ($text === '' || ($key === 'sort' && $text === self::SORT_DEFAULT)) {
                continue;
            }
            if ($key === 'page' && (int)$text <= 1) {
                continue;
            }
            $query[$key] = $text;
        }

        if ($query === []) {
            return $base;
        }

        return $base . '?' . http_build_query($query);
    }
}
