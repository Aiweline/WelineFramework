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

#[Table(comment: 'Multipass identity token')]
#[Index(name: 'idx_multipass_identity_token_token', columns: ['token'], type: 'UNIQUE', comment: 'Token')]
#[Index(name: 'idx_multipass_identity_token_binding_type', columns: ['binding_id', 'type'], comment: 'Binding token type')]
#[Index(name: 'idx_multipass_identity_token_expires', columns: ['expires_at'], comment: 'Expires at')]
class IdentityToken extends Model
{
    public const schema_table = 'multipass_identity_token';
    public const schema_primary_key = 'token_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Token ID')]
    public const schema_fields_ID = 'token_id';
    #[Col(type: 'int', nullable: false, comment: 'Trusted app ID')]
    public const schema_fields_APP_ID = 'app_id';
    #[Col(type: 'int', nullable: false, comment: 'Binding ID')]
    public const schema_fields_BINDING_ID = 'binding_id';
    #[Col(type: 'int', nullable: false, comment: 'Local customer ID')]
    public const schema_fields_LOCAL_CUSTOMER_ID = 'local_customer_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Token')]
    public const schema_fields_TOKEN = 'token';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Token type')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'text', nullable: true, comment: 'Scopes JSON')]
    public const schema_fields_SCOPES = 'scopes';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Revoked at')]
    public const schema_fields_REVOKED_AT = 'revoked_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const TYPE_ACCESS_TOKEN = 'access_token';
    public const TYPE_REFRESH_TOKEN = 'refresh_token';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['app_id', 'binding_id', 'token', 'type'];

    public function getId(mixed $default = 0): int
    {
        return (int) parent::getId($default);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('Multipass identity token')
            ->addColumn(self::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Token ID')
            ->addColumn(self::schema_fields_APP_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Trusted app ID')
            ->addColumn(self::schema_fields_BINDING_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Binding ID')
            ->addColumn(self::schema_fields_LOCAL_CUSTOMER_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Local customer ID')
            ->addColumn(self::schema_fields_TOKEN, TableInterface::column_type_VARCHAR, 255, 'not null', 'Token')
            ->addColumn(self::schema_fields_TYPE, TableInterface::column_type_VARCHAR, 32, 'not null', 'Token type')
            ->addColumn(self::schema_fields_SCOPES, TableInterface::column_type_TEXT, null, 'default null', 'Scopes JSON')
            ->addColumn(self::schema_fields_EXPIRES_AT, TableInterface::column_type_INTEGER, null, 'not null default 0', 'Expires at')
            ->addColumn(self::schema_fields_REVOKED_AT, TableInterface::column_type_INTEGER, null, 'not null default 0', 'Revoked at')
            ->addColumn(self::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Created at')
            ->addIndex(TableInterface::index_type_UNIQUE, 'idx_multipass_identity_token_token', [self::schema_fields_TOKEN], 'Token')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_identity_token_binding_type', [self::schema_fields_BINDING_ID, self::schema_fields_TYPE], 'Binding token type')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_identity_token_expires', [self::schema_fields_EXPIRES_AT], 'Expires at')
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

    public function getToken(): string
    {
        return (string) ($this->getData(self::schema_fields_TOKEN) ?? '');
    }

    public function setToken(string $token): self
    {
        return $this->setData(self::schema_fields_TOKEN, trim($token));
    }

    public function getType(): string
    {
        return (string) ($this->getData(self::schema_fields_TYPE) ?? '');
    }

    public function setType(string $type): self
    {
        return $this->setData(self::schema_fields_TYPE, $type);
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

    public function getExpiresAt(): int
    {
        return (int) ($this->getData(self::schema_fields_EXPIRES_AT) ?? 0);
    }

    public function setExpiresAt(int $expiresAt): self
    {
        return $this->setData(self::schema_fields_EXPIRES_AT, $expiresAt);
    }

    public function getRevokedAt(): int
    {
        return (int) ($this->getData(self::schema_fields_REVOKED_AT) ?? 0);
    }

    public function setRevokedAt(int $revokedAt): self
    {
        return $this->setData(self::schema_fields_REVOKED_AT, $revokedAt);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        return $expiresAt > 0 && time() > $expiresAt;
    }

    public function isRevoked(): bool
    {
        return $this->getRevokedAt() > 0;
    }

    public function save_before()
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }

        parent::save_before();
    }
}
