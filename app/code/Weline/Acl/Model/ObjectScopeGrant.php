<?php

declare(strict_types=1);

namespace Weline\Acl\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 角色对象 Scope 授权表（P1B-004-ACL）。
 *
 * is_all_sites=1 时 Scope 字段必须全空，且只允许只读动作。
 * 禁止写入 Store=* / Channel=* 通配；website_id=0 是合法默认站。
 */
#[Table(comment: '角色对象 Scope 授权')]
#[Index(name: 'idx_acl_object_scope_role', columns: ['role_id'])]
class ObjectScopeGrant extends Model
{
    public const schema_table = 'weline_acl_object_scope_grant';
    public const schema_primary_key = 'grant_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '授权ID')]
    public const schema_fields_ID = 'grant_id';

    #[Col('int', nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('smallint', 1, nullable: false, default: 0, comment: 'All Sites 只读授权')]
    public const schema_fields_IS_ALL_SITES = 'is_all_sites';

    #[Col('varchar', 16, nullable: true, comment: '授权 Scope kind：global|website|store|channel')]
    public const schema_fields_SCOPE_KIND = 'scope_kind';

    #[Col('int', nullable: true, comment: 'Website ID（0 合法）')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: true, comment: 'Website code')]
    public const schema_fields_WEBSITE_CODE = 'website_code';

    #[Col('varchar', 64, nullable: true, comment: 'Store code')]
    public const schema_fields_STORE_CODE = 'store_code';

    #[Col('varchar', 64, nullable: true, comment: 'Channel code')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col('text', nullable: false, comment: '允许动作 JSON 数组')]
    public const schema_fields_ACTIONS = 'actions_json';

    #[Col('int', nullable: false, default: 1, comment: '授权版本（提交重鉴权）')]
    public const schema_fields_GRANT_VERSION = 'grant_version';
}
