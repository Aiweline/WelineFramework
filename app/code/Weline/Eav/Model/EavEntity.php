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
    public const fields_ID = 'eav_entity_id';
    public const fields_eav_entity_id = 'eav_entity_id';
    public const fields_code = 'code';
    public const fields_name = 'name';
    public const fields_class = 'class';
    public const fields_is_system = 'is_system';
    public const fields_eav_entity_id_field_type = 'eav_entity_id_field_type';
    public const fields_eav_entity_id_field_length = 'eav_entity_id_field_length';

    public array $_unit_primary_keys = ['eav_entity_id', 'code', 'name'];
    public array $_index_sort_keys = ['eav_entity_id', 'code', 'name'];

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
        $eavs = [];
        foreach ($modules as $module) {
            d($module);
            d($moduleFileReader->readClass(new Module($module), 'Model'));
            $eavs = array_merge($eavs, $moduleFileReader->readClass(new Module($module), 'Model'));
        }
        foreach ($eavs as $eav) {
            # 检测类是否可以实例化
            $eavEntityReflectionInstance = ObjectManager::getReflectionInstance($eav);
            if (!$eavEntityReflectionInstance->isInstantiable()) {
                continue;
            }
            /**@var \Weline\Eav\EavInterface $eavEntity */
            $eavEntity = ObjectManager::getInstance($eav);
            if ($eavEntity instanceof EavInterface) {
                $this->reset()
                    ->setData(
                        [
                            self::fields_ID => $eavEntity->getEavEntityId(),
                            self::fields_code => $eavEntity->getEntityCode(),
                            self::fields_class => $eav,
                            self::fields_name => $eavEntity->getEntityName(),
                            self::fields_is_system => 1,
                            self::fields_eav_entity_id_field_type => $eavEntity->getEntityFieldIdType(),
                            self::fields_eav_entity_id_field_length => $eavEntity->getEntityFieldIdLength(),
                        ]
                    )
                    ->forceCheck(true, $this::fields_code)
                    ->save();
                # 检查属性集和属性组，没有则为实体创建默认属性集和默认属性组
                #--属性集
                $attributeSet = $eavEntity->getAttributeSets();
                if (empty($attributeSet)) {
                    /**@var \Weline\Eav\Model\EavAttribute\Set $eavAttributeSet */
                    $eavAttributeSet = ObjectManager::make(EavAttribute\Set::class);
                    $eavAttributeSet->reset()->clearData()
                        ->insert([
                            'eav_entity_id' => $eavEntity->getEavEntityId(),
                            'name' => '默认属性集',
                            'code' => 'default',
                        ])->fetch();
                }
                # --属性组
                $attributeGroup = $eavEntity->getAttributeGroups();
                if (empty($attributeGroup)) {
                    # 获取默认属性集
                    $attributeSet = $eavEntity->getAttributeSet('default');
                    /**@var \Weline\Eav\Model\EavAttribute\Group $eavAttributeGroup */
                    $eavAttributeGroup = ObjectManager::make(EavAttribute\Group::class);
                    $eavAttributeGroup->reset()->clearData()
                        ->insert([
                            'set_id' => $attributeSet->getId(),
                            'eav_entity_id' => $eavEntity->getEavEntityId(),
                            'name' => '默认属性组',
                            'code' => 'default',
                        ])->fetch();
                }
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
                    self::fields_eav_entity_id_field_type,
                    TableInterface::column_type_VARCHAR,
                    60,
                    'not null',
                    '实体ID字段类型')
                ->addColumn(
                    self::fields_eav_entity_id_field_length,
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
        $attributeModel = ObjectManager::make(EavAttribute::class);
        $attributeModel->where(EavAttribute::fields_eav_entity_id, $this->getId())
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

    public function isSystem(bool $is_system = false): bool|static
    {
        if (is_bool($is_system)) {
            return $this->setData(self::fields_is_system, $is_system);
        }
        return (bool)$this->getData(self::fields_is_system);
    }

    public function getEavEntityIdFieldType(): string
    {
        return $this->getData(self::fields_eav_entity_id_field_type);
    }

    public function setEntityIdFieldType(string $eav_entity_id_field_type): static
    {
        return $this->setData(self::fields_eav_entity_id_field_type, $eav_entity_id_field_type);
    }

    public function getEavEntityIdFieldLength(): int
    {
        return intval($this->getData(self::fields_eav_entity_id_field_length));
    }

    public function setEntityIdFieldLength(int $eav_entity_id_field_length): static
    {
        return $this->setData(self::fields_eav_entity_id_field_length, $eav_entity_id_field_length);
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