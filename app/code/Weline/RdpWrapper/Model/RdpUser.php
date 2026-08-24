<?php

declare(strict_types=1);

namespace Weline\RdpWrapper\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** RDP 用户管理模型 - 记录通过本模块创建的 Windows 用户账户 */
#[Table(comment: 'RDP远程桌面用户管理')]
#[Index(name: 'uk_username', columns: ['username'], type: 'UNIQUE')]
class RdpUser extends Model
{

    public const schema_table = 'weline_rdp_wrapper_rdp_user';
    public const schema_primary_key = 'id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 100, nullable: false, comment: 'Windows用户名')]
    public const schema_fields_USERNAME = 'username';
    #[Col('varchar', 255, default: '', comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
    #[Col('varchar', 255, default: '', comment: '密码提示')]
    public const schema_fields_PASSWORD_HINT = 'password_hint';
    #[Col('smallint', 1, default: 0, comment: '是否管理员')]
    public const schema_fields_IS_ADMIN = 'is_admin';
    #[Col('smallint', 1, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '备注')]
    public const schema_fields_REMARK = 'remark';
    #[Col('timestamp', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'username'];
}

