<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable Vendor identity; payment account bindings are stored separately. */
#[Table(comment: 'Vendor durable identity')]
#[Index(name: 'uk_vendor_identity_id', columns: ['vendor_id'], type: 'UNIQUE')]
#[Index(name: 'uk_vendor_identity_code_env', columns: ['code', 'environment'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_identity_status', columns: ['status'])]
class VendorRecord extends Model
{
    public const schema_table = 'weline_vendor_identity';
    public const schema_primary_key = 'identity_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Identity ID')]
    public const schema_fields_ID = 'identity_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable public Vendor ID')]
    public const schema_fields_VENDOR_ID = 'vendor_id';

    #[Col('varchar', 64, nullable: false, comment: 'Vendor code')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 255, nullable: false, comment: 'Legal name')]
    public const schema_fields_LEGAL_NAME = 'legal_name';

    #[Col('varchar', 16, nullable: false, comment: 'sandbox|live')]
    public const schema_fields_ENVIRONMENT = 'environment';

    #[Col('varchar', 16, nullable: false, comment: 'active|disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 255, nullable: false, default: '', comment: 'Legacy non-secret account reference')]
    public const schema_fields_ACCOUNT_REF = 'account_ref';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        VendorIdentity::assertVendorCode((string) $this->getData(self::schema_fields_CODE));
        VendorIdentity::assertEnvironment((string) $this->getData(self::schema_fields_ENVIRONMENT));
        VendorIdentity::assertStatus((string) $this->getData(self::schema_fields_STATUS));
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
