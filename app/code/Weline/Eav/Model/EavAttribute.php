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
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Exception\Core;
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
    public const fields_eav_entity_id = 'eav_entity_id';
    public const fields_multiple_valued = 'multiple_valued';
    public const fields_has_option = 'has_option';
    public const fields_is_system = 'is_system';
    public const fields_is_enable = 'is_enable';
    public const fields_model_class = 'model_class';
    public const fields_default_value = 'default_value';
    public const fields_dependence = 'dependence'; # 多个依赖以英文逗号隔开,demo1,demo2

    public const value_key = 'value';
    public const swatch_value_key = 'swatch_value';

    public const value_keys = [
        self::value_key,
        self::swatch_value_key,
    ];

    public array $_unit_primary_keys = ['eav_entity_id', 'code'];
    public array $_index_sort_keys = ['attribute_id', 'eav_entity_id', 'set_id', 'group_id', 'name'];

    private ?Type $type = null;
    private ?Value $value = null;
    private ?EavModel $currentEntity = null;
    private array $exist_types = [];

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
                    self::fields_eav_entity_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '所属Eav实体ID'
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
                ->addColumn(
                    self::fields_model_class,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default ""',
                    '模型'
                )
                ->addColumn(
                    self::fields_default_value,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '默认值'
                )
                ->addColumn(
                    self::fields_dependence,
                    TableInterface::column_type_VARCHAR,
                    128,
                    'default null',
                    '依赖属性:多个属性以英文逗号隔开,demo1,demo2'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_unique_entity_and_code', [self::fields_code, self::fields_eav_entity_id])
                ->addIndex(TableInterface::index_type_KEY, 'idx_eav_entity_id', self::fields_eav_entity_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_set_id', self::fields_set_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_group_id', self::fields_group_id)
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_name)
                ->create();
        }
    }

    public function getEavEntityId(): int
    {
        return (int)$this->getData(self::fields_eav_entity_id);
    }

    public function getEntityModel(): EavModel
    {
        if ($this->currentEntity) {
            return $this->currentEntity;
        }
        throw new Exception(__('属性没有实体！'));
    }

    public function getEavEntity(): EavEntity
    {
        /**@var EavEntity $eavEntity */
        $eavEntity = ObjectManager::getInstance(EavEntity::class);
        return $eavEntity->load($this->getEavEntityId());
    }

    public function loadByCode(string $code)
    {
        return $this->load('code', $code);
    }

    public function getCode(): string
    {
        return $this->getData(self::fields_code) ?: '';
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    public function getDependence(): string
    {
        return $this->getData(self::fields_dependence) ?: '';
    }

    public function setDependence(string $dependence): static
    {
        return $this->setData(self::fields_dependence, $dependence);
    }

    public function getTypeId(): int
    {
        return (int)$this->getData(self::fields_type_id) ?: 0;
    }

    public function getModelClass(): string
    {
        return $this->getData(self::fields_model_class) ?: '';
    }

    public function setModelClass(string $model_class): static
    {
        return $this->setData(self::fields_model_class, $model_class);
    }

    public function getDefaultValue()
    {
        return $this->getData(self::fields_default_value) ?: '';
    }

    public function setDefaultValue(string $default_value): static
    {
        return $this->setData(self::fields_default_value, $default_value);
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
        return ObjectManager::getInstance(Option::class)->reset()->where(self::fields_ID, $this->getId())->select()->fetchArray();
    }

    /**
     * @param Option[] $options
     * @return $this
     */
    public function setOptions(array $options): static
    {
        $insert_attribute_options = [];
        /**@var Option $option */
        foreach ($options as $option) {
            $insert_attribute_options[] = [
                EavAttribute\Option::fields_option_id => $option->getId(),
                EavAttribute\Option::fields_code => $option->getCode(),
                EavAttribute\Option::fields_value => $option->getValue(),
                EavAttribute\Option::fields_eav_entity_id => $option->getEavEntityId(),
                EavAttribute\Option::fields_attribute_id => $option->getAttributeId(),
                EavAttribute\Option::fields_swatch_image => $option->getSwatchImage(),
                EavAttribute\Option::fields_swatch_color => $option->getSwatchColor(),
                EavAttribute\Option::fields_swatch_text => $option->getSwatchText(),
            ];
        }
        /**@var EavAttribute\Option $optionModel */
        $optionModel = ObjectManager::getInstance(EavAttribute\Option::class);
        $optionModel->beginTransaction();
        try {
            $optionModel->reset()->insert($insert_attribute_options, ['eav_entity_id', 'attribute_id', 'code'])->fetch();
            $optionModel->commit();
        } catch (\Throwable $e) {
            $optionModel->rollBack();
        }
        return $this;
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
        return $this->setData(self::fields_multiple_valued, $is_multiple_valued ? '1' : '0');
    }

    public function getValue(string|int $entity_id = null, bool $return_attribute = false)
    {
        if (!$this->current_getEntity()->getId()) {
            throw new Exception(__('该属性没有entity实体！'));
        }
        if (!$this->getCode()) {
            throw new Exception(__('该属性没有code代码！'));
        }
        if ($this->getData($this::value_key)) {
            if ($return_attribute) {
                return $this;
            }
            return $this->getData($this::value_key);
        }
        $entity_id = $entity_id ?: $this->current_getEntity()->getId();
        $this->setData('entity_id', $entity_id);
        if ($entity_id) {
            $valueModel = $this->w_getValueModel();
            $valueModel
                ->fields(Value::fields_value)
                ->where(Value::fields_attribute_id, $this->getId())
                ->where(Value::fields_entity_id, $entity_id);
            if ($this->getMultipleValued()) {
                $values = $valueModel->select()->fetchArray();
                foreach ($values as $key => &$item) {
                    $item = $item['value'];
                }
                $this->setData($this::value_key, $values ?: []);
            } else {
                $value = $valueModel->find()->fetchArray();
                $this->setData($this::value_key, $value['value'] ?? '');
            }
        }
        if ($return_attribute) {
            return $this;
        }

        return $this->getData($this::value_key);
    }

    public function getValueWithOptions(string|int $entity_id = null, bool $return_attribute = false, string $option_key = 'value'): array|Option
    {
        $optionModel = $this->getOptionModel();
        $values = $this->getValue($entity_id, false);
        if (!$values) {
            return [];
        }
        $optionModel->where('option_id', $values, is_array($values) ? 'in' : '=')->select();
        if (!$return_attribute) {
            $options = $optionModel->fetchArray();
            if ($option_key) {
                $option_key_array = [];
                foreach ($options as $option) {
                    $option_key_array[$option['option_id']] = $option[$option_key];
                }
                return $option_key_array;
            }
            return $options;
        }
        return $optionModel->fetch();
    }

    public function getSwatchValue(string|int $eav_entity_id = null, bool $object = false)
    {
        if (!$this->current_getEntity()->getId()) {
            throw new Exception(__('该属性没有entity实体！'));
        }
        if (!$this->getCode()) {
            throw new Exception(__('该属性没有code代码！'));
        }
        $eav_entity_id = $eav_entity_id ?: $this->current_getEntity()->getId();
        if ($eav_entity_id) {
            $attribute = clone $this;
            $valueModel = $this->w_getValueModel();
            $valueModel->setAttribute($this);
            $attribute->clearQuery()
                ->fields('main_table.code,main_table.eav_entity_id,main_table.name,main_table.type_id,v.value')
                ->where($attribute::fields_eav_entity_id, $attribute->getEavEntityId())
                ->where($attribute::fields_code, $attribute->getCode())
                ->where("v.value !='' or v.value is not null");
            $attribute->joinModel(
                $valueModel,
                'v',
                "main_table.attribute_id=v.attribute_id and v.eav_entity_id='{$eav_entity_id}'",
                'left',
                'v.value'
            );
            if ($attribute->getMultipleValued()) {
                $values = $attribute->select()->fetchArray();
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
                $value = $attribute->find()->fetchArray()[0] ?? [];
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
     * @param string|int $eav_entity_id eav_entity_id：1 或者 'eav_entity_id_code'
     *
     * @param Value|array|string|int $value eav_entity_id值：Array:[1,2,3...] 或者 1 或者 ‘1’
     * @param string $swatch_image
     * @param string $swatch_color
     * @param string $swatch_text
     * @return EavAttribute
     * @throws Exception
     * @throws \ReflectionException
     * @throws ModelException
     * @throws Core
     */
    public function setValue(string|int $entity_id, \Weline\Eav\Model\EavAttribute\Type\Value|array|string|int $value, string $swatch_image = '', string $swatch_color = '', string $swatch_text = ''): static
    {
        if ($value instanceof Value) {
            $value->save(true);
            return $this;
        }
        if (is_string($value) || is_int($value)) {
            $valueModel = $this->w_getValueModel();
            $valueModel->reset()->where(['entity_id' => $entity_id, 'attribute_id' => $this->getId()])
                ->delete()->fetch();
            $data = ['entity_id' => $entity_id, 'attribute_id' => $this->getId(), 'value' => $value];
            $bindFieldsData = [];
            if ($swatch_image) {
                $bindFieldsData['swatch_image'] = $swatch_image;
            }
            if ($swatch_color) {
                $bindFieldsData['swatch_color'] = $swatch_color;
            }
            if ($swatch_text) {
                $bindFieldsData['swatch_text'] = $swatch_text;
            }
            if ($bindFieldsData) {
                $bindFieldsData['is_swatch'] = 1;
                $data = array_merge($data, $bindFieldsData);
            }
            try {
                $valueModel->reset()
                    ->insert($data, ['entity_id', 'attribute_id', 'value'])
                    ->fetch();
            } catch (\Throwable $e) {
                throw new Exception(__('属性值保存失败！信息：%1', $e->getMessage()));
            }
        } elseif (is_array($value)) {
            if (!$this->getMultipleValued() && (count($value) > 1)) {
                throw new Exception(__('单值属性只能接收一个值！当前值：%1', w_var_export($value, true)));
            }
            $valueModel = $this->w_getValueModel();
            $valueModel->where(['entity_id' => $entity_id, 'attribute_id' => $this->getId()])->delete()->fetch();
            $data = [];
            $bindFieldsData = [];
            foreach ($value as $item) {
                $data_tmp = ['entity_id' => $entity_id, 'value' => $item, 'attribute_id' => $this->getId()];
                if (isset($item['swatch_image'])) {
                    $bindFieldsData['swatch_image'] = $swatch_image;
                }
                if (isset($item['swatch_color'])) {
                    $bindFieldsData['swatch_color'] = $swatch_color;
                }
                if (isset($item['swatch_text'])) {
                    $bindFieldsData['swatch_text'] = $swatch_text;
                }
                if ($bindFieldsData) {
                    $data_tmp['is_swatch'] = 1;
                    $data_tmp = array_merge($data_tmp, $bindFieldsData);
                }
                $data[] = $data_tmp;
            }
            if ($bindFieldsData) {
                $valueModel->bindModelFields(array_keys($bindFieldsData));
            }
            try {
                $valueModel->reset()
                    ->insert($data, ['entity_id', 'attribute_id', 'value'])
                    ->fetch();
            } catch (\Throwable $e) {
                throw new Exception(__('属性值保存失败！信息：%1', $e->getMessage()));
            }
        }
        return $this;
    }

    public function addValue(string|int $entity_id, array|string|int $value, string $swatch_image = '', string $swatch_color = '', string $swatch_text = ''): bool
    {
        if (!$this->getMultipleValued()) {
            if (is_string($value) || is_int($value)) {
                $valueModel = $this->w_getValueModel();
                if (!empty($item['swatch_image'])) {
                    $valueModel->setSwatchImage($item['swatch_image']);
                }
                if (!empty($item['swatch_color'])) {
                    $valueModel->setSwatchImage($item['swatch_color']);
                }
                if (!empty($item['swatch_text'])) {
                    $valueModel->setSwatchImage($item['swatch_text']);
                }
                $valueModel->setEntityId($entity_id)
                    ->setValue($value)->save();
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
            $valueModel = $this->w_getValueModel();
            if (!empty($item['swatch_image'])) {
                $valueModel->setSwatchImage($item['swatch_image']);
            }
            if (!empty($item['swatch_color'])) {
                $valueModel->setSwatchImage($item['swatch_color']);
            }
            if (!empty($item['swatch_text'])) {
                $valueModel->setSwatchImage($item['swatch_text']);
            }
            $valueModel
                ->setEntityId($entity_id)
                ->setValue($item)->save();
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
        return clone $optionModel->reset()->clearData()->where($optionModel::fields_attribute_id, $this->getId())
            ->where($optionModel::fields_eav_entity_id, $this->getEavEntityId());
    }

    public function getGroupId(): int
    {
        return (int)$this->getData(self::fields_group_id);
    }

    public function setGroupId(int $groupId): static
    {
        $this->setData(self::fields_group_id, $groupId);
        return $this;
    }

    public function getSetId(): int
    {
        return (int)$this->getData(self::fields_set_id);
    }

    public function setSetId(int $setId): static
    {
        $this->setData(self::fields_set_id, $setId);
        return $this;
    }

    public function setEavEntityId(int $eav_entity_id): static
    {
        $this->setData(self::fields_eav_entity_id, $eav_entity_id);
        return $this;
    }

    public function setAttributeId(int $attributeId): static
    {
        $this->setData(self::fields_attribute_id, $attributeId);
        return $this;
    }

    public function existType(string $type_code = ''): bool
    {
        if ($type_code) {
            if (isset($this->exist_types[$type_code])) {
                return true;
            }
            /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
            $typeModel = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Type::class);
            $typeModel->reset()->clearData();
            $typeModel->load('code', $type_code);
            if ($typeModel->getId()) {
                $this->exist_types[$type_code] = $typeModel;
                return true;
            } else {
                throw new \Exception(__('属性类型不存在！类型：%1', $type_code));
            }
        } else {
            /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
            $typeModel = $this->getTypeModel();
            if ($typeModel->getId()) {
                $this->exist_types[$typeModel->getCode()] = $typeModel;
                return true;
            } else {
                throw new \Exception(__('属性类型不存在！类型：%1', $type_code));
            }
        }
    }


    public function getTypeModel(): Type
    {
        if ($this->type) {
            return $this->type;
        }
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $this->type = clone $typeModel->reset()->clearData()->load($this->getTypeId());
        return $this->type;
    }

    public function resetTypeModel(): Type
    {
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $this->type = clone $typeModel->reset()->clearData()->load($this->getTypeId());
        return $this->type;
    }

    public function getType(string $type_code = ''): Type
    {
        if ($type_code) {
            if (empty($this->exist_types[$type_code])) {
                $this->exist_types[$type_code] = $this->getTypeModel();
                return $this->exist_types[$type_code];
            }
            $this->existType($type_code);
            return $this->exist_types[$type_code];
        } else {
            /**@var Type $type */
            $type = ObjectManager::getInstance(Type::class);
            $type->load($this->getTypeId());
            return $type;
        }
    }

    /**
     * @param array $options ['options' => ['1' => '选项1', '2' => '选项2'], 'attrs' => ['class' => 'form-control'], 'label_class' => 'label-class','only_custom_options'=>false]
     * @return string
     * @throws \Exception
     */
    public function getHtml(array $options = [], string $save_option_field = 'option_id', string $option_show_field = 'value')
    {
        $type = $this->getTypeModel();
        if (!isset($options['options'])) {
            foreach ($this->getOptions() as $option) {
                $options['options'][$option[$save_option_field]] = $option[$option_show_field];
            }
        }
        try {
            if (!isset($options['values']) and isset($options['entity'])) {
                $options['values'] = $this->getValue();
            }
        } catch (\Exception $e) {
            $options['values'] = [];
        }
        if($this->getId()){
            $options['entity'] = $this;
        }
        return $type->getHtml($this, $options);
    }

    // 不安全，容易删除属性所有数据
    //    function removeValue(string|int $eav_entity_id=null){
    //
    //    }

    /**
     * @DESC          # 系统：读取值模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/15 21:13
     * 参数区：
     * @return Value
     * @throws Exception
     * @throws \ReflectionException
     */
    public function w_getValueModel(): \Weline\Eav\Model\EavAttribute\Type\Value
    {
        if (!$this->value) {
            /**@var \Weline\Eav\Model\EavAttribute\Type\Value $valueModel */
            $valueModel = ObjectManager::make(\Weline\Eav\Model\EavAttribute\Type\Value::class);
            $valueModel->setAttribute($this);
            $this->value = clone $valueModel;
        }
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


    public function getEavEntityAttributeValueTable(): string
    {
        if (!$this->getId()) {
            return '';
        }
        # 查询属性所属eav实体
        $eav_entity = $this->getEavEntity();
        if (!$eav_entity->getId()) {
            return '';
        }
        # 查询属性所属eav类型
        $type = $this->getType();
        if (!$eav_entity->getId()) {
            return '';
        }
        return $this->getTable('eav_' . $eav_entity->getCode() . '_' . $type->getCode());
    }
}
