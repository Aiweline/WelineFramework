<?php

declare(strict_types=1);

namespace Weline\Marketing\Model\Lifecycle;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '营销生命周期发送日志')]
#[Index(name: 'uniq_lifecycle_campaign_customer', columns: ['campaign_id', 'customer_id'], type: 'UNIQUE')]
class LifecycleSendLog extends Model
{
    public const schema_table = 'weline_marketing_lifecycle_send_log';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'campaign_id', 'customer_id', 'status'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'int', nullable: false, comment: '活动ID')]
    public const schema_fields_CAMPAIGN_ID = 'campaign_id';

    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'sent', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '券码')]
    public const schema_fields_COUPON_CODE = 'coupon_code';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '原因')]
    public const schema_fields_REASON = 'reason';

    #[Col(type: 'datetime', nullable: true, comment: '发送时间')]
    public const schema_fields_SENT_AT = 'sent_at';

    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
}
