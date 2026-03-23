<?php

declare(strict_types=1);

namespace WeShop\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop customer password reset token table')]
#[Index(name: 'idx_weshop_customer_password_reset_token', columns: ['token'], type: 'UNIQUE')]
#[Index(name: 'idx_weshop_customer_password_reset_user', columns: ['user_id'])]
#[Index(name: 'idx_weshop_customer_password_reset_expire', columns: ['expires_at'])]
class PasswordResetToken extends Model
{
    public const schema_table = 'weshop_customer_password_reset_token';
    public const schema_primary_key = 'reset_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Reset id')]
    public const schema_fields_ID = 'reset_id';
    #[Col(type: 'int', nullable: false, comment: 'User id')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Email')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Reset token')]
    public const schema_fields_TOKEN = 'token';
    #[Col(type: 'int', nullable: false, comment: 'Expires at timestamp')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Used at')]
    public const schema_fields_USED_AT = 'used_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function isExpired(): bool
    {
        return (int) $this->getData(self::schema_fields_EXPIRES_AT) <= time();
    }

    public function isUsed(): bool
    {
        return (string) ($this->getData(self::schema_fields_USED_AT) ?? '') !== '';
    }
}
