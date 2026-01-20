<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 助手收入统计模型
 */
class AiAssistantRevenue extends \Weline\Framework\Database\Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_ASSISTANT_ID = 'assistant_id';
    public const fields_PERIOD_TYPE = 'period_type';
    public const fields_PERIOD_DATE = 'period_date';
    public const fields_RENTAL_COUNT = 'rental_count';
    public const fields_USAGE_COUNT = 'usage_count';
    public const fields_GROSS_REVENUE = 'gross_revenue';
    public const fields_PLATFORM_COMMISSION = 'platform_commission';
    public const fields_NET_REVENUE = 'net_revenue';
    public const fields_NEW_RENTERS = 'new_renters';
    public const fields_ACTIVE_RENTERS = 'active_renters';
    public const fields_RATING_AVERAGE = 'rating_average';
    public const fields_RATING_COUNT = 'rating_count';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 周期类型常量
    public const PERIOD_TYPE_DAILY = 'daily';
    public const PERIOD_TYPE_MONTHLY = 'monthly';
    public const PERIOD_TYPE_YEARLY = 'yearly';
    public const PERIOD_TYPE_TOTAL = 'total';
    
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
            [self::fields_USER_ID],
            [self::fields_ASSISTANT_ID],
            [self::fields_PERIOD_TYPE, self::fields_PERIOD_DATE],
        ];
        
        if (!$setup->tableExist()) {
            $setup->createTable('助手收入统计表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '用户ID（所有者）'
                )
                ->addColumn(
                    self::fields_ASSISTANT_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'null',
                    '助手ID'
                )
                ->addColumn(
                    self::fields_PERIOD_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '统计周期'
                )
                ->addColumn(
                    self::fields_PERIOD_DATE,
                    TableInterface::column_type_DATE,
                    0,
                    'not null',
                    '统计日期'
                )
                ->addColumn(
                    self::fields_RENTAL_COUNT,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '租赁次数'
                )
                ->addColumn(
                    self::fields_USAGE_COUNT,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '使用次数'
                )
                ->addColumn(
                    self::fields_GROSS_REVENUE,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'default 0.0000',
                    '总收入'
                )
                ->addColumn(
                    self::fields_PLATFORM_COMMISSION,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'default 0.0000',
                    '平台分成'
                )
                ->addColumn(
                    self::fields_NET_REVENUE,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'default 0.0000',
                    '净收入'
                )
                ->addColumn(
                    self::fields_NEW_RENTERS,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '新增租用者'
                )
                ->addColumn(
                    self::fields_ACTIVE_RENTERS,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '活跃租用者'
                )
                ->addColumn(
                    self::fields_RATING_AVERAGE,
                    TableInterface::column_type_DECIMAL . '(3,2)',
                    0,
                    'default 0.00',
                    '平均评分'
                )
                ->addColumn(
                    self::fields_RATING_COUNT,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '评分数量'
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
                    TableInterface::index_type_DEFAULT,
                    'idx_user',
                    self::fields_USER_ID
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_assistant',
                    self::fields_ASSISTANT_ID
                )
                ->addIndex(
                    TableInterface::index_type_DEFAULT,
                    'idx_period',
                    self::fields_PERIOD_TYPE . ',' . self::fields_PERIOD_DATE
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

