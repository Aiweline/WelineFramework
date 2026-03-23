<?php

declare(strict_types=1);

namespace WeShop\Auth\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop pending auth challenge table')]
#[Index(name: 'idx_weshop_auth_challenge_token', columns: ['challenge_token'], type: 'UNIQUE')]
#[Index(name: 'idx_weshop_auth_challenge_actor', columns: ['actor_type', 'local_user_id'])]
#[Index(name: 'idx_weshop_auth_challenge_expires', columns: ['expires_at'])]
class PendingAuthChallenge extends Model
{
    public const schema_table = 'weshop_auth_pending_challenge';
    public const schema_primary_key = 'challenge_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Challenge id')]
    public const schema_fields_ID = 'challenge_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Challenge token')]
    public const schema_fields_CHALLENGE_TOKEN = 'challenge_token';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Actor type')]
    public const schema_fields_ACTOR_TYPE = 'actor_type';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Auth method')]
    public const schema_fields_AUTH_METHOD = 'auth_method';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Area')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'int', nullable: false, comment: 'Local user id')]
    public const schema_fields_LOCAL_USER_ID = 'local_user_id';
    #[Col(type: 'text', nullable: true, comment: 'Scopes json')]
    public const schema_fields_SCOPES = 'scopes';
    #[Col(type: 'text', nullable: true, comment: 'Payload json')]
    public const schema_fields_PAYLOAD = 'payload';
    #[Col(type: 'int', nullable: false, comment: 'Expires at timestamp')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getScopes(): array
    {
        $value = (string) ($this->getData(self::schema_fields_SCOPES) ?? '');
        return $value === '' ? [] : (json_decode($value, true) ?: []);
    }

    public function setScopes(array $scopes): static
    {
        return $this->setData(self::schema_fields_SCOPES, json_encode(array_values(array_unique($scopes)), JSON_UNESCAPED_UNICODE));
    }

    public function getPayload(): array
    {
        $value = (string) ($this->getData(self::schema_fields_PAYLOAD) ?? '');
        return $value === '' ? [] : (json_decode($value, true) ?: []);
    }

    public function setPayload(array $payload): static
    {
        return $this->setData(self::schema_fields_PAYLOAD, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function isExpired(): bool
    {
        return (int) $this->getData(self::schema_fields_EXPIRES_AT) <= time();
    }
}
