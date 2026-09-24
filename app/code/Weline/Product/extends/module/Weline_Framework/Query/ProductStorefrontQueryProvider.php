<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Api\ProductQuoteRequestSubmitInterface;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Service\ProductCardRenderer;
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
            'cardsByProductIds' => $this->cardsByProductIds($params),
            'liveOffersByProductIds' => $this->liveOffersByProductIds($params),
            'submitQuoteRequest' => $this->submitQuoteRequest($params),
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
            'description' => 'Server-side read/write boundary for published storefront offers and quote requests.',
            'module' => 'Weline_Product',
            'operations' => [
                [
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
                ],
                [
                    'name' => 'cardsByProductIds',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 24],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Batch storefront cards for explicit product IDs (targeted catalog; no live N+1)',
                ],
                [
                    'name' => 'liveOffersByProductIds',
                    'frontend' => false,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => '按当前范围批量读取实时商品首个已发布 Offer，以商品 ID 为键；每批最多 100 个商品',
                ],
                [
                    'name' => 'submitQuoteRequest',
                    'frontend' => true,
                    'external' => false,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'product_id', 'type' => 'int', 'required' => true],
                        ['name' => 'sku', 'type' => 'string', 'required' => true],
                        ['name' => 'idempotency_key', 'type' => 'string', 'required' => true],
                        ['name' => 'contact_name', 'type' => 'string', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit a Product-owned quote_only inquiry',
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function liveOffersByProductIds(array $params): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval', is_array($params['product_ids'] ?? null) ? $params['product_ids'] : [],
        ), static fn(int $id): bool => $id > 0)));
        $byId = [];
        foreach (array_chunk($ids, 100) as $batch) {
            foreach ($this->livePublishedOffers($batch) as $offer) {
                $id = (int)($offer['product_id'] ?? 0);
                if (in_array($id, $batch, true) && !isset($byId[$id])) {
                    $byId[$id] = $offer;
                }
            }
        }
        return $byId;
    }

    /** @return list<array<string, mixed>> */
    protected function livePublishedOffers(array $productIds): array
    {
        return ObjectManager::getInstance(StorefrontCatalogViewService::class)
            ->livePublishedOffersForProductIds($productIds);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function cardsByProductIds(array $params): array
    {
        $rawIds = $params['product_ids'] ?? [];
        if (!\is_array($rawIds)) {
            $rawIds = [];
        }
        $orderedIds = [];
        foreach ($rawIds as $rawId) {
            $productId = max(0, (int)$rawId);
            if ($productId <= 0 || isset($orderedIds[$productId])) {
                continue;
            }
            $orderedIds[$productId] = $productId;
        }
        $orderedIds = \array_values($orderedIds);
        $limit = max(1, min(24, (int)($params['limit'] ?? \count($orderedIds))));
        if ($orderedIds === []) {
            return [];
        }
        $orderedIds = \array_slice($orderedIds, 0, $limit);

        $offersByProductId = [];
        foreach ($this->targetedPublishedOffers($orderedIds) as $offer) {
            if (!\is_array($offer)) {
                continue;
            }
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($productId <= 0 || isset($offersByProductId[$productId])) {
                continue;
            }
            $offersByProductId[$productId] = $this->normalizeOfferSlug($offer);
        }

        $cards = [];
        foreach ($orderedIds as $productId) {
            $offer = $offersByProductId[$productId] ?? null;
            if (!\is_array($offer)) {
                continue;
            }
            $card = $this->renderCard($offer, \count($cards));
            if ((int)($card['id'] ?? 0) <= 0) {
                continue;
            }
            $cards[] = $card;
            if (\count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    protected function renderCard(array $offer, int $index): array
    {
        return ProductCardRenderer::fromStorefrontOffer($offer, $index);
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    protected function targetedPublishedOffers(array $productIds): array
    {
        return ObjectManager::getInstance(StorefrontCatalogViewService::class)
            ->publishedOffersForProductIds($productIds, max(\count($productIds), 1), false);
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeOfferSlug(array $offer): array
    {
        $slug = \strtolower(\trim((string)($offer['slug'] ?? '')));
        if ($slug === '') {
            $slug = \strtolower(\trim((string)($offer['source_slug'] ?? '')));
        }
        if ($slug !== '' && \preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) === 1) {
            $offer['slug'] = $slug;
        }

        return $offer;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function submitQuoteRequest(array $params): array
    {
        /** @var ProductQuoteRequestSubmitInterface $service */
        $service = ObjectManager::getInstance(ProductQuoteRequestSubmitInterface::class);

        return $service->submit($params);
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
