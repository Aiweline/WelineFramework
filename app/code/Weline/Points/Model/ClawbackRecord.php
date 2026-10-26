<?php

declare(strict_types=1);

namespace Weline\Points\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Refund clawback fact. Unrecovered points are an audit liability, not balance.
 */
#[Table(comment: 'Points refund clawback record')]
#[Index(name: 'uk_points_clawback_event', columns: ['event_id'], type: 'UNIQUE')]
#[Index(name: 'idx_points_clawback_earn', columns: ['earn_record_id'])]
#[Index(name: 'idx_points_clawback_order', columns: ['order_id', 'refund_key'])]
class ClawbackRecord extends Model
{
    public const schema_table = 'weline_points_clawback';
    public const schema_primary_key = 'clawback_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Clawback ID')]
    public const schema_fields_ID = 'clawback_id';

    #[Col('varchar', 191, nullable: false, comment: 'Globally unique clawback event ID')]
    public const schema_fields_EVENT_ID = 'event_id';

    #[Col('bigint', 20, nullable: false, comment: 'Source earning record ID')]
    public const schema_fields_EARN_RECORD_ID = 'earn_record_id';

    #[Col('int', 11, nullable: false, comment: 'Order ID')]
    public const schema_fields_ORDER_ID = 'order_id';

    #[Col('varchar', 64, nullable: false, comment: 'Refund identity')]
    public const schema_fields_REFUND_KEY = 'refund_key';

    #[Col('int', 11, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'live', comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Expected clawback points')]
    public const schema_fields_EXPECTED_POINTS = 'expected_points';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Actual clawed back points')]
    public const schema_fields_ACTUAL_POINTS = 'actual_points';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Unrecovered audit liability')]
    public const schema_fields_UNRECOVERED_POINTS = 'unrecovered_points';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Reason code')]
    public const schema_fields_REASON = 'reason';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';
}
