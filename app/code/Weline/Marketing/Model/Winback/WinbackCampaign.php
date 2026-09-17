<?php

declare(strict_types=1);

namespace Weline\Marketing\Model\Winback;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 营销挽回活动（独立表，勿复用促销 Campaign）。
 */
#[Table(comment: '营销挽回活动')]
#[Index(name: 'idx_winback_status_type', columns: ['status', 'type'])]
class WinbackCampaign extends Model
{
    public const schema_table = 'weline_marketing_winback_campaign';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'status', 'type', 'website_id'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '活动ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: '活动名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'unpaid_order_reminder', comment: '活动类型')]
    public const schema_fields_TYPE = 'type';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'disabled', comment: '状态 enabled/disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: false, default: 24, comment: '下单后若干小时才开始挽回')]
    public const schema_fields_ABANDON_AFTER_HOURS = 'abandon_after_hours';

    #[Col(type: 'int', nullable: false, default: 1, comment: '最大步骤数')]
    public const schema_fields_MAX_STEPS = 'max_steps';

    #[Col(type: 'int', nullable: false, default: 24, comment: '步骤间隔小时')]
    public const schema_fields_STEP_INTERVAL_HOURS = 'step_interval_hours';

    #[Col(type: 'int', nullable: false, default: 168, comment: '同一订单冷却小时')]
    public const schema_fields_COOLDOWN_HOURS = 'cooldown_hours';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID，0=全部')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '激励券规则ID，step≥2 时发券；0=不发')]
    public const schema_fields_INCENTIVE_RULE_ID = 'incentive_rule_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '分群ID，0=全部')]
    public const schema_fields_SEGMENT_ID = 'segment_id';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const TYPE_UNPAID_ORDER_REMINDER = 'unpaid_order_reminder';
    public const TYPE_CHECKOUT_ABANDON_REMINDER = 'checkout_abandon_reminder';
    public const TYPE_CART_ABANDON_REMINDER = 'cart_abandon_reminder';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_UNPAID_ORDER_REMINDER,
            self::TYPE_CHECKOUT_ABANDON_REMINDER,
            self::TYPE_CART_ABANDON_REMINDER,
        ];
    }
}
