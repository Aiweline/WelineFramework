<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable ToB hang-order lifecycle rows. */
#[Table(comment: 'B2B hang orders (deposit lifecycle)')]
#[Index(name: 'uk_b2b_order_hang_id', columns: ['hang_id'], type: 'UNIQUE')]
#[Index(name: 'uk_b2b_order_hang_order_ref', columns: ['order_ref'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_order_hang_status', columns: ['website_id', 'hang_status', 'updated_at_epoch'])]
class B2BOrderHangRecord extends Model
{
    public const schema_table = 'weline_b2b_order_hang';
    public const schema_primary_key = 'hang_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Hang row ID')]
    public const schema_fields_ID = 'hang_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable hang ID')]
    public const schema_fields_HANG_ID = 'hang_id';

    #[Col('varchar', 64, nullable: false, comment: 'Order ref / order UUID')]
    public const schema_fields_ORDER_REF = 'order_ref';

    #[Col('varchar', 64, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 48, nullable: false, default: 'awaiting_deposit', comment: 'hang_status enum')]
    public const schema_fields_HANG_STATUS = 'hang_status';

    #[Col('bigint', 20, nullable: false, comment: 'Tax-inclusive goods subtotal after discount ban')]
    public const schema_fields_GOODS_SUBTOTAL_TAXED_MINOR = 'goods_subtotal_taxed_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Deposit amount minor')]
    public const schema_fields_DEPOSIT_AMOUNT_MINOR = 'deposit_amount_minor';

    #[Col('bigint', 20, nullable: false, comment: 'Balance amount minor (remaining goods + shipping if owner)')]
    public const schema_fields_BALANCE_AMOUNT_MINOR = 'balance_amount_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Shipping amount on this order')]
    public const schema_fields_SHIPPING_AMOUNT_MINOR = 'shipping_amount_minor';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Is shipping charge owner')]
    public const schema_fields_IS_SHIPPING_OWNER = 'is_shipping_owner';

    #[Col('int', 11, nullable: false, default: 3000, comment: 'Deposit ratio in basis points')]
    public const schema_fields_DEPOSIT_RATIO_BPS = 'deposit_ratio_bps';

    #[Col('text', nullable: false, comment: 'Consumed quote token IDs JSON')]
    public const schema_fields_TOKEN_IDS_JSON = 'token_ids_json';

    #[Col('varchar', 64, nullable: true, comment: 'B2B group ID')]
    public const schema_fields_GROUP_ID = 'group_id';

    #[Col('varchar', 64, nullable: true, comment: 'Price list ID')]
    public const schema_fields_PRICE_LIST_ID = 'price_list_id';

    #[Col('bigint', 20, nullable: true, comment: 'Price list version')]
    public const schema_fields_LIST_VERSION = 'list_version';

    #[Col('varchar', 128, nullable: true, comment: 'Deposit Payment Intent code')]
    public const schema_fields_DEPOSIT_INTENT_CODE = 'deposit_intent_code';

    #[Col('varchar', 128, nullable: true, comment: 'Balance Payment Intent code')]
    public const schema_fields_BALANCE_INTENT_CODE = 'balance_intent_code';

    #[Col('text', nullable: true, comment: 'Inventory reservation snapshots JSON')]
    public const schema_fields_RESERVATION_SNAPSHOTS_JSON = 'reservation_snapshots_json';

    #[Col('bigint', 20, nullable: true, comment: 'Inventory reserved epoch')]
    public const schema_fields_INVENTORY_RESERVED_AT_EPOCH = 'inventory_reserved_at_epoch';

    #[Col('bigint', 20, nullable: true, comment: 'Inventory hang TTL expiry epoch')]
    public const schema_fields_INVENTORY_EXPIRES_AT_EPOCH = 'inventory_expires_at_epoch';

    #[Col('bigint', 20, nullable: false, comment: 'Created epoch')]
    public const schema_fields_CREATED_AT_EPOCH = 'created_at_epoch';

    #[Col('bigint', 20, nullable: false, comment: 'Updated epoch')]
    public const schema_fields_UPDATED_AT_EPOCH = 'updated_at_epoch';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        $status = strtolower(trim((string)$this->getData(self::schema_fields_HANG_STATUS)));
        if (!in_array($status, B2BOrderHang::STATUSES, true)) {
            throw new \InvalidArgumentException(__('B2B hang_status 非法：%{1}', [$status]));
        }
        $this->setData(self::schema_fields_HANG_STATUS, $status);
        parent::save_before();
    }
}
