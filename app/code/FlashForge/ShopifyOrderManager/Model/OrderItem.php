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
    public const fields_ORDER_ID = 'order_ref_id';
    public const fields_SHOPIFY_PRODUCT_ID = 'shopify_product_id';
    public const fields_SHOPIFY_VARIANT_ID = 'shopify_variant_id';
    public const fields_PRODUCT_TITLE = 'product_title';
    public const fields_VARIANT_TITLE = 'variant_title';
    public const fields_SKU = 'sku';
    public const fields_QUANTITY = 'quantity';
    public const fields_PRICE = 'price';
    public const fields_TOTAL_DISCOUNT = 'total_discount';
    public const fields_VENDOR = 'vendor';
    public const fields_PRODUCT_TYPE = 'product_type';
    public const fields_REQUIRES_SHIPPING = 'requires_shipping';
    public const fields_TAXABLE = 'taxable';
    public const fields_GIFT_CARD = 'gift_card';
    public const fields_FULFILLMENT_SERVICE = 'fulfillment_service';
    public const fields_PROPERTIES = 'properties';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['item_id', 'order_ref_id', 'shopify_product_id'];

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
        // 升级逻辑（如果需要）
    }

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
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
                    '单价'
                )
                ->addColumn(
                    self::fields_TOTAL_DISCOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '总折扣'
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
                    'idx_order_ref_id',
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
                ->create();
        }
    }

    /**
     * 根据订单ID获取所有项目
     */
    public function getItemsByOrderId(int $orderId): array
    {
        return $this->where(self::fields_ORDER_ID, $orderId)
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

        return $this->insert($items)->fetch();
    }

    /**
     * 删除订单的所有项目
     */
    public function deleteByOrderId(int $orderId): bool
    {
        return $this->where(self::fields_ORDER_ID, $orderId)->delete()->fetch();
    }

    /**
     * 计算订单项目总价
     */
    public function calculateItemTotal(): float
    {
        $quantity = $this->getData(self::fields_QUANTITY) ?: 1;
        $price = $this->getData(self::fields_PRICE) ?: 0;
        $discount = $this->getData(self::fields_TOTAL_DISCOUNT) ?: 0;
        
        return ($quantity * $price) - $discount;
    }
}
