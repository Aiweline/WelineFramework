<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Service\StorefrontCatalogViewService;

/**
 * Cross-module storefront product lookup boundary (provider name: product).
 *
 * Affiliate and other consumers call w_query('product', 'getProductByIds', …).
 * Offers come from the published catalog projection — not draft/admin shards.
 */
final class ProductQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'product';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getProductByIds' => $this->getProductByIds($params),
            default => throw new \InvalidArgumentException(
                'Product query provider does not support operation: ' . $operation
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'Product',
            'description' => 'Published product cards by ID for cross-module storefront consumers.',
            'module' => 'Weline_Product',
            'operations' => [
                [
                    'name' => 'getProductByIds',
                    'frontend' => true,
                    'external' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Return published product cards keyed by product_id',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function getProductByIds(array $params): array
    {
        $rawIds = $params['product_ids'] ?? [];
        if (!is_array($rawIds)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        /** @var StorefrontCatalogViewService $catalog */
        $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
        $limit = max(count($ids), 1);
        $offers = $catalog->publishedOffersForProductIds($ids, $limit, true);

        $byProduct = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $productId = (int)($offer['product_id'] ?? 0);
            if ($productId <= 0 || isset($byProduct[$productId])) {
                continue;
            }
            $slug = strtolower(trim((string)($offer['slug'] ?? '')));
            if ($slug === '' || preg_match('#^\d+$#', $slug) === 1) {
                $slug = $this->publicSlugFromSku((string)($offer['sku'] ?? ''));
            }
            $byProduct[$productId] = [
                'product_id' => $productId,
                'name' => (string)($offer['name'] ?? ''),
                'sku' => (string)($offer['sku'] ?? ''),
                'handle' => $slug,
                'slug' => $slug,
                'status' => Product::STATUS_PUBLISHED,
                'image' => (string)($offer['image'] ?? ''),
                'url' => $slug !== '' ? ('product/' . ltrim($slug, '/')) : '',
            ];
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byProduct[$id])) {
                $ordered[] = $byProduct[$id];
            }
        }

        return $ordered;
    }

    private function publicSlugFromSku(string $sku): string
    {
        $slug = strtolower(trim($sku));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
            return '';
        }

        return $slug;
    }
}
