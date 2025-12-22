<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 订单项模型
 */
class OrderItem extends Model
{
    public const table = 'weline_order_item';
    
    // 字段常量
    public const fields_ID = 'item_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_PRODUCT_SKU = 'product_sku';
    public const fields_PRODUCT_NAME = 'product_name';
    public const fields_PRODUCT_TYPE = 'product_type';
    public const fields_QTY_ORDERED = 'qty_ordered';
    public const fields_QTY_SHIPPED = 'qty_shipped';
    public const fields_QTY_REFUNDED = 'qty_refunded';
    public const fields_QTY_CANCELLED = 'qty_cancelled';
    public const fields_PRICE = 'price';
    public const fields_ROW_TOTAL = 'row_total';
    public const fields_DISCOUNT_AMOUNT = 'discount_amount';
    public const fields_TAX_AMOUNT = 'tax_amount';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['item_id'];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['item_id', 'order_id', 'product_id'];
    
    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }
    
    /**
     * 模型设置
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * 模型升级
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑可以在这里添加
    }
    
    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('订单项表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '订单项ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_PRODUCT_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'null',
                    '商品ID（可空，支持外部商品）'
                )
                ->addColumn(
                    self::fields_PRODUCT_SKU,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '商品SKU'
                )
                ->addColumn(
                    self::fields_PRODUCT_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '商品名称'
                )
                ->addColumn(
                    self::fields_PRODUCT_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '商品类型'
                )
                ->addColumn(
                    self::fields_QTY_ORDERED,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '订购数量'
                )
                ->addColumn(
                    self::fields_QTY_SHIPPED,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '已发货数量'
                )
                ->addColumn(
                    self::fields_QTY_REFUNDED,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '已退款数量'
                )
                ->addColumn(
                    self::fields_QTY_CANCELLED,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '已取消数量'
                )
                ->addColumn(
                    self::fields_PRICE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '单价'
                )
                ->addColumn(
                    self::fields_ROW_TOTAL,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '行总计'
                )
                ->addColumn(
                    self::fields_DISCOUNT_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '折扣金额'
                )
                ->addColumn(
                    self::fields_TAX_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '税费'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'default current_timestamp on update current_timestamp',
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
                    self::fields_PRODUCT_ID,
                    '商品ID索引'
                )
                ->create();
        }
    }
    
    /**
     * 计算行总计
     */
    public function calculateRowTotal(): float
    {
        $qty = (float)$this->getData(self::fields_QTY_ORDERED);
        $price = (float)$this->getData(self::fields_PRICE);
        $discount = (float)$this->getData(self::fields_DISCOUNT_AMOUNT);
        $tax = (float)$this->getData(self::fields_TAX_AMOUNT);
        
        return ($qty * $price) - $discount + $tax;
    }
}

