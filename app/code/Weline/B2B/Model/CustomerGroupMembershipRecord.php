<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Server-owned B2B group membership, scoped by Customer and Website. */
#[Table(comment: 'B2B customer group memberships')]
#[Index(
    name: 'uk_b2b_membership_customer_scope',
    columns: ['customer_id', 'website_id'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_b2b_membership_group_scope', columns: ['group_id', 'website_id'])]
class CustomerGroupMembershipRecord extends Model
{
    public const schema_table = 'weline_b2b_customer_group_membership';
    public const schema_primary_key = 'membership_row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Membership row ID')]
    public const schema_fields_ID = 'membership_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'B2B group ID')]
    public const schema_fields_GROUP_ID = 'group_id';

    #[Col('bigint', 20, nullable: false, default: 1, comment: 'Monotonic membership version')]
    public const schema_fields_VERSION = 'membership_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        if (trim((string)$this->getData(self::schema_fields_CUSTOMER_ID)) === ''
            || trim((string)$this->getData(self::schema_fields_GROUP_ID)) === ''
            || (int)$this->getData(self::schema_fields_WEBSITE_ID) < 0
            || (int)$this->getData(self::schema_fields_VERSION) < 1
        ) {
            throw new \InvalidArgumentException(__('B2B membership 数据非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
