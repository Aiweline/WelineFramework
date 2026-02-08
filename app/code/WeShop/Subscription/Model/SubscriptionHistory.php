<?php

declare(strict_types=1);

namespace WeShop\Subscription\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * @DESC | 订阅历史记录模型
 */
class SubscriptionHistory extends \Weline\Framework\Database\Model
{
    public const table = 'weshop_subscription_history';
    public const primary_key = 'history_id';

    public const fields_ID = 'history_id';
    public const fields_SUBSCRIPTION_ID = 'subscription_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_ACTION = 'action';
    public const fields_AMOUNT = 'amount';
    public const fields_NOTE = 'note';
    public const fields_OPERATOR = 'operator';
    public const fields_CREATED_AT = 'created_at';

    // 操作类型常量
    public const ACTION_CREATED = 'created';
    public const ACTION_RENEWED = 'renewed';
    public const ACTION_CANCELLED = 'cancelled';
    public const ACTION_PAUSED = 'paused';
    public const ACTION_RESUMED = 'resumed';
    public const ACTION_EXPIRED = 'expired';
    public const ACTION_UPGRADED = 'upgraded';
    public const ACTION_DOWNGRADED = 'downgraded';
    public const ACTION_PAYMENT_FAILED = 'payment_failed';
    public const ACTION_TRIAL_STARTED = 'trial_started';
    public const ACTION_TRIAL_ENDED = 'trial_ended';

    public string $indexer = 'subscription_history_indexer';
    public array $_unit_primary_keys = ['history_id'];
    public array $_index_sort_keys = ['subscription_id', 'action', 'created_at'];

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
            $setup->createTable('WeShop订阅历史表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '历史ID')
                ->addColumn(self::fields_SUBSCRIPTION_ID, TableInterface::column_type_INTEGER, 0, 'not null', '订阅ID')
                ->addColumn(self::fields_ORDER_ID, TableInterface::column_type_INTEGER, 0, 'default 0', '关联订单ID')
                ->addColumn(self::fields_ACTION, TableInterface::column_type_VARCHAR, 30, 'not null', '操作类型')
                ->addColumn(self::fields_AMOUNT, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '金额')
                ->addColumn(self::fields_NOTE, TableInterface::column_type_VARCHAR, 500, '', '备注')
                ->addColumn(self::fields_OPERATOR, TableInterface::column_type_VARCHAR, 100, '', '操作者')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_subscription_id', self::fields_SUBSCRIPTION_ID, '订阅ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_action', self::fields_ACTION, '操作类型索引')
                ->create();
        }
    }

    /**
     * 获取操作类型选项
     *
     * @return array
     */
    public static function getActionOptions(): array
    {
        return [
            self::ACTION_CREATED        => __('创建订阅'),
            self::ACTION_RENEWED        => __('续费'),
            self::ACTION_CANCELLED      => __('取消订阅'),
            self::ACTION_PAUSED         => __('暂停订阅'),
            self::ACTION_RESUMED        => __('恢复订阅'),
            self::ACTION_EXPIRED        => __('订阅过期'),
            self::ACTION_UPGRADED       => __('升级计划'),
            self::ACTION_DOWNGRADED     => __('降级计划'),
            self::ACTION_PAYMENT_FAILED => __('支付失败'),
            self::ACTION_TRIAL_STARTED  => __('试用开始'),
            self::ACTION_TRIAL_ENDED    => __('试用结束'),
        ];
    }

    /**
     * 获取操作显示文本
     *
     * @return string
     */
    public function getActionLabel(): string
    {
        $options = self::getActionOptions();
        $action = $this->getData(self::fields_ACTION);
        return $options[$action] ?? $action;
    }
}
