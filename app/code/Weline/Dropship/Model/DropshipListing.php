<?php

declare(strict_types=1);

namespace Weline\Dropship\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Dropship listing map (shell truth)')]
#[Index(name: 'uk_dropship_listing', columns: ['provider_code', 'external_spu', 'website_id', 'store_id', 'channel'], type: 'UNIQUE')]
#[Index(name: 'idx_dropship_listing_offer', columns: ['local_offer_id'])]
#[Index(name: 'idx_dropship_listing_source', columns: ['provider_code', 'sync_status'])]
class DropshipListing extends Model
{
    public const schema_table = 'weline_dropship_listing';
    public const schema_primary_key = 'listing_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PENDING = 'pending';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'listing_id';

    #[Col('varchar', 64, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col('varchar', 128, nullable: false, comment: 'External SPU/PID')]
    public const schema_fields_EXTERNAL_SPU = 'external_spu';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'External SKU/VID')]
    public const schema_fields_EXTERNAL_SKU = 'external_sku';

    #[Col('int', 11, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 64, nullable: false, default: 'default', comment: 'Sales channel')]
    public const schema_fields_CHANNEL = 'channel';

    #[Col('varchar', 64, nullable: true, comment: 'Local product UUID')]
    public const schema_fields_LOCAL_PRODUCT_UUID = 'local_product_uuid';

    #[Col('int', 11, nullable: true, comment: 'Local offer ID')]
    public const schema_fields_LOCAL_OFFER_ID = 'local_offer_id';

    #[Col('int', 11, nullable: true, comment: 'Local warehouse ID')]
    public const schema_fields_LOCAL_WAREHOUSE_ID = 'local_warehouse_id';

    #[Col('varchar', 8, nullable: false, default: '', comment: 'CJ/remote country')]
    public const schema_fields_REMOTE_COUNTRY = 'remote_country';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Remote storage id')]
    public const schema_fields_REMOTE_STORAGE_ID = 'remote_storage_id';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Origin price minor')]
    public const schema_fields_ORIGIN_PRICE_MINOR = 'origin_price_minor';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Previous origin minor')]
    public const schema_fields_ORIGIN_PRICE_PREV_MINOR = 'origin_price_prev_minor';

    #[Col('varchar', 8, nullable: false, default: 'USD', comment: 'Origin currency')]
    public const schema_fields_ORIGIN_CURRENCY = 'origin_currency';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Sale price minor synced')]
    public const schema_fields_SALE_PRICE_MINOR = 'sale_price_minor';

    #[Col('int', 11, nullable: false, default: 30, comment: 'Uplift percent snapshot')]
    public const schema_fields_UPLIFT_PERCENT = 'uplift_percent';

    #[Col('varchar', 16, nullable: false, default: '', comment: 'up|down|same')]
    public const schema_fields_PRICE_DIRECTION = 'price_direction';

    #[Col('text', nullable: true, comment: 'Price drop tip')]
    public const schema_fields_PRICE_DROP_TIP = 'price_drop_tip';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Remote qty')]
    public const schema_fields_REMOTE_QTY = 'remote_qty';

    #[Col('varchar', 32, nullable: false, default: 'pending', comment: 'Sync status')]
    public const schema_fields_SYNC_STATUS = 'sync_status';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Lock sale price')]
    public const schema_fields_PRICE_LOCK = 'price_lock';

    #[Col('datetime', nullable: true, comment: 'Last sync')]
    public const schema_fields_LAST_SYNCED_AT = 'last_synced_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
