<?php

declare(strict_types=1);

namespace WeShop\Auth\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop unified auth token table')]
#[Index(name: 'idx_weshop_auth_token_token', columns: ['token'], type: 'UNIQUE')]
#[Index(name: 'idx_weshop_auth_token_actor', columns: ['actor_type', 'actor_id'])]
#[Index(name: 'idx_weshop_auth_token_expires', columns: ['expires_at'])]
class AuthToken extends Model
{
    public const TYPE_ACCESS = 'access_token';
    public const TYPE_REFRESH = 'refresh_token';

    public const schema_table = 'weshop_auth_token';
    public const schema_primary_key = 'token_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Token id')]
    public const schema_fields_ID = 'token_id';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Actor type')]
    public const schema_fields_ACTOR_TYPE = 'actor_type';
    #[Col(type: 'int', nullable: false, comment: 'Actor id')]
    public const schema_fields_ACTOR_ID = 'actor_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'api', comment: 'Actor area')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Token type')]
    public const schema_fields_TOKEN_TYPE = 'token_type';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Token')]
    public const schema_fields_TOKEN = 'token';
    #[Col(type: 'text', nullable: true, comment: 'Scopes json')]
    public const schema_fields_SCOPES = 'scopes';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Is 2FA verified')]
    public const schema_fields_IS_2FA_VERIFIED = 'is_2fa_verified';
    #[Col(type: 'int', nullable: false, comment: 'Expires at timestamp')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Revoked at')]
    public const schema_fields_REVOKED_AT = 'revoked_at';

    public function getScopes(): array
    {
        $value = $this->getData(self::schema_fields_SCOPES);
        if (!is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?: [];
    }

    public function setScopes(array $scopes): static
    {
        return $this->setData(self::schema_fields_SCOPES, json_encode(array_values(array_unique($scopes)), JSON_UNESCAPED_UNICODE));
    }

    public function isExpired(): bool
    {
        return (int) $this->getData(self::schema_fields_EXPIRES_AT) <= time();
    }

    public function isRevoked(): bool
    {
        return (string) ($this->getData(self::schema_fields_REVOKED_AT) ?? '') !== '';
    }
}
