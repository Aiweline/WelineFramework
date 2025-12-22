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
 * 发货记录模型
 */
class OrderShipment extends Model
{
    public const table = 'weline_order_shipment';
    
    // 字段常量
    public const fields_ID = 'shipment_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_TRACKING_NUMBER = 'tracking_number';
    public const fields_CARRIER = 'carrier';
    public const fields_STATUS = 'status';
    public const fields_SHIPPED_AT = 'shipped_at';
    public const fields_DELIVERED_AT = 'delivered_at';
    public const fields_CREATED_AT = 'created_at';
    
    // 发货状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['shipment_id'];
    
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['shipment_id', 'order_id', 'tracking_number'];
    
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
            $setup->createTable('发货记录表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '发货ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '订单ID'
                )
                ->addColumn(
                    self::fields_TRACKING_NUMBER,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '物流单号'
                )
                ->addColumn(
                    self::fields_CARRIER,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '承运商'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default "pending"',
                    '发货状态'
                )
                ->addColumn(
                    self::fields_SHIPPED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'null',
                    '发货时间'
                )
                ->addColumn(
                    self::fields_DELIVERED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    0,
                    'null',
                    '送达时间'
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
                    'idx_tracking_number',
                    self::fields_TRACKING_NUMBER,
                    '物流单号索引'
                )
                ->create();
        }
    }
}

