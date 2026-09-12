<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Dropship scope warehouse map')]
#[Index(name: 'uk_dropship_scope_wh', columns: ['provider_code', 'website_id', 'store_id', 'remote_country_code'], type: 'UNIQUE')]
class DropshipScopeWarehouseMap extends Model
{
    public const schema_table = 'weline_dropship_scope_warehouse_map';
    public const schema_primary_key = 'map_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'map_id';

    #[Col('varchar', 64, nullable: false, comment: 'Provider')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('int', 11, nullable: false, comment: 'Website')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Optional channel')]
    public const schema_fields_CHANNEL = 'channel';

    #[Col('varchar', 8, nullable: false, comment: 'Remote country code')]
    public const schema_fields_REMOTE_COUNTRY_CODE = 'remote_country_code';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Remote storage id')]
    public const schema_fields_REMOTE_STORAGE_ID = 'remote_storage_id';

    #[Col('int', 11, nullable: false, comment: 'Local warehouse id')]
    public const schema_fields_LOCAL_WAREHOUSE_ID = 'local_warehouse_id';

    #[Col('int', 11, nullable: true, comment: 'Optional shipping address id')]
    public const schema_fields_SHIPPING_ADDRESS_ID = 'shipping_address_id';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
