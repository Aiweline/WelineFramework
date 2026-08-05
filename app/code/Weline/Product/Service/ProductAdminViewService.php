<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\ProductCopyOperation;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\SkuRegistry;
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
                'products' => $this->products->listAll($websiteId),
                'offers' => $this->offers->listByProductIds($websiteId, $this->productIds($websiteId)),
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
            'columns' => $this->columns($rows),
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
    private function columns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }
}
