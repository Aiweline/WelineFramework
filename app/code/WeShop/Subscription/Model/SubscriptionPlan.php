<?php

declare(strict_types=1);

namespace WeShop\Subscription\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * @DESC | 订阅计划模型
 */
class SubscriptionPlan extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_subscription_plan';
    public const primary_key = 'plan_id';

    public const fields_ID = 'plan_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_PRICE = 'price';
    public const fields_ORIGINAL_PRICE = 'original_price';
    public const fields_BILLING_CYCLE = 'billing_cycle';
    public const fields_BILLING_INTERVAL = 'billing_interval';
    public const fields_TRIAL_DAYS = 'trial_days';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 计费周期常量
    public const CYCLE_DAY = 'day';
    public const CYCLE_WEEK = 'week';
    public const CYCLE_MONTH = 'month';
    public const CYCLE_YEAR = 'year';

    // 状态常量
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    public string $indexer = 'subscription_plan_indexer';
    public array $_unit_primary_keys = ['plan_id'];
    public array $_index_sort_keys = ['plan_id', 'product_id', 'status', 'sort_order'];

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
            $setup->createTable('WeShop订阅计划表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '计划ID')
                ->addColumn(self::fields_PRODUCT_ID, TableInterface::column_type_INTEGER, 0, 'not null default 0', '关联产品ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '计划名称')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, 0, '', '计划描述')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'not null default 0.00', '订阅价格')
                ->addColumn(self::fields_ORIGINAL_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '原价')
                ->addColumn(self::fields_BILLING_CYCLE, TableInterface::column_type_VARCHAR, 20, "not null default 'month'", '计费周期(day/week/month/year)')
                ->addColumn(self::fields_BILLING_INTERVAL, TableInterface::column_type_INTEGER, 0, 'not null default 1', '计费间隔')
                ->addColumn(self::fields_TRIAL_DAYS, TableInterface::column_type_INTEGER, 0, 'not null default 0', '试用天数')
                ->addColumn(self::fields_SORT_ORDER, TableInterface::column_type_INTEGER, 0, 'not null default 0', '排序')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_SMALLINT, 1, 'not null default 1', '状态')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_product_id', self::fields_PRODUCT_ID, '产品ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->create();
        }
    }

    /**
     * 获取计费周期选项
     *
     * @return array
     */
    public static function getBillingCycleOptions(): array
    {
        return [
            self::CYCLE_DAY   => __('天'),
            self::CYCLE_WEEK  => __('周'),
            self::CYCLE_MONTH => __('月'),
            self::CYCLE_YEAR  => __('年'),
        ];
    }

    /**
     * 获取计费周期显示文本
     *
     * @return string
     */
    public function getBillingCycleLabel(): string
    {
        $options = self::getBillingCycleOptions();
        $cycle = $this->getData(self::fields_BILLING_CYCLE);
        $interval = (int)$this->getData(self::fields_BILLING_INTERVAL);

        $cycleLabel = $options[$cycle] ?? $cycle;

        if ($interval > 1) {
            return __('每%{interval}%{cycle}', ['interval' => $interval, 'cycle' => $cycleLabel]);
        }

        return __('每%{cycle}', ['cycle' => $cycleLabel]);
    }
}
