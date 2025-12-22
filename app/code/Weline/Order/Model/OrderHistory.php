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
 * 订单历史模型
 */
class OrderHistory extends Model
{
    public const table = 'weline_order_history';
    
    // 字段常量
    public const fields_ID = 'history_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_STATUS = 'status';
    public const fields_COMMENT = 'comment';
    public const fields_IS_CUSTOMER_NOTIFIED = 'is_customer_notified';
    public const fields_CREATED_BY = 'created_by';
    public const fields_CREATED_AT = 'created_at';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['history_id'];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['history_id', 'order_id', 'created_at'];
    
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
            $setup->createTable('订单历史表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '历史ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '状态'
                )
                ->addColumn(
                    self::fields_COMMENT,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '备注'
                )
                ->addColumn(
                    self::fields_IS_CUSTOMER_NOTIFIED,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否通知客户'
                )
                ->addColumn(
                    self::fields_CREATED_BY,
                    TableInterface::column_type_INTEGER,
                    11,
                    'null',
                    '创建人ID'
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
                    'idx_created_at',
                    self::fields_CREATED_AT,
                    '创建时间索引'
                )
                ->create();
        }
    }
}

