<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\WeShop_Search\Document;

use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use WeShop\Search\Api\SearchDocumentProviderInterface;

class ProductDocumentProvider implements SearchDocumentProviderInterface
{
    public function __construct(
        private readonly Product $productModel,
        private readonly ProductCategory $productCategory,
        private readonly PriceService $priceService
    ) {
    }

    public function getProviderCode(): string
    {
        return 'product';
    }

    public function getDocumentType(): string
    {
        return 'product';
    }

    public function getBatchDocuments(int $page = 1, int $pageSize = 100): array
    {
        $product = clone $this->productModel;
        $product->clear()->pagination(max(1, $page), max(1, $pageSize));

        $documents = [];
        foreach ($product->select()->fetchArray() as $item) {
            $document = $this->buildDocument($item);
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function getDocumentByEntityId(int|string $entityId): ?array
    {
        $productId = (int) $entityId;
        if ($productId <= 0) {
            return null;
        }

        $product = clone $this->productModel;
        $product->load($productId);
        if (!$product->getId()) {
            return null;
        }

        return $this->buildDocument($product->getData());
    }

    public function getDocumentId(int|string $entityId): string
    {
        return 'product_' . (int) $entityId;
    }

    public function getIndexConfiguration(): array
    {
        return [
            'searchable_fields' => [
                'name',
                'sku',
                'spu',
                'handle',
                'short_description',
                'description',
                'searchable_text',
                'category_names',
            ],
            'filterable_fields' => [
                'document_type',
                'category_ids',
                'price',
                'status',
                'stock',
            ],
            'sortable_fields' => [
                'price',
                'name',
                'entity_id',
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'product',
            'document_type' => 'product',
            'module' => 'WeShop_Product',
            'description' => __('提供商品搜索文档（名称、SKU、分类、价格、库存等）'),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function buildDocument(array $product): ?array
    {
        $productId = (int) ($product[Product::schema_fields_ID] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        $categoryIds = $this->getCategoryIds($productId);
        $categoryNames = $this->getCategoryNames($categoryIds);
        $description = trim(strip_tags((string) ($product[Product::schema_fields_description] ?? '')));
        $priceData = $this->priceService->resolveProductData($product);

        return [
            'document_id' => $this->getDocumentId($productId),
            'document_type' => 'product',
            'entity_id' => $productId,
            'product_id' => $productId,
            'name' => (string) ($product[Product::schema_fields_name] ?? ''),
            'sku' => (string) ($product[Product::schema_fields_sku] ?? ''),
            'spu' => (string) ($product[Product::schema_fields_spu] ?? ''),
            'handle' => (string) ($product[Product::schema_fields_HANDLE] ?? ''),
            'short_description' => (string) ($product[Product::schema_fields_short_description] ?? ''),
            'description' => $description,
            'price' => (float) ($priceData['price'] ?? 0),
            'original_price' => (float) ($priceData['original_price'] ?? 0),
            'special_price' => $priceData['special_price'] ?? null,
            'has_discount' => (bool) ($priceData['has_discount'] ?? false),
            'discount_amount' => (float) ($priceData['discount_amount'] ?? 0),
            'discount_percent' => (int) ($priceData['discount_percent'] ?? 0),
            'cost' => (float) ($product[Product::schema_fields_cost] ?? 0),
            'stock' => (int) ($product[Product::schema_fields_stock] ?? 0),
            'status' => (int) ($product[Product::schema_fields_status] ?? 0),
            'image' => (string) ($product[Product::schema_fields_image] ?? ''),
            'category_ids' => $categoryIds,
            'category_names' => $categoryNames,
            'meta_title' => (string) ($product[Product::schema_fields_meta_name] ?? ''),
            'meta_description' => (string) ($product[Product::schema_fields_meta_description] ?? ''),
            'meta_keywords' => (string) ($product[Product::schema_fields_meta_keywords] ?? ''),
            'url' => '/product/view?id=' . $productId,
            'searchable_text' => $this->buildSearchableText($product, $categoryNames),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function getCategoryIds(int $productId): array
    {
        try {
            return array_values(array_filter(array_map('intval', $this->productCategory->getCategoryIdsByProductId($productId))));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, int> $categoryIds
     * @return array<int, string>
     */
    private function getCategoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $result = w_query('catalog', 'getCategoryNames', ['category_ids' => $categoryIds]);

        return is_array($result) ? array_values(array_filter(array_map('strval', $result))) : [];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, string> $categoryNames
     */
    private function buildSearchableText(array $product, array $categoryNames): string
    {
        $parts = array_filter([
            trim((string) ($product[Product::schema_fields_name] ?? '')),
            trim((string) ($product[Product::schema_fields_sku] ?? '')),
            trim((string) ($product[Product::schema_fields_spu] ?? '')),
            trim(strip_tags((string) ($product[Product::schema_fields_short_description] ?? ''))),
            trim(strip_tags((string) ($product[Product::schema_fields_description] ?? ''))),
            trim((string) ($product[Product::schema_fields_meta_keywords] ?? '')),
            implode(' ', $categoryNames),
        ]);

        return implode(' ', $parts);
    }
}
