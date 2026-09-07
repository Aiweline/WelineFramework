<?php

declare(strict_types=1);

namespace Weline\Customer\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: 'Customer social login identity binding')]
#[Index(name: 'idx_customer_social_provider_subject', columns: ['provider_code', 'subject'], type: 'UNIQUE', comment: 'Provider subject')]
#[Index(name: 'idx_customer_social_customer', columns: ['customer_id'], comment: 'Customer')]
class SocialLoginBinding extends Model
{
    public const schema_table = 'customer_social_login_binding';
    public const schema_primary_key = 'binding_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding ID')]
    public const schema_fields_ID = 'binding_id';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: 'Provider subject')]
    public const schema_fields_SUBJECT = 'subject';

    #[Col(type: 'int', nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Email snapshot')]
    public const schema_fields_EMAIL = 'email';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Display name')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: 'Avatar URL')]
    public const schema_fields_AVATAR_URL = 'avatar_url';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_PROVIDER_CODE,
        self::schema_fields_SUBJECT,
        self::schema_fields_CUSTOMER_ID,
    ];

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('Customer social login identity binding')
            ->addColumn(self::schema_fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Binding ID')
            ->addColumn(self::schema_fields_PROVIDER_CODE, TableInterface::column_type_VARCHAR, 32, "not null default ''", 'Provider code')
            ->addColumn(self::schema_fields_SUBJECT, TableInterface::column_type_VARCHAR, 191, "not null default ''", 'Provider subject')
            ->addColumn(self::schema_fields_CUSTOMER_ID, TableInterface::column_type_INTEGER, null, 'not null', 'Customer ID')
            ->addColumn(self::schema_fields_EMAIL, TableInterface::column_type_VARCHAR, 255, "not null default ''", 'Email snapshot')
            ->addColumn(self::schema_fields_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 255, "not null default ''", 'Display name')
            ->addColumn(self::schema_fields_AVATAR_URL, TableInterface::column_type_VARCHAR, 512, "not null default ''", 'Avatar URL')
            ->addColumn(self::schema_fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Created at')
            ->addColumn(self::schema_fields_UPDATED_AT, TableInterface::column_type_DATETIME, null, 'not null', 'Updated at')
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_customer_social_provider_subject',
                [self::schema_fields_PROVIDER_CODE, self::schema_fields_SUBJECT],
                'Provider subject'
            )
            ->addIndex(
                TableInterface::index_type_DEFAULT,
                'idx_customer_social_customer',
                [self::schema_fields_CUSTOMER_ID],
                'Customer'
            )
            ->create();
    }

    public function getId(mixed $default = 0): int
    {
        return (int) parent::getId($default);
    }

    public function getProviderCode(): string
    {
        return (string) $this->getData(self::schema_fields_PROVIDER_CODE);
    }

    public function getSubject(): string
    {
        return (string) $this->getData(self::schema_fields_SUBJECT);
    }

    public function getCustomerId(): int
    {
        return (int) $this->getData(self::schema_fields_CUSTOMER_ID);
    }
}
