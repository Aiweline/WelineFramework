<?php
declare(strict_types=1);

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'API app token')]
#[Index(name: 'idx_w_api_app_token_token', columns: ['token'], type: 'UNIQUE', comment: 'Token')]
#[Index(name: 'idx_w_api_app_token_install_type', columns: ['installation_id', 'type'], comment: 'Installation token type')]
#[Index(name: 'idx_w_api_app_token_expires_at', columns: ['expires_at'], comment: 'Expires at')]
class ApiAppToken extends Model
{
    public const schema_table = 'm_api_app_token';
    public const schema_primary_key = 'token_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Token ID')]
    public const schema_fields_ID = 'token_id';
    #[Col(type: 'int', nullable: false, comment: 'Installation ID')]
    public const schema_fields_INSTALLATION_ID = 'installation_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Token')]
    public const schema_fields_TOKEN = 'token';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Token type')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Revoked at')]
    public const schema_fields_REVOKED_AT = 'revoked_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const TYPE_ACCESS_TOKEN = 'access_token';
    public const TYPE_REFRESH_TOKEN = 'refresh_token';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['installation_id', 'token', 'type'];

    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    public function getInstallationId(): int
    {
        return (int)($this->getData(self::schema_fields_INSTALLATION_ID) ?? 0);
    }

    public function setInstallationId(int $installationId): self
    {
        return $this->setData(self::schema_fields_INSTALLATION_ID, $installationId);
    }

    public function getToken(): string
    {
        return (string)($this->getData(self::schema_fields_TOKEN) ?? '');
    }

    public function setToken(string $token): self
    {
        return $this->setData(self::schema_fields_TOKEN, $token);
    }

    public function getType(): string
    {
        return (string)($this->getData(self::schema_fields_TYPE) ?? '');
    }

    public function setType(string $type): self
    {
        return $this->setData(self::schema_fields_TYPE, $type);
    }

    public function getExpiresAt(): int
    {
        return (int)($this->getData(self::schema_fields_EXPIRES_AT) ?? 0);
    }

    public function setExpiresAt(int $expiresAt): self
    {
        return $this->setData(self::schema_fields_EXPIRES_AT, $expiresAt);
    }

    public function getRevokedAt(): int
    {
        return (int)($this->getData(self::schema_fields_REVOKED_AT) ?? 0);
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
