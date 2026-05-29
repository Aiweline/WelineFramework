<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop affiliate withdrawal request table')]
#[Index(name: 'idx_affiliate_status', columns: [self::schema_fields_AFFILIATE_ID, self::schema_fields_STATUS], type: 'KEY', comment: 'Affiliate withdrawal status lookup')]
#[Index(name: 'idx_customer_id', columns: [self::schema_fields_CUSTOMER_ID], type: 'KEY', comment: 'Customer withdrawal lookup')]
class AffiliateWithdrawal extends Model
{
    public const schema_table = 'weshop_affiliate_withdrawal';
    public const schema_primary_key = 'withdrawal_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Withdrawal ID')]
    public const schema_fields_ID = 'withdrawal_id';

    #[Col(type: 'int', nullable: false, comment: 'Affiliate ID')]
    public const schema_fields_AFFILIATE_ID = 'affiliate_id';

    #[Col(type: 'int', nullable: false, comment: 'Affiliate customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Withdrawal amount')]
    public const schema_fields_AMOUNT = 'amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: '', comment: 'Withdrawal currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 24, nullable: false, default: 'requested', comment: 'Withdrawal status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 40, nullable: false, default: 'manual', comment: 'Withdrawal method')]
    public const schema_fields_METHOD = 'method';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Masked payout account label')]
    public const schema_fields_ACCOUNT_LABEL = 'account_label';

    #[Col(type: 'text', nullable: true, comment: 'Request metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Operation note')]
    public const schema_fields_NOTE = 'note';

    #[Col(type: 'datetime', nullable: false, comment: 'Requested at')]
    public const schema_fields_REQUESTED_AT = 'requested_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Processed at')]
    public const schema_fields_PROCESSED_AT = 'processed_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Paid at')]
    public const schema_fields_PAID_AT = 'paid_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_AFFILIATE_ID,
        self::schema_fields_CUSTOMER_ID,
        self::schema_fields_CURRENCY_CODE,
        self::schema_fields_STATUS,
        self::schema_fields_REQUESTED_AT,
    ];
}
