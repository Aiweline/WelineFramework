<?php
declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_Framework\Query;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use WeShop\Product\Service\ProductEavCompatibilityService;

/**
 * 产品查询器
 *
 * 提供 getProductById、getPriceStats、filterByPriceRange 等能力，供其他模块通过 w_query('product', ...) 调用。
 */
class ProductQueryProvider implements QueryProviderInterface
{
    private ?int $productEavEntityId = null;

    public function __construct(
        private readonly Product $productModel,
        private readonly PriceService $priceService,
        private readonly ProductCategory $productCategoryModel = new ProductCategory(),
        private readonly EavEntity $eavEntityModel = new EavEntity(),
        private readonly EavAttribute $eavAttributeModel = new EavAttribute()
    ) {
    }

    public function getProviderName(): string
    {
        return 'product';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getProductById' => $this->getProductById($params),
            'getProductByIds' => $this->getProductByIds($params),
            'getProductIdsByCategoryId' => $this->getProductIdsByCategoryId($params),
            'searchProducts' => $this->searchProducts($params),
            'getProductSuggestions' => $this->getProductSuggestions($params),
            'getPriceStats' => $this->getPriceStats($params),
            'filterByPriceRange' => $this->filterByPriceRange($params),
            'countByPriceRange' => $this->countByPriceRange($params),
            'getAttributeInfo' => $this->getAttributeInfo($params),
            'getProductEavValues' => $this->getProductEavValues($params),
            'filterByEavAttribute' => $this->filterByEavAttribute($params),
            default => throw new \InvalidArgumentException(
                (string)__('Product 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getProductById(array $params): ?array
    {
        $productId = (int)($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }
        $product = clone $this->productModel;
        $product->load($productId);
        if (!$product->getId()) {
            return null;
        }
        return $this->productToArray($product);
    }

    private function getProductByIds(array $params): array
    {
        $ids = $params['product_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return [];
        }
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return [];
        }
        $product = clone $this->productModel;
        $product->clear()->where(Product::schema_fields_ID, $ids, 'in');
        $items = $product->select()->fetch()->getItems();
        $list = [];
        foreach ($items as $p) {
            if ($p->getId()) {
                $list[] = $this->productToArray($p);
            }
        }
        return $list;
    }

    private function getProductIdsByCategoryId(array $params): array
    {
        $categoryIds = $params['category_ids'] ?? [];
        if (!\is_array($categoryIds)) {
            $categoryIds = [];
        }

        $singleCategoryId = (int)($params['category_id'] ?? 0);
        if ($singleCategoryId > 0) {
            $categoryIds[] = $singleCategoryId;
        }

        $categoryIds = \array_values(\array_filter(\array_map('intval', $categoryIds)));
        if ($categoryIds === []) {
            return [];
        }

        try {
            $productCategory = clone $this->productCategoryModel;
            $rows = $productCategory
                ->clear()
                ->where(ProductCategory::schema_fields_category_id, $categoryIds, 'in')
                ->select()
                ->fetchArray();

            return \array_values(\array_unique(\array_filter(
                \array_map(
                    static fn (array $row): int => (int)($row[ProductCategory::schema_fields_product_id] ?? 0),
                    $rows
                )
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    private function productToArray(object $product): array
    {
        return $this->priceService->resolveProductData([
            'product_id' => (int)$product->getId(),
            'name' => $product->getData(Product::schema_fields_name),
            'short_description' => $product->getData(Product::schema_fields_short_description),
            'description' => $product->getData(Product::schema_fields_description),
            'sku' => $product->getData(Product::schema_fields_sku),
            'spu' => $product->getData(Product::schema_fields_spu),
            'price' => (float)($product->getData(Product::schema_fields_price) ?? 0),
            'cost' => (float)($product->getData(Product::schema_fields_cost) ?? 0),
            'stock' => (int)($product->getData(Product::schema_fields_stock) ?? 0),
            'image' => $product->getData(Product::schema_fields_image),
            'images' => $product->getData(Product::schema_fields_images),
            'status' => (int)($product->getData(Product::schema_fields_status) ?? 0),
            'handle' => $product->getData(Product::schema_fields_HANDLE),
            'parent_id' => (int)($product->getData(Product::schema_fields_parent_id) ?? 0),
        ]);
    }

    /**
     * 获取产品价格统计（min/max/avg）
     */
    private function getPriceStats(array $params): array
    {
        $productIds = $params['product_ids'] ?? [];
        if (!is_array($productIds) || empty($productIds)) {
            return ['min' => null, 'max' => null, 'avg' => null];
        }
        $productIds = array_filter(array_map('intval', $productIds));
        if (empty($productIds)) {
            return ['min' => null, 'max' => null, 'avg' => null];
        }

        $prices = [];
        foreach ($this->loadResolvedProductsByIds($productIds) as $product) {
            $price = $this->extractComparablePrice($product);
            if ($price > 0.0) {
                $prices[] = $price;
            }
        }

        if ($prices === []) {
            return ['min' => null, 'max' => null, 'avg' => null];
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => array_sum($prices) / count($prices),
        ];
    }

    /**
     * 按价格区间筛选产品 ID
     * @param array $params product_ids, ranges (array of {min, max?})
     */
    private function filterByPriceRange(array $params): array
    {
        $productIds = $params['product_ids'] ?? [];
        $ranges = $params['ranges'] ?? [];
        if (!is_array($productIds) || empty($productIds) || !is_array($ranges) || empty($ranges)) {
            return [];
        }
        $productIds = array_filter(array_map('intval', $productIds));
        if (empty($productIds)) {
            return [];
        }

        $resolvedProducts = $this->loadResolvedProductsByIds($productIds);
        $matched = [];

        foreach ($productIds as $productId) {
            $product = $resolvedProducts[$productId] ?? null;
            if (!is_array($product)) {
                continue;
            }

            $price = $this->extractComparablePrice($product);
            foreach ($ranges as $range) {
                $min = (float) ($range['min'] ?? 0);
                $max = isset($range['max']) && $range['max'] !== null ? (float) $range['max'] : null;
                if ($this->priceMatchesRange($price, $min, $max)) {
                    $matched[] = $productId;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * 统计价格区间内产品数量
     */
    private function countByPriceRange(array $params): int
    {
        $productIds = $params['product_ids'] ?? [];
        $minPrice = (float)($params['min_price'] ?? 0);
        $maxPrice = isset($params['max_price']) && $params['max_price'] !== null ? (float)$params['max_price'] : null;
        if (!is_array($productIds) || empty($productIds)) {
            return 0;
        }
        $productIds = array_filter(array_map('intval', $productIds));
        if (empty($productIds)) {
            return 0;
        }

        $count = 0;
        foreach ($this->loadResolvedProductsByIds($productIds) as $product) {
            if ($this->priceMatchesRange($this->extractComparablePrice($product), $minPrice, $maxPrice)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 获取产品 EAV 属性信息（attribute_id, type_code）
     */
    private function getAttributeInfo(array $params): ?array
    {
        $code = trim((string)($params['attribute_code'] ?? ''));
        if ($code === '') {
            return null;
        }
        try {
            $entityId = $this->getProductEavEntityId();
            if ($entityId <= 0) {
                return ObjectManager::getInstance(ProductEavCompatibilityService::class)->getAttributeInfo($code);
            }

            $attribute = clone $this->eavAttributeModel;
            $attribute->reset()->clearData()
                ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
                ->where(EavAttribute::schema_fields_code, $code)
                ->where(EavAttribute::schema_fields_is_enable, 1)
                ->find()
                ->fetch();
            if (!$attribute || !$attribute->getId()) {
                return ObjectManager::getInstance(ProductEavCompatibilityService::class)->getAttributeInfo($code);
            }
            $typeModel = $attribute->getTypeModel();
            $attributeId = (int)($attribute->getData(EavAttribute::schema_fields_attribute_id) ?? 0);
            if ($attributeId <= 0) {
                $attributeId = (int)$attribute->getId();
            }
            return [
                'attribute_id' => $attributeId,
                'type_code' => $typeModel ? (string)$typeModel->getCode() : 'input_string',
                'has_option' => (bool)$attribute->hasOption(),
                'name' => (string)$attribute->getName(),
                'code' => (string)$attribute->getCode(),
                'is_multiple' => (bool)$attribute->getMultipleValued(),
                'frontend_is_filterable' => (bool)$attribute->isFilterable(),
                'frontend_is_searchable' => (bool)$attribute->isSearchable(),
                'frontend_is_visible' => (bool)$attribute->isVisibleOnFront(),
            ];
        } catch (\Throwable $e) {
            return ObjectManager::getInstance(ProductEavCompatibilityService::class)->getAttributeInfo($code);
        }
    }

    private function getProductEavEntityId(): int
    {
        if ($this->productEavEntityId !== null) {
            return $this->productEavEntityId;
        }

        try {
            $entity = clone $this->eavEntityModel;
            $entity->reset()->clearData()
                ->where(EavEntity::schema_fields_code, Product::entity_code)
                ->find()
                ->fetch();
            $this->productEavEntityId = (int)$entity->getId();
        } catch (\Throwable) {
            $this->productEavEntityId = 0;
        }

        return $this->productEavEntityId;
    }

    /**
     * 从产品 EAV 值表读取属性值列表
     */
    private function getProductEavValues(array $params): array
    {
        $attributeId = (int)($params['attribute_id'] ?? 0);
        $typeCode = $this->sanitizeTypeCode((string)($params['type_code'] ?? 'input_string'));
        $productIds = $params['product_ids'] ?? [];
        if ($attributeId <= 0 || !is_array($productIds) || empty($productIds)) {
            return [];
        }
        $productIds = array_filter(array_map('intval', $productIds));
        if (empty($productIds)) {
            return [];
        }
        try {
            $valueTable = 'm_eav_product_' . $typeCode;
            $pdo = $this->productModel->getConnection()->getConnector()->getLink();
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql = "SELECT value FROM \"{$valueTable}\" WHERE attribute_id = ? AND entity_id IN ({$placeholders})";
            $params2 = array_merge([$attributeId], $productIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params2);
            $values = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (isset($row['value']) && $row['value'] !== '' && $row['value'] !== null) {
                    $values[] = $row['value'];
                }
            }
            return $values;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 按 EAV 属性值筛选产品 ID
     */
    private function filterByEavAttribute(array $params): array
    {
        $attributeId = (int)($params['attribute_id'] ?? 0);
        $typeCode = $this->sanitizeTypeCode((string)($params['type_code'] ?? 'input_string'));
        $productIds = $params['product_ids'] ?? [];
        $filterValues = $params['filter_values'] ?? [];
        if ($attributeId <= 0 || !is_array($productIds) || empty($productIds) || !is_array($filterValues) || empty($filterValues)) {
            return [];
        }
        $productIds = array_filter(array_map('intval', $productIds));
        $filterValues = array_filter(array_map('strval', $filterValues));
        if (empty($productIds) || empty($filterValues)) {
            return [];
        }
        try {
            $valueTable = 'm_eav_product_' . $typeCode;
            $pdo = $this->productModel->getConnection()->getConnector()->getLink();
            $phIds = implode(',', array_fill(0, count($productIds), '?'));
            $phVals = implode(',', array_fill(0, count($filterValues), '?'));
            $sql = "SELECT entity_id FROM \"{$valueTable}\" WHERE attribute_id = ? AND value IN ({$phVals}) AND entity_id IN ({$phIds})";
            $params2 = array_merge([$attributeId], $filterValues, $productIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params2);
            $ids = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (isset($row['entity_id'])) {
                    $ids[] = (int)$row['entity_id'];
                }
            }
            return array_values(array_unique($ids));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function sanitizeTypeCode(string $code): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower($code)) ?: 'input_string';
    }

    private function searchProducts(array $params): array
    {
        $keyword = \trim((string)($params['keyword'] ?? ''));
        $filters = $params['filters'] ?? [];
        if (!\is_array($filters)) {
            $filters = [];
        }
        $page = \max(1, (int)($params['page'] ?? 1));
        $pageSize = \max(1, (int)($params['page_size'] ?? 20));

        $product = clone $this->productModel;
        $product->clear();
        $this->applyProductSearchBaseFilters($product, $keyword, $filters);

        $items = array_map(
            fn (array $item): array => $this->priceService->resolveProductData($item),
            $product->select()->fetchArray()
        );
        $items = $this->filterResolvedProductsByPrice($items, $filters);

        $orderBy = (string)($filters['order_by'] ?? Product::schema_fields_ID);
        $orderDir = \strtoupper((string)($filters['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $items = $this->sortResolvedProducts($items, $orderBy, $orderDir);

        $total = count($items);
        $product->pagination($page, $pageSize, [], 1000, $total);
        $items = array_slice($items, max(0, ($page - 1) * $pageSize), $pageSize);

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => $product->getPagination(),
        ];
    }

    private function getProductSuggestions(array $params): array
    {
        $keyword = \trim((string)($params['keyword'] ?? ''));
        $limit = \max(1, (int)($params['limit'] ?? 10));
        if ($keyword === '') {
            return [];
        }

        $product = clone $this->productModel;
        $product->clear();
        $product->where(Product::schema_fields_name, '%' . $keyword . '%', 'LIKE')
            ->where(Product::schema_fields_status, 1)
            ->order(Product::schema_fields_ID, 'DESC')
            ->limit(\min(5, $limit));

        $products = $product->select()->fetchArray();
        $suggestions = [];
        foreach ($products as $item) {
            $suggestions[] = [
                'text' => $item[Product::schema_fields_name] ?? '',
                'type' => 'product',
                'icon' => 'fa-shopping-bag',
                'url' => '/product/view?id=' . ($item[Product::schema_fields_ID] ?? ''),
            ];
        }

        return \array_slice($suggestions, 0, $limit);
    }

    private function applyProductSearchBaseFilters(Product $product, string $keyword, array $filters): void
    {
        if ($keyword !== '') {
            $escapedKeyword = $this->escapeLikeValue($keyword);
            $product->where(
                sprintf(
                    "(%s LIKE '%s' OR %s LIKE '%s' OR %s LIKE '%s' OR %s LIKE '%s')",
                    Product::schema_fields_name,
                    $escapedKeyword,
                    Product::schema_fields_sku,
                    $escapedKeyword,
                    Product::schema_fields_short_description,
                    $escapedKeyword,
                    Product::schema_fields_description,
                    $escapedKeyword
                )
            );
        }

        $categoryProductIds = $this->getProductIdsByCategoryId([
            'category_id' => $filters['category_id'] ?? null,
            'category_ids' => $filters['category_ids'] ?? [],
        ]);
        if ($categoryProductIds !== []) {
            $product->where(Product::schema_fields_ID, $categoryProductIds, 'in');
        } elseif (!empty($filters['category_id']) || !empty($filters['category_ids'])) {
            $product->where(Product::schema_fields_ID, [0], 'in');
        }

        $product->where(Product::schema_fields_status, 1);
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>>
     */
    private function loadResolvedProductsByIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $product = clone $this->productModel;
        $product->clear()->where(Product::schema_fields_ID, $productIds, 'in');

        $resolvedProducts = [];
        foreach ($product->select()->fetchArray() as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $this->extractProductId($item);
            if ($productId <= 0) {
                continue;
            }

            $resolvedProducts[$productId] = $this->priceService->resolveProductData($item + [
                Product::schema_fields_ID => $productId,
            ]);
        }

        return $resolvedProducts;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function filterResolvedProductsByPrice(array $items, array $filters): array
    {
        $hasMin = array_key_exists('price_min', $filters) && $filters['price_min'] !== '' && $filters['price_min'] !== null;
        $hasMax = array_key_exists('price_max', $filters) && $filters['price_max'] !== '' && $filters['price_max'] !== null;

        if (!$hasMin && !$hasMax) {
            return $items;
        }

        $minPrice = $hasMin ? (float) $filters['price_min'] : 0.0;
        $maxPrice = $hasMax ? (float) $filters['price_max'] : null;

        return array_values(array_filter(
            $items,
            fn (array $item): bool => $this->priceMatchesRange($this->extractComparablePrice($item), $minPrice, $maxPrice)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortResolvedProducts(array $items, string $orderBy, string $orderDir): array
    {
        $direction = strtoupper($orderDir) === 'ASC' ? 1 : -1;
        $field = trim($orderBy) !== '' ? $orderBy : Product::schema_fields_ID;

        usort($items, function (array $left, array $right) use ($field, $direction): int {
            $comparison = $this->compareResolvedValues(
                $this->extractSortableValue($left, $field),
                $this->extractSortableValue($right, $field)
            );

            if ($comparison === 0) {
                $comparison = $this->extractProductId($left) <=> $this->extractProductId($right);
            }

            return $comparison * $direction;
        });

        return $items;
    }

    private function compareResolvedValues(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strcmp((string) $left, (string) $right);
    }

    private function extractSortableValue(array $item, string $field): mixed
    {
        return match ($field) {
            Product::schema_fields_ID, 'product_id', 'entity_id' => $this->extractProductId($item),
            'price', 'final_price' => $this->extractComparablePrice($item),
            'original_price', 'base_price', 'special_price', 'sale_price', 'discount_amount', 'discount_percent', 'stock', 'status', 'cost'
                => is_numeric($item[$field] ?? null) ? (float) $item[$field] : 0.0,
            default => strtolower(trim((string) ($item[$field] ?? ''))),
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractComparablePrice(array $item): float
    {
        if (isset($item['price']) && is_numeric($item['price'])) {
            return max(0.0, (float) $item['price']);
        }

        if (isset($item['final_price']) && is_numeric($item['final_price'])) {
            return max(0.0, (float) $item['final_price']);
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractProductId(array $item): int
    {
        return (int) ($item[Product::schema_fields_ID] ?? $item['product_id'] ?? $item['entity_id'] ?? 0);
    }

    private function priceMatchesRange(float $price, float $minPrice, ?float $maxPrice): bool
    {
        if ($price < $minPrice) {
            return false;
        }

        return $maxPrice === null || $price <= $maxPrice;
    }

    private function escapeLikeValue(string $keyword): string
    {
        return str_replace(["\\", "'"], ["\\\\", "''"], '%' . $keyword . '%');
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'product',
            'name' => __('产品查询'),
            'description' => __('提供产品信息与价格统计查询能力'),
            'module' => 'WeShop_Product',
            'operations' => [
                [
                    'name' => 'getProductById',
                    'description' => __('根据 ID 获取产品信息'),
                    'params' => [['name' => 'product_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'getProductByIds',
                    'description' => __('批量获取产品信息'),
                    'params' => [['name' => 'product_ids', 'type' => 'array', 'required' => true]],
                ],
                [
                    'name' => 'getProductIdsByCategoryId',
                    'description' => __('根据分类 ID 获取关联的商品 ID 列表'),
                    'params' => [['name' => 'category_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'searchProducts',
                    'description' => __('按关键字与筛选条件搜索商品'),
                    'params' => [
                        ['name' => 'keyword', 'type' => 'string', 'required' => false],
                        ['name' => 'filters', 'type' => 'array', 'required' => false],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getProductSuggestions',
                    'description' => __('获取商品搜索建议'),
                    'params' => [
                        ['name' => 'keyword', 'type' => 'string', 'required' => true],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getPriceStats',
                    'description' => __('获取产品价格统计 min/max/avg'),
                    'params' => [['name' => 'product_ids', 'type' => 'array', 'required' => true]],
                ],
                [
                    'name' => 'filterByPriceRange',
                    'description' => __('按价格区间筛选产品 ID'),
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                        ['name' => 'ranges', 'type' => 'array', 'required' => true, 'description' => __('[{min, max?}, ...]')],
                    ],
                ],
                [
                    'name' => 'countByPriceRange',
                    'description' => __('统计价格区间内产品数量'),
                    'params' => [
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                        ['name' => 'min_price', 'type' => 'float', 'required' => true],
                        ['name' => 'max_price', 'type' => 'float|null', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getAttributeInfo',
                    'description' => __('获取产品 EAV 属性信息'),
                    'params' => [['name' => 'attribute_code', 'type' => 'string', 'required' => true]],
                ],
                [
                    'name' => 'getProductEavValues',
                    'description' => __('从 EAV 值表读取产品属性值列表'),
                    'params' => [
                        ['name' => 'attribute_id', 'type' => 'int', 'required' => true],
                        ['name' => 'type_code', 'type' => 'string', 'required' => false],
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'filterByEavAttribute',
                    'description' => __('按 EAV 属性值筛选产品 ID'),
                    'params' => [
                        ['name' => 'attribute_id', 'type' => 'int', 'required' => true],
                        ['name' => 'type_code', 'type' => 'string', 'required' => false],
                        ['name' => 'product_ids', 'type' => 'array', 'required' => true],
                        ['name' => 'filter_values', 'type' => 'array', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
