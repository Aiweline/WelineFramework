<?php

declare(strict_types=1);

namespace WeShop\Order\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Order extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_order';
    public const primary_key = 'order_id';
    
    public const fields_ID = 'order_id';
    public const fields_increment_id = 'increment_id';
    public const fields_customer_id = 'customer_id';
    public const fields_status = 'status';
    public const fields_total = 'total';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

    public array $_unit_primary_keys = ['order_id'];

    public function setup(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('WeShop订单表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '订单ID')
                ->addColumn(self::fields_increment_id, TableInterface::column_type_VARCHAR, 32, 'not null unique', '订单号')
                ->addColumn(self::fields_customer_id, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_status, TableInterface::column_type_VARCHAR, 50, "default 'pending'", '订单状态')
                ->addColumn(self::fields_total, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '订单总额')
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
