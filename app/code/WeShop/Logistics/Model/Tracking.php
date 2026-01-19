<?php

declare(strict_types=1);

namespace WeShop\Logistics\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 物流追踪模型
 */
class Tracking extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_tracking';
    public const primary_key = 'tracking_id';
    
    public const fields_ID = 'tracking_id';
    public const fields_order_id = 'order_id';
    public const fields_tracking_number = 'tracking_number';
    public const fields_carrier = 'carrier';
    public const fields_status = 'status';
    public const fields_location = 'location';
    public const fields_description = 'description';
    public const fields_tracked_at = 'tracked_at';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

    public array $_unit_primary_keys = ['tracking_id'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('WeShop物流追踪表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '追踪ID')
                ->addColumn(self::fields_order_id, TableInterface::column_type_INTEGER, 0, 'not null', '订单ID')
                ->addColumn(self::fields_tracking_number, TableInterface::column_type_VARCHAR, 100, 'not null', '物流单号')
                ->addColumn(self::fields_carrier, TableInterface::column_type_VARCHAR, 50, 'not null', '承运商')
                ->addColumn(self::fields_status, TableInterface::column_type_VARCHAR, 50, '', '状态')
                ->addColumn(self::fields_location, TableInterface::column_type_VARCHAR, 255, '', '位置')
                ->addColumn(self::fields_description, TableInterface::column_type_TEXT, 0, '', '描述')
                ->addColumn(self::fields_tracked_at, TableInterface::column_type_DATETIME, 0, '', '追踪时间')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, '', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        $this->setup($setup, $context);
    }
}
