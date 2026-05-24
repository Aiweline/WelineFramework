<?php

declare(strict_types=1);

namespace WeShop\Shipping\Model\ShippingMethod;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop shipping method local descriptions')]
#[Index(name: 'idx_weshop_shipping_method_local_unique', columns: ['method_code', 'locale_code'], type: 'UNIQUE')]
class LocalDescription extends AbstractModel
{
    public const schema_table = 'weshop_shipping_method_local_description';
    public const schema_primary_keys = ['method_code', 'locale_code'];

    #[Col(type: 'varchar', length: 64, nullable: false, primaryKey: true, comment: 'Shipping method code')]
    public const schema_fields_METHOD_CODE = 'method_code';

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = 'locale_code';

    #[Col(type: 'varchar', length: 150, nullable: true, comment: 'Localized method name')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: 'Localized method description')]
    public const schema_fields_DESCRIPTION = 'description';

    public array $_unit_primary_keys = [
        self::schema_fields_METHOD_CODE,
        self::schema_fields_LOCALE_CODE,
    ];

    public array $_index_sort_keys = [
        self::schema_fields_METHOD_CODE,
        self::schema_fields_LOCALE_CODE,
    ];
}
