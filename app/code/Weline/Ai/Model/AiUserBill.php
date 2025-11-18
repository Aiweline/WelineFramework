<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 用户账单模型
 */
class AiUserBill extends \Weline\Framework\Database\Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_BILL_TYPE = 'bill_type';
    public const fields_BILL_DATE = 'bill_date';
    public const fields_CALL_COUNT = 'call_count';
    public const fields_TOTAL_TOKENS = 'total_tokens';
    public const fields_TOTAL_COST = 'total_cost';
    public const fields_MODEL_STATS = 'model_stats';
    public const fields_RECHARGE_COUNT = 'recharge_count';
    public const fields_RECHARGE_AMOUNT = 'recharge_amount';
    public const fields_BALANCE_START = 'balance_start';
    public const fields_BALANCE_END = 'balance_end';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 账单类型常量
    public const BILL_TYPE_DAILY = 'daily';
    public const BILL_TYPE_MONTHLY = 'monthly';
    
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
            [self::fields_BILL_TYPE, self::fields_BILL_DATE],
        ];
        
        if (!$setup->tableExist($setup->getTableName())) {
            $setup->createTable('用户账单表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_BIGINT,
                    20,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    '用户ID'
                )
                ->addColumn(
                    self::fields_BILL_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '账单类型'
                )
                ->addColumn(
                    self::fields_BILL_DATE,
                    TableInterface::column_type_DATE,
                    0,
                    'not null',
                    '账单日期'
                )
                ->addColumn(
                    self::fields_CALL_COUNT,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '调用次数'
                )
                ->addColumn(
                    self::fields_TOTAL_TOKENS,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '总Token数'
                )
                ->addColumn(
                    self::fields_TOTAL_COST,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'default 0.0000',
                    '总费用'
                )
                ->addColumn(
                    self::fields_MODEL_STATS,
                    TableInterface::column_type_JSON,
                    0,
                    'null',
                    '各模型使用统计'
                )
                ->addColumn(
                    self::fields_RECHARGE_COUNT,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '充值次数'
                )
                ->addColumn(
                    self::fields_RECHARGE_AMOUNT,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'default 0.0000',
                    '充值金额'
                )
                ->addColumn(
                    self::fields_BALANCE_START,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'null',
                    '期初余额'
                )
                ->addColumn(
                    self::fields_BALANCE_END,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'null',
                    '期末余额'
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
                    'idx_user',
                    self::fields_USER_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_bill',
                    self::fields_BILL_TYPE . ',' . self::fields_BILL_DATE
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_user_bill',
                    self::fields_USER_ID . ',' . self::fields_BILL_TYPE . ',' . self::fields_BILL_DATE
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

