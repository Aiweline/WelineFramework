<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Customer asset reservation state')]
#[Index(name: 'uk_customer_asset_reservation_id', columns: ['reservation_id'], type: 'UNIQUE')]
#[Index(name: 'uk_customer_asset_reserve_event', columns: ['reserve_event_id'], type: 'UNIQUE')]
#[Index(name: 'uk_customer_asset_terminal_event', columns: ['terminal_event_id'], type: 'UNIQUE')]
#[Index(name: 'idx_customer_asset_reservation_account', columns: ['account_id', 'status'])]
#[Index(name: 'idx_customer_asset_reservation_owner', columns: ['customer_id', 'website_id', 'namespace'])]
class AssetReservation extends Model
{
    public const schema_table = 'weline_customer_asset_reservation';
    public const schema_primary_key = 'reservation_row_id';

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_RELEASED = 'released';
    public const STATUS_COMMITTED = 'committed';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Reservation row ID')]
    public const schema_fields_ID = 'reservation_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable reservation ID')]
    public const schema_fields_RESERVATION_ID = 'reservation_id';

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

    #[Col('varchar', 128, nullable: false, comment: 'Reserve event ID')]
    public const schema_fields_RESERVE_EVENT_ID = 'reserve_event_id';

    #[Col('varchar', 64, nullable: false, comment: 'Reserve request SHA-256')]
    public const schema_fields_RESERVE_REQUEST_HASH = 'reserve_request_hash';

    #[Col('bigint', 20, nullable: false, comment: 'Reserved amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Committed amount already returned')]
    public const schema_fields_RETURNED_AMOUNT_MINOR = 'returned_amount_minor';

    #[Col('varchar', 16, nullable: false, default: self::STATUS_RESERVED, comment: 'reserved|released|committed')]
    public const schema_fields_STATUS = 'status';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic reservation version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: false, comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('varchar', 128, nullable: true, comment: 'Terminal release/commit event ID')]
    public const schema_fields_TERMINAL_EVENT_ID = 'terminal_event_id';

    #[Col('varchar', 64, nullable: true, comment: 'Terminal request SHA-256')]
    public const schema_fields_TERMINAL_REQUEST_HASH = 'terminal_request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('datetime', nullable: true, comment: 'Committed or released')]
    public const schema_fields_TERMINAL_AT = 'terminal_at';

    public function save_before(): void
    {
        foreach ([
            self::schema_fields_RESERVATION_ID,
            self::schema_fields_ACCOUNT_ID,
            self::schema_fields_CUSTOMER_ID,
            self::schema_fields_ASSET_CODE,
            self::schema_fields_RESERVE_EVENT_ID,
        ] as $field) {
            if (trim((string) $this->getData($field)) === '') {
                throw new \InvalidArgumentException(__('CustomerAsset reservation 必填字段为空：%{1}', [$field]));
            }
        }
        $amountMinor = (int) $this->getData(self::schema_fields_AMOUNT_MINOR);
        $returnedAmountMinor = (int) $this->getData(self::schema_fields_RETURNED_AMOUNT_MINOR);
        if ((int) $this->getData(self::schema_fields_WEBSITE_ID) < 0
            || $amountMinor <= 0
            || $returnedAmountMinor < 0
            || $returnedAmountMinor > $amountMinor
            || (int) $this->getData(self::schema_fields_VERSION) < 1
        ) {
            throw new \InvalidArgumentException(__('CustomerAsset reservation 数值非法'));
        }
        if (!in_array((string) $this->getData(self::schema_fields_NAMESPACE), [
            AssetAccount::NS_LIVE,
            AssetAccount::NS_SANDBOX,
        ], true)) {
            throw new \InvalidArgumentException(__('CustomerAsset reservation namespace 非法'));
        }
        if (!in_array((string) $this->getData(self::schema_fields_STATUS), [
            self::STATUS_RESERVED,
            self::STATUS_RELEASED,
            self::STATUS_COMMITTED,
        ], true)) {
            throw new \InvalidArgumentException(__('CustomerAsset reservation status 非法'));
        }
        foreach ([self::schema_fields_RESERVE_REQUEST_HASH, self::schema_fields_CAS_TOKEN] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string) $this->getData($field))) {
                throw new \InvalidArgumentException(__('CustomerAsset reservation hash/CAS 非法'));
            }
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
