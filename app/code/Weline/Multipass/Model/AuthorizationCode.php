<?php
declare(strict_types=1);

namespace Weline\Multipass\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: 'Multipass identity authorization code')]
#[Index(name: 'idx_multipass_auth_code_code', columns: ['code'], type: 'UNIQUE', comment: 'Authorization code')]
#[Index(name: 'idx_multipass_auth_code_app', columns: ['app_id'], comment: 'Trusted app')]
#[Index(name: 'idx_multipass_auth_code_binding', columns: ['binding_id'], comment: 'Account binding')]
class AuthorizationCode extends Model
{
    public const schema_table = 'multipass_authorization_code';
    public const schema_primary_key = 'code_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Code ID')]
    public const schema_fields_ID = 'code_id';
    #[Col(type: 'int', nullable: false, comment: 'Trusted app ID')]
    public const schema_fields_APP_ID = 'app_id';
    #[Col(type: 'int', nullable: false, comment: 'Binding ID')]
    public const schema_fields_BINDING_ID = 'binding_id';
    #[Col(type: 'int', nullable: false, comment: 'Local customer ID')]
    public const schema_fields_LOCAL_CUSTOMER_ID = 'local_customer_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Authorization code')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Redirect URI')]
    public const schema_fields_REDIRECT_URI = 'redirect_uri';
    #[Col(type: 'text', nullable: true, comment: 'Scopes JSON')]
    public const schema_fields_SCOPES = 'scopes';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'State')]
    public const schema_fields_STATE = 'state';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Consumed at')]
    public const schema_fields_CONSUMED_AT = 'consumed_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['app_id', 'binding_id', 'code'];

    public function getId(mixed $default = 0): int
    {
        return (int) parent::getId($default);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('Multipass identity authorization code')
            ->addColumn(self::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Code ID')
            ->addColumn(self::schema_fields_APP_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Trusted app ID')
            ->addColumn(self::schema_fields_BINDING_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Binding ID')
            ->addColumn(self::schema_fields_LOCAL_CUSTOMER_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Local customer ID')
            ->addColumn(self::schema_fields_CODE, TableInterface::column_type_VARCHAR, 255, 'not null', 'Authorization code')
            ->addColumn(self::schema_fields_REDIRECT_URI, TableInterface::column_type_VARCHAR, 1024, "not null default ''", 'Redirect URI')
            ->addColumn(self::schema_fields_SCOPES, TableInterface::column_type_TEXT, null, 'default null', 'Scopes JSON')
            ->addColumn(self::schema_fields_STATE, TableInterface::column_type_VARCHAR, 255, "not null default ''", 'State')
            ->addColumn(self::schema_fields_EXPIRES_AT, TableInterface::column_type_INTEGER, null, 'not null default 0', 'Expires at')
            ->addColumn(self::schema_fields_CONSUMED_AT, TableInterface::column_type_INTEGER, null, 'not null default 0', 'Consumed at')
            ->addColumn(self::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Created at')
            ->addIndex(TableInterface::index_type_UNIQUE, 'idx_multipass_auth_code_code', [self::schema_fields_CODE], 'Authorization code')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_auth_code_app', [self::schema_fields_APP_ID], 'Trusted app')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_auth_code_binding', [self::schema_fields_BINDING_ID], 'Account binding')
            ->create();
    }

    public function getAppId(): int
    {
        return (int) ($this->getData(self::schema_fields_APP_ID) ?? 0);
    }

    public function setAppId(int $appId): self
    {
        return $this->setData(self::schema_fields_APP_ID, $appId);
    }

    public function getBindingId(): int
    {
        return (int) ($this->getData(self::schema_fields_BINDING_ID) ?? 0);
    }

    public function setBindingId(int $bindingId): self
    {
        return $this->setData(self::schema_fields_BINDING_ID, $bindingId);
    }

    public function getLocalCustomerId(): int
    {
        return (int) ($this->getData(self::schema_fields_LOCAL_CUSTOMER_ID) ?? 0);
    }

    public function setLocalCustomerId(int $customerId): self
    {
        return $this->setData(self::schema_fields_LOCAL_CUSTOMER_ID, $customerId);
    }

    public function getCode(): string
    {
        return (string) ($this->getData(self::schema_fields_CODE) ?? '');
    }

    public function setCode(string $code): self
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getRedirectUri(): string
    {
        return (string) ($this->getData(self::schema_fields_REDIRECT_URI) ?? '');
    }

    public function setRedirectUri(string $redirectUri): self
    {
        return $this->setData(self::schema_fields_REDIRECT_URI, trim($redirectUri));
    }

    public function getScopes(): array
    {
        $raw = (string) ($this->getData(self::schema_fields_SCOPES) ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : ['profile.basic'];
    }

    public function setScopes(array $scopes): self
    {
        return $this->setData(self::schema_fields_SCOPES, json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE));
    }

    public function getState(): string
    {
        return (string) ($this->getData(self::schema_fields_STATE) ?? '');
    }

    public function setState(string $state): self
    {
        return $this->setData(self::schema_fields_STATE, trim($state));
    }

    public function getExpiresAt(): int
    {
        return (int) ($this->getData(self::schema_fields_EXPIRES_AT) ?? 0);
    }

    public function setExpiresAt(int $expiresAt): self
    {
        return $this->setData(self::schema_fields_EXPIRES_AT, $expiresAt);
    }

    public function getConsumedAt(): int
    {
        return (int) ($this->getData(self::schema_fields_CONSUMED_AT) ?? 0);
    }

    public function setConsumedAt(int $consumedAt): self
    {
        return $this->setData(self::schema_fields_CONSUMED_AT, $consumedAt);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        return $expiresAt > 0 && time() > $expiresAt;
    }

    public function isConsumed(): bool
    {
        return $this->getConsumedAt() > 0;
    }

    public function save_before()
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }

        parent::save_before();
    }
}
