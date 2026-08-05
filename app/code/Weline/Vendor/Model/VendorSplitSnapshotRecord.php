<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Write-once legal/account/commission split fact. */
#[Table(comment: 'Vendor immutable split snapshot')]
#[Index(name: 'uk_vendor_split_snapshot_id', columns: ['snapshot_id'], type: 'UNIQUE')]
#[Index(
    name: 'uk_vendor_split_snapshot_payable',
    columns: ['vendor_id', 'store_id', 'order_ref', 'payment_ref'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_vendor_split_snapshot_scope', columns: ['website_id', 'store_id', 'environment'])]
#[Index(name: 'idx_vendor_split_snapshot_group', columns: ['checkout_group_ref'])]
class VendorSplitSnapshotRecord extends Model
{
    public const schema_table = 'weline_vendor_split_snapshot';
    public const schema_primary_key = 'snapshot_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Snapshot row ID')]
    public const schema_fields_ID = 'snapshot_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable snapshot ID')]
    public const schema_fields_SNAPSHOT_ID = 'snapshot_id';

    #[Col('varchar', 32, nullable: false, comment: 'Snapshot schema version')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';

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

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Checkout group reference')]
    public const schema_fields_CHECKOUT_GROUP_REF = 'checkout_group_ref';

    #[Col('varchar', 64, nullable: false, comment: 'Child Order reference')]
    public const schema_fields_ORDER_REF = 'order_ref';

    #[Col('varchar', 64, nullable: false, comment: 'Payment reference')]
    public const schema_fields_PAYMENT_REF = 'payment_ref';

    #[Col('varchar', 8, nullable: false, comment: 'Currency')]
    public const schema_fields_CURRENCY = 'currency';

    #[Col('bigint', 20, nullable: false, comment: 'Gross amount in minor units')]
    public const schema_fields_GROSS_MINOR = 'gross_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Vendor share in minor units')]
    public const schema_fields_VENDOR_SHARE_MINOR = 'vendor_share_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Platform share in minor units')]
    public const schema_fields_PLATFORM_SHARE_MINOR = 'platform_share_minor';

    #[Col('int', 11, nullable: false, comment: 'Frozen commission basis points')]
    public const schema_fields_COMMISSION_BPS = 'commission_bps';

    #[Col('text', nullable: false, comment: 'Frozen legal JSON')]
    public const schema_fields_LEGAL_JSON = 'legal_json';

    #[Col('text', nullable: false, comment: 'Frozen Store account JSON')]
    public const schema_fields_ACCOUNT_JSON = 'account_json';

    #[Col('text', nullable: false, comment: 'Frozen commission JSON')]
    public const schema_fields_COMMISSION_JSON = 'commission_json';

    #[Col('varchar', 64, nullable: false, comment: 'Canonical payload SHA-256')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        VendorIdentity::assertEnvironment((string) $this->getData(self::schema_fields_ENVIRONMENT));
        $gross = (int) $this->getData(self::schema_fields_GROSS_MINOR);
        $vendor = (int) $this->getData(self::schema_fields_VENDOR_SHARE_MINOR);
        $platform = (int) $this->getData(self::schema_fields_PLATFORM_SHARE_MINOR);
        if ((int) $this->getData(self::schema_fields_STORE_ID) <= 0
            || $gross <= 0
            || $vendor < 0
            || $platform < 0
            || $vendor + $platform !== $gross
        ) {
            throw new \InvalidArgumentException(__('Vendor 分账快照不守恒'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
