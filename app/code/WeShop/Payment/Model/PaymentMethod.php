<?php

declare(strict_types=1);

namespace WeShop\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment method catalogue')]
#[Index(name: 'idx_weshop_payment_method_provider', columns: ['provider_code'], comment: 'Provider')]
#[Index(name: 'idx_weshop_payment_method_enabled', columns: ['enabled'], comment: 'Enabled')]
class PaymentMethod extends Model
{
    public const schema_table = 'weshop_payment_method';
    public const schema_primary_key = 'method_code';

    #[Col(type: 'varchar', length: 96, primaryKey: true, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col(type: 'varchar', length: 150, nullable: false, comment: 'Method title')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Payment type')]
    public const schema_fields_METHOD_TYPE = 'method_type';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: 'Checkout flow')]
    public const schema_fields_FLOW = 'flow';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Global popularity score, higher sorts first')]
    public const schema_fields_POPULARITY_SCORE = 'popularity_score';
    #[Col(type: 'text', nullable: true, comment: 'Country codes JSON')]
    public const schema_fields_COUNTRIES = 'countries';
    #[Col(type: 'text', nullable: true, comment: 'Country tag codes JSON')]
    public const schema_fields_COUNTRY_TAGS = 'country_tags';
    #[Col(type: 'text', nullable: true, comment: 'Currency codes JSON')]
    public const schema_fields_CURRENCIES = 'currencies';
    #[Col(type: 'text', nullable: true, comment: 'Required config JSON')]
    public const schema_fields_REQUIRED_CONFIG = 'required_config';
    #[Col(type: 'text', nullable: true, comment: 'Config field JSON')]
    public const schema_fields_CONFIG_FIELDS = 'config_fields';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['method_code'];
    public array $_index_sort_keys = ['provider_code', 'enabled', 'popularity_score', 'sort_order'];
}
