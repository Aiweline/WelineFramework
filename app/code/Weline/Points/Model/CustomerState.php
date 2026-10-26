<?php

declare(strict_types=1);

namespace Weline\Points\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Customer growth and current VIP level. One row per customer/site/namespace.
 */
#[Table(comment: 'Points customer growth and VIP state')]
#[Index(
    name: 'uk_points_customer_state_identity',
    columns: ['customer_id', 'website_id', 'namespace'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_points_customer_state_level', columns: ['level_id'])]
class CustomerState extends Model
{
    public const schema_table = 'weline_points_customer_state';
    public const schema_primary_key = 'state_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'State row ID')]
    public const schema_fields_ID = 'state_id';

    #[Col('int', 11, nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'live', comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Monotonic growth value')]
    public const schema_fields_GROWTH_VALUE = 'growth_value';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Lifetime earned points; never reduced by redemption')]
    public const schema_fields_LIFETIME_POINTS = 'lifetime_points';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Current level ID')]
    public const schema_fields_LEVEL_ID = 'level_id';

    #[Col('varchar', 32, nullable: false, default: '', comment: 'Current level code snapshot')]
    public const schema_fields_LEVEL_CODE = 'level_code';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
