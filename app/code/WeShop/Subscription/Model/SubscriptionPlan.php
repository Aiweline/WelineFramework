<?php
declare(strict_types=1);
namespace WeShop\Subscription\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * @DESC | 订阅计划模型
 */
#[Table(comment: 'WeShop订阅计划表')]
#[Index(name: 'idx_product_id', columns: ['product_id'], comment: '产品ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
class SubscriptionPlan extends Model
{
    public const schema_table = 'weshop_subscription_plan';
    public const schema_primary_key = 'plan_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '计划ID')]
    public const schema_fields_ID = 'plan_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '关联产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '计划名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'text', nullable: true, comment: '计划描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: '0.00', comment: '订阅价格')]
    public const schema_fields_PRICE = 'price';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: '原价')]
    public const schema_fields_ORIGINAL_PRICE = 'original_price';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'month', comment: '计费周期(day/week/month/year)')]
    public const schema_fields_BILLING_CYCLE = 'billing_cycle';
    #[Col(type: 'int', nullable: false, default: 1, comment: '计费间隔')]
    public const schema_fields_BILLING_INTERVAL = 'billing_interval';
    #[Col(type: 'int', nullable: false, default: 0, comment: '试用天数')]
    public const schema_fields_TRIAL_DAYS = 'trial_days';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const CYCLE_DAY = 'day';
    public const CYCLE_WEEK = 'week';
    public const CYCLE_MONTH = 'month';
    public const CYCLE_YEAR = 'year';
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;
    public string $indexer = 'subscription_plan_indexer';
    public array $_unit_primary_keys = ['plan_id'];
    public array $_index_sort_keys = ['plan_id', 'product_id', 'status', 'sort_order'];
    public static function getBillingCycleOptions(): array
    {
        return [
            self::CYCLE_DAY   => __('天'),
            self::CYCLE_WEEK  => __('周'),
            self::CYCLE_MONTH => __('月'),
            self::CYCLE_YEAR  => __('年'),
        ];
    }
    public function getBillingCycleLabel(): string
    {
        $options = self::getBillingCycleOptions();
        $cycle = $this->getData(self::schema_fields_BILLING_CYCLE);
        $interval = (int)$this->getData(self::schema_fields_BILLING_INTERVAL);
        $cycleLabel = $options[$cycle] ?? $cycle;
        if ($interval > 1) {
            return __('每%{interval}%{cycle}', ['interval' => $interval, 'cycle' => $cycleLabel]);
        }
        return __('每%{cycle}', ['cycle' => $cycleLabel]);
    }
}
