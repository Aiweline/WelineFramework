<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social platform account binding')]
#[Index(name: 'idx_social_account_platform', columns: ['platform_code'])]
#[Index(name: 'idx_social_account_status', columns: ['status'])]
#[Index(name: 'idx_social_account_test_status', columns: ['test_status'])]
#[Index(name: 'idx_social_account_widget', columns: ['widget_enabled'])]
#[Index(name: 'idx_social_account_publish', columns: ['publish_enabled'])]
class SocialPlatformAccount extends Model
{
    public const schema_table = 'weline_social_platform_account';
    public const schema_primary_key = 'account_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const TEST_STATUS_UNTESTED = 'untested';
    public const TEST_STATUS_PASSED = 'passed';
    public const TEST_STATUS_FAILED = 'failed';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Account ID')]
    public const schema_fields_ID = 'account_id';
    #[Col('varchar', 64, nullable: false, comment: 'Social platform code')]
    public const schema_fields_PLATFORM_CODE = 'platform_code';
    #[Col('varchar', 150, nullable: false, comment: 'Account display name')]
    public const schema_fields_ACCOUNT_NAME = 'account_name';
    #[Col('varchar', 64, nullable: false, comment: 'Authorization mode')]
    public const schema_fields_AUTH_MODE = 'auth_mode';
    #[Col('text', nullable: true, comment: 'Scope JSON')]
    public const schema_fields_SCOPES_JSON = 'scopes_json';
    #[Col('text', nullable: true, comment: 'Encrypted credential JSON')]
    public const schema_fields_CREDENTIALS_ENCRYPTED = 'credentials_encrypted';
    #[Col('datetime', nullable: true, comment: 'Credential expiration time')]
    public const schema_fields_TOKEN_EXPIRES_AT = 'token_expires_at';
    #[Col('varchar', 512, nullable: true, comment: 'Public social profile URL')]
    public const schema_fields_PROFILE_URL = 'profile_url';
    #[Col('int', 1, nullable: false, default: 0, comment: 'Visible in social account widget')]
    public const schema_fields_WIDGET_ENABLED = 'widget_enabled';
    #[Col('int', 1, nullable: false, default: 0, comment: 'Allowed for publish batches')]
    public const schema_fields_PUBLISH_ENABLED = 'publish_enabled';
    #[Col('int', 11, nullable: false, default: 1000, comment: 'Widget/account sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'Account status')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 16, nullable: false, default: 'untested', comment: 'Last config test status')]
    public const schema_fields_TEST_STATUS = 'test_status';
    #[Col('text', nullable: true, comment: 'Last config test message')]
    public const schema_fields_TEST_MESSAGE = 'test_message';
    #[Col('datetime', nullable: true, comment: 'Last config test time')]
    public const schema_fields_TESTED_AT = 'tested_at';
    #[Col('varchar', 150, nullable: true, comment: 'Remote account ID')]
    public const schema_fields_REMOTE_ACCOUNT_ID = 'remote_account_id';
    #[Col('varchar', 190, nullable: true, comment: 'Remote account name')]
    public const schema_fields_REMOTE_ACCOUNT_NAME = 'remote_account_name';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['account_id'];
    public array $_index_sort_keys = ['platform_code', 'status', 'test_status', 'widget_enabled', 'publish_enabled', 'sort_order'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /**
     * @return array<int, string>
     */
    public function getScopes(): array
    {
        $decoded = \json_decode((string)$this->getData(self::schema_fields_SCOPES_JSON), true);
        return \is_array($decoded) ? \array_values(\array_map('strval', $decoded)) : [];
    }

    /**
     * @param array<int, string> $scopes
     */
    public function setScopes(array $scopes): static
    {
        return $this->setData(self::schema_fields_SCOPES_JSON, \json_encode(\array_values($scopes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'account_id' => (int)$this->getId(),
            'platform_code' => (string)$this->getData(self::schema_fields_PLATFORM_CODE),
            'account_name' => (string)$this->getData(self::schema_fields_ACCOUNT_NAME),
            'auth_mode' => (string)$this->getData(self::schema_fields_AUTH_MODE),
            'scopes' => $this->getScopes(),
            'token_expires_at' => (string)$this->getData(self::schema_fields_TOKEN_EXPIRES_AT),
            'profile_url' => (string)$this->getData(self::schema_fields_PROFILE_URL),
            'widget_enabled' => (int)$this->getData(self::schema_fields_WIDGET_ENABLED),
            'publish_enabled' => (int)$this->getData(self::schema_fields_PUBLISH_ENABLED),
            'sort_order' => (int)$this->getData(self::schema_fields_SORT_ORDER),
            'status' => (string)$this->getData(self::schema_fields_STATUS),
            'test_status' => (string)$this->getData(self::schema_fields_TEST_STATUS),
            'test_message' => (string)$this->getData(self::schema_fields_TEST_MESSAGE),
            'tested_at' => (string)$this->getData(self::schema_fields_TESTED_AT),
            'remote_account_id' => (string)$this->getData(self::schema_fields_REMOTE_ACCOUNT_ID),
            'remote_account_name' => (string)$this->getData(self::schema_fields_REMOTE_ACCOUNT_NAME),
            'created_at' => (string)$this->getData(self::schema_fields_CREATED_AT),
            'updated_at' => (string)$this->getData(self::schema_fields_UPDATED_AT),
        ];
    }
}
