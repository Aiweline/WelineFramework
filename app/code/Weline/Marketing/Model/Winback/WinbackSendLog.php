<?php

declare(strict_types=1);

namespace Weline\Marketing\Model\Winback;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 营销挽回发送日志（独立表）。
 */
#[Table(comment: '营销挽回发送日志')]
#[Index(name: 'uniq_winback_campaign_order_step', columns: ['campaign_id', 'order_uuid', 'step'], type: 'UNIQUE')]
#[Index(name: 'idx_winback_send_campaign', columns: ['campaign_id', 'sent_at'])]
class WinbackSendLog extends Model
{
    public const schema_table = 'weline_marketing_winback_send_log';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'campaign_id', 'order_uuid', 'step', 'status'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'int', nullable: false, comment: '挽回活动ID')]
    public const schema_fields_CAMPAIGN_ID = 'campaign_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '订单 UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col(type: 'int', nullable: false, default: 1, comment: '步骤')]
    public const schema_fields_STEP = 'step';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'sent', comment: '状态 sent|skipped|failed')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '原因')]
    public const schema_fields_REASON = 'reason';

    #[Col(type: 'datetime', nullable: true, comment: '发送/判定时间')]
    public const schema_fields_SENT_AT = 'sent_at';

    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
}
