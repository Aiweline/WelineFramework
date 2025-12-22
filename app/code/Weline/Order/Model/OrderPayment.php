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
 * 支付记录模型
 */
class OrderPayment extends Model
{
    public const table = 'weline_order_payment';
    
    // 字段常量
    public const fields_ID = 'payment_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_AMOUNT = 'amount';
    public const fields_CURRENCY = 'currency';
    public const fields_TRANSACTION_ID = 'transaction_id';
    public const fields_STATUS = 'status';
    public const fields_PAID_AT = 'paid_at';
    public const fields_CREATED_AT = 'created_at';
    
    // 支付状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['payment_id'];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['payment_id', 'order_id', 'transaction_id'];
    
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
            $setup->createTable('支付记录表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '支付ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_PAYMENT_METHOD,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '支付方式'
                )
                ->addColumn(
                    self::fields_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'not null',
                    '支付金额'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default "CNY"',
                    '货币代码'
                )
                ->addColumn(
                    self::fields_TRANSACTION_ID,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '交易ID'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '支付状态'
                )
                ->addColumn(
                    self::fields_PAID_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'null',
                    '支付时间'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_order_id',
                    self::fields_ORDER_ID,
                    '订单ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_transaction_id',
                    self::fields_TRANSACTION_ID,
                    '交易ID索引'
                )
                ->create();
        }
    }
}

