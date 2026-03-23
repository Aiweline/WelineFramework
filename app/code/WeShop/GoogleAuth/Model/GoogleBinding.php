<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop Google auth binding table')]
#[Index(name: 'uk_weshop_google_binding_subject_area', columns: ['google_subject', 'area'], type: 'UNIQUE')]
#[Index(name: 'uk_weshop_google_binding_area_user', columns: ['area', 'local_user_id'], type: 'UNIQUE')]
#[Index(name: 'idx_weshop_google_binding_email_area', columns: ['email', 'area'])]
class GoogleBinding extends Model
{
    public const schema_table = 'weshop_google_auth_binding';
    public const schema_primary_key = 'binding_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding id')]
    public const schema_fields_ID = 'binding_id';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Google subject')]
    public const schema_fields_GOOGLE_SUBJECT = 'google_subject';
    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'Google email')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'Binding area')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'int', nullable: false, comment: 'Local user id')]
    public const schema_fields_LOCAL_USER_ID = 'local_user_id';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Bound at')]
    public const schema_fields_BOUND_AT = 'bound_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Last login at')]
    public const schema_fields_LAST_LOGIN_AT = 'last_login_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
