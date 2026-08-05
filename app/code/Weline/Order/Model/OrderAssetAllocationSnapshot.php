<?php

declare(strict_types=1);

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Immutable committed Payment allocation snapshot owned by Order. */
#[Table(comment: 'Immutable Order asset allocation snapshot')]
#[Index(name: 'uniq_order_asset_snapshot_code', columns: ['snapshot_code'], type: 'UNIQUE')]
#[Index(name: 'uniq_order_asset_allocation_code', columns: ['allocation_code'], type: 'UNIQUE')]
#[Index(name: 'idx_order_asset_snapshot_order', columns: ['order_uuid', 'snapshot_id'])]
#[Index(name: 'idx_order_asset_snapshot_intent', columns: ['intent_code', 'attempt_code'])]
class OrderAssetAllocationSnapshot extends Model
{
    public const schema_table = 'weline_order_asset_allocation_snapshot';
    public const schema_primary_key = 'snapshot_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Snapshot ID')]
    public const schema_fields_ID = 'snapshot_id';
    #[Col('varchar', 64, nullable: false, comment: 'Stable snapshot code')]
    public const schema_fields_SNAPSHOT_CODE = 'snapshot_code';
    #[Col('varchar', 96, nullable: false, comment: 'Payment allocation code')]
    public const schema_fields_ALLOCATION_CODE = 'allocation_code';
    #[Col('varchar', 36, nullable: false, comment: 'Order UUID')]
    public const schema_fields_ORDER_UUID = 'order_uuid';
    #[Col('varchar', 96, nullable: false, comment: 'Payment intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Cash attempt code; null for zero-cash intent')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('varchar', 64, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false, comment: 'Asset code')]
    public const schema_fields_ASSET_CODE = 'asset_code';
    #[Col('varchar', 96, nullable: false, comment: 'Allocation source code')]
    public const schema_fields_SOURCE_CODE = 'source_code';
    #[Col('varchar', 32, nullable: false, comment: 'payment|discount')]
    public const schema_fields_ROLE = 'role';
    #[Col('varchar', 16, nullable: false, comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';
    #[Col('varchar', 64, nullable: false, comment: 'CustomerAsset reservation ID')]
    public const schema_fields_RESERVATION_ID = 'reservation_id';
    #[Col('bigint', 20, nullable: false, comment: 'Committed asset quantity')]
    public const schema_fields_ASSET_AMOUNT_MINOR = 'asset_amount_minor';
    #[Col('bigint', 20, nullable: false, comment: 'Payable allocation amount')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col('varchar', 3, nullable: false, comment: 'Payment currency')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('varchar', 191, nullable: false, comment: 'Payment commit effect key')]
    public const schema_fields_EFFECT_KEY = 'effect_key';
    #[Col('char', 64, nullable: false, comment: 'Canonical immutable payload SHA-256')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';
    #[Col('text', nullable: false, comment: 'Canonical immutable allocation JSON')]
    public const schema_fields_SNAPSHOT_JSON = 'snapshot_json';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Committed at')]
    public const schema_fields_COMMITTED_AT = 'committed_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['snapshot_id'];
    public array $_index_sort_keys = [
        'snapshot_code',
        'allocation_code',
        'order_uuid',
        'intent_code',
        'attempt_code',
        'customer_id',
        'website_id',
        'asset_code',
        'created_at',
    ];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function save_before(): void
    {
        foreach ([
            self::schema_fields_SNAPSHOT_CODE,
            self::schema_fields_ALLOCATION_CODE,
            self::schema_fields_ORDER_UUID,
            self::schema_fields_INTENT_CODE,
            self::schema_fields_CUSTOMER_ID,
            self::schema_fields_ASSET_CODE,
            self::schema_fields_SOURCE_CODE,
            self::schema_fields_RESERVATION_ID,
            self::schema_fields_EFFECT_KEY,
        ] as $field) {
            if (trim((string) $this->getData($field)) === '') {
                throw new \InvalidArgumentException(
                    __('Order asset snapshot 必填字段为空：%{1}', [$field]),
                );
            }
        }
        if ((int) $this->getData(self::schema_fields_WEBSITE_ID) < 0
            || (int) $this->getData(self::schema_fields_ASSET_AMOUNT_MINOR) <= 0
            || (int) $this->getData(self::schema_fields_AMOUNT_MINOR) <= 0
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                (string) $this->getData(self::schema_fields_PAYLOAD_HASH),
            )
        ) {
            throw new \InvalidArgumentException(__('Order asset snapshot 数值/hash 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
