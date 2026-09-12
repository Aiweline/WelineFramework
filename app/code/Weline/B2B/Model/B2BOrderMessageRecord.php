<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'B2B order chat messages')]
#[Index(name: 'uk_b2b_order_message_id', columns: ['message_id'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_order_message_thread', columns: ['thread_id', 'message_row_id'])]
class B2BOrderMessageRecord extends Model
{
    public const schema_table = 'weline_b2b_order_message';
    public const schema_primary_key = 'message_row_id';

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_MERCHANT = 'merchant';
    public const ROLE_SYSTEM = 'system';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Row ID')]
    public const schema_fields_ID = 'message_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable message ID')]
    public const schema_fields_MESSAGE_ID = 'message_id';

    #[Col('varchar', 64, nullable: false, comment: 'Thread ID')]
    public const schema_fields_THREAD_ID = 'thread_id';

    #[Col('varchar', 16, nullable: false, comment: 'customer|merchant|system')]
    public const schema_fields_SENDER_ROLE = 'sender_role';

    #[Col('text', nullable: false, comment: 'Message body')]
    public const schema_fields_BODY_TEXT = 'body_text';

    #[Col('text', nullable: true, comment: 'Attachments JSON')]
    public const schema_fields_ATTACHMENTS_JSON = 'attachments_json';

    #[Col('bigint', 20, nullable: false, comment: 'Created epoch')]
    public const schema_fields_CREATED_AT_EPOCH = 'created_at_epoch';
}
