<?php

declare(strict_types=1);

namespace Weline\Acl\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'ACL标签元数据')]
#[Index(name: 'uk_acl_tag', columns: ['tag'], type: 'UNIQUE', comment: '标签唯一')]
class AclTag extends Model
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'tag_id';

    #[Col(type: 'varchar', length: 64, nullable: false, unique: true, comment: '标签词')]
    public const schema_fields_TAG = 'tag';

    #[Col(type: 'varchar', length: 127, nullable: false, default: '', comment: '显示名')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col(type: 'text', nullable: true, comment: '说明')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: '颜色')]
    public const schema_fields_COLOR = 'color';

    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
}
