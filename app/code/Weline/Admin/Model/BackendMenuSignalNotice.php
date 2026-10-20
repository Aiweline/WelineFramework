<?php

declare(strict_types=1);

namespace Weline\Admin\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '后台菜单未读消息')]
#[Index(name: 'uk_admin_menu_signal_notice', columns: ['signal_code', 'item_id'], type: 'UNIQUE', comment: '同一菜单消息只记一条')]
class BackendMenuSignalNotice extends Model
{
    public const schema_table = 'weline_admin_menu_signal_notice';

    public const schema_primary_key = 'notice_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'notice_id';

    #[Col(type: 'varchar', length: 191, nullable: false, comment: '菜单 source_id')]
    public const schema_fields_SIGNAL_CODE = 'signal_code';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '业务对象 id')]
    public const schema_fields_ITEM_ID = 'item_id';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: '角标标题')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'varchar', length: 512, nullable: false, comment: '深链')]
    public const schema_fields_HREF = 'href';

    /** @var list<string> */
    public array $_unit_primary_keys = [self::schema_fields_ID];
}
