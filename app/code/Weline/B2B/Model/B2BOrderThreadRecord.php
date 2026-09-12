<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'B2B order chat threads')]
#[Index(name: 'uk_b2b_order_thread_id', columns: ['thread_id'], type: 'UNIQUE')]
#[Index(name: 'uk_b2b_order_thread_order_ref', columns: ['order_ref'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_order_thread_customer', columns: ['website_id', 'customer_id', 'customer_unread'])]
class B2BOrderThreadRecord extends Model
{
    public const schema_table = 'weline_b2b_order_thread';
    public const schema_primary_key = 'thread_row_id';

    public const MENU_SIGNAL_CODE = 'b2b.order_chat';
    public const ACCOUNT_SECTION = 'b2b-order-chat';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Row ID')]
    public const schema_fields_ID = 'thread_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable thread ID')]
    public const schema_fields_THREAD_ID = 'thread_id';

    #[Col('varchar', 64, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_REF = 'order_ref';

    #[Col('varchar', 64, nullable: true, comment: 'Hang ID')]
    public const schema_fields_HANG_ID = 'hang_id';

    #[Col('varchar', 64, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Unread for merchant')]
    public const schema_fields_MERCHANT_UNREAD = 'merchant_unread';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Unread for customer')]
    public const schema_fields_CUSTOMER_UNREAD = 'customer_unread';

    #[Col('bigint', 20, nullable: true, comment: 'Last message epoch')]
    public const schema_fields_LAST_MESSAGE_AT_EPOCH = 'last_message_at_epoch';

    #[Col('bigint', 20, nullable: false, comment: 'Created epoch')]
    public const schema_fields_CREATED_AT_EPOCH = 'created_at_epoch';

    #[Col('bigint', 20, nullable: false, comment: 'Updated epoch')]
    public const schema_fields_UPDATED_AT_EPOCH = 'updated_at_epoch';
}
