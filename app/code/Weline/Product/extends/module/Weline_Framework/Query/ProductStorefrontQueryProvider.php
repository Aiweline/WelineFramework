<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Service\StorefrontCatalogViewService;

/**
 * Server-side storefront catalogue boundary for cross-module consumers.
 *
 * Website scope is always taken from RequestContext. Callers cannot select a
 * website or shard through query parameters.
 */
class ProductStorefrontQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'product_storefront';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'searchPublishedOffers' => $this->searchPublishedOffers($params),
            default => throw new \InvalidArgumentException((string)__(
                'Product Storefront 接口不支持操作：%{1}',
                [$operation],
            )),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'Product Storefront',
            'description' => 'Server-side read boundary for published storefront offers.',
            'module' => 'Weline_Product',
            'operations' => [[
                'name' => 'searchPublishedOffers',
                'frontend' => false,
                'external' => false,
                'mode' => 'read',
                'graph' => false,
                'cost' => 4,
                'params' => [
                    ['name' => 'keyword', 'type' => 'string', 'required' => false, 'max_length' => 200],
                    ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 48],
                ],
                'returns' => ['type' => 'array'],
                'summary' => 'Search and normalize current-scope published Product offers',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function searchPublishedOffers(array $params): array
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(48, (int)($params['page_size'] ?? 20)));
        $needle = $this->normalize($keyword);

        $matches = array_values(array_filter(
            $this->publishedOffers(),
            function (array $offer) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                return str_contains($this->normalize(implode(' ', [
                    (string)($offer['name'] ?? ''),
                    (string)($offer['sku'] ?? ''),
                ])), $needle);
            },
        ));

        $total = count($matches);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $pageOffers = array_slice($matches, $offset, $pageSize);
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn(array $offer): int => (int)($offer['product_id'] ?? 0),
            $pageOffers,
        ))));
        $media = $this->primaryMedia($productIds);

        $items = [];
        foreach ($pageOffers as $offer) {
            $productId = (int)($offer['product_id'] ?? 0);
            $sku = trim((string)($offer['sku'] ?? ''));
            $currency = trim((string)($offer['currency'] ?? ''));
            $price = ((int)($offer['unit_price_minor'] ?? 0)) / 100;
            $items[] = [
                'product_id' => $productId,
                'name' => (string)($offer['name'] ?? $sku),
                'sku' => $sku,
                'short_description' => $sku !== '' ? 'SKU: ' . $sku : '',
                'image' => trim((string)($offer['image'] ?? '')) !== ''
                    ? (string)$offer['image']
                    : (string)($media[$productId] ?? ''),
                'price' => $price,
                'currency' => $currency,
                'formatted_price' => trim($currency . ' ' . number_format((float)$price, 2)),
                'url' => 'products/',
                'source' => 'weline_product',
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'pages' => $pages,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $pageSize, $total),
            ],
            'pagination_html' => '',
            'facets' => [],
            'applied_filters' => [],
            'clear_all_url' => '/search',
            'engine' => 'weline_product',
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function publishedOffers(): array
    {
        return ObjectManager::getInstance(StorefrontCatalogViewService::class)
            ->publishedOffers(200);
    }

    /** @param list<int> $productIds @return array<int, string> */
    protected function primaryMedia(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $websiteId = max(0, RequestContext::getWelineWebsiteId());
        $rows = ObjectManager::getInstance(MediaRepository::class)
            ->listByProductIds($websiteId, $productIds);
        $primary = [];
        foreach ($rows as $row) {
            $productId = (int)($row[Media::schema_fields_PRODUCT_ID] ?? 0);
            $path = trim((string)($row[Media::schema_fields_PATH] ?? ''));
            if ($productId > 0 && $path !== '' && !isset($primary[$productId])) {
                $primary[$productId] = $path;
            }
        }

        return $primary;
    }

    private function normalize(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
