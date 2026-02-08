<?php

declare(strict_types=1);

namespace WeShop\Subscription\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * @DESC | 订阅记录模型
 */
class Subscription extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_subscription';
    public const primary_key = 'subscription_id';

    public const fields_ID = 'subscription_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_PLAN_ID = 'plan_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_STATUS = 'status';
    public const fields_PRICE = 'price';
    public const fields_CURRENCY = 'currency';
    public const fields_BILLING_CYCLE = 'billing_cycle';
    public const fields_BILLING_INTERVAL = 'billing_interval';
    public const fields_TRIAL_ENDS_AT = 'trial_ends_at';
    public const fields_CURRENT_PERIOD_START = 'current_period_start';
    public const fields_CURRENT_PERIOD_END = 'current_period_end';
    public const fields_NEXT_BILLING_AT = 'next_billing_at';
    public const fields_CANCELLED_AT = 'cancelled_at';
    public const fields_PAUSED_AT = 'paused_at';
    public const fields_CANCEL_REASON = 'cancel_reason';
    public const fields_PAYMENT_METHOD = 'payment_method';
    public const fields_RENEWAL_COUNT = 'renewal_count';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 订阅状态常量
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public string $indexer = 'subscription_indexer';
    public array $_unit_primary_keys = ['subscription_id'];
    public array $_index_sort_keys = ['subscription_id', 'customer_id', 'plan_id', 'status', 'next_billing_at', 'created_at'];

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
            $setup->createTable('WeShop订阅表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '订阅ID')
                ->addColumn(self::fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, 0, 'not null', '客户ID')
                ->addColumn(self::fields_PLAN_ID, TableInterface::column_type_INTEGER, 0, 'not null', '计划ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null default 0', '产品ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, 0, 'not null default 0', '初始订单ID')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 30, "not null default 'active'", '订阅状态')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '订阅价格')
                ->addColumn(self::fields_CURRENCY, TableInterface::column_type_VARCHAR, 10, "default 'USD'", '货币')
                ->addColumn(self::fields_BILLING_CYCLE, TableInterface::column_type_VARCHAR, 20, "not null default 'month'", '计费周期')
                ->addColumn(self::fields_BILLING_INTERVAL, TableInterface::column_type_INTEGER, 0, 'not null default 1', '计费间隔')
                ->addColumn(self::fields_TRIAL_ENDS_AT, TableInterface::column_type_DATETIME, 0, '', '试用结束时间')
                ->addColumn(self::fields_CURRENT_PERIOD_START, TableInterface::column_type_DATETIME, 0, '', '当前周期开始')
                ->addColumn(self::fields_CURRENT_PERIOD_END, TableInterface::column_type_DATETIME, 0, '', '当前周期结束')
                ->addColumn(self::fields_NEXT_BILLING_AT, TableInterface::column_type_DATETIME, 0, '', '下次计费时间')
                ->addColumn(self::fields_CANCELLED_AT, TableInterface::column_type_DATETIME, 0, '', '取消时间')
                ->addColumn(self::fields_PAUSED_AT, TableInterface::column_type_DATETIME, 0, '', '暂停时间')
                ->addColumn(self::fields_CANCEL_REASON, TableInterface::column_type_VARCHAR, 500, '', '取消原因')
                ->addColumn(self::fields_PAYMENT_METHOD, TableInterface::column_type_VARCHAR, 50, '', '支付方式')
                ->addColumn(self::fields_RENEWAL_COUNT, TableInterface::column_type_INTEGER, 0, 'not null default 0', '续费次数')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_plan_id', self::fields_PLAN_ID, '计划ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_next_billing', self::fields_NEXT_BILLING_AT, '下次计费索引')
                ->create();
        }
    }

    /**
     * 获取状态选项
     *
     * @return array
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_TRIALING  => __('试用中'),
            self::STATUS_ACTIVE    => __('生效中'),
            self::STATUS_PAUSED    => __('已暂停'),
            self::STATUS_PAST_DUE  => __('逾期'),
            self::STATUS_CANCELLED => __('已取消'),
            self::STATUS_EXPIRED   => __('已过期'),
        ];
    }

    /**
     * 获取状态显示文本
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        $options = self::getStatusOptions();
        $status = $this->getData(self::fields_STATUS);
        return $options[$status] ?? $status;
    }

    /**
     * 是否处于活跃状态
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return in_array($this->getData(self::fields_STATUS), [
            self::STATUS_ACTIVE,
            self::STATUS_TRIALING,
        ]);
    }

    /**
     * 是否可以取消
     *
     * @return bool
     */
    public function canCancel(): bool
    {
        return in_array($this->getData(self::fields_STATUS), [
            self::STATUS_ACTIVE,
            self::STATUS_TRIALING,
            self::STATUS_PAUSED,
            self::STATUS_PAST_DUE,
        ]);
    }

    /**
     * 是否可以暂停
     *
     * @return bool
     */
    public function canPause(): bool
    {
        return in_array($this->getData(self::fields_STATUS), [
            self::STATUS_ACTIVE,
            self::STATUS_TRIALING,
        ]);
    }

    /**
     * 是否可以恢复
     *
     * @return bool
     */
    public function canResume(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_PAUSED;
    }
}
