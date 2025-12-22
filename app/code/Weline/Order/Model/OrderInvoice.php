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
 * 发票模型
 */
class OrderInvoice extends Model
{
    public const table = 'weline_order_invoice';
    
    // 字段常量
    public const fields_ID = 'invoice_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_INVOICE_NUMBER = 'invoice_number';
    public const fields_AMOUNT = 'amount';
    public const fields_STATUS = 'status';
    public const fields_ISSUED_AT = 'issued_at';
    public const fields_CREATED_AT = 'created_at';
    
    // 发票状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_CANCELLED = 'cancelled';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['invoice_id'];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['invoice_id', 'order_id', 'invoice_number'];
    
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
            $setup->createTable('发票表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '发票ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_INVOICE_NUMBER,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null unique',
                    '发票号'
                )
                ->addColumn(
                    self::fields_AMOUNT,
                    TableInterface::column_type_DECIMAL,
                    '10,2',
                    'not null',
                    '发票金额'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '发票状态'
                )
                ->addColumn(
                    self::fields_ISSUED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'null',
                    '开具时间'
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
                    TableInterface::index_type_UNIQUE,
                    'idx_invoice_number',
                    self::fields_INVOICE_NUMBER,
                    '发票号唯一索引'
                )
                ->create();
        }
    }
    
    /**
     * 生成发票号
     */
    public function generateInvoiceNumber(): string
    {
        return 'INV' . date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}

