<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '模块卸载/MDP 审计')]
class ModuleUninstallAudit extends Model
{
    public const schema_table = 'module_uninstall_audit';
    public const schema_primary_key = 'module_uninstall_audit_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'module_uninstall_audit_id';
    #[Col('varchar', 255, nullable: false, default: '', comment: '模块名')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('varchar', 64, nullable: false, default: '', comment: '动作')]
    public const schema_fields_ACTION = 'action';
    #[Col('text', nullable: true, comment: 'MDP 路径')]
    public const schema_fields_PACKAGE_PATH = 'package_path';
    #[Col('int', 11, nullable: false, default: 0, comment: '表数')]
    public const schema_fields_TABLE_COUNT = 'table_count';
    #[Col('int', 11, nullable: false, default: 0, comment: '行数')]
    public const schema_fields_ROW_COUNT = 'row_count';
    #[Col('text', nullable: true, comment: 'JSON')]
    public const schema_fields_META = 'meta';
    #[Col('varchar', 32, nullable: false, default: '', comment: '时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const ACTION_MDP_CREATE = 'mdp_create';
    public const ACTION_UNINSTALL_BEFORE = 'uninstall_before';
    public const ACTION_RESTORE = 'restore';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }
}
