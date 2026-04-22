<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 20:24:56
 */

namespace Weline\Eav\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV实体模型 (SRP - 单一职责原则)
 *
 * 表结构由 #[Table]/#[Col] 声明，供 SchemaDiff 同步；与 Schema/EavEntitySchema.php 定义一致。
 */
#[Table(comment: 'EAV实体表')]
class EavEntity extends Model
{
    public const schema_table = 'eav_entity';
    /** @var list<string> */
    public const schema_primary_keys = ['eav_entity_id'];

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '实体ID')]
    public const schema_fields_ID = 'eav_entity_id';
    public const schema_fields_eav_entity_id = 'eav_entity_id';
    #[Col('varchar', 255, nullable: false, unique: true, comment: '实体代码')]
    public const schema_fields_code = 'code';
    #[Col('varchar', 255, nullable: false, comment: '实体名')]
    public const schema_fields_name = 'name';
    #[Col('varchar', 255, nullable: false, comment: '实体类')]
    public const schema_fields_class = 'class';
    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否系统')]
    public const schema_fields_is_system = 'is_system';
    #[Col('varchar', 60, nullable: false, comment: '实体ID字段类型')]
    public const schema_fields_eav_entity_id_field_type = 'eav_entity_id_field_type';
    #[Col('smallint', 5, nullable: false, comment: '实体ID字段长度')]
    public const schema_fields_eav_entity_id_field_length = 'eav_entity_id_field_length';

    public array $_unit_primary_keys = ['eav_entity_id', 'code', 'name'];
    public array $_index_sort_keys = ['eav_entity_id', 'code', 'name'];

    // 表结构已迁移到 Schema/EavEntitySchema.php
    // 由 SchemaRegistry 统一管理表创建
    public function setup(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void
    {
        $this->install($setup, $context);
    }
    
    public function upgrade(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void {}
    
    public function install(\Weline\Framework\Setup\Db\ModelSetup $setup, \Weline\Framework\Setup\Data\Context $context): void
    {
        // 使用 SchemaRegistry 创建所有 EAV 核心表
        // EavEntity 是第一个被安装的 Model，所以在这里触发所有表的创建
        $registry = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Eav\Schema\SchemaRegistry::class);
        $registry->registerClasses(\Weline\Eav\Schema\SchemaRegistry::getDefaultSchemas());
        $registry->createAllTables($setup);
    }

    public function loadByCode($code): static
    {
        return $this->load('code', $code);
    }

    public function getAttribute(string $code)
    {
        /**@var \Weline\Eav\Model\EavAttribute $attributeModel */
        $attributeModel = ObjectManager::make(EavAttribute::class);
        $attributeModel->where(EavAttribute::schema_fields_eav_entity_id, $this->getId())
            ->where(EavAttribute::schema_fields_code, $code)
            ->find()
            ->fetch();
        return $attributeModel;
    }

    public function getCode(): string
    {
        return $this->getData(self::schema_fields_code);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_code, $code);
    }

    public function getName(): string
    {
        return $this->getData(self::schema_fields_name);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }

    public function getClass(): string
    {
        return $this->getData(self::schema_fields_class);
    }

    public function setClass(string $class): static
    {
        return $this->setData(self::schema_fields_class, $class);
    }

    public function isSystem(bool $is_system = false): bool|static
    {
        if (is_bool($is_system)) {
            return $this->setData(self::schema_fields_is_system, $is_system);
        }
        return (bool)$this->getData(self::schema_fields_is_system);
    }

    public function getEavEntityIdFieldType(): string
    {
        return $this->getData(self::schema_fields_eav_entity_id_field_type);
    }

    public function setEntityIdFieldType(string $eav_entity_id_field_type): static
    {
        return $this->setData(self::schema_fields_eav_entity_id_field_type, $eav_entity_id_field_type);
    }

    public function getEavEntityIdFieldLength(): int
    {
        return intval($this->getData(self::schema_fields_eav_entity_id_field_length));
    }

    public function setEntityIdFieldLength(int $eav_entity_id_field_length): static
    {
        return $this->setData(self::schema_fields_eav_entity_id_field_length, $eav_entity_id_field_length);
    }
}
