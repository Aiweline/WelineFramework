<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Immutable version header for a B2B price list. */
#[Table(comment: 'Versioned B2B price list headers')]
#[Index(name: 'uk_b2b_price_list_version', columns: ['list_id', 'version'], type: 'UNIQUE')]
#[Index(
    name: 'idx_b2b_price_list_selection',
    columns: ['group_id', 'website_id', 'channel_id', 'active', 'version'],
)]
class PriceListRecord extends Model
{
    public const schema_table = 'weline_b2b_price_list';
    public const schema_primary_key = 'price_list_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Price list row ID')]
    public const schema_fields_ID = 'price_list_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable price list ID')]
    public const schema_fields_LIST_ID = 'list_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning B2B group ID')]
    public const schema_fields_GROUP_ID = 'group_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('bigint', 20, nullable: false, comment: 'Immutable price list version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: true, comment: 'Optional Channel override')]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('int', 1, nullable: false, default: 1, comment: 'Active revision')]
    public const schema_fields_ACTIVE = 'active';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        if (trim((string)$this->getData(self::schema_fields_LIST_ID)) === ''
            || trim((string)$this->getData(self::schema_fields_GROUP_ID)) === ''
            || (int)$this->getData(self::schema_fields_WEBSITE_ID) < 0
            || (int)$this->getData(self::schema_fields_VERSION) < 1
            || !in_array((int)$this->getData(self::schema_fields_ACTIVE), [0, 1], true)
        ) {
            throw new \InvalidArgumentException(__('B2B price list header 数据非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
