<?php

declare(strict_types=1);

namespace Weline\Marketing\Model\Lifecycle;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '营销生命周期活动')]
#[Index(name: 'idx_lifecycle_status_type', columns: ['status', 'type'])]
class LifecycleCampaign extends Model
{
    public const schema_table = 'weline_marketing_lifecycle_campaign';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'status', 'type'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '活动ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'welcome_customer', comment: '类型')]
    public const schema_fields_TYPE = 'type';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'disabled', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: false, default: 0, comment: '激励券规则ID')]
    public const schema_fields_INCENTIVE_RULE_ID = 'incentive_rule_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '分群ID')]
    public const schema_fields_SEGMENT_ID = 'segment_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const TYPE_WELCOME_CUSTOMER = 'welcome_customer';
    public const TYPE_IDLE_WAKE = 'idle_wake';
    public const TYPE_BIRTHDAY = 'birthday';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
}
