<?php

declare(strict_types=1);

namespace WeShop\Subscription\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * @DESC | 订阅记录模型
 */
#[Table(comment: 'WeShop订阅表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_plan_id', columns: ['plan_id'], type: 'KEY', comment: '计划ID索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
#[Index(name: 'idx_next_billing', columns: ['next_billing_at'], type: 'KEY', comment: '下次计费索引')]
class Subscription extends Model
{
    public const schema_table = 'weshop_subscription';
    public const schema_primary_key = 'subscription_id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '订阅ID')]
    public const schema_fields_ID = 'subscription_id';
    #[Col('int', 0, nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('int', 0, nullable: false, comment: '计划ID')]
    public const schema_fields_PLAN_ID = 'plan_id';
    #[Col('int', 0, nullable: false, default: 0, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col('int', 0, nullable: false, default: 0, comment: '初始订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('varchar', 30, nullable: false, default: 'active', comment: '订阅状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('decimal', '10,2', nullable: false, default: '0.00', comment: '订阅价格')]
    public const schema_fields_PRICE = 'price';
    #[Col('varchar', 10, nullable: true, default: 'USD', comment: '货币')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('varchar', 20, nullable: false, default: 'month', comment: '计费周期')]
    public const schema_fields_BILLING_CYCLE = 'billing_cycle';
    #[Col('int', 0, nullable: false, default: 1, comment: '计费间隔')]
    public const schema_fields_BILLING_INTERVAL = 'billing_interval';
    #[Col('datetime', 0, nullable: true, comment: '试用结束时间')]
    public const schema_fields_TRIAL_ENDS_AT = 'trial_ends_at';
    #[Col('datetime', 0, nullable: true, comment: '当前周期开始')]
    public const schema_fields_CURRENT_PERIOD_START = 'current_period_start';
    #[Col('datetime', 0, nullable: true, comment: '当前周期结束')]
    public const schema_fields_CURRENT_PERIOD_END = 'current_period_end';
    #[Col('datetime', 0, nullable: true, comment: '下次计费时间')]
    public const schema_fields_NEXT_BILLING_AT = 'next_billing_at';
    #[Col('datetime', 0, nullable: true, comment: '取消时间')]
    public const schema_fields_CANCELLED_AT = 'cancelled_at';
    #[Col('datetime', 0, nullable: true, comment: '暂停时间')]
    public const schema_fields_PAUSED_AT = 'paused_at';
    #[Col('varchar', 500, nullable: true, comment: '取消原因')]
    public const schema_fields_CANCEL_REASON = 'cancel_reason';
    #[Col('varchar', 50, nullable: true, comment: '支付方式')]
    public const schema_fields_PAYMENT_METHOD = 'payment_method';
    #[Col('int', 0, nullable: false, default: 0, comment: '续费次数')]
    public const schema_fields_RENEWAL_COUNT = 'renewal_count';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', 0, nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public string $indexer = 'subscription_indexer';
    public array $_unit_primary_keys = ['subscription_id'];
    public array $_index_sort_keys = ['subscription_id', 'customer_id', 'plan_id', 'status', 'next_billing_at', 'created_at'];

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

    public function getStatusLabel(): string
    {
        $options = self::getStatusOptions();
        $status = $this->getData(self::schema_fields_STATUS);
        return $options[$status] ?? $status;
    }

    public function isActive(): bool
    {
        return in_array($this->getData(self::schema_fields_STATUS), [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    public function canCancel(): bool
    {
        return in_array($this->getData(self::schema_fields_STATUS), [self::STATUS_ACTIVE, self::STATUS_TRIALING, self::STATUS_PAUSED, self::STATUS_PAST_DUE]);
    }

    public function canPause(): bool
    {
        return in_array($this->getData(self::schema_fields_STATUS), [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    public function canResume(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_PAUSED;
    }
}

