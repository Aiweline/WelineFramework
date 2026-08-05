<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Append-only refund-to-payout reversal journal. */
#[Table(comment: 'Vendor payout refund reversal')]
#[Index(name: 'uk_vendor_reversal_id', columns: ['reversal_id'], type: 'UNIQUE')]
#[Index(name: 'uk_vendor_reversal_ref', columns: ['payout_id', 'refund_ref'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_reversal_scope', columns: ['website_id', 'store_id', 'environment'])]
class VendorRefundReversalRecord extends Model
{
    public const schema_table = 'weline_vendor_refund_reversal';
    public const schema_primary_key = 'reversal_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Reversal row ID')]
    public const schema_fields_ID = 'reversal_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Reversal ID')]
    public const schema_fields_REVERSAL_ID = 'reversal_id';

    #[Col('varchar', 64, nullable: false, comment: 'Payout ID')]
    public const schema_fields_PAYOUT_ID = 'payout_id';

    #[Col('varchar', 64, nullable: false, comment: 'Split snapshot ID')]
    public const schema_fields_SNAPSHOT_ID = 'snapshot_id';

    #[Col('varchar', 64, nullable: false, comment: 'Vendor ID')]
    public const schema_fields_VENDOR_ID = 'vendor_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 16, nullable: false, comment: 'Trusted Store mode snapshot')]
    public const schema_fields_STORE_MODE = 'store_mode_snapshot';

    #[Col('varchar', 16, nullable: false, comment: 'sandbox|live')]
    public const schema_fields_ENVIRONMENT = 'environment';

    #[Col('varchar', 128, nullable: false, comment: 'Refund reference')]
    public const schema_fields_REFUND_REF = 'refund_ref';

    #[Col('bigint', 20, nullable: false, comment: 'Reversal amount')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    #[Col('varchar', 8, nullable: false, comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('varchar', 255, nullable: false, default: 'refund', comment: 'Reason')]
    public const schema_fields_REASON = 'reason';

    #[Col('bigint', 20, nullable: false, comment: 'Payout net after reversal')]
    public const schema_fields_PAYOUT_NET_AFTER_MINOR = 'payout_net_after_minor';

    #[Col('varchar', 64, nullable: false, comment: 'Reversal request SHA-256')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        VendorIdentity::assertEnvironment((string) $this->getData(self::schema_fields_ENVIRONMENT));
        if ((int) $this->getData(self::schema_fields_STORE_ID) <= 0
            || (int) $this->getData(self::schema_fields_AMOUNT_MINOR) <= 0
            || (int) $this->getData(self::schema_fields_PAYOUT_NET_AFTER_MINOR) < 0
        ) {
            throw new \InvalidArgumentException(__('Vendor reversal 金额或 Store 无效'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
