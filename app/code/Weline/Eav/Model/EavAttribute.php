<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 20:25:54
 */

namespace Weline\Eav\Model;

use Weline\Eav\Model\EavAttribute\LocalDescription;
use Weline\Eav\EavInterface;
use Weline\Eav\EavModel;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class EavAttribute extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'attribute_id';
    public const fields_attribute_id = 'attribute_id';
    public const fields_code = 'code';
    public const fields_name = 'name';
    public const fields_type_id = 'type_id';
    public const fields_set_id = 'set_id';
    public const fields_group_id = 'group_id';
    public const fields_entity_id = 'entity_id';
    public const fields_multiple_valued = 'multiple_valued';
    public const fields_has_option = 'has_option';
    public const fields_is_system = 'is_system';
    public const fields_is_enable = 'is_enable';

    public const value_key = 'value';
    public const swatch_value_key = 'swatch_value';

    public const value_keys = [
        self::value_key,
        self::swatch_value_key,
    ];

    public array $_unit_primary_keys = ['entity_id', 'code'];
    public array $_index_sort_keys = ['attribute_id', 'entity_id', 'set_id', 'group_id', 'name'];

    private ?Type $type = null;
    private ?Value $value = null;
    private ?EavModel $currentEntity = null;


    public function addLocalDescription()
    {
        $lang = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \Weline\Eav\Model\EavAttribute\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }

    public function loadByAttributeId(int $attribute_id): AbstractModel
    {
        return parent::load('main_table.attribute_id', $attribute_id);
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
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
        //        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('属性表')
                ->addColumn(
                    self::fields_attribute_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '属性ID'
                )
                ->addColumn(
                    self::fields_code,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '代码'
                )
                ->addColumn(
                    self::fields_entity_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '所属实体ID'
                )
                ->addColumn(
                    self::fields_set_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '所属属性集ID'
                )
                ->addColumn(
                    self::fields_group_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '所属属性组ID'
                )
                ->addColumn(
                    self::fields_name,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '名称'
                )
                ->addColumn(
                    self::fields_type_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '类型'
                )
                ->addColumn(
                    self::fields_multiple_valued,
                    TableInterface::column_type_SMALLINT,
                    0,
                    'default 0',
                    '是否多值'
                )
                ->addColumn(
                    self::fields_has_option,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否多值'
                )
                ->addColumn(
                    self::fields_is_system,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 0',
                    '是否系统生成'
                )
                ->addColumn(
                    self::fields_is_enable,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_unique_entity_and_code', [self::fields_code, self::fields_entity_id])
                ->addIndex(TableInterface::index_type_KEY, 'idx_entity_id', self::fields_entity_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_set_id', self::fields_set_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_group_id', self::fields_group_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_name)
                ->create();
        }
    }

    public function getEntityId(): int
    {
        return (int)$this->getData(self::fields_entity_id);
    }

    public function getEntityModel(): EavModel
    {
        if ($this->currentEntity) {
            return $this->currentEntity;
        }
        /**@var EavEntity $eavEntity */
        $eavEntity = ObjectManager::getInstance(EavEntity::class)->load($this->getEntityId());
        $this->currentEntity = ObjectManager::getInstance($eavEntity->getClass())->load($eavEntity->getId());
        return $this->currentEntity;
    }

    public function loadByCode(string $code)
    {
        return $this->load('code', $code);
    }


    public function setEntity(string $entity): static
    {
        return $this->setData(self::fields_entity_id, $entity);
    }

    public function getCode(): string
    {
        return $this->getData(self::fields_code) ?: '';
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    public function getTypeId(): int
    {
        return (int)$this->getData(self::fields_type_id) ?: 0;
    }

    public function getTypeModel(): Type
    {
        if ($this->type) {
            return $this->type;
        }
        $this->type = ObjectManager::getInstance(Type::class)->load($this->getTypeId());
        return $this->type;
    }

    public function setTypeId(int $type_id): static
    {
        return $this->setData(self::fields_type_id, $type_id);
    }


    public function getName(): string
    {
        return $this->getData(self::fields_name) ?: '';
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function hasOption(bool $has_option = null): bool|static
    {
        if (is_bool($has_option)) {
            return $this->setData(self::fields_has_option, $has_option);
        }
        return (bool)$this->getData(self::fields_has_option);
    }

    public function getOptions(): array
    {
        return ObjectManager::getInstance(Option::class)->where(self::fields_ID, $this->getId())->select()->fetchOrigin();
    }

    public function isSystem(bool $is_system = null): bool|static
    {
        if (is_bool($is_system)) {
            return $this->setData(self::fields_is_system, $is_system);
        }
        return (bool)$this->getData(self::fields_is_system);
    }

    public function isEnable(bool $is_enable = null): bool|static
    {
        if (is_bool($is_enable)) {
            return $this->setData(self::fields_is_enable, $is_enable);
        }
        return (bool)$this->getData(self::fields_is_enable);
    }

    public function getMultipleValued(): bool
    {
        return (bool)$this->getData(self::fields_multiple_valued);
    }

    public function setMultipleValued(bool $is_multiple_valued = false): static
    {
        return $this->setData(self::fields_multiple_valued, $is_multiple_valued);
    }

    public function getValue(string|int $entity_id = null, bool $object = false)
    {
        if (!$this->current_getEntity()->getEntityCode()) {
            throw new Exception(__('该属性没有entity实体！'));
        }
        if (!$this->getCode()) {
            throw new Exception(__('该属性没有code代码！'));
        }
        $entity_id = $entity_id ?: $this->getEntityId();
        if ($entity_id) {
            $attribute = clone $this;
            $valueModel = $this->w_getValueModel();
            $valueModel->setAttribute($this);
            $attribute->clearQuery()
                ->fields('main_table.code,main_table.entity_id,main_table.name,main_table.type_id,v.value')
                ->where($attribute::fields_entity_id, $attribute->getEntity())
                ->where($attribute::fields_code, $attribute->getCode());
            $attribute->joinModel(
                $valueModel,
                'v',
                "main_table.attribute_id=v.attribute_id and v.entity_id='{$entity_id}'",
                'left',
                'v.value'
            );
            if ($attribute->getMultipleValued()) {
                $values = $attribute->select()->fetchOrigin();
                foreach ($values as $key => &$item) {
                    $item = $item['value'];
                }
                $attribute->setData($this::value_key, $values);
            } else {
                $value = $attribute->find()->fetchOrigin();
                $attribute->setData($this::value_key, $value[0]['value'] ?? []);
            }
            if ($object) {
                return $attribute;
            }
            return $attribute->getData($this::value_key);
        }
        if ($object) {
            return $this;
        }
        return $this->getData($this::value_key);
    }

    public function getSwatchValue(string|int $entity_id = null, bool $object = false)
    {
        if (!$this->current_getEntity()->getEntityCode()) {
            throw new Exception(__('该属性没有entity实体！'));
        }
        if (!$this->getCode()) {
            throw new Exception(__('该属性没有code代码！'));
        }
        $entity_id = $entity_id ?: $this->getEntityId();
        if ($entity_id) {
            $attribute = clone $this;
            $valueModel = $this->w_getValueModel();
            $valueModel->setAttribute($this);
            $attribute->clearQuery()
                ->fields('main_table.code,main_table.entity_id,main_table.name,main_table.type_id,v.value')
                ->where($attribute::fields_entity_id, $attribute->getEntity())
                ->where($attribute::fields_code, $attribute->getCode());
            $attribute->joinModel(
                $valueModel,
                'v',
                "main_table.attribute_id=v.attribute_id and v.entity_id='{$entity_id}'",
                'left',
                'v.value'
            );
            if ($attribute->getMultipleValued()) {
                $values = $attribute->select()->fetchOrigin();
                $swatchs = [];
                foreach ($values as $key => &$item) {
                    $item = $item['value'];
                    $swatchs[] = [
                        'value' => $item['value'],
                        'is_swatch' => isset($item['is_swatch']) ? (bool)$item['is_swatch'] : false,
                        'swatch_image' => $item['swatch_image'] ?? null,
                        'swatch_color' => $item['swatch_color'] ?? null,
                        'swatch_text' => $item['swatch_text'] ?? null,
                    ];
                }
                $attribute->setData($this::swatch_value_key, $values);
            } else {
                $value = $attribute->find()->fetchOrigin()[0]??[];
                $swatch = [
                    'value' => $value['value'],
                    'is_swatch' => isset($value['is_swatch']) ? (bool)$value['is_swatch'] : false,
                    'swatch_image' => $value['swatch_image'] ?? null,
                    'swatch_color' => $value['swatch_color'] ?? null,
                    'swatch_text' => $value['swatch_text'] ?? null,
                ];
                $attribute->setData($this::swatch_value_key, $swatch);
            }
            if ($object) {
                return $attribute;
            }
            return $attribute->getData($this::swatch_value_key);
        }
        if ($object) {
            return $this;
        }
        return $this->getData($this::swatch_value_key);
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/13 20:19
     * 参数区：
     *
     * @param string|int $entity_id entity_id：1 或者 'entity_id_code'
     *
     * @param \Weline\Eav\Model\EavAttribute\Type\Value|array|string|int $value entity_id值：Array:[1,2,3...] 或者 1 或者 ‘1’
     *
     * @return \Weline\Eav\Model\EavAttribute
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     * @throws \Weline\Framework\Exception\Core
     */
    public function setValue(string|int $entity_id, \Weline\Eav\Model\EavAttribute\Type\Value|array|string|int $value): static
    {
        if (is_string($value) || is_int($value)) {
            $this->w_getValueModel()->where(['entity_id' => $entity_id, 'attribute' => $this->getCode()])->delete();
            $this->w_getValueModel()->insert(['entity_id' => $entity_id, 'attribute' => $this->getCode(), 'value' => $value])->fetch();
        } elseif (is_array($value)) {
            if (!$this->getMultipleValued() && (count($value) > 1)) {
                throw new Exception(__('单值属性只能接收一个值！当前值：%1', w_var_export($value, true)));
            }
            $this->w_getValueModel()->where(['entity_id' => $entity_id, 'attribute' => $this->getCode()])->delete();
            $data = [];
            foreach ($value as $item) {
                $data[] = ['entity_id' => $entity_id, 'value' => $item, 'attribute' => $this->getCode()];
            }
            $this->w_getValueModel()->insert($data)->fetch();
        } elseif ($value instanceof Value) {
            $value->save(true);
        }
        return $this;
    }

    public function addValue(string|int $entity_id, array|string|int $value): bool
    {
        if (!$this->getMultipleValued()) {
            if (is_string($value) || is_int($value)) {
                $this->w_getValueModel()
                    ->setEntityId($entity_id)
                    ->setValue($value);
                return true;
            } else {
                if (DEV) {
                    throw new Exception(__('单值属性不支持数组或者对象类型值：%1', w_var_export($value, true)));
                }
                return false;
            }
        }

        // FIXME 添加值
        foreach ($value as $item) {
            if (!is_string($item) || !is_int($item)) {
                if (DEV) {
                    throw new Exception(__('不接受除string和int以外的值！'));
                }
            }
            $this->w_getValueModel()
                ->setEntityId($entity_id)
                ->setValue($item);
        }
        return true;
    }

    /**
     * @DESC          # 读取配置项模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/18 22:27
     * 参数区：
     */
    public function getOptionModel()
    {
        /**@var \Weline\Eav\Model\EavAttribute\Option $optionModel */
        $optionModel = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Option::class);
        return $optionModel->where($optionModel::fields_attribute_id, $this->getId())
            ->where($optionModel::fields_entity_id, $this->getEntityCode());
    }

    // 不安全，容易删除属性所有数据
    //    function removeValue(string|int $entity_id=null){
    //
    //    }

    /**
     * @DESC          # 系统：读取值模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/15 21:13
     * 参数区：
     * @return \Weline\Eav\Model\EavAttribute\Type\Value
     */
    public function w_getValueModel(): \Weline\Eav\Model\EavAttribute\Type\Value
    {
        if (!$this->value) {
            /**@var \Weline\Eav\Model\EavAttribute\Type\Value $valueModel */
            $valueModel = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Type\Value::class);
            $valueModel->setAttribute($this);
            $this->value = $valueModel;
        }
        $this->value->reset();
        return $this->value;
    }

    /**
     * @DESC          # 系统：设置属性实体
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/15 21:08
     * 参数区：
     *
     * @param EavModel|\Weline\Eav\EavInterface $entity
     *
     * @return $this
     */
    public function current_setEntity(EavModel|\Weline\Eav\EavInterface &$entity): EavAttribute
    {
        $this->currentEntity = $entity;
        return $this;
    }

    /**
     * @DESC          # 系统：获取当前属性实体
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/15 21:10
     * 参数区：
     * @return \Weline\Eav\EavModel
     * @throws \Weline\Framework\App\Exception
     */
    public function current_getEntity(): EavModel
    {
        if (!$this->currentEntity) {
            $this->currentEntity = $this->getEntityModel();
            if (!$this->currentEntity) {
                throw new Exception(__('属性没有实体！'));
            }
        }
        return $this->currentEntity;
    }
}
