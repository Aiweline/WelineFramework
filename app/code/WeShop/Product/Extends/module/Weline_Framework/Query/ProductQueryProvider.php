<?php
declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Product\Model\Product;

/**
 * 产品查询器
 *
 * 提供 getProductById、getPriceStats、filterByPriceRange 等能力，供其他模块通过 w_query('product', ...) 调用。
 */
class ProductQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Product $productModel
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

    private function productToArray(object $product): array
    {
        return [
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
        ];
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
        $product = clone $this->productModel;
        $product->reset()
            ->fields([
                'MIN(' . Product::schema_fields_price . ') as min_price',
                'MAX(' . Product::schema_fields_price . ') as max_price',
                'AVG(' . Product::schema_fields_price . ') as avg_price',
            ])
            ->where(Product::schema_fields_ID, $productIds, 'in')
            ->where(Product::schema_fields_price, 0, '>');
        $row = $product->find()->fetchArray();
        return [
            'min' => isset($row['min_price']) && $row['min_price'] !== null ? (float)$row['min_price'] : null,
            'max' => isset($row['max_price']) && $row['max_price'] !== null ? (float)$row['max_price'] : null,
            'avg' => isset($row['avg_price']) && $row['avg_price'] !== null ? (float)$row['avg_price'] : null,
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
        $product = clone $this->productModel;
        $product->reset()->fields(Product::schema_fields_ID)->where(Product::schema_fields_ID, $productIds, 'in');
        if (count($ranges) === 1) {
            $r = $ranges[0];
            $product->where(Product::schema_fields_price, (float)($r['min'] ?? 0), '>=');
            if (isset($r['max']) && $r['max'] !== null) {
                $product->where(Product::schema_fields_price, (float)$r['max'], '<=');
            }
        } else {
            $conditions = [];
            foreach ($ranges as $r) {
                $min = (float)($r['min'] ?? 0);
                $max = $r['max'] ?? null;
                if ($max !== null) {
                    $conditions[] = '(' . Product::schema_fields_price . ' >= ' . number_format($min, 2, '.', '') .
                        ' AND ' . Product::schema_fields_price . ' <= ' . number_format((float)$max, 2, '.', '') . ')';
                } else {
                    $conditions[] = Product::schema_fields_price . ' >= ' . number_format($min, 2, '.', '');
                }
            }
            if (!empty($conditions)) {
                $product->where('(' . implode(' OR ', $conditions) . ')');
            }
        }
        $results = $product->select()->fetchArray();
        return array_column($results, Product::schema_fields_ID);
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
        $product = clone $this->productModel;
        $product->reset()
            ->fields('COUNT(*) as cnt')
            ->where(Product::schema_fields_ID, $productIds, 'in')
            ->where(Product::schema_fields_price, $minPrice, '>=');
        if ($maxPrice !== null) {
            $product->where(Product::schema_fields_price, $maxPrice, '<=');
        }
        $row = $product->find()->fetchArray();
        return (int)($row['cnt'] ?? 0);
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
            $attribute = $this->productModel->getAttribute($code);
            if (!$attribute || !$attribute->getId()) {
                return null;
            }
            $typeModel = $attribute->getTypeModel();
            return [
                'attribute_id' => (int)$attribute->getId(),
                'type_code' => $typeModel ? (string)$typeModel->getCode() : 'input_string',
                'has_option' => (bool)$attribute->hasOption(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
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
        $this->applyProductSearchFilters($product, $keyword, $filters);

        $orderBy = (string)($filters['order_by'] ?? Product::schema_fields_ID);
        $orderDir = \strtoupper((string)($filters['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $product->order($orderBy, $orderDir);
        $product->pagination($page, $pageSize);
        $items = $product->select()->fetchArray();

        return [
            'items' => $items,
            'total' => $product->getTotalCount(),
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
        $product->where(Product::schema_fields_name, ['like', '%' . $keyword . '%'])
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

    private function applyProductSearchFilters(Product $product, string $keyword, array $filters): void
    {
        if ($keyword !== '') {
            $product->where(Product::schema_fields_name, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::schema_fields_sku, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::schema_fields_short_description, ['like', '%' . $keyword . '%'], 'or')
                ->where(Product::schema_fields_description, ['like', '%' . $keyword . '%'], 'or');
        }

        if (!empty($filters['category_id'])) {
            $product->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['price_min'])) {
            $product->where(Product::schema_fields_price, ['>=', $filters['price_min']]);
        }

        if (!empty($filters['price_max'])) {
            $product->where(Product::schema_fields_price, ['<=', $filters['price_max']]);
        }

        $product->where(Product::schema_fields_status, 1);
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
