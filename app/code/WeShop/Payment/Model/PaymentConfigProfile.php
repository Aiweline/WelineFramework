<?php

declare(strict_types=1);

namespace WeShop\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment method environment configuration')]
#[Index(name: 'idx_weshop_payment_config_method_env', columns: ['method_code', 'environment'], comment: 'Method environment')]
class PaymentConfigProfile extends Model
{
    public const schema_table = 'weshop_payment_config_profile';
    public const schema_primary_key = 'profile_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Profile ID')]
    public const schema_fields_ID = 'profile_id';
    #[Col(type: 'varchar', length: 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col(type: 'text', nullable: true, comment: 'Configuration JSON')]
    public const schema_fields_CONFIG_JSON = 'config_json';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['profile_id'];
    public array $_index_sort_keys = ['method_code', 'environment', 'enabled'];
}
