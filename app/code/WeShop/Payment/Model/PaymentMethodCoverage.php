<?php

declare(strict_types=1);

namespace WeShop\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment method country and currency coverage')]
#[Index(name: 'idx_weshop_payment_method_coverage_method', columns: ['method_code'], comment: 'Method')]
#[Index(name: 'idx_weshop_payment_method_coverage_country', columns: ['country_code'], comment: 'Country')]
#[Index(name: 'idx_weshop_payment_method_coverage_popularity', columns: ['country_code', 'popularity_score'], comment: 'Country popularity')]
class PaymentMethodCoverage extends Model
{
    public const schema_table = 'weshop_payment_method_coverage';
    public const schema_primary_key = 'coverage_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Coverage ID')]
    public const schema_fields_ID = 'coverage_id';
    #[Col(type: 'varchar', length: 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col(type: 'varchar', length: 2, nullable: false, comment: 'ISO country code')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col(type: 'varchar', length: 3, nullable: true, comment: 'ISO currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Region')]
    public const schema_fields_REGION = 'region';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Country popularity score, higher sorts first')]
    public const schema_fields_POPULARITY_SCORE = 'popularity_score';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Popular tag in country')]
    public const schema_fields_IS_POPULAR = 'is_popular';

    public array $_unit_primary_keys = ['coverage_id'];
    public array $_index_sort_keys = ['method_code', 'country_code', 'currency_code', 'popularity_score'];
}
