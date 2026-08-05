<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable, Store-scoped non-secret provider account binding. */
#[Table(comment: 'Vendor Store account binding')]
#[Index(name: 'uk_vendor_store_account', columns: ['vendor_id', 'website_id', 'store_id'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_store_account_status', columns: ['website_id', 'store_id', 'status'])]
#[Index(name: 'idx_vendor_store_account_hash', columns: ['account_ref_hash'])]
class VendorStoreAccountBindingRecord extends Model
{
    public const schema_table = 'weline_vendor_store_account_binding';
    public const schema_primary_key = 'binding_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding ID')]
    public const schema_fields_ID = 'binding_id';

    #[Col('varchar', 64, nullable: false, comment: 'Vendor ID')]
    public const schema_fields_VENDOR_ID = 'vendor_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: 'Store ID')]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('varchar', 16, nullable: false, comment: 'Trusted Store mode snapshot')]
    public const schema_fields_STORE_MODE = 'store_mode_snapshot';

    #[Col('varchar', 16, nullable: false, comment: 'sandbox|live')]
    public const schema_fields_ENVIRONMENT = 'environment';

    #[Col('varchar', 255, nullable: false, comment: 'Non-secret provider account reference')]
    public const schema_fields_ACCOUNT_REF = 'account_ref';

    #[Col('varchar', 64, nullable: false, comment: 'SHA-256 account reference hash')]
    public const schema_fields_ACCOUNT_REF_HASH = 'account_ref_hash';

    #[Col('varchar', 16, nullable: false, comment: 'bound|revoked')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Monotonic binding version')]
    public const schema_fields_BINDING_VERSION = 'binding_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Bound')]
    public const schema_fields_BOUND_AT = 'bound_at';

    #[Col('datetime', nullable: true, comment: 'Revoked')]
    public const schema_fields_REVOKED_AT = 'revoked_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        VendorIdentity::assertEnvironment((string) $this->getData(self::schema_fields_ENVIRONMENT));
        if ((int) $this->getData(self::schema_fields_STORE_ID) <= 0) {
            throw new \InvalidArgumentException(__('Store ID 无效'));
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
