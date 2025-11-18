<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 用户充值记录模型
 */
class AiUserRecharge extends \Weline\Framework\Database\Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_AMOUNT = 'amount';
    public const fields_CURRENCY = 'currency';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_PAYMENT_TRANSACTION_ID = 'payment_transaction_id';
    public const fields_PAYMENT_STATUS = 'payment_status';
    public const fields_PAYMENT_TIME = 'payment_time';
    public const fields_BALANCE_BEFORE = 'balance_before';
    public const fields_BALANCE_AFTER = 'balance_after';
    public const fields_BONUS_AMOUNT = 'bonus_amount';
    public const fields_PROMOTION_ID = 'promotion_id';
    public const fields_REMARK = 'remark';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';
    
    // 支付方式常量
    public const PAYMENT_METHOD_ALIPAY = 'alipay';
    public const PAYMENT_METHOD_WECHAT = 'wechat';
    public const PAYMENT_METHOD_BANK = 'bank';
    public const PAYMENT_METHOD_PAYPAL = 'paypal';
    public const PAYMENT_METHOD_BALANCE = 'balance';
    
    // 支付状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
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
            [self::fields_USER_ID],
            [self::fields_PAYMENT_STATUS],
            [self::fields_CREATED_TIME],
        ];
        
        if (!$setup->tableExist()) {
            $setup->createTable('用户充值记录表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '用户ID'
                )
                ->addColumn(
                    self::fields_TENANT_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'null',
                    '租户ID'
                )
                ->addColumn(
                    self::fields_AMOUNT,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'not null',
                    '充值金额'
                )
                ->addColumn(
                    self::fields_CURRENCY,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default "CNY"',
                    '货币类型'
                )
                ->addColumn(
                    self::fields_PAYMENT_METHOD,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
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
                    self::fields_PAYMENT_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default "pending"',
                    '支付状态'
                )
                ->addColumn(
                    self::fields_PAYMENT_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '支付时间'
                )
                ->addColumn(
                    self::fields_BALANCE_BEFORE,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'null',
                    '充值前余额'
                )
                ->addColumn(
                    self::fields_BALANCE_AFTER,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'null',
                    '充值后余额'
                )
                ->addColumn(
                    self::fields_BONUS_AMOUNT,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'default 0.0000',
                    '赠送金额'
                )
                ->addColumn(
                    self::fields_PROMOTION_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'null',
                    '优惠活动ID'
                )
                ->addColumn(
                    self::fields_REMARK,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '备注'
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
                    TableInterface::index_type_KEY,
                    'idx_user',
                    self::fields_USER_ID,
                    '用户索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_PAYMENT_STATUS,
                    '支付状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_time',
                    self::fields_CREATED_TIME,
                    '创建时间索引'
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

