<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Product downloadable customer entitlement')]
#[Index(name: 'uk_product_download_entitlement_uuid', columns: ['entitlement_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_product_download_entitlement_grant', columns: ['grant_key'], type: 'UNIQUE')]
#[Index(name: 'idx_product_download_customer', columns: ['customer_id', 'website_id', 'status'])]
#[Index(name: 'idx_product_download_order', columns: ['order_uuid', 'order_line_key'])]
final class DownloadEntitlement extends Model
{
    public const schema_table = 'weline_product_download_entitlement';
    public const schema_primary_key = 'entitlement_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_REVOKED = 'revoked';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'entitlement_id';
    #[Col('varchar', 36, nullable: false, unique: true)]
    public const schema_fields_UUID = 'entitlement_uuid';
    #[Col('char', 64, nullable: false, unique: true)]
    public const schema_fields_GRANT_KEY = 'grant_key';
    #[Col('char', 64, nullable: false)]
    public const schema_fields_SNAPSHOT_HASH = 'snapshot_hash';
    #[Col('varchar', 36, nullable: false)]
    public const schema_fields_ORDER_UUID = 'order_uuid';
    #[Col('varchar', 128, nullable: false)]
    public const schema_fields_ORDER_LINE_KEY = 'order_line_key';
    #[Col('bigint', 20, nullable: false)]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('bigint', 20, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('bigint', 20, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('varchar', 36, nullable: false)]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';
    #[Col('varchar', 36, nullable: false)]
    public const schema_fields_OFFER_UUID = 'global_offer_uuid';
    #[Col('varchar', 36, nullable: false)]
    public const schema_fields_ASSET_ID = 'asset_id';
    #[Col('int', 11, nullable: false, default: 1)]
    public const schema_fields_ASSET_REVISION = 'asset_revision';
    #[Col('int', 11, nullable: false, default: 1)]
    public const schema_fields_POLICY_REVISION = 'policy_revision';
    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_ASSET_NAME = 'asset_name';
    #[Col('int', 11, nullable: true)]
    public const schema_fields_DOWNLOAD_LIMIT = 'download_limit';
    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_DOWNLOAD_COUNT = 'download_count';
    #[Col('datetime', nullable: true)]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('varchar', 24, nullable: false, default: self::STATUS_ACTIVE)]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, nullable: false, default: 1)]
    public const schema_fields_VERSION = 'version';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_GRANTED_AT = 'granted_at';
    #[Col('datetime', nullable: true)]
    public const schema_fields_LAST_DOWNLOAD_AT = 'last_download_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
