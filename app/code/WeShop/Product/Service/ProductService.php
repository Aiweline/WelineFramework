<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\Product\LocalDescription as ProductLocalDescription;
use Weline\Framework\Manager\ObjectManager;

/**
 * 产品服务
 */
class ProductService
{
    /**
     * @var array<string, string>
     */
    private const LOCALIZED_FIELD_MAP = [
        'local_name' => Product::schema_fields_name,
        'local_short_description' => Product::schema_fields_short_description,
        'local_description' => Product::schema_fields_description,
        'local_meta_name' => Product::schema_fields_meta_name,
        'local_meta_description' => Product::schema_fields_meta_description,
        'local_meta_keywords' => Product::schema_fields_meta_keywords,
    ];

    public function __construct(
        private readonly PriceService $priceService
    ) {
    }

    /**
     * 获取产品
     * 
     * @param int $productId 产品ID
     * @return Product|null
     */
    public function getProduct(int $productId): ?Product
    {
        $product = $this->createLocalizedProductQuery();
        $rows = $product->clear()
            ->loadLocalDescription()
            ->fields($this->localizedSelectFields())
            ->where('main_table.' . Product::schema_fields_ID, $productId)
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;

        if (is_array($row)) {
            $product->setData($this->mergeLocalizedProductRow($row));
            return $product;
        }

        return null;
    }

    /**
     * 根据SKU获取产品
     * 
     * @param string $sku SKU
     * @return Product|null
     */
    public function getProductBySku(string $sku): ?Product
    {
        $product = $this->createLocalizedProductQuery();
        $rows = $product->clear()
            ->loadLocalDescription()
            ->fields($this->localizedSelectFields())
            ->where('main_table.' . Product::schema_fields_sku, $sku)
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;

        if (is_array($row)) {
            $product->setData($this->mergeLocalizedProductRow($row));
            return $product;
        }

        return null;
    }

    /**
     * 通过handle或sku获取产品
     * 
     * @param string $handle Handle标识或SKU
     * @return Product|null
     */
    public function getProductByHandle(string $handle): ?Product
    {
        $product = $this->createLocalizedProductQuery();

        // 先尝试通过 handle 查询（如果模型有 handle 字段）
        if (defined(Product::class . '::schema_fields_HANDLE')) {
            $rows = $product->clear()
                ->loadLocalDescription()
                ->fields($this->localizedSelectFields())
                ->where(Product::schema_fields_HANDLE, $handle)
                ->where(Product::schema_fields_status, 1)
                ->limit(1)
                ->select()
                ->fetchArray();
            $row = is_array($rows[0] ?? null) ? $rows[0] : null;

            if (is_array($row)) {
                $product->setData($this->mergeLocalizedProductRow($row));
                return $product;
            }
        }

        // 如果通过 handle 没找到，尝试通过sku查询
        $rows = $product->clear()
            ->loadLocalDescription()
            ->fields($this->localizedSelectFields())
            ->where(Product::schema_fields_sku, $handle)
            ->where(Product::schema_fields_status, 1)
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;

        if (is_array($row)) {
            $product->setData($this->mergeLocalizedProductRow($row));
            return $product;
        }

        return null;
    }

    /**
     * 获取产品列表
     * 
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array ['items' => [], 'total' => 0]
     */
    public function getProducts(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $product = $this->createLocalizedProductQuery();
        $product->clear()->loadLocalDescription();

        // 应用过滤条件
        if (!empty($filters['category_id'])) {
            $product->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['product_ids']) && is_array($filters['product_ids'])) {
            $productIds = array_values(array_unique(array_filter(array_map('intval', $filters['product_ids']))));
            if ($productIds !== []) {
                $product->where('main_table.' . Product::schema_fields_ID, $productIds, 'in');
            }
        }

        if (!empty($filters['status'])) {
            $product->where('main_table.' . Product::schema_fields_status, $this->normalizeStatusFilter($filters['status']));
        }

        if (!empty($filters['name'])) {
            $likeValue = '%' . trim((string)$filters['name']) . '%';
            $product->where([
                ['main_table.' . Product::schema_fields_name, $likeValue, 'LIKE', 'OR'],
                ['local.' . ProductLocalDescription::schema_fields_NAME, $likeValue, 'LIKE', 'OR'],
            ]);
        }

        if (!empty($filters['min_price'])) {
            $product->where('main_table.' . Product::schema_fields_price, ['>=', $filters['min_price']]);
        }

        if (!empty($filters['max_price'])) {
            $product->where('main_table.' . Product::schema_fields_price, ['<=', $filters['max_price']]);
        }

        // 排序
        $orderBy = $filters['order_by'] ?? Product::schema_fields_ID;
        $orderDir = $filters['order_dir'] ?? 'DESC';
        $product->order($this->qualifyMainField((string)$orderBy), $orderDir);

        // 分页
        $product->pagination($page, $pageSize);
        $items = array_map(
            fn (array $item): array => $this->priceService->resolveProductData($this->mergeLocalizedProductRow($item)),
            $product->fields($this->localizedSelectFields())->select()->fetchArray()
        );
        $pagination = $product->getPagination();

        return [
            'items' => $items,
            'total' => $product->getTotalCount(),
            'pagination' => $pagination,
        ];
    }
    
    /**
     * 保存产品
     * 
     * @param array $productData 产品数据
     * @return Product
     */
    public function saveProduct(array $productData): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);

        if (!empty($productData[Product::schema_fields_ID])) {
            $product->load($productData[Product::schema_fields_ID]);
        }

        // 触发保存前事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_save_before', [
            'product' => $product,
            'product_data' => $productData,
        ]);

        // 设置数据
        foreach ($productData as $key => $value) {
            if ($key !== Product::schema_fields_ID) {
                $product->setData($key, $value);
            }
        }

        $product->save();

        // 触发保存后事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_save_after', [
            'product' => $product,
        ]);

        return $product;
    }

    /**
     * 删除产品
     * 
     * @param int $productId 产品ID
     * @return bool
     */
    public function deleteProduct(int $productId): bool
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);

        if (!$product->getId()) {
            return false;
        }

        // 触发删除前事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_delete_before', [
            'product' => $product,
        ]);

        $result = $product->delete();

        // 触发删除后事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_delete_after', [
            'product_id' => $productId,
        ]);

        return $result;
    }

    /**
     * 更新产品价格
     * 
     * @param int $productId 产品ID
     * @param float $newPrice 新价格
     * @return Product
     */
    public function updateProductPrice(int $productId, float $newPrice): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);

        if (!$product->getId()) {
            throw new \Exception(__('产品不存在'));
        }

        $oldPrice = (float)$product->getData(Product::schema_fields_price);
        $product->setData(Product::schema_fields_price, $newPrice);
        $product->save();

        // 触发价格变更事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_price_change', [
            'product' => $product,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
        ]);

        return $product;
    }

    /**
     * 更新产品状态
     * 
     * @param int $productId 产品ID
     * @param string $status 状态
     * @return Product
     */
    public function updateProductStatus(int $productId, string $status): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);

        if (!$product->getId()) {
            throw new \Exception(__('产品不存在'));
        }

        $oldStatus = $product->getData(Product::schema_fields_status);
        $product->setData(Product::schema_fields_status, $status);
        $product->save();

        // 触发状态变更事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_status_change', [
            'product' => $product,
            'old_status' => $oldStatus,
            'new_status' => $status,
        ]);

        return $product;
    }

    protected function normalizeStatusFilter(mixed $status): mixed
    {
        if (is_string($status)) {
            $normalized = strtolower(trim($status));
            return match ($normalized) {
                'enabled', 'enable', 'active' => 1,
                'disabled', 'disable', 'inactive' => 0,
                default => $status,
            };
        }

        return $status;
    }
    
    /**
     * 检查产品库存
     * 
     * @param int $productId 产品ID
     * @param int $quantity 需要数量
     * @return bool
     */
    public function checkStock(int $productId, int $quantity): bool
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);

        if (!$product->getId()) {
            return false;
        }

        $stock = (int)$product->getData(Product::schema_fields_stock);
        return $stock >= $quantity;
    }

    private function createLocalizedProductQuery(): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->loadLocalDescription();

        return $product;
    }

    private function applyLocalizedProductModel(Product $product): Product
    {
        foreach (self::LOCALIZED_FIELD_MAP as $localField => $field) {
            $localizedValue = trim((string)($product->getData($localField) ?? ''));
            if ($localizedValue !== '') {
                $product->setData($field, $localizedValue);
            }
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function mergeLocalizedProductRow(array $item): array
    {
        foreach (self::LOCALIZED_FIELD_MAP as $localField => $field) {
            $localizedValue = trim((string)($item[$localField] ?? ''));
            if ($localizedValue !== '') {
                $item[$field] = $localizedValue;
            }
        }

        return $item;
    }

    private function qualifyMainField(string $field): string
    {
        if ($field === '' || str_contains($field, '.')) {
            return $field;
        }

        return 'main_table.' . $field;
    }

    private function localizedSelectFields(): string
    {
        return implode(',', [
            'main_table.*',
            'local.name AS local_name',
            'local.short_description AS local_short_description',
            'local.description AS local_description',
            'local.meta_name AS local_meta_name',
            'local.meta_description AS local_meta_description',
            'local.meta_keywords AS local_meta_keywords',
        ]);
    }
}
