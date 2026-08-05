<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** Durable Vendor↔Website authorization state. */
#[Table(comment: 'Vendor Website authorization')]
#[Index(name: 'uk_vendor_website_auth', columns: ['vendor_id', 'website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_vendor_website_auth_status', columns: ['website_id', 'status'])]
class VendorWebsiteAuthorizationRecord extends Model
{
    public const schema_table = 'weline_vendor_website_authorization';
    public const schema_primary_key = 'authorization_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Authorization ID')]
    public const schema_fields_ID = 'authorization_id';

    #[Col('varchar', 64, nullable: false, comment: 'Vendor ID')]
    public const schema_fields_VENDOR_ID = 'vendor_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, comment: 'authorized|revoked')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Monotonic grant version')]
    public const schema_fields_GRANT_VERSION = 'grant_version';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Authorized')]
    public const schema_fields_AUTHORIZED_AT = 'authorized_at';

    #[Col('datetime', nullable: true, comment: 'Revoked')]
    public const schema_fields_REVOKED_AT = 'revoked_at';

    public function save_before(): void
    {
        VendorIdentity::assertWebsiteId((int) $this->getData(self::schema_fields_WEBSITE_ID));
        $this->setData(
            self::schema_fields_GRANT_VERSION,
            max(1, (int) $this->getData(self::schema_fields_GRANT_VERSION)),
        );
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
