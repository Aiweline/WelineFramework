<?php

declare(strict_types=1);

namespace Weline\Admin\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '后台菜单消息已读标记')]
#[Index(name: 'uk_admin_menu_signal_read', columns: ['signal_code', 'item_id', 'user_id'], type: 'UNIQUE', comment: '每人每条消息一个已读标记')]
class BackendMenuSignalRead extends Model
{
    public const schema_table = 'weline_admin_menu_signal_read';

    public const schema_primary_key = 'read_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'read_id';

    #[Col(type: 'varchar', length: 191, nullable: false, comment: '菜单 source_id')]
    public const schema_fields_SIGNAL_CODE = 'signal_code';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '业务对象 id')]
    public const schema_fields_ITEM_ID = 'item_id';

    #[Col(type: 'int', nullable: false, comment: '后台用户 ID')]
    public const schema_fields_USER_ID = 'user_id';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '1 已读，0 未读')]
    public const schema_fields_IS_READ = 'is_read';

    #[Col(type: 'datetime', nullable: true, comment: '标为已读的时间')]
    public const schema_fields_READ_AT = 'read_at';

    /** @var list<string> */
    public array $_unit_primary_keys = [self::schema_fields_ID];
}
