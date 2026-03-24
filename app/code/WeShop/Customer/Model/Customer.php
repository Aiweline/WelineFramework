<?php

declare(strict_types=1);

namespace WeShop\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Session\Auth\AuthenticableInterface;

#[Table(comment: 'WeShop customer profile table')]
#[Index(name: 'idx_weshop_customer_email', columns: ['email'], comment: 'Email index')]
class Customer extends Model implements AuthenticableInterface
{
    public const schema_table = 'weshop_customer';
    public const schema_primary_key = 'customer_id';
    public string $indexer = 'customer_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Customer ID')]
    public const schema_fields_ID = 'customer_id';
    public const schema_fields_USER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Email')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: 'First name')]
    public const schema_fields_FIRST_NAME = 'firstname';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: 'Last name')]
    public const schema_fields_LAST_NAME = 'lastname';
    #[Col(type: 'varchar', length: 20, nullable: true, comment: 'Phone')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Avatar')]
    public const schema_fields_AVATAR = 'avatar';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Active flag')]
    public const schema_fields_STATUS = 'is_active';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['customer_id'];
    public array $_index_sort_keys = ['email', 'is_active'];

    public function getAuthIdentifier(): int|string
    {
        return (int) $this->getId();
    }

    public function getAuthUsername(): string
    {
        return (string) ($this->getData(self::schema_fields_EMAIL) ?? '');
    }

    public function getAuthSessionId(): string
    {
        return '';
    }

    public static function getAuthModelClass(): string
    {
        return self::class;
    }

    public function getFullName(): string
    {
        $firstName = (string) ($this->getData(self::schema_fields_FIRST_NAME) ?? '');
        $lastName = (string) ($this->getData(self::schema_fields_LAST_NAME) ?? '');

        return trim($firstName . ' ' . $lastName);
    }

    public function getIsEnabled(): bool
    {
        $value = $this->getData(self::schema_fields_STATUS);
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'active'
            || $value === 'enabled';
    }
}
