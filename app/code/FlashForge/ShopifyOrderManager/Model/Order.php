<?php

namespace FlashForge\ShopifyOrderManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Shopify订单模型
 */
class Order extends Model
{
    public const table = 'shopify_orders';
    public const primary_key = 'order_id';
    
    public const fields_ID = 'order_id';
    public const fields_SHOP_ID = 'shop_id';
    public const fields_SHOPIFY_ORDER_ID = 'shopify_order_id';
    public const fields_ORDER_NUMBER = 'order_number';
    public const fields_CUSTOMER_EMAIL = 'customer_email';
    public const fields_CUSTOMER_NAME = 'customer_name';
    public const fields_TOTAL_PRICE = 'total_price';
    public const fields_SUBTOTAL_PRICE = 'subtotal_price';
    public const fields_TOTAL_TAX = 'total_tax';
    public const fields_CURRENCY = 'currency';
    public const fields_FINANCIAL_STATUS = 'financial_status';
    public const fields_FULFILLMENT_STATUS = 'fulfillment_status';
    public const fields_ORDER_STATUS = 'order_status';
    public const fields_TAGS = 'tags';
    public const fields_NOTE = 'note';
    public const fields_SHIPPING_ADDRESS = 'shipping_address';
    public const fields_BILLING_ADDRESS = 'billing_address';
    public const fields_SHOPIFY_CREATED_AT = 'shopify_created_at';
    public const fields_SHOPIFY_UPDATED_AT = 'shopify_updated_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 订单状态
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    
    // 发货状态
    public const FULFILLMENT_PENDING = 'pending';
    public const FULFILLMENT_SHIPPED = 'shipped';
    public const FULFILLMENT_DELIVERED = 'delivered';
    
    public array $_unit_primary_keys = ['order_id'];
    public array $_index_sort_keys = ['order_id', 'shop_id', 'order_number', 'shopify_created_at'];

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
            $setup->createTable('Shopify订单表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_SHOP_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '店铺ID'
                )
                ->addColumn(
                    self::fields_SHOPIFY_ORDER_ID,
                    TableInterface::column_type_BIGINT,
                    20,
                    'not null',
                    'Shopify订单ID'
                )
                ->addColumn(
                    self::fields_ORDER_NUMBER,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '订单号'
                )
                ->addColumn(
                    self::fields_CUSTOMER_EMAIL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '客户邮箱'
                )
                ->addColumn(
                    self::fields_CUSTOMER_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '客户姓名'
                )
                ->addColumn(
                    self::fields_TOTAL_PRICE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '订单总价'
                )
                ->addColumn(
                    self::fields_SUBTOTAL_PRICE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '商品小计'
                )
                ->addColumn(
                    self::fields_TOTAL_TAX,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '税费'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default "USD"',
                    '货币'
                )
                ->addColumn(
                    self::fields_FINANCIAL_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '支付状态'
                )
                ->addColumn(
                    self::fields_FULFILLMENT_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '发货状态'
                )
                ->addColumn(
                    self::fields_ORDER_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '订单状态'
                )
                ->addColumn(
                    self::fields_TAGS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '订单标签'
                )
                ->addColumn(
                    self::fields_NOTE,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '订单备注'
                )
                ->addColumn(
                    self::fields_SHIPPING_ADDRESS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '收货地址JSON'
                )
                ->addColumn(
                    self::fields_BILLING_ADDRESS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '账单地址JSON'
                )
                ->addColumn(
                    self::fields_SHOPIFY_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    'Shopify创建时间'
                )
                ->addColumn(
                    self::fields_SHOPIFY_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    'Shopify更新时间'
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
                    TableInterface::index_type_UNIQUE,
                    'idx_shop_shopify_order',
                    [self::fields_SHOP_ID, self::fields_SHOPIFY_ORDER_ID],
                    '店铺订单唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_shop_id',
                    self::fields_SHOP_ID,
                    '店铺ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_order_status',
                    self::fields_ORDER_STATUS,
                    '订单状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_created_at',
                    self::fields_SHOPIFY_CREATED_AT,
                    '创建时间索引'
                )
                ->create();
        }
    }

    /**
     * 获取超时未发货订单（15天）
     */
    public function getOverdueOrders(): array
    {
        $overdueDate = date('Y-m-d H:i:s', strtotime('-15 days'));
        
        return $this->where(self::fields_FULFILLMENT_STATUS, self::FULFILLMENT_PENDING, '=', 'AND')
            ->where(self::fields_SHOPIFY_CREATED_AT, $overdueDate, '<')
            ->select()
            ->fetchArray();
    }

    /**
     * 根据店铺和时间范围获取订单
     */
    public function getOrdersByShopAndDateRange(int $shopId = 0, string $startDate = '', string $endDate = ''): array
    {
        $query = $this->select();
        
        if ($shopId > 0) {
            $query->where(self::fields_SHOP_ID, $shopId);
        }
        
        if ($startDate) {
            $query->where(self::fields_SHOPIFY_CREATED_AT, $startDate, '>=');
        }
        
        if ($endDate) {
            $query->where(self::fields_SHOPIFY_CREATED_AT, $endDate . ' 23:59:59', '<=');
        }
        
        return $query->order(self::fields_SHOPIFY_CREATED_AT, 'DESC')->fetchArray();
    }

    /**
     * 检查订单是否已存在
     */
    public function orderExists(int $shopId, string $shopifyOrderId): bool
    {
        $count = $this->where(self::fields_SHOP_ID, $shopId)
            ->where(self::fields_SHOPIFY_ORDER_ID, $shopifyOrderId)
            ->total();
            
        return $count > 0;
    }
}
