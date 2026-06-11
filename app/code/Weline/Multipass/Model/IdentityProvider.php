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

#[Table(comment: 'Multipass remote identity provider')]
#[Index(name: 'idx_multipass_identity_provider_status', columns: ['status'], comment: 'Status')]
#[Index(name: 'idx_multipass_identity_provider_sort', columns: ['sort_order'], comment: 'Sort order')]
class IdentityProvider extends Model
{
    public const schema_table = 'multipass_identity_provider';
    public const schema_primary_key = 'provider_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Provider ID')]
    public const schema_fields_ID = 'provider_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Provider name')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Official authorization base URL')]
    public const schema_fields_ISSUER_BASE_URL = 'issuer_base_url';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Official REST base URL')]
    public const schema_fields_REST_BASE_URL = 'rest_base_url';
    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Client ID')]
    public const schema_fields_CLIENT_ID = 'client_id';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Client secret')]
    public const schema_fields_CLIENT_SECRET = 'client_secret';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Callback URI')]
    public const schema_fields_REDIRECT_URI = 'redirect_uri';
    #[Col(type: 'text', nullable: true, comment: 'Requested scopes JSON')]
    public const schema_fields_SCOPES = 'scopes';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'active', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: false, default: 100, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['provider_id', 'status', 'sort_order'];

    public function getId(mixed $default = 0): int
    {
        return (int) parent::getId($default);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('Multipass remote identity provider')
            ->addColumn(self::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Provider ID')
            ->addColumn(self::schema_fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', 'Provider name')
            ->addColumn(self::schema_fields_ISSUER_BASE_URL, TableInterface::column_type_VARCHAR, 1024, "not null default ''", 'Official authorization base URL')
            ->addColumn(self::schema_fields_REST_BASE_URL, TableInterface::column_type_VARCHAR, 1024, "not null default ''", 'Official REST base URL')
            ->addColumn(self::schema_fields_CLIENT_ID, TableInterface::column_type_VARCHAR, 80, 'not null', 'Client ID')
            ->addColumn(self::schema_fields_CLIENT_SECRET, TableInterface::column_type_VARCHAR, 255, "not null default ''", 'Client secret')
            ->addColumn(self::schema_fields_REDIRECT_URI, TableInterface::column_type_VARCHAR, 1024, "not null default ''", 'Callback URI')
            ->addColumn(self::schema_fields_SCOPES, TableInterface::column_type_TEXT, null, 'default null', 'Requested scopes JSON')
            ->addColumn(self::schema_fields_STATUS, TableInterface::column_type_VARCHAR, 32, "not null default 'active'", 'Status')
            ->addColumn(self::schema_fields_SORT_ORDER, TableInterface::column_type_INTEGER, null, 'not null default 100', 'Sort order')
            ->addColumn(self::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Created at')
            ->addColumn(self::schema_fields_UPDATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Updated at')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_identity_provider_status', [self::schema_fields_STATUS], 'Status')
            ->addIndex(TableInterface::index_type_DEFAULT, 'idx_multipass_identity_provider_sort', [self::schema_fields_SORT_ORDER], 'Sort order')
            ->create();
    }

    public function getName(): string
    {
        return (string) ($this->getData(self::schema_fields_NAME) ?? '');
    }

    public function setName(string $name): self
    {
        return $this->setData(self::schema_fields_NAME, trim($name));
    }

    public function getIssuerBaseUrl(): string
    {
        return rtrim((string) ($this->getData(self::schema_fields_ISSUER_BASE_URL) ?? ''), '/');
    }

    public function setIssuerBaseUrl(string $url): self
    {
        return $this->setData(self::schema_fields_ISSUER_BASE_URL, rtrim(trim($url), '/'));
    }

    public function getRestBaseUrl(): string
    {
        $restBaseUrl = rtrim((string) ($this->getData(self::schema_fields_REST_BASE_URL) ?? ''), '/');
        return $restBaseUrl !== '' ? $restBaseUrl : rtrim($this->getIssuerBaseUrl() . '/api123', '/');
    }

    public function setRestBaseUrl(string $url): self
    {
        return $this->setData(self::schema_fields_REST_BASE_URL, rtrim(trim($url), '/'));
    }

    public function getClientId(): string
    {
        return trim((string) ($this->getData(self::schema_fields_CLIENT_ID) ?? ''));
    }

    public function setClientId(string $clientId): self
    {
        return $this->setData(self::schema_fields_CLIENT_ID, trim($clientId));
    }

    public function getClientSecret(): string
    {
        return (string) ($this->getData(self::schema_fields_CLIENT_SECRET) ?? '');
    }

    public function setClientSecret(string $clientSecret): self
    {
        return $this->setData(self::schema_fields_CLIENT_SECRET, trim($clientSecret));
    }

    public function getMaskedClientSecret(): string
    {
        $secret = $this->getClientSecret();
        if ($secret === '') {
            return '';
        }

        return substr($secret, 0, 4) . str_repeat('*', 12) . substr($secret, -4);
    }

    public function getRedirectUri(): string
    {
        return trim((string) ($this->getData(self::schema_fields_REDIRECT_URI) ?? ''));
    }

    public function setRedirectUri(string $redirectUri): self
    {
        return $this->setData(self::schema_fields_REDIRECT_URI, trim($redirectUri));
    }

    public function getScopes(): array
    {
        $raw = (string) ($this->getData(self::schema_fields_SCOPES) ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return ['profile.basic', 'profile.email', 'account.bind'];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    public function setScopes(array $scopes): self
    {
        $scopes = array_values(array_unique(array_filter(array_map(static fn($scope) => trim((string) $scope), $scopes))));
        if (empty($scopes)) {
            $scopes = ['profile.basic', 'profile.email', 'account.bind'];
        }

        return $this->setData(self::schema_fields_SCOPES, json_encode($scopes, JSON_UNESCAPED_UNICODE));
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_STATUS) ?? self::STATUS_ACTIVE);
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            $status = self::STATUS_DISABLED;
        }

        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function getSortOrder(): int
    {
        return (int) ($this->getData(self::schema_fields_SORT_ORDER) ?? 100);
    }

    public function setSortOrder(int $sortOrder): self
    {
        return $this->setData(self::schema_fields_SORT_ORDER, $sortOrder);
    }

    public function save_before()
    {
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }

        parent::save_before();
    }
}
