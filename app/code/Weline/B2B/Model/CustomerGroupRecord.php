<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable B2B customer-group definition. */
#[Table(comment: 'B2B customer groups')]
#[Index(name: 'uk_b2b_group_id', columns: ['group_id'], type: 'UNIQUE')]
#[Index(name: 'uk_b2b_group_scope_code', columns: ['website_id', 'code'], type: 'UNIQUE')]
#[Index(name: 'idx_b2b_group_scope_status', columns: ['website_id', 'status'])]
class CustomerGroupRecord extends Model
{
    public const schema_table = 'weline_b2b_customer_group';
    public const schema_primary_key = 'group_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Group row ID')]
    public const schema_fields_ID = 'group_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable B2B group ID')]
    public const schema_fields_GROUP_ID = 'group_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Website-scoped group code')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 16, nullable: false, default: 'active', comment: 'active|disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic group version')]
    public const schema_fields_VERSION = 'group_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        new CustomerGroup(
            trim((string)$this->getData(self::schema_fields_GROUP_ID)),
            (int)$this->getData(self::schema_fields_WEBSITE_ID),
            trim((string)$this->getData(self::schema_fields_CODE)),
            (string)$this->getData(self::schema_fields_STATUS),
        );
        if ((int)$this->getData(self::schema_fields_VERSION) < 1) {
            throw new \InvalidArgumentException(__('B2B group version 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
