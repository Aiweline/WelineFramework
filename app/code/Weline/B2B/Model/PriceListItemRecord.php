<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Immutable SKU amount detail for one price-list revision. */
#[Table(comment: 'B2B price list SKU details')]
#[Index(
    name: 'uk_b2b_price_list_item',
    columns: ['list_id', 'list_version', 'sku'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_b2b_price_list_item_lookup', columns: ['sku', 'list_id', 'list_version'])]
class PriceListItemRecord extends Model
{
    public const schema_table = 'weline_b2b_price_list_item';
    public const schema_primary_key = 'price_item_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Price item row ID')]
    public const schema_fields_ID = 'price_item_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable price list ID')]
    public const schema_fields_LIST_ID = 'list_id';

    #[Col('bigint', 20, nullable: false, comment: 'Price list version')]
    public const schema_fields_LIST_VERSION = 'list_version';

    #[Col('varchar', 128, nullable: false, comment: 'Canonical SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('bigint', 20, nullable: false, comment: 'Minor-unit amount')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';

    public function save_before(): void
    {
        if (trim((string)$this->getData(self::schema_fields_LIST_ID)) === ''
            || (int)$this->getData(self::schema_fields_LIST_VERSION) < 1
            || trim((string)$this->getData(self::schema_fields_SKU)) === ''
            || (int)$this->getData(self::schema_fields_AMOUNT_MINOR) < 0
        ) {
            throw new \InvalidArgumentException(__('B2B price list item 数据非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
