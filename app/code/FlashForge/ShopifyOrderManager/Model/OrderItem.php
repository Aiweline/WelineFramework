<?php

namespace FlashForge\ShopifyOrderManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Shopify订单项目模型
 */
class OrderItem extends Model
{
    public const table = 'shopify_order_items';
    public const primary_key = 'item_id';
    
    public const fields_ID = 'item_id';
    public const fields_PLATFORM = 'platform';
    public const fields_SHOP_ID = 'shop_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_ORDER_REF_ID = 'order_ref_id';
    public const fields_ORDER_NUMBER = 'order_number';
    public const fields_SHOPIFY_ITEM_ID = 'shopify_item_id';
    public const fields_SHOPIFY_PRODUCT_ID = 'shopify_product_id';
    public const fields_SHOPIFY_VARIANT_ID = 'shopify_variant_id';
    public const fields_SKU = 'sku';
    public const fields_PRODUCT_TITLE = 'product_title';
    public const fields_VARIANT_TITLE = 'variant_title';
    public const fields_VENDOR = 'vendor';
    public const fields_PRODUCT_TYPE = 'product_type';
    public const fields_QUANTITY = 'quantity';
    public const fields_PRICE = 'price';
    public const fields_ORIGINAL_PRICE = 'original_price';
    public const fields_TOTAL_DISCOUNT = 'total_discount';
    public const fields_TOTAL_TAX = 'total_tax';
    public const fields_UNIT_TAX = 'unit_tax';
    public const fields_FULFILLABLE_QUANTITY = 'fulfillable_quantity';
    public const fields_FULFILLMENT_STATUS = 'fulfillment_status';
    public const fields_FULFILLMENT_SERVICE = 'fulfillment_service';
    public const fields_REQUIRES_SHIPPING = 'requires_shipping';
    public const fields_TAXABLE = 'taxable';
    public const fields_GIFT_CARD = 'gift_card';
    public const fields_PROPERTIES = 'properties';
    public const fields_TAX_LINES = 'tax_lines';
    public const fields_DISCOUNT_ALLOCATIONS = 'discount_allocations';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['item_id', 'order_id'];

    /**
     * 设置模型
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加唯一索引防止重复订单项目
        if (!$setup->hasIndex('idx_unique_shop_shopify_item')) {
            $setup->alterTable('添加唯一索引防止重复订单项目')
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_unique_shop_shopify_item',
                    [self::fields_SHOP_ID, self::fields_SHOPIFY_ITEM_ID],
                    '店铺和Shopify项目ID唯一索引'
                );
        }
    }

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('Shopify订单项目表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '项目ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_ORDER_REF_ID,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '订单Ref ID'
                )
                ->addColumn(
                    self::fields_PLATFORM,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "shopify"',
                    '平台标识'
                )
                ->addColumn(
                    self::fields_SHOP_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '店铺ID'
                )
                ->addColumn(
                    self::fields_ORDER_NUMBER,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '订单号'
                )
                ->addColumn(
                    self::fields_SHOPIFY_ITEM_ID,
                    TableInterface::column_type_BIGINT,
                    20,
                    'null',
                    'Shopify项目ID'
                )
                ->addColumn(
                    self::fields_SHOPIFY_PRODUCT_ID,
                    TableInterface::column_type_BIGINT,
                    20,
                    'null',
                    'Shopify产品ID'
                )
                ->addColumn(
                    self::fields_SHOPIFY_VARIANT_ID,
                    TableInterface::column_type_BIGINT,
                    20,
                    'null',
                    'Shopify变体ID'
                )
                ->addColumn(
                    self::fields_PRODUCT_TITLE,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'not null',
                    '产品标题'
                )
                ->addColumn(
                    self::fields_VARIANT_TITLE,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'null',
                    '变体标题'
                )
                ->addColumn(
                    self::fields_SKU,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    'SKU'
                )
                ->addColumn(
                    self::fields_QUANTITY,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 1',
                    '数量'
                )
                ->addColumn(
                    self::fields_PRICE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '销售单价'
                )
                ->addColumn(
                    self::fields_ORIGINAL_PRICE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '原始单价'
                )
                ->addColumn(
                    self::fields_TOTAL_DISCOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '总折扣'
                )
                ->addColumn(
                    self::fields_TOTAL_TAX,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '总税费'
                )
                ->addColumn(
                    self::fields_UNIT_TAX,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '单件税费'
                )
                ->addColumn(
                    self::fields_FULFILLABLE_QUANTITY,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '可发货数量'
                )
                ->addColumn(
                    self::fields_FULFILLMENT_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '发货状态'
                )
                ->addColumn(
                    self::fields_VENDOR,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '供应商'
                )
                ->addColumn(
                    self::fields_PRODUCT_TYPE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '产品类型'
                )
                ->addColumn(
                    self::fields_REQUIRES_SHIPPING,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否需要配送'
                )
                ->addColumn(
                    self::fields_TAXABLE,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否征税'
                )
                ->addColumn(
                    self::fields_GIFT_CARD,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否礼品卡'
                )
                ->addColumn(
                    self::fields_FULFILLMENT_SERVICE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '配送服务'
                )
                ->addColumn(
                    self::fields_PROPERTIES,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '产品属性JSON'
                )
                ->addColumn(
                    self::fields_TAX_LINES,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '税费明细JSON'
                )
                ->addColumn(
                    self::fields_DISCOUNT_ALLOCATIONS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '折扣分配JSON'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_order_id',
                    self::fields_ORDER_ID,
                    '订单ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_product_id',
                    self::fields_SHOPIFY_PRODUCT_ID,
                    '产品ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_sku',
                    self::fields_SKU,
                    'SKU索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_shop_id',
                    self::fields_SHOP_ID,
                    '店铺ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_order_number',
                    self::fields_ORDER_NUMBER,
                    '订单号索引'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_unique_shop_shopify_item',
                    [self::fields_SHOP_ID, self::fields_SHOPIFY_ITEM_ID],
                    '店铺和Shopify项目ID唯一索引'
                )
                ->create();
        }
    }

    /**
     * 根据订单ID获取所有项目
     */
    public function getItemsByOrderId(int $orderId): array
    {
        return $this->where(self::fields_ORDER_ID, $orderId)
            ->order(self::fields_ID, 'ASC')  // 按ID排序，确保顺序一致
            ->select()
            ->fetchArray();
    }

    /**
     * 批量插入订单项目
     */
    public function batchInsertItems(array $items): bool
    {
        if (empty($items)) {
            return true;
        }

        try {
            // 使用事务确保数据一致性
            $this->beginTransaction();
            
            $result = $this->insert($items)->fetch();
            
            if ($result === false) {
                $this->rollback();
                return false;
            }
            
            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 删除订单的所有项目
     */
    public function deleteByOrderId(int $orderId): bool
    {
        try {
            $result = $this->where(self::fields_ORDER_ID, $orderId)->delete()->fetch();
            return $result !== false;
        } catch (\Exception $e) {
            // 记录错误但不抛出异常，避免影响主流程
            error_log("删除订单项目失败: OrderID={$orderId}, Error=" . $e->getMessage());
            return false;
        }
    }

    /**
     * 计算订单项目总价（包含税费）
     */
    public function calculateItemTotal(): float
    {
        $quantity = $this->getData(self::fields_QUANTITY) ?: 1;
        $price = $this->getData(self::fields_PRICE) ?: 0;
        $discount = $this->getData(self::fields_TOTAL_DISCOUNT) ?: 0;
        $tax = $this->getData(self::fields_TOTAL_TAX) ?: 0;
        
        return ($quantity * $price) - $discount + $tax;
    }

    /**
     * 计算订单项目净价（不含税费）
     */
    public function calculateItemNetTotal(): float
    {
        $quantity = $this->getData(self::fields_QUANTITY) ?: 1;
        $price = $this->getData(self::fields_PRICE) ?: 0;
        $discount = $this->getData(self::fields_TOTAL_DISCOUNT) ?: 0;
        
        return ($quantity * $price) - $discount;
    }

    /**
     * 计算单件税费
     */
    public function calculateUnitTax(): float
    {
        $quantity = $this->getData(self::fields_QUANTITY) ?: 1;
        $totalTax = $this->getData(self::fields_TOTAL_TAX) ?: 0;
        
        return $quantity > 0 ? $totalTax / $quantity : 0;
    }

    /**
     * 获取真实单价（已包含折扣的最终单价）
     */
    public function getRealUnitPrice(): float
    {
        // 直接返回数据库中存储的真实销售单价
        return floatval($this->getData(self::fields_PRICE) ?: 0);
    }

    /**
     * 获取原始单价（折扣前的价格）
     */
    public function getOriginalUnitPrice(): float
    {
        return floatval($this->getData(self::fields_ORIGINAL_PRICE) ?: 0);
    }

    /**
     * 获取单件折扣金额
     */
    public function getUnitDiscount(): float
    {
        $quantity = $this->getData(self::fields_QUANTITY) ?: 1;
        $totalDiscount = $this->getData(self::fields_TOTAL_DISCOUNT) ?: 0;
        
        return $quantity > 0 ? $totalDiscount / $quantity : 0;
    }

    /**
     * 获取折扣百分比
     */
    public function getDiscountPercent(): float
    {
        $originalPrice = $this->getOriginalUnitPrice();
        $unitDiscount = $this->getUnitDiscount();
        
        return $originalPrice > 0 ? round(($unitDiscount / $originalPrice) * 100, 2) : 0;
    }

    /**
     * 检查并清理重复的订单项目
     */
    public function cleanDuplicateItems(int $orderId): int
    {
        // 获取该订单的所有项目
        $items = $this->where(self::fields_ORDER_ID, $orderId)
            ->select()
            ->fetchArray();
        
        if (empty($items)) {
            return 0;
        }
        
        // 按shopify_item_id分组，保留第一个，删除重复的
        $seenItems = [];
        $duplicateIds = [];
        
        foreach ($items as $item) {
            $key = $item['shopify_item_id'] . '_' . $item['product_title'] . '_' . $item['sku'];
            
            if (isset($seenItems[$key])) {
                // 发现重复，记录要删除的ID
                $duplicateIds[] = $item['item_id'];
            } else {
                // 第一次出现，记录
                $seenItems[$key] = $item['item_id'];
            }
        }
        
        // 删除重复的项目
        if (!empty($duplicateIds)) {
            $this->where(self::fields_ID, $duplicateIds, 'in')
                ->delete()
                ->fetch();
        }
        
        return count($duplicateIds);
    }

    /**
     * 获取订单项目的统计信息
     */
    public function getOrderItemStats(int $orderId): array
    {
        $items = $this->where(self::fields_ORDER_ID, $orderId)
            ->select()
            ->fetchArray();
        
        $stats = [
            'total_items' => count($items),
            'unique_products' => 0,
            'total_quantity' => 0,
            'total_amount' => 0,
            'duplicates' => []
        ];
        
        if (empty($items)) {
            return $stats;
        }
        
        $seenProducts = [];
        $productCounts = [];
        
        foreach ($items as $item) {
            $key = $item['shopify_item_id'] . '_' . $item['product_title'] . '_' . $item['sku'];
            
            if (!isset($seenProducts[$key])) {
                $seenProducts[$key] = true;
                $stats['unique_products']++;
            } else {
                // 发现重复
                if (!isset($stats['duplicates'][$key])) {
                    $stats['duplicates'][$key] = 1;
                } else {
                    $stats['duplicates'][$key]++;
                }
            }
            
            $stats['total_quantity'] += intval($item['quantity']);
            $stats['total_amount'] += floatval($item['price']) * intval($item['quantity']);
        }
        
        return $stats;
    }
}
