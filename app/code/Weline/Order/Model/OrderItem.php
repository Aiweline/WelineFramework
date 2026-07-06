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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 订单项模型 */
#[Table(comment: '订单项表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_product_id', columns: ['product_id'])]
#[Index(name: 'idx_source_module', columns: ['source_module'])]
#[Index(name: 'idx_business_code', columns: ['business_code'])]
class OrderItem extends Model
{
    public const schema_table = 'weline_order_item';
    public const schema_primary_key = 'item_id';
    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['item_id', 'order_id', 'product_id'];

    #[Col(type: 'int', length: 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '订单项ID')]
    public const schema_fields_ID = 'item_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'int', length: 11, nullable: true, comment: '商品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '商品SKU')]
    public const schema_fields_PRODUCT_SKU = 'product_sku';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '商品名称')]
    public const schema_fields_PRODUCT_NAME = 'product_name';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '商品类型')]
    public const schema_fields_PRODUCT_TYPE = 'product_type';
    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: '来源应用')]
    public const schema_fields_SOURCE_APP = 'source_app';
    #[Col(type: 'varchar', length: 100, nullable: false, default: '', comment: '来源模块')]
    public const schema_fields_SOURCE_MODULE = 'source_module';
    #[Col(type: 'varchar', length: 100, nullable: false, default: '', comment: '业务代码')]
    public const schema_fields_BUSINESS_CODE = 'business_code';
    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: '业务名称')]
    public const schema_fields_BUSINESS_NAME = 'business_name';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '订购数量')]
    public const schema_fields_QTY_ORDERED = 'qty_ordered';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '已发货数量')]
    public const schema_fields_QTY_SHIPPED = 'qty_shipped';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '已退款数量')]
    public const schema_fields_QTY_REFUNDED = 'qty_refunded';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '已取消数量')]
    public const schema_fields_QTY_CANCELLED = 'qty_cancelled';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '单价')]
    public const schema_fields_PRICE = 'price';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '行总计')]
    public const schema_fields_ROW_TOTAL = 'row_total';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '折扣金额')]
    public const schema_fields_DISCOUNT_AMOUNT = 'discount_amount';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '税费')]
    public const schema_fields_TAX_AMOUNT = 'tax_amount';
    #[Col(type: 'timestamp', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'timestamp', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 计算行总计
     */
    public function calculateRowTotal(): float
    {
        $qty = (float)$this->getData(self::schema_fields_QTY_ORDERED);
        $price = (float)$this->getData(self::schema_fields_PRICE);
        $discount = (float)$this->getData(self::schema_fields_DISCOUNT_AMOUNT);
        $tax = (float)$this->getData(self::schema_fields_TAX_AMOUNT);

        return ($qty * $price) - $discount + $tax;
    }
}
