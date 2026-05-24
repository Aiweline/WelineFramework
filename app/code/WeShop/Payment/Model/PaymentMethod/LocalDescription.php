<?php

declare(strict_types=1);

namespace WeShop\Payment\Model\PaymentMethod;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop payment method local descriptions')]
#[Index(name: 'idx_weshop_payment_method_local_unique', columns: ['method_code', 'locale_code'], type: 'UNIQUE')]
class LocalDescription extends AbstractModel
{
    public const schema_table = 'weshop_payment_method_local_description';
    public const schema_primary_keys = ['method_code', 'locale_code'];

    #[Col(type: 'varchar', length: 64, nullable: false, primaryKey: true, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = 'locale_code';

    #[Col(type: 'varchar', length: 150, nullable: true, comment: 'Localized method title')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'text', nullable: true, comment: 'Localized method description')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'text', nullable: true, comment: 'Localized checkout note')]
    public const schema_fields_CHECKOUT_NOTE = 'checkout_note';

    public array $_unit_primary_keys = [
        self::schema_fields_METHOD_CODE,
        self::schema_fields_LOCALE_CODE,
    ];

    public array $_index_sort_keys = [
        self::schema_fields_METHOD_CODE,
        self::schema_fields_LOCALE_CODE,
    ];
}
