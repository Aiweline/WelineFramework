<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 模块表注册模型（Framework 内置）
 * 记录模块与表名/模型的映射关系，供 table_ddl_after 观察者写入。表由 FrameworkDbBootstrapStage 创建。
 *
 * @package Weline\Framework\Setup\Model
 */
#[Table(comment: '模块模型表')]
#[Index(name: 'UNIQUE_MODEL', columns: ['model'], type: 'UNIQUE')]
#[Index(name: 'UNIQUE_NAME', columns: ['name'], type: 'UNIQUE')]
class ModuleTable extends Model
{
    public const schema_table = 'weline_module_table';
    public const schema_primary_key = 'module_table_id';

    #[Col(type: 'integer', nullable: false, primaryKey: true, autoIncrement: true, comment: '模块表ID')]
    public const schema_fields_ID = 'module_table_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '模块名称')]
    public const schema_fields_module_name = 'module_name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '表名')]
    public const schema_fields_name = 'name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '模块模型')]
    public const schema_fields_model = 'model';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'owned', comment: 'owned|shared|successor')]
    public const schema_fields_TABLE_POLICY = 'table_policy';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'shared 时 DDL owner 模块')]
    public const schema_fields_OWNER_MODULE_NAME = 'owner_module_name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '承接模块')]
    public const schema_fields_SUCCESSOR_MODULE_NAME = 'successor_module_name';
    #[Col(type: 'varchar', length: 32, nullable: true, comment: '弃用时间')]
    public const schema_fields_DEPRECATED_AT = 'deprecated_at';

    public const POLICY_OWNED = 'owned';
    public const POLICY_SHARED = 'shared';
    public const POLICY_SUCCESSOR = 'successor';

    public function setModuleName(string $module_name): static
    {
        return $this->setData(self::schema_fields_module_name, $module_name);
    }

    public function getModuleName(): string
    {
        return (string) ($this->getData(self::schema_fields_module_name) ?: '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }

    public function getName(): string
    {
        return (string) ($this->getData(self::schema_fields_name) ?: '');
    }

    public function setModel(string $model): static
    {
        return $this->setData(self::schema_fields_model, $model, true);
    }

    public function getModel(): string
    {
        return (string) ($this->getData(self::schema_fields_model) ?: '');
    }
}
