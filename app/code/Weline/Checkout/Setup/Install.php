<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * Checkout Module Installation Script
 *
 * Handles database schema creation for the Weline_Checkout module.
 * Connection is obtained via Setup::getDb() (set by setModuleContext before setup runs).
 *
 * @package Weline_Checkout
 */
class Install implements InstallInterface
{
    /**
     * Execute installation
     * 
     * Creates all required database tables and indexes for the Checkout module.
     * 
     * @param Setup $setup Setup instance
     * @param Context $context Installation context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        $connection = $setup->getDb();
        
        // 创建订单表
        $connection->createTable('weline_checkout_order', '订单表')
            ->addColumn('order_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '订单ID')
            ->addColumn('order_number', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 64, 'not null', '订单号')
            ->addColumn('customer_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '客户ID')
            ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '订单状态（pending, processing, completed, cancelled, refunded）')
            ->addColumn('subtotal', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '商品小计')
            ->addColumn('shipping_amount', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '运费')
            ->addColumn('tax_amount', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '税费')
            ->addColumn('discount_amount', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '折扣金额')
            ->addColumn('total_amount', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '订单总额')
            ->addColumn('currency', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 10, 'default \'CNY\'', '货币代码')
            ->addColumn('shipping_address', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '收货地址（JSON）')
            ->addColumn('billing_address', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '账单地址（JSON）')
            ->addColumn('payment_method', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, '', '支付方式')
            ->addColumn('payment_status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '支付状态（pending, paid, failed, refunded）')
            ->addColumn('shipping_method', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, '', '配送方式')
            ->addColumn('shipping_status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '配送状态（pending, shipped, delivered）')
            ->addColumn('remark', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '订单备注')
            ->addColumn('created_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addColumn('updated_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
            ->addIndex('UNIQUE', 'idx_weline_checkout_order_number', ['order_number'])
            ->addIndex('INDEX', 'idx_weline_checkout_order_customer', ['customer_id'])
            ->addIndex('INDEX', 'idx_weline_checkout_order_status', ['status'])
            ->addIndex('INDEX', 'idx_weline_checkout_order_payment_status', ['payment_status'])
            ->addIndex('INDEX', 'idx_weline_checkout_order_created_time', ['created_time'])
            ->create();
        
        // 创建订单项表
        $connection->createTable('weline_checkout_order_item', '订单项表')
            ->addColumn('item_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '订单项ID')
            ->addColumn('order_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '订单ID')
            ->addColumn('product_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '产品ID')
            ->addColumn('product_name', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '产品名称')
            ->addColumn('product_sku', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, '', '产品SKU')
            ->addColumn('quantity', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 1', '数量')
            ->addColumn('price', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '单价')
            ->addColumn('total_price', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '总价')
            ->addColumn('attributes', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '产品属性（JSON）')
            ->addColumn('created_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addIndex('INDEX', 'idx_weline_checkout_order_item_order', ['order_id'])
            ->addIndex('INDEX', 'idx_weline_checkout_order_item_product', ['product_id'])
            ->addIndex('INDEX', 'idx_weline_checkout_order_item_sku', ['product_sku'])
            ->create();
        
        // 创建支付交易表
        $connection->createTable('weline_checkout_payment_transaction', '支付交易表')
            ->addColumn('transaction_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '交易ID')
            ->addColumn('order_id', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '订单ID')
            ->addColumn('payment_method', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '支付方式')
            ->addColumn('transaction_number', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, '', '交易号')
            ->addColumn('amount', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '交易金额')
            ->addColumn('currency', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 10, 'default \'CNY\'', '货币代码')
            ->addColumn('status', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '交易状态（pending, success, failed, refunded）')
            ->addColumn('gateway_response', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, '', '支付网关响应（JSON）')
            ->addColumn('created_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
            ->addColumn('updated_time', \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DATETIME, null, 'not null', '更新时间')
            ->addIndex('INDEX', 'idx_weline_checkout_payment_transaction_order', ['order_id'])
            ->addIndex('INDEX', 'idx_weline_checkout_payment_transaction_number', ['transaction_number'])
            ->addIndex('INDEX', 'idx_weline_checkout_payment_transaction_status', ['status'])
            ->addIndex('INDEX', 'idx_weline_checkout_payment_transaction_created_time', ['created_time'])
            ->create();
    }
}

