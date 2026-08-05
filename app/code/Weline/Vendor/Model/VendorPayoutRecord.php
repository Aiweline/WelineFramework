<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable payout ledger derived from one immutable split snapshot. */
#[Table(comment: 'Vendor payout ledger')]
#[Index(name: 'uk_vendor_payout_id', columns: ['payout_id'], type: 'UNIQUE')]
#[Index(name: 'uk_vendor_payout_snapshot', columns: ['snapshot_id'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_payout_scope', columns: ['website_id', 'store_id', 'environment', 'status'])]
class VendorPayoutRecord extends Model
{
    public const schema_table = 'weline_vendor_payout';
    public const schema_primary_key = 'payout_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Payout row ID')]
    public const schema_fields_ID = 'payout_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Payout ID')]
    public const schema_fields_PAYOUT_ID = 'payout_id';

    #[Col('varchar', 64, nullable: false, comment: 'Immutable split snapshot ID')]
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

    #[Col('varchar', 8, nullable: false, comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('bigint', 20, nullable: false, comment: 'Scheduled amount')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Reversed amount')]
    public const schema_fields_REVERSED_MINOR = 'reversed_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Net amount')]
    public const schema_fields_NET_MINOR = 'net_minor';

    #[Col('varchar', 32, nullable: false, comment: 'Payout status')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 255, nullable: false, comment: 'Frozen non-secret account reference')]
    public const schema_fields_ACCOUNT_REF = 'account_ref';

    #[Col('varchar', 255, nullable: false, comment: 'Frozen legal entity')]
    public const schema_fields_LEGAL_ENTITY = 'legal_entity';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'Caller idempotency key')]
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';

    #[Col('varchar', 64, nullable: false, comment: 'Schedule request SHA-256')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic ledger version')]
    public const schema_fields_LEDGER_VERSION = 'ledger_version';

    #[Col('varchar', 64, nullable: false, comment: 'CAS writer token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        VendorIdentity::assertEnvironment((string) $this->getData(self::schema_fields_ENVIRONMENT));
        $amount = (int) $this->getData(self::schema_fields_AMOUNT_MINOR);
        $reversed = (int) $this->getData(self::schema_fields_REVERSED_MINOR);
        $net = (int) $this->getData(self::schema_fields_NET_MINOR);
        if ((int) $this->getData(self::schema_fields_STORE_ID) <= 0
            || $amount < 0
            || $reversed < 0
            || $net < 0
            || $reversed + $net !== $amount
        ) {
            throw new \InvalidArgumentException(__('Vendor payout ledger 不守恒'));
        }
        $this->setData(
            self::schema_fields_LEDGER_VERSION,
            max(1, (int) $this->getData(self::schema_fields_LEDGER_VERSION)),
        );
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
