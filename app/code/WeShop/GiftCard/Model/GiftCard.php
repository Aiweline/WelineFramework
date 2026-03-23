<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Gift card storage model.
 */
#[Table(comment: 'WeShop gift card table')]
#[Index(name: 'idx_card_number', columns: [self::schema_fields_CARD_NUMBER], type: 'UNIQUE', comment: 'Gift card number unique index')]
#[Index(name: 'idx_customer_id', columns: [self::schema_fields_CUSTOMER_ID], type: 'BTREE', comment: 'Customer gift card index')]
class GiftCard extends Model
{
    public const schema_table = 'weshop_gift_card';
    public const schema_primary_key = 'card_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Gift card ID')]
    public const schema_fields_ID = 'card_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'Gift card number')]
    public const schema_fields_CARD_NUMBER = 'card_number';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: 'Face amount')]
    public const schema_fields_AMOUNT = 'amount';

    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: 'Current balance')]
    public const schema_fields_BALANCE = 'balance';

    #[Col(type: 'varchar', length: 20, nullable: false, default: 'active', comment: 'Gift card status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'datetime', nullable: true, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_CUSTOMER_ID, self::schema_fields_CREATED_AT];
}
