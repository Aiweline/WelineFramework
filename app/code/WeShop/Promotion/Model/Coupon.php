<?php

declare(strict_types=1);

namespace WeShop\Promotion\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 优惠券模型
 */
class Coupon extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_coupon';
    public const primary_key = 'coupon_id';
    public string $indexer = 'coupon_indexer';
    
    public const fields_ID = 'coupon_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DISCOUNT_TYPE = 'discount_type';
    public const fields_DISCOUNT_VALUE = 'discount_value';
    public const fields_MIN_AMOUNT = 'min_amount';
    public const fields_MAX_DISCOUNT = 'max_discount';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    public array $_unit_primary_keys = ['coupon_id'];
    public array $_index_sort_keys = ['code', 'is_active'];
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('WeShop优惠券表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '优惠券ID')
                ->addColumn(self::fields_CODE, TableInterface::column_type_VARCHAR, 50, 'not null unique', '优惠券代码')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '名称')
                ->addColumn(self::fields_DISCOUNT_TYPE, TableInterface::column_type_VARCHAR, 20, "default 'fixed'", '折扣类型（fixed/percent）')
                ->addColumn(self::fields_DISCOUNT_VALUE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '折扣值')
                ->addColumn(self::fields_MIN_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '最低消费金额')
                ->addColumn(self::fields_MAX_DISCOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '最大折扣金额')
                ->addColumn(self::fields_START_DATE, TableInterface::column_type_DATETIME, 0, 'not null', '开始日期')
                ->addColumn(self::fields_END_DATE, TableInterface::column_type_DATETIME, 0, 'not null', '结束日期')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 1', '是否启用')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_code', self::fields_CODE, '优惠券代码唯一索引')
                ->create();
        }
    }
}
