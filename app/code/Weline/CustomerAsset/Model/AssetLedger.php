<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Append-only customer asset ledger event. */
#[Table(comment: 'Immutable customer asset ledger')]
#[Index(name: 'uk_customer_asset_entry_id', columns: ['entry_id'], type: 'UNIQUE')]
#[Index(name: 'uk_customer_asset_event_id', columns: ['event_id'], type: 'UNIQUE')]
#[Index(name: 'idx_customer_asset_ledger_account', columns: ['account_id', 'ledger_row_id'])]
#[Index(name: 'idx_customer_asset_ledger_owner', columns: ['customer_id', 'website_id', 'namespace'])]
class AssetLedger extends Model
{
    public const schema_table = 'weline_customer_asset_ledger';
    public const schema_primary_key = 'ledger_row_id';

    public const TYPE_CREDIT = 'credit';
    public const TYPE_RESERVE = 'reserve';
    public const TYPE_RELEASE = 'release';
    public const TYPE_COMMIT = 'commit';
    public const TYPE_RETURN = 'return';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Ledger row ID')]
    public const schema_fields_ID = 'ledger_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable entry ID')]
    public const schema_fields_ENTRY_ID = 'entry_id';

    #[Col('varchar', 128, nullable: false, comment: 'Globally unique caller event ID')]
    public const schema_fields_EVENT_ID = 'event_id';

    #[Col('varchar', 64, nullable: false, comment: 'Asset account ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Asset code')]
    public const schema_fields_ASSET_CODE = 'asset_code';

    #[Col('varchar', 16, nullable: false, comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';

    #[Col('varchar', 16, nullable: false, comment: 'credit|reserve|release|commit|return')]
    public const schema_fields_EVENT_TYPE = 'event_type';

    #[Col('bigint', 20, nullable: false, comment: 'Positive event amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    #[Col('varchar', 64, nullable: true, comment: 'Reservation ID when applicable')]
    public const schema_fields_RESERVATION_ID = 'reservation_id';

    #[Col('varchar', 64, nullable: false, comment: 'Canonical request SHA-256')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('bigint', 20, nullable: false, comment: 'Available balance after event')]
    public const schema_fields_BALANCE_AVAILABLE = 'balance_after_available';

    #[Col('bigint', 20, nullable: false, comment: 'Reserved balance after event')]
    public const schema_fields_BALANCE_RESERVED = 'balance_after_reserved';

    #[Col('bigint', 20, nullable: false, comment: 'Account version after event')]
    public const schema_fields_ACCOUNT_VERSION = 'account_version';

    #[Col('text', nullable: false, default: '{}', comment: 'Sanitized immutable metadata JSON')]
    public const schema_fields_META_JSON = 'meta_json';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        foreach ([
            self::schema_fields_ENTRY_ID,
            self::schema_fields_EVENT_ID,
            self::schema_fields_ACCOUNT_ID,
            self::schema_fields_CUSTOMER_ID,
            self::schema_fields_ASSET_CODE,
        ] as $field) {
            if (trim((string) $this->getData($field)) === '') {
                throw new \InvalidArgumentException(__('CustomerAsset ledger 必填字段为空：%{1}', [$field]));
            }
        }
        if (!in_array((string) $this->getData(self::schema_fields_NAMESPACE), [
            AssetAccount::NS_LIVE,
            AssetAccount::NS_SANDBOX,
        ], true)) {
            throw new \InvalidArgumentException(__('CustomerAsset ledger namespace 非法'));
        }
        if (!in_array((string) $this->getData(self::schema_fields_EVENT_TYPE), [
            self::TYPE_CREDIT,
            self::TYPE_RESERVE,
            self::TYPE_RELEASE,
            self::TYPE_COMMIT,
            self::TYPE_RETURN,
        ], true)) {
            throw new \InvalidArgumentException(__('CustomerAsset ledger event_type 非法'));
        }
        if ((int) $this->getData(self::schema_fields_WEBSITE_ID) < 0
            || (int) $this->getData(self::schema_fields_AMOUNT_MINOR) <= 0
            || (int) $this->getData(self::schema_fields_BALANCE_AVAILABLE) < 0
            || (int) $this->getData(self::schema_fields_BALANCE_RESERVED) < 0
            || (int) $this->getData(self::schema_fields_ACCOUNT_VERSION) < 1
            || !preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_REQUEST_HASH))
        ) {
            throw new \InvalidArgumentException(__('CustomerAsset ledger 数值或 hash 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
