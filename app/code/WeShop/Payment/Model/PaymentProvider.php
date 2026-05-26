<?php

declare(strict_types=1);

namespace WeShop\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment provider catalogue')]
#[Index(name: 'idx_weshop_payment_provider_status', columns: ['status'], comment: 'Provider status')]
class PaymentProvider extends Model
{
    public const schema_table = 'weshop_payment_provider';
    public const schema_primary_key = 'provider_code';

    #[Col(type: 'varchar', length: 64, primaryKey: true, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col(type: 'varchar', length: 150, nullable: false, comment: 'Provider title')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Primary provider region')]
    public const schema_fields_REGION = 'region';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Provider category')]
    public const schema_fields_PROVIDER_TYPE = 'provider_type';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Status 1 active 0 disabled')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['provider_code'];
    public array $_index_sort_keys = ['provider_code', 'status'];
}
