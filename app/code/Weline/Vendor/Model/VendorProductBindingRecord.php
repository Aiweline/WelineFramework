<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable Vendor↔Store↔Product identity binding. */
#[Table(comment: 'Vendor Product binding')]
#[Index(name: 'uk_vendor_product_binding', columns: ['vendor_id', 'website_id', 'store_id', 'product_registry_id'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_product_binding_status', columns: ['website_id', 'store_id', 'status'])]
#[Index(name: 'idx_vendor_product_binding_sku', columns: ['product_sku'])]
class VendorProductBindingRecord extends Model
{
    public const schema_table = 'weline_vendor_product_binding';
    public const schema_primary_key = 'binding_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding ID')]
    public const schema_fields_ID = 'binding_id';

    #[Col('varchar', 64, nullable: false, comment: 'Vendor ID')]
    public const schema_fields_VENDOR_ID = 'vendor_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, comment: 'Product global registry ID')]
    public const schema_fields_PRODUCT_REGISTRY_ID = 'product_registry_id';

    #[Col('varchar', 128, nullable: false, comment: 'Canonical Product SKU snapshot')]
    public const schema_fields_PRODUCT_SKU = 'product_sku';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID snapshot')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('varchar', 16, nullable: false, comment: 'sandbox|live')]
    public const schema_fields_ENVIRONMENT = 'environment';

    #[Col('varchar', 16, nullable: false, comment: 'bound|unbound')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Monotonic binding version')]
    public const schema_fields_BINDING_VERSION = 'binding_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Bound')]
    public const schema_fields_BOUND_AT = 'bound_at';

    #[Col('datetime', nullable: true, comment: 'Unbound')]
    public const schema_fields_UNBOUND_AT = 'unbound_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        if ((int) $this->getData(self::schema_fields_STORE_ID) <= 0
            || (int) $this->getData(self::schema_fields_PRODUCT_REGISTRY_ID) <= 0
        ) {
            throw new \InvalidArgumentException(__('Store/Product identity 无效'));
        }
        $this->setData(
            self::schema_fields_BINDING_VERSION,
            max(1, (int) $this->getData(self::schema_fields_BINDING_VERSION)),
        );
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
