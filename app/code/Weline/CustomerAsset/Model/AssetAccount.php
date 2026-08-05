<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Customer asset balance aggregate. Website 0 is valid.
 */
#[Table(comment: 'Customer asset account and balance')]
#[Index(name: 'uk_customer_asset_account_id', columns: ['account_id'], type: 'UNIQUE')]
#[Index(
    name: 'uk_customer_asset_identity',
    columns: ['customer_id', 'website_id', 'asset_code', 'namespace'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_customer_asset_owner', columns: ['customer_id', 'website_id', 'namespace'])]
class AssetAccount extends Model
{
    public const schema_table = 'weline_customer_asset_account';
    public const schema_primary_key = 'asset_account_row_id';

    public const NS_LIVE = 'live';
    public const NS_SANDBOX = 'sandbox';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Account row ID')]
    public const schema_fields_ID = 'asset_account_row_id';

    #[Col('varchar', 64, nullable: false, comment: 'Stable account ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';

    #[Col('varchar', 64, nullable: false, comment: 'Owning Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID including 0')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, comment: 'Asset code')]
    public const schema_fields_ASSET_CODE = 'asset_code';

    #[Col('varchar', 16, nullable: false, default: self::NS_LIVE, comment: 'live|sandbox')]
    public const schema_fields_NAMESPACE = 'namespace';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Total available amount in minor units')]
    public const schema_fields_AVAILABLE_MINOR = 'available_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Reserved amount in minor units')]
    public const schema_fields_RESERVED_MINOR = 'reserved_minor';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Monotonic balance version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 64, nullable: false, comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        $customerId = trim((string) $this->getData(self::schema_fields_CUSTOMER_ID));
        $websiteId = (int) $this->getData(self::schema_fields_WEBSITE_ID);
        $assetCode = trim((string) $this->getData(self::schema_fields_ASSET_CODE));
        $namespace = (string) $this->getData(self::schema_fields_NAMESPACE);
        $available = (int) $this->getData(self::schema_fields_AVAILABLE_MINOR);
        $reserved = (int) $this->getData(self::schema_fields_RESERVED_MINOR);
        $version = (int) $this->getData(self::schema_fields_VERSION);
        if ($customerId === '' || strlen($customerId) > 64) {
            throw new \InvalidArgumentException(__('CustomerAsset customer_id 非法'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('CustomerAsset website_id 不能为负数：%{1}', [$websiteId]));
        }
        if ($assetCode === '' || strlen($assetCode) > 64) {
            throw new \InvalidArgumentException(__('CustomerAsset asset_code 非法'));
        }
        if (!in_array($namespace, [self::NS_LIVE, self::NS_SANDBOX], true)) {
            throw new \InvalidArgumentException(__('CustomerAsset namespace 非法：%{1}', [$namespace]));
        }
        if ($available < 0 || $reserved < 0 || $reserved > $available || $version < 0) {
            throw new \InvalidArgumentException(__('CustomerAsset 余额或版本非法'));
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $this->getData(self::schema_fields_CAS_TOKEN))) {
            throw new \InvalidArgumentException(__('CustomerAsset CAS token 非法'));
        }
        parent::save_before();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
