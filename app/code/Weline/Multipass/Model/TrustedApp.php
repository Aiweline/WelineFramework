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

#[Table(comment: 'Multipass trusted identity app')]
#[Index(name: 'idx_multipass_trusted_app_client_id', columns: ['client_id'], type: 'UNIQUE', comment: 'Client ID')]
#[Index(name: 'idx_multipass_trusted_app_status', columns: ['status'], comment: 'Status')]
class TrustedApp extends Model
{
    public const schema_table = 'multipass_trusted_app';
    public const schema_primary_key = 'app_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'App ID')]
    public const schema_fields_ID = 'app_id';
    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Client ID')]
    public const schema_fields_CLIENT_ID = 'client_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Client secret hash')]
    public const schema_fields_CLIENT_SECRET_HASH = 'client_secret_hash';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'App name')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 40, nullable: false, default: 'app', comment: 'App type')]
    public const schema_fields_APP_TYPE = 'app_type';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Trusted domain')]
    public const schema_fields_TRUSTED_DOMAIN = 'trusted_domain';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Redirect URI')]
    public const schema_fields_REDIRECT_URI = 'redirect_uri';
    #[Col(type: 'text', nullable: true, comment: 'Allowed scopes JSON')]
    public const schema_fields_ALLOWED_SCOPES = 'allowed_scopes';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'active', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_DELETED = 'deleted';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['app_id', 'client_id', 'status'];

    public function getId(mixed $default = 0): int
    {
        return (int) parent::getId($default);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('Multipass trusted identity app')
            ->addColumn(self::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'App ID')
            ->addColumn(self::schema_fields_CLIENT_ID, TableInterface::column_type_VARCHAR, 80, 'not null', 'Client ID')
            ->addColumn(self::schema_fields_CLIENT_SECRET_HASH, TableInterface::column_type_VARCHAR, 255, 'not null', 'Client secret hash')
            ->addColumn(self::schema_fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', 'App name')
            ->addColumn(self::schema_fields_APP_TYPE, TableInterface::column_type_VARCHAR, 40, "not null default 'app'", 'App type')
            ->addColumn(self::schema_fields_TRUSTED_DOMAIN, TableInterface::column_type_VARCHAR, 255, "not null default ''", 'Trusted domain')
            ->addColumn(self::schema_fields_REDIRECT_URI, TableInterface::column_type_VARCHAR, 1024, "not null default ''", 'Redirect URI')
            ->addColumn(self::schema_fields_ALLOWED_SCOPES, TableInterface::column_type_TEXT, null, 'default null', 'Allowed scopes JSON')
            ->addColumn(self::schema_fields_STATUS, TableInterface::column_type_VARCHAR, 32, "not null default 'active'", 'Status')
            ->addColumn(self::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Created at')
            ->addColumn(self::schema_fields_UPDATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Updated at')
            ->addIndex(TableInterface::index_type_UNIQUE, 'idx_multipass_trusted_app_client_id', [self::schema_fields_CLIENT_ID], 'Client ID')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_trusted_app_status', [self::schema_fields_STATUS], 'Status')
            ->create();
    }

    public function getClientId(): string
    {
        return (string) ($this->getData(self::schema_fields_CLIENT_ID) ?? '');
    }

    public function setClientId(string $clientId): self
    {
        return $this->setData(self::schema_fields_CLIENT_ID, trim($clientId));
    }

    public function getClientSecretHash(): string
    {
        return (string) ($this->getData(self::schema_fields_CLIENT_SECRET_HASH) ?? '');
    }

    public function setClientSecret(string $clientSecret): self
    {
        return $this->setData(self::schema_fields_CLIENT_SECRET_HASH, password_hash($clientSecret, PASSWORD_DEFAULT));
    }

    public function verifyClientSecret(string $clientSecret): bool
    {
        $hash = $this->getClientSecretHash();
        return $hash !== '' && password_verify($clientSecret, $hash);
    }

    public function getName(): string
    {
        return (string) ($this->getData(self::schema_fields_NAME) ?? '');
    }

    public function setName(string $name): self
    {
        return $this->setData(self::schema_fields_NAME, trim($name));
    }

    public function getAppType(): string
    {
        return (string) ($this->getData(self::schema_fields_APP_TYPE) ?? 'app');
    }

    public function setAppType(string $appType): self
    {
        $appType = strtolower(trim($appType));
        if (!in_array($appType, ['app', 'skill', 'bbs', 'appstore', 'community', 'custom'], true)) {
            $appType = 'custom';
        }
        return $this->setData(self::schema_fields_APP_TYPE, $appType);
    }

    public function getTrustedDomain(): string
    {
        return (string) ($this->getData(self::schema_fields_TRUSTED_DOMAIN) ?? '');
    }

    public function setTrustedDomain(string $trustedDomain): self
    {
        return $this->setData(self::schema_fields_TRUSTED_DOMAIN, strtolower(trim($trustedDomain)));
    }

    public function getRedirectUri(): string
    {
        return (string) ($this->getData(self::schema_fields_REDIRECT_URI) ?? '');
    }

    public function setRedirectUri(string $redirectUri): self
    {
        return $this->setData(self::schema_fields_REDIRECT_URI, trim($redirectUri));
    }

    public function getAllowedScopes(): array
    {
        $raw = (string) ($this->getData(self::schema_fields_ALLOWED_SCOPES) ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return ['profile.basic'];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    public function setAllowedScopes(array $scopes): self
    {
        $scopes = array_values(array_unique(array_filter(array_map(static fn($scope) => trim((string) $scope), $scopes))));
        if (empty($scopes)) {
            $scopes = ['profile.basic'];
        }

        return $this->setData(self::schema_fields_ALLOWED_SCOPES, json_encode($scopes, JSON_UNESCAPED_UNICODE));
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_STATUS) ?? self::STATUS_ACTIVE);
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_DELETED], true)) {
            $status = self::STATUS_DISABLED;
        }
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function generateClientCredentials(): array
    {
        return [
            'client_id' => 'mpapp_' . bin2hex(random_bytes(16)),
            'client_secret' => 'mps_' . bin2hex(random_bytes(32)),
        ];
    }

    public function autoGenerateClientCredentials(): self
    {
        $credentials = $this->generateClientCredentials();
        $this->setClientId($credentials['client_id']);
        $this->setClientSecret($credentials['client_secret']);
        $this->setData('raw_client_secret', $credentials['client_secret']);

        return $this;
    }

    public function save_before()
    {
        if (!$this->getId() && $this->getClientId() === '') {
            $this->autoGenerateClientCredentials();
        }
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }

        parent::save_before();
    }
}
