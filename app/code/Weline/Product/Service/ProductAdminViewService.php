<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\ProductCopyOperation;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;

/**
 * Read-only data adapter for Product backend management surfaces.
 */
final class ProductAdminViewService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly CategoryRepository $categories,
        private readonly MediaRepository $media,
        private readonly SkuRegistry $skuRegistry,
        private readonly ProductCopyOperation $copyOperations,
        private readonly ProductShardRegistry $shards,
    ) {
    }

    /**
     * @return array{rows:list<array<string,mixed>>,columns:list<string>}
     */
    public function load(string $section, int $websiteId): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能小于 0'));
        }

        try {
            $rows = match ($section) {
                'products' => $this->productsWithMainImage($websiteId),
                'offers' => $this->offersWithMainImage($websiteId),
                'sku-registry' => $this->modelRows($this->skuRegistry),
                'categories' => $this->categories->listAll($websiteId),
                'media' => $this->media->listByProductIds($websiteId, $this->productIds($websiteId)),
                'store-copy' => $this->modelRows($this->copyOperations),
                'shards' => $this->modelRows($this->shards),
                default => throw new \InvalidArgumentException(__('未知商品管理区段：%{1}', [$section])),
            };
        } catch (\RuntimeException) {
            $rows = [[
                'website_id' => $websiteId,
                'status' => 'unavailable',
                'message' => __('当前网站商品分片未就绪'),
            ]];
        }

        $rows = array_slice(array_map([$this, 'sanitizeRow'], $rows), 0, 100);

        return [
            'rows' => $rows,
            'columns' => $this->columns($rows, $section),
        ];
    }

    /**
     * Backend product edit workbench payload (identity + related offers/media).
     *
     * @return array{
     *   product:array<string,mixed>,
     *   main_image:string,
     *   offers:list<array<string,mixed>>,
     *   media:list<array<string,mixed>>
     * }|null
     */
    public function loadProductEdit(int $websiteId, int $productId): ?array
    {
        if ($websiteId < 0 || $productId <= 0) {
            return null;
        }

        $product = $this->products->findById($websiteId, $productId);
        if ($product === null) {
            return null;
        }

        $productRow = $this->sanitizeRow($product->getData());
        $productRow['product_id'] = $productId;
        $mainImages = $this->mainImagesByProductIds($websiteId, [$productId]);
        $mainImage = $mainImages[$productId] ?? '';

        $offers = [];
        foreach ($this->offers->listByProductIds($websiteId, [$productId]) as $offer) {
            $offers[] = $this->sanitizeRow($offer);
        }

        $mediaRows = [];
        foreach ($this->media->listByProductIds($websiteId, [$productId]) as $media) {
            $row = $this->sanitizeRow($media);
            $path = trim((string)($row[Media::schema_fields_PATH] ?? $row['path'] ?? ''));
            $row['display_url'] = $this->displayableImageUrl($path);
            $mediaRows[] = $row;
        }

        return [
            'product' => $productRow + ['main_image' => $mainImage],
            'main_image' => $mainImage,
            'offers' => $offers,
            'media' => $mediaRows,
        ];
    }

    /** @return list<int> */
    private function productIds(int $websiteId): array
    {
        $ids = [];
        foreach ($this->products->listAll($websiteId) as $row) {
            $id = (int)($row['product_id'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Attach the primary media path (lowest position) for admin product rows.
     *
     * @return list<array<string, mixed>>
     */
    private function productsWithMainImage(int $websiteId): array
    {
        $rows = $this->products->listAll($websiteId);

        return $this->attachMainImage($websiteId, $rows, 'product_id');
    }

    /**
     * Attach the related product primary media path for admin offer rows.
     *
     * @return list<array<string, mixed>>
     */
    private function offersWithMainImage(int $websiteId): array
    {
        $rows = $this->offers->listByProductIds($websiteId, $this->productIds($websiteId));

        return $this->attachMainImage($websiteId, $rows, 'product_id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachMainImage(int $websiteId, array $rows, string $productIdKey): array
    {
        $productIds = [];
        foreach ($rows as $row) {
            $id = (int)($row[$productIdKey] ?? $row['product_id'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $productIds[] = $id;
            }
        }

        $mainByProduct = $this->mainImagesByProductIds($websiteId, $productIds);
        $enriched = [];
        foreach ($rows as $row) {
            $id = (int)($row[$productIdKey] ?? $row['product_id'] ?? $row['id'] ?? 0);
            $enriched[] = ['main_image' => $mainByProduct[$id] ?? ''] + $row;
        }

        return $enriched;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function mainImagesByProductIds(int $websiteId, array $productIds): array
    {
        $mainByProduct = [];
        foreach ($this->media->listByProductIds($websiteId, array_values(array_unique($productIds))) as $media) {
            $productId = (int)($media[Media::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId <= 0 || isset($mainByProduct[$productId])) {
                continue;
            }
            $path = $this->displayableImageUrl(trim((string)($media[Media::schema_fields_PATH] ?? '')));
            if ($path !== '') {
                $mainByProduct[$productId] = $path;
            }
        }

        return $mainByProduct;
    }

    /**
     * Only absolute http(s)/data image URLs are safe to render without a theme media base.
     */
    private function displayableImageUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'data:image/')
        ) {
            return $path;
        }
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '://')) {
            return '';
        }
        $url = \Weline\FileManager\Api\Image::pathToMediaUrl($path, 64, 64);

        return is_string($url) && $url !== '' ? $url : '';
    }



    /** @return list<array<string,mixed>> */
    private function modelRows(object $model): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $model->clear()->select()->fetchArray();
        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function sanitizeRow(array $row): array
    {
        foreach ([
            'cas_token', 'claim_token', 'request_hash', 'draft_json', 'result_json',
        ] as $sensitive) {
            unset($row[$sensitive]);
        }
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_object($value)) {
                $row[$key] = $value instanceof \Stringable ? (string)$value : get_debug_type($value);
            }
        }

        return $row;
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function columns(array $rows, string $section = ''): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        if ($section === 'products') {
            $columns = array_values(array_filter(
                $columns,
                static fn(string $column): bool => !in_array($column, ['product_id', 'id'], true),
            ));
        }

        if (in_array($section, ['products', 'offers'], true) && in_array('main_image', $columns, true)) {
            $columns = array_values(array_unique(array_merge(
                ['main_image'],
                array_values(array_filter($columns, static fn(string $column): bool => $column !== 'main_image')),
            )));
        }

        return $columns;
    }
}
