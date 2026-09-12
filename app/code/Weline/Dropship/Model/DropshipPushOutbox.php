<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Dropship push outbox')]
#[Index(name: 'uk_dropship_outbox_biz', columns: ['biz_key'], type: 'UNIQUE')]
#[Index(name: 'idx_dropship_outbox_status', columns: ['status', 'provider_code'])]
class DropshipPushOutbox extends Model
{
    public const schema_table = 'weline_dropship_push_outbox';
    public const schema_primary_key = 'outbox_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';
    /** 不可履约终态：停 Cron/队列重试；可触发自动退款补偿。 */
    public const STATUS_DEAD = 'dead';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'outbox_id';

    #[Col('varchar', 191, nullable: false, comment: 'Idempotency biz key')]
    public const schema_fields_BIZ_KEY = 'biz_key';

    #[Col('varchar', 64, nullable: false, comment: 'Provider')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 32, nullable: false, comment: 'create|cancel|sync')]
    public const schema_fields_ACTION = 'action';

    #[Col('varchar', 64, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';

    #[Col('text', nullable: true, comment: 'Payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Attempts')]
    public const schema_fields_ATTEMPTS = 'attempts';

    #[Col('text', nullable: true, comment: 'Last error')]
    public const schema_fields_LAST_ERROR = 'last_error';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
