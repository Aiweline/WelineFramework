<?php
declare(strict_types=1);
namespace WeShop\Subscription\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * @DESC | 订阅历史记录模型
 */
#[Table(comment: 'WeShop订阅历史表')]
#[Index(name: 'idx_subscription_id', columns: ['subscription_id'], type: 'KEY', comment: '订阅ID索引')]
#[Index(name: 'idx_action', columns: ['action'], type: 'KEY', comment: '操作类型索引')]
class SubscriptionHistory extends Model
{
    public const schema_table = 'weshop_subscription_history';
    public const schema_primary_key = 'history_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '历史ID')]
    public const schema_fields_ID = 'history_id';
    #[Col(type: 'int', nullable: false, comment: '订阅ID')]
    public const schema_fields_SUBSCRIPTION_ID = 'subscription_id';
    #[Col(type: 'int', nullable: true, default: 0, comment: '关联订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 30, nullable: false, comment: '操作类型')]
    public const schema_fields_ACTION = 'action';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: '金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '备注')]
    public const schema_fields_NOTE = 'note';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '操作者')]
    public const schema_fields_OPERATOR = 'operator';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
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
    public function getActionLabel(): string
    {
        $options = self::getActionOptions();
        $action = $this->getData(self::schema_fields_ACTION);
        return $options[$action] ?? $action;
    }
}
