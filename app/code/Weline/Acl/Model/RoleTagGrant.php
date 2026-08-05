<?php

declare(strict_types=1);

namespace Weline\Acl\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '角色标签路径订阅')]
#[Index(name: 'uk_role_tag_path', columns: ['role_id', 'tag_path'], type: 'UNIQUE', comment: '角色+标签路径唯一')]
class RoleTagGrant extends Model
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'grant_id';

    #[Col(type: 'int', nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col(type: 'varchar', length: 191, nullable: false, comment: '标签路径 query:media')]
    public const schema_fields_TAG_PATH = 'tag_path';
}
