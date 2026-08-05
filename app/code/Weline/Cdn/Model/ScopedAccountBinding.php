<?php

declare(strict_types=1);

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'CDN account bindings by Scope and store mode')]
#[Index(
    name: 'idx_cdn_scope_account_identity',
    columns: ['storage_scope', 'store_mode', 'adapter'],
    type: 'UNIQUE',
)]
#[Index(name: 'idx_cdn_scope_account_mode', columns: ['store_mode', 'adapter'])]
class ScopedAccountBinding extends Model
{
    public const schema_table = 'cdn_scoped_account_binding';
    public const schema_primary_key = 'binding_id';
    public string $_primary_key = 'binding_id';
    public array $_unit_primary_keys = ['binding_id'];

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Binding ID')]
    public const schema_fields_ID = 'binding_id';

    #[Col(type: 'varchar', length: 191, nullable: false, comment: 'SystemConfig storage Scope')]
    public const schema_fields_STORAGE_SCOPE = 'storage_scope';

    #[Col(type: 'varchar', length: 16, nullable: false, comment: 'Store runtime mode')]
    public const schema_fields_STORE_MODE = 'store_mode';

    #[Col(type: 'varchar', length: 50, nullable: false, comment: 'CDN or storage adapter code')]
    public const schema_fields_ADAPTER = 'adapter';

    #[Col(type: 'int', length: 11, nullable: false, comment: 'Authorized CDN account ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';

    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Scope media base URL')]
    public const schema_fields_MEDIA_BASE_URL = 'media_base_url';

    #[Col(type: 'varchar', length: 191, nullable: false, default: '', comment: 'Authorized global account alias')]
    public const schema_fields_GLOBAL_ALIAS = 'global_alias';

    #[Col(type: 'datetime', nullable: false, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
