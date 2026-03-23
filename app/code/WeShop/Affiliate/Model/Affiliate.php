<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Affiliate account model.
 */
#[Table(comment: 'WeShop affiliate account table')]
#[Index(name: 'idx_customer_id', columns: [self::schema_fields_CUSTOMER_ID], type: 'UNIQUE', comment: 'Unique customer affiliate account')]
#[Index(name: 'idx_referral_code', columns: [self::schema_fields_REFERRAL_CODE], type: 'UNIQUE', comment: 'Unique referral code')]
class Affiliate extends Model
{
    public const schema_table = 'weshop_affiliate';
    public const schema_primary_key = 'affiliate_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Affiliate ID')]
    public const schema_fields_ID = 'affiliate_id';

    #[Col(type: 'int', nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Referral code')]
    public const schema_fields_REFERRAL_CODE = 'referral_code';

    #[Col(type: 'decimal', length: '5,2', nullable: false, default: 0.10, comment: 'Commission rate')]
    public const schema_fields_COMMISSION_RATE = 'commission_rate';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: 'Total commission amount')]
    public const schema_fields_TOTAL_COMMISSION = 'total_commission';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: 'Paid commission amount')]
    public const schema_fields_PAID_COMMISSION = 'paid_commission';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: 'Affiliate status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_CUSTOMER_ID, self::schema_fields_CREATED_AT];
}
