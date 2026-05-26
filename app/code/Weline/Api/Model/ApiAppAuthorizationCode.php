<?php
declare(strict_types=1);

namespace Weline\Api\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'API app authorization code')]
#[Index(name: 'idx_w_api_app_code_code', columns: ['code'], type: 'UNIQUE', comment: 'Authorization code')]
#[Index(name: 'idx_w_api_app_code_app', columns: ['app_id'], comment: 'App ID')]
#[Index(name: 'idx_w_api_app_code_install', columns: ['installation_id'], comment: 'Installation ID')]
class ApiAppAuthorizationCode extends Model
{
    public const schema_table = 'm_api_app_authorization_code';
    public const schema_primary_key = 'code_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Code ID')]
    public const schema_fields_ID = 'code_id';
    #[Col(type: 'int', nullable: false, comment: 'App ID')]
    public const schema_fields_APP_ID = 'app_id';
    #[Col(type: 'int', nullable: false, comment: 'Installation ID')]
    public const schema_fields_INSTALLATION_ID = 'installation_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Code')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Redirect URI')]
    public const schema_fields_REDIRECT_URI = 'redirect_uri';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Expires at')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Consumed at')]
    public const schema_fields_CONSUMED_AT = 'consumed_at';
    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['app_id', 'installation_id', 'code'];

    public function getId(mixed $default = 0): int
    {
        return (int)parent::getId($default);
    }

    public function getAppId(): int
    {
        return (int)($this->getData(self::schema_fields_APP_ID) ?? 0);
    }

    public function setAppId(int $appId): self
    {
        return $this->setData(self::schema_fields_APP_ID, $appId);
    }

    public function getInstallationId(): int
    {
        return (int)($this->getData(self::schema_fields_INSTALLATION_ID) ?? 0);
    }

    public function setInstallationId(int $installationId): self
    {
        return $this->setData(self::schema_fields_INSTALLATION_ID, $installationId);
    }

    public function getCode(): string
    {
        return (string)($this->getData(self::schema_fields_CODE) ?? '');
    }

    public function setCode(string $code): self
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getRedirectUri(): string
    {
        return (string)($this->getData(self::schema_fields_REDIRECT_URI) ?? '');
    }

    public function setRedirectUri(string $redirectUri): self
    {
        return $this->setData(self::schema_fields_REDIRECT_URI, trim($redirectUri));
    }

    public function getExpiresAt(): int
    {
        return (int)($this->getData(self::schema_fields_EXPIRES_AT) ?? 0);
    }

    public function setExpiresAt(int $expiresAt): self
    {
        return $this->setData(self::schema_fields_EXPIRES_AT, $expiresAt);
    }

    public function getConsumedAt(): int
    {
        return (int)($this->getData(self::schema_fields_CONSUMED_AT) ?? 0);
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
