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

use Weline\Eav\EavInterface;
use Weline\Eav\Model\EavAttribute\LocalDescription;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class EavEntity extends Model
{
    public const fields_ID                     = 'entity_id';
    public const fields_entity_id              = 'entity_id';
    public const fields_code                   = 'code';
    public const fields_name                   = 'name';
    public const fields_class                  = 'class';
    public const fields_is_system              = 'is_system';
    public const fields_entity_id_field_type   = 'entity_id_field_type';
    public const fields_entity_id_field_length = 'entity_id_field_length';

    public array $_unit_primary_keys = ['entity_id', 'code', 'name'];
    public array $_index_sort_keys = ['entity_id', 'code', 'name'];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
        // 安装实体
        /**@var \Weline\Framework\Module\Config\ModuleFileReader $moduleFileReader */
        $moduleFileReader = ObjectManager::getInstance(ModuleFileReader::class);

        $modules = Env::getInstance()->getActiveModules();
        $eavs    = [];
        foreach ($modules as $module) {
            $eavs = array_merge($eavs, $moduleFileReader->readClass(new Module($module), 'Model'));
        }
        foreach ($eavs as $eav) {
            # 检测类是否可以实例化
            $eavEntityReflectionInstance = ObjectManager::getReflectionInstance($eav);
            if(!$eavEntityReflectionInstance->isInstantiable()){
                continue;
            }
            /**@var \Weline\Eav\EavInterface $eavEntity */
            $eavEntity = ObjectManager::getInstance($eav);
            if ($eavEntity instanceof EavInterface) {
                $this->reset()
                     ->setData(
                         [
                             self::fields_ID                     => $eavEntity->getEntityId(),
                             self::fields_code                   => $eavEntity->getEntityCode(),
                             self::fields_class                  => $eav,
                             self::fields_name                   => $eavEntity->getEntityName(),
                             self::fields_is_system              => 1,
                             self::fields_entity_id_field_type   => $eavEntity->getEntityFieldIdType(),
                             self::fields_entity_id_field_length => $eavEntity->getEntityFieldIdLength(),
                         ]
                     )
                     ->forceCheck(true, $this::fields_code)
                     ->save();
            }
        }
    }

    public function loadByCode($code): static
    {
        return $this->load('code', $code);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('Eav实体表')
                  ->addColumn(
                      self::fields_ID,
                      TableInterface::column_type_INTEGER,
                      0,
                      'primary key auto_increment',
                      '实体ID')
                  ->addColumn(
                      self::fields_code,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'unique not null',
                      '实体代码')
                  ->addColumn(
                      self::fields_name,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '实体名')
                  ->addColumn(
                      self::fields_class,
                      TableInterface::column_type_VARCHAR,
                      255,
                      'not null',
                      '实体类')
                  ->addColumn(
                      self::fields_entity_id_field_type,
                      TableInterface::column_type_VARCHAR,
                      60,
                      'not null',
                      '实体ID字段类型')
                  ->addColumn(
                      self::fields_entity_id_field_length,
                      TableInterface::column_type_SMALLINT,
                      5,
                      'not null',
                      '实体ID字段长度')
                  ->addColumn(
                      self::fields_is_system,
                      TableInterface::column_type_SMALLINT,
                      1,
                      'default 0',
                      '是否系统生成')
                  ->addIndex(TableInterface::index_type_UNIQUE, 'idx_code', self::fields_code, '实体编码索引')
                  ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_name, '实体名索引')
                  ->create();
        }
    }

    public function getAttribute(string $code)
    {
        /**@var \Weline\Eav\Model\EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $attributeModel->where(EavAttribute::fields_entity_id, $this->getId())
                       ->where(EavAttribute::fields_code, $code)
                       ->find()
                       ->fetch();
        return $attributeModel;
    }

    public function getCode(): string
    {
        return $this->getData(self::fields_code);
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    public function getName(): string
    {
        return $this->getData(self::fields_name);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function getClass(): string
    {
        return $this->getData(self::fields_class);
    }

    public function setClass(string $class): static
    {
        return $this->setData(self::fields_class, $class);
    }

    public function isSystem(bool $is_system = null): bool|static
    {
        if (is_bool($is_system)) {
            return $this->setData(self::fields_is_system, $is_system);
        }
        return (bool)$this->getData(self::fields_is_system);
    }

    public function getEntityIdFieldType(): string
    {
        return $this->getData(self::fields_entity_id_field_type);
    }

    public function setEntityIdFieldType(string $entity_id_field_type): static
    {
        return $this->setData(self::fields_entity_id_field_type, $entity_id_field_type);
    }

    public function getEntityIdFieldLength(): int
    {
        return intval($this->getData(self::fields_entity_id_field_length));
    }

    public function setEntityIdFieldLength(int $entity_id_field_length): static
    {
        return $this->setData(self::fields_entity_id_field_length, $entity_id_field_length);
    }

    function addLocalDescription()
    {
        $lang = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \Weline\Eav\Model\EavEntity\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }

}