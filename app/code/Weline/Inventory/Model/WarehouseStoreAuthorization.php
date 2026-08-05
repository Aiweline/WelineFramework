<?php

declare(strict_types=1);

namespace Weline\Inventory\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable Store↔Warehouse authorization matrix and Store default binding. */
#[Table(comment: 'Inventory Store warehouse authorization')]
#[Index(name: 'uk_inv_wh_store_auth', columns: ['website_id', 'store_id', 'warehouse_id'], type: 'UNIQUE')]
#[Index(name: 'uk_inv_wh_store_default', columns: ['website_id', 'store_id', 'default_guard'], type: 'UNIQUE')]
#[Index(name: 'idx_inv_wh_auth_wh', columns: ['warehouse_id', 'enabled'])]
class WarehouseStoreAuthorization extends Model
{
    public const schema_table = 'weline_inventory_warehouse_store_authorization';
    public const schema_primary_key = 'authorization_id';
    public const DEFAULT_GUARD = 'default';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Authorization ID')]
    public const schema_fields_ID = 'authorization_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, comment: 'Warehouse ID')]
    public const schema_fields_WAREHOUSE_ID = 'warehouse_id';

    #[Col('varchar', 16, nullable: false, comment: 'Trusted Store mode snapshot')]
    public const schema_fields_STORE_MODE_SNAPSHOT = 'store_mode_snapshot';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Default logical warehouse binding')]
    public const schema_fields_IS_DEFAULT = 'is_default';

    #[Col('varchar', 16, nullable: true, comment: 'Nullable unique guard for Store default')]
    public const schema_fields_DEFAULT_GUARD = 'default_guard';

    #[Col('tinyint', 1, nullable: false, default: 1, comment: 'Enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Verified Warehouse writer enabled')]
    public const schema_fields_WRITER_ENABLED = 'writer_enabled';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Authorization version')]
    public const schema_fields_AUTHORIZATION_VERSION = 'authorization_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        $websiteId = (int) $this->getData(self::schema_fields_WEBSITE_ID);
        $storeId = (int) $this->getData(self::schema_fields_STORE_ID);
        $warehouseId = (int) $this->getData(self::schema_fields_WAREHOUSE_ID);
        if ($websiteId < 0 || $storeId <= 0 || $warehouseId <= 0) {
            throw new \InvalidArgumentException(__('仓授权 Scope 无效'));
        }
        $isDefault = (int) $this->getData(self::schema_fields_IS_DEFAULT) === 1;
        $this->setData(self::schema_fields_IS_DEFAULT, $isDefault ? 1 : 0);
        $this->setData(
            self::schema_fields_DEFAULT_GUARD,
            $isDefault ? self::DEFAULT_GUARD : null,
        );
        $this->setData(
            self::schema_fields_WRITER_ENABLED,
            (int) $this->getData(self::schema_fields_WRITER_ENABLED) === 1 ? 1 : 0,
        );
        $this->setData(
            self::schema_fields_AUTHORIZATION_VERSION,
            max(0, (int) $this->getData(self::schema_fields_AUTHORIZATION_VERSION)),
        );

        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
