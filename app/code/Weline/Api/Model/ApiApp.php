<?php
declare(strict_types=1);

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'API application')]
#[Index(name: 'idx_w_api_app_client_id', columns: ['client_id'], type: 'UNIQUE', comment: 'Client ID')]
#[Index(name: 'idx_w_api_app_status', columns: ['status'], comment: 'Status')]
class ApiApp extends Model
{
    public const schema_table = 'm_api_app';
    public const schema_primary_key = 'app_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'App ID')]
    public const schema_fields_ID = 'app_id';
    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Client ID')]
    public const schema_fields_CLIENT_ID = 'client_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Client secret hash')]
    public const schema_fields_CLIENT_SECRET_HASH = 'client_secret_hash';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'App name')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Redirect URI')]
    public const schema_fields_REDIRECT_URI = 'redirect_uri';
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
        return (int)parent::getId($default);
    }

    public function getClientId(): string
    {
        return (string)($this->getData(self::schema_fields_CLIENT_ID) ?? '');
    }

    public function setClientId(string $clientId): self
    {
        return $this->setData(self::schema_fields_CLIENT_ID, $clientId);
    }

    public function getClientSecretHash(): string
    {
        return (string)($this->getData(self::schema_fields_CLIENT_SECRET_HASH) ?? '');
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
        return (string)($this->getData(self::schema_fields_NAME) ?? '');
    }

    public function setName(string $name): self
    {
        return $this->setData(self::schema_fields_NAME, trim($name));
    }

    public function getRedirectUri(): string
    {
        return (string)($this->getData(self::schema_fields_REDIRECT_URI) ?? '');
    }

    public function setRedirectUri(string $redirectUri): self
    {
        return $this->setData(self::schema_fields_REDIRECT_URI, trim($redirectUri));
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?? self::STATUS_ACTIVE);
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_DELETED], true)) {
            $status = self::STATUS_DISABLED;
        }
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getIsEnabled(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function generateClientCredentials(): array
    {
        return [
            'client_id' => 'app_' . bin2hex(random_bytes(16)),
            'client_secret' => 'apps_' . bin2hex(random_bytes(32)),
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
