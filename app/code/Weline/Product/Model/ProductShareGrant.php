<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Product cross Website share grant')]
#[Index(name: 'uk_product_share_target', columns: ['global_product_uuid', 'target_website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_product_share_target_status', columns: ['target_website_id', 'status'])]
final class ProductShareGrant extends Model
{
    public const schema_table = 'weline_product_share_grant';
    public const schema_primary_key = 'grant_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Grant ID')]
    public const schema_fields_ID = 'grant_id';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('int', 11, nullable: false, comment: 'Target Website ID')]
    public const schema_fields_TARGET_WEBSITE_ID = 'target_website_id';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_ACTIVE, comment: 'Grant status')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Grant version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 128, nullable: false, comment: 'Mutation request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';


    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
