<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 助手租赁记录模型
 */
class AiAssistantRental extends \Weline\Framework\Database\Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_ASSISTANT_ID = 'assistant_id';
    public const fields_OWNER_ID = 'owner_id';
    public const fields_RENTER_ID = 'renter_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_RENTAL_TYPE = 'rental_type';
    public const fields_PRICE = 'price';
    public const fields_CURRENCY = 'currency';
    public const fields_START_TIME = 'start_time';
    public const fields_END_TIME = 'end_time';
    public const fields_STATUS = 'status';
    public const fields_USAGE_COUNT = 'usage_count';
    public const fields_USAGE_LIMIT = 'usage_limit';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_PAYMENT_TRANSACTION_ID = 'payment_transaction_id';
    public const fields_PAYMENT_TIME = 'payment_time';
    public const fields_PLATFORM_COMMISSION_RATE = 'platform_commission_rate';
    public const fields_OWNER_REVENUE = 'owner_revenue';
    public const fields_PLATFORM_REVENUE = 'platform_revenue';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 租赁类型常量
    public const RENTAL_TYPE_PER_USE = 'per_use';
    public const RENTAL_TYPE_DAILY = 'daily';
    public const RENTAL_TYPE_MONTHLY = 'monthly';
    public const RENTAL_TYPE_PERMANENT = 'permanent';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 主表安装在install方法中
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
        $this->_unit_primary_keys = [self::fields_ID];
        $this->_index_sort_keys = [
            [self::fields_ASSISTANT_ID],
            [self::fields_OWNER_ID],
            [self::fields_RENTER_ID],
            [self::fields_STATUS],
        ];
        
        if (!$setup->tableExist($setup->getTableName())) {
            $setup->createTable('助手租赁记录表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INT,
                    11,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_ASSISTANT_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    '助手ID'
                )
                ->addColumn(
                    self::fields_OWNER_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    '所有者用户ID'
                )
                ->addColumn(
                    self::fields_RENTER_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    '租用者用户ID'
                )
                ->addColumn(
                    self::fields_TENANT_ID,
                    TableInterface::column_type_INT,
                    11,
                    'null',
                    '租用者租户ID'
                )
                ->addColumn(
                    self::fields_RENTAL_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '租赁类型'
                )
                ->addColumn(
                    self::fields_PRICE,
                    TableInterface::column_type_DECIMAL . '(10,4)',
                    0,
                    'not null',
                    '租赁价格'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default "USD"',
                    '货币类型'
                )
                ->addColumn(
                    self::fields_START_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'not null',
                    '开始时间'
                )
                ->addColumn(
                    self::fields_END_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '结束时间'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "active"',
                    '状态'
                )
                ->addColumn(
                    self::fields_USAGE_COUNT,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '使用次数'
                )
                ->addColumn(
                    self::fields_USAGE_LIMIT,
                    TableInterface::column_type_INT,
                    11,
                    'null',
                    '使用次数限制'
                )
                ->addColumn(
                    self::fields_PAYMENT_METHOD,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '支付方式'
                )
                ->addColumn(
                    self::fields_PAYMENT_TRANSACTION_ID,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '支付交易ID'
                )
                ->addColumn(
                    self::fields_PAYMENT_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '支付时间'
                )
                ->addColumn(
                    self::fields_PLATFORM_COMMISSION_RATE,
                    TableInterface::column_type_DECIMAL . '(5,4)',
                    0,
                    'default 0.1000',
                    '平台分成比例'
                )
                ->addColumn(
                    self::fields_OWNER_REVENUE,
                    TableInterface::column_type_DECIMAL . '(10,4)',
                    0,
                    'null',
                    '所有者收入'
                )
                ->addColumn(
                    self::fields_PLATFORM_REVENUE,
                    TableInterface::column_type_DECIMAL . '(10,4)',
                    0,
                    'null',
                    '平台收入'
                )
                ->addColumn(
                    self::fields_CREATED_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp on update current_timestamp',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_assistant',
                    self::fields_ASSISTANT_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_owner',
                    self::fields_OWNER_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_renter',
                    self::fields_RENTER_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_status',
                    self::fields_STATUS
                )
                ->create();
        }
    }
    
    /**
     * 初始化方法
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
}

