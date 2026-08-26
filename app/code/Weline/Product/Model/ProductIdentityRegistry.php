<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Global Product identity V2')]
#[Index(name: 'uk_product_v2_uuid', columns: ['global_product_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_product_v2_code', columns: ['product_code'], type: 'UNIQUE')]
#[Index(name: 'uk_product_v2_request', columns: ['request_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_product_v2_owner_status', columns: ['owner_website_id', 'lifecycle_status'])]
final class ProductIdentityRegistry extends Model
{
    public const schema_table = 'weline_product_identity_v2';
    public const schema_primary_key = 'registry_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ARCHIVED = 'archived';

    public const SHARE_DEFAULT_SITE = 'default_site_authorized';
    public const SHARE_PRIVATE = 'private';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Registry ID')]
    public const schema_fields_ID = 'registry_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Global Product UUID')]
    public const schema_fields_UUID = 'global_product_uuid';

    #[Col('varchar', 32, nullable: false, unique: true, comment: 'Stable generated product code')]
    public const schema_fields_PRODUCT_CODE = 'product_code';

    #[Col('int', 11, nullable: false, comment: 'Owning Website ID')]
    public const schema_fields_OWNER_WEBSITE_ID = 'owner_website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 64, nullable: false, comment: 'Product type')]
    public const schema_fields_PRODUCT_TYPE = 'product_type';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_DRAFT, comment: 'Lifecycle status')]
    public const schema_fields_LIFECYCLE_STATUS = 'lifecycle_status';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Optimistic identity version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: false, default: self::SHARE_PRIVATE, comment: 'Default share policy')]
    public const schema_fields_SHARE_POLICY = 'share_policy';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Idempotent request hash')]
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
