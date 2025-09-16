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
    public const fields_PLATFORM = 'platform';
    public const fields_SHOPIFY_ORDER_ID = 'shopify_order_id';
    public const fields_ORDER_NUMBER = 'order_number';
    public const fields_NAME = 'name';
    public const fields_CUSTOMER_EMAIL = 'customer_email';
    public const fields_CUSTOMER_NAME = 'customer_name';
    public const fields_CUSTOMER_PHONE = 'customer_phone';
    public const fields_SHOPIFY_CREATED_AT = 'shopify_created_at';
    public const fields_SHOPIFY_UPDATED_AT = 'shopify_updated_at';
    public const fields_PROCESSED_AT = 'processed_at';
    public const fields_CANCELLED_AT = 'cancelled_at';
    public const fields_CLOSED_AT = 'closed_at';
    public const fields_FINANCIAL_STATUS = 'financial_status';
    public const fields_FULFILLMENT_STATUS = 'fulfillment_status';
    public const fields_ORDER_STATUS = 'order_status';
    public const fields_TOTAL_PRICE = 'total_price';
    public const fields_SUBTOTAL_PRICE = 'subtotal_price';
    public const fields_TOTAL_TAX = 'total_tax';
    public const fields_TOTAL_DISCOUNTS = 'total_discounts';
    public const fields_TOTAL_SHIPPING_PRICE = 'total_shipping_price';
    public const fields_CURRENCY = 'currency';
    public const fields_GATEWAY = 'gateway';
    public const fields_PAYMENT_METHOD_NAME = 'payment_method_name';
    public const fields_PAYMENT_METHOD_TYPE = 'payment_method_type';
    public const fields_PAYMENT_GATEWAY_NAMES = 'payment_gateway_names';
    public const fields_TRANSACTIONS = 'transactions';
    public const fields_TEST = 'test';
    public const fields_TAGS = 'tags';
    public const fields_NOTE = 'note';
    public const fields_BILLING_ADDRESS = 'billing_address';
    public const fields_SHIPPING_ADDRESS = 'shipping_address';
    public const fields_CUSTOMER = 'customer';
    public const fields_RAW_DATA = 'raw_data';
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
        // 添加支付方式相关字段
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_PAYMENT_METHOD_NAME)) {
            $setup->alterTable('添加支付方式名称字段')
                ->addColumn(
                    self::fields_PAYMENT_METHOD_NAME,
                    '',
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null default ""',
                    '支付方式名称'
                );
        }
        
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_PAYMENT_METHOD_TYPE)) {
            $setup->alterTable('添加支付方式类型字段')
                ->addColumn(
                    self::fields_PAYMENT_METHOD_TYPE,
                    '',
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null default ""',
                    '支付方式类型'
                );
        }
        
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_PAYMENT_GATEWAY_NAMES)) {
            $setup->alterTable('添加支付网关名称字段')
                ->addColumn(
                    self::fields_PAYMENT_GATEWAY_NAMES,
                    '',
                    TableInterface::column_type_TEXT,
                    '',
                    'null',
                    '支付网关名称'
                );
        }
        
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_TRANSACTIONS)) {
            $setup->alterTable('添加交易信息字段')
                ->addColumn(
                    self::fields_TRANSACTIONS,
                    '',
                    TableInterface::column_type_TEXT,
                    '',
                    'null',
                    '交易信息'
                );
        }
        
        // 添加时间字段
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_PROCESSED_AT)) {
            $setup->alterTable('添加处理时间字段')
                ->addColumn(
                    self::fields_PROCESSED_AT,
                    '',
                    TableInterface::column_type_DATETIME,
                    '',
                    'null',
                    '处理时间'
                );
        }
        
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_CANCELLED_AT)) {
            $setup->alterTable('添加取消时间字段')
                ->addColumn(
                    self::fields_CANCELLED_AT,
                    '',
                    TableInterface::column_type_DATETIME,
                    '',
                    'null',
                    '取消时间'
                );
        }
        
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_CLOSED_AT)) {
            $setup->alterTable('添加关闭时间字段')
                ->addColumn(
                    self::fields_CLOSED_AT,
                    '',
                    TableInterface::column_type_DATETIME,
                    '',
                    'null',
                    '关闭时间'
                );
        }
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
                    self::fields_PLATFORM,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "shopify"',
                    '平台标识'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '订单名称'
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
                    self::fields_CUSTOMER_PHONE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '客户电话'
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
                    self::fields_TOTAL_DISCOUNTS,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '折扣总额'
                )
                ->addColumn(
                    self::fields_TOTAL_SHIPPING_PRICE,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'default 0.00',
                    '运费'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default "USD"',
                    '货币'
                )
                ->addColumn(
                    self::fields_GATEWAY,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '支付网关'
                )
                ->addColumn(
                    self::fields_PAYMENT_METHOD_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '支付方式名称'
                )
                ->addColumn(
                    self::fields_PAYMENT_METHOD_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '支付方式类型'
                )
                ->addColumn(
                    self::fields_PAYMENT_GATEWAY_NAMES,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '支付网关名称列表JSON'
                )
                ->addColumn(
                    self::fields_TRANSACTIONS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '交易信息JSON'
                )
                ->addColumn(
                    self::fields_TEST,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否测试订单'
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
                    self::fields_PROCESSED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '处理时间'
                )
                ->addColumn(
                    self::fields_CANCELLED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '取消时间'
                )
                ->addColumn(
                    self::fields_CLOSED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '关闭时间'
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
                    self::fields_CUSTOMER,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '客户信息JSON'
                )
                ->addColumn(
                    self::fields_RAW_DATA,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '原始数据JSON'
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
                    'idx_shopify_created_at',
                    self::fields_SHOPIFY_CREATED_AT,
                    'Shopify创建时间索引'
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
