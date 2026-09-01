<?php

declare(strict_types=1);

namespace Weline\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Affiliate account model.
 */
#[Table(comment: 'Weline affiliate account table')]
#[Index(name: 'idx_customer_scope', columns: [self::schema_fields_CUSTOMER_ID, self::schema_fields_WEBSITE_ID, self::schema_fields_STORE_CODE, self::schema_fields_CHANNEL_CODE], type: 'UNIQUE', comment: 'Unique affiliate per customer per scope')]
#[Index(name: 'idx_customer_id', columns: [self::schema_fields_CUSTOMER_ID], type: 'KEY', comment: 'Customer lookup')]
#[Index(name: 'idx_referral_code', columns: [self::schema_fields_REFERRAL_CODE], type: 'UNIQUE', comment: 'Unique referral code')]
#[Index(name: 'idx_scope', columns: [self::schema_fields_WEBSITE_ID, self::schema_fields_STORE_CODE, self::schema_fields_CHANNEL_CODE], type: 'KEY', comment: 'Scope lookup')]
#[Index(name: 'idx_deleted_at', columns: [self::schema_fields_DELETED_AT], type: 'KEY', comment: 'Soft delete lookup')]
class Affiliate extends Model
{
    public const schema_table = 'weline_affiliate';
    public const schema_primary_key = 'affiliate_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Affiliate ID')]
    public const schema_fields_ID = 'affiliate_id';

    #[Col(type: 'int', nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Website ID, 0 means all websites')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Store code, empty means all stores')]
    public const schema_fields_STORE_CODE = 'store_code';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Channel code, empty means all channels')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Referral code')]
    public const schema_fields_REFERRAL_CODE = 'referral_code';

    #[Col(type: 'decimal', length: '5,4', nullable: false, default: 0.1000, comment: 'Commission rate decimal 0-1')]
    public const schema_fields_COMMISSION_RATE = 'commission_rate';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: 'Total commission amount')]
    public const schema_fields_TOTAL_COMMISSION = 'total_commission';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: 'Paid commission amount')]
    public const schema_fields_PAID_COMMISSION = 'paid_commission';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: 'Affiliate status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'datetime', nullable: true, comment: 'Soft delete timestamp')]
    public const schema_fields_DELETED_AT = 'deleted_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_CUSTOMER_ID, self::schema_fields_CREATED_AT];
}
