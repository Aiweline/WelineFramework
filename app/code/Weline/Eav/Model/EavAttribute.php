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

use Weline\Eav\EavInterface;
use Weline\Eav\EavModel;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV属性模型 (SRP - 单一职责原则)
 * 
 * 表结构定义已迁移到 Schema/EavAttributeSchema.php
 * 本类只负责数据操作和业务逻辑
 */
class EavAttribute extends \Weline\Framework\Database\Model
{
    public const schema_table = 'eav_attribute';
    /** @var list<string> */
    public const schema_primary_keys = ['eav_entity_id', 'code'];

    public const schema_fields_ID = 'attribute_id';
    public const schema_fields_attribute_id = 'attribute_id';
    public const schema_fields_code = 'code';
    public const schema_fields_name = 'name';
    public const schema_fields_type_id = 'type_id';
    public const schema_fields_set_id = 'set_id';
    public const schema_fields_group_id = 'group_id';
    public const schema_fields_eav_entity_id = 'eav_entity_id';
    public const schema_fields_is_system = 'is_system';
    public const schema_fields_model_class = 'model_class';
    public const schema_fields_default_value = 'default_value';
    public const schema_fields_dependence = 'dependence';

    public const schema_fields_basic_is_enable = 'basic_is_enable';
    public const schema_fields_frontend_is_visible = 'frontend_is_visible';
    public const schema_fields_frontend_is_filterable = 'frontend_is_filterable';
    public const schema_fields_frontend_is_searchable = 'frontend_is_searchable';
    public const schema_fields_data_is_multiple = 'data_is_multiple';
    public const schema_fields_data_has_option = 'data_has_option';

    /** @deprecated use schema_fields_data_is_multiple */
    public const schema_fields_multiple_valued = 'data_is_multiple';
    /** @deprecated use schema_fields_data_has_option */
    public const schema_fields_has_option = 'data_has_option';
    /** @deprecated use schema_fields_basic_is_enable */
    public const schema_fields_is_enable = 'basic_is_enable';
    /** @deprecated use schema_fields_frontend_is_filterable */
    public const schema_fields_is_filterable = 'frontend_is_filterable';
    /** @deprecated use schema_fields_frontend_is_searchable */
    public const schema_fields_is_searchable = 'frontend_is_searchable';
    /** @deprecated use schema_fields_frontend_is_visible */
    public const schema_fields_is_visible_on_front = 'frontend_is_visible';

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

    // 表结构已迁移到 Schema/EavAttributeSchema.php，由 Setup/Install.php 统一管理表创建；此处不再定义 setup/upgrade/install，使用父类空实现。

    public function loadByAttributeId(int $attribute_id): AbstractModel
    {
        return parent::load('main_table.attribute_id', $attribute_id);
    }

    /**
     * 属性自增主键 attribute_id（值表、选项表外键引用此 ID）。
     *
     * 与 getId() / getEavEntityId() 区分：
     * - getAttributeId() → 本属性行主键
     * - getEavEntityId() → 所属 EAV 实体（eav_entity 表）外键
     * - getId()         → 框架联合主键首字段 eav_entity_id（勿用于值表 attribute_id）
     */
    public function getAttributeId(): int
    {
        return (int)($this->getData(self::schema_fields_attribute_id) ?: 0);
    }

    /**
     * 所属 EAV 实体 ID（eav_entity 表主键，对应字段 eav_entity_id）。
     */
    public function getEavEntityId(): int
    {
        return (int)$this->getData(self::schema_fields_eav_entity_id);
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
        return $this->getData(self::schema_fields_code) ?: '';
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_code, $code);
    }

    public function getDependence(): string
    {
        return $this->getData(self::schema_fields_dependence) ?: '';
    }

    public function setDependence(string $dependence): static
    {
        return $this->setData(self::schema_fields_dependence, $dependence);
    }

    public function getTypeId(): int
    {
        return (int)$this->getData(self::schema_fields_type_id) ?: 0;
    }

    public function getModelClass(): string
    {
        return $this->getData(self::schema_fields_model_class) ?: '';
    }

    public function setModelClass(string $model_class): static
    {
        return $this->setData(self::schema_fields_model_class, $model_class);
    }

    public function getDefaultValue()
    {
        return $this->getData(self::schema_fields_default_value) ?: '';
    }

    public function setDefaultValue(string $default_value): static
    {
        return $this->setData(self::schema_fields_default_value, $default_value);
    }

    public function setTypeId(int $type_id): static
    {
        return $this->setData(self::schema_fields_type_id, $type_id);
    }


    public function getName(): string
    {
        return $this->getData(self::schema_fields_name) ?: '';
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }

    public function hasOption(bool|null $has_option = null): bool|static
    {
        if (is_bool($has_option)) {
            return $this->setData(self::schema_fields_data_has_option, $has_option);
        }
        return (bool)$this->getData(self::schema_fields_data_has_option);
    }

    public function getOptions(): array
    {
        if ($this->hasData('options')) {
            return $this->getData('options');
        }
        $this->setData('options', ObjectManager::getInstance(Option::class)->reset()
            ->where(self::schema_fields_ID, $this->getAttributeId())
            ->select()
            ->fetchArray());
        return $this->getData('options');
    }

    public function getOptionsWithValue(bool $only_has_value = false): array
    {
        if (!$this->hasOption()) {
            return [];
        }
        $options = $this->getOptions();
        $values = $this->getValue();
        if(is_string($values)){
             foreach ($options as $op_key => $option) {
                if ($option[Option::schema_fields_option_id] == $values) {
                    $option['selected'] = 1;
                    $options[$op_key] = $option;
                } else {
                    if ($only_has_value) {
                        unset($options[$op_key]);
                    } else {
                        $option['selected'] = 0;
                        $options[$op_key] = $option;
                    }
                }
            }
        }elseif(is_array($values)){
            foreach ($values as $value) {
                foreach ($options as $op_key => $option) {
                    if ($option[Option::schema_fields_option_id] == $value) {
                        $option['selected'] = 1;
                        $options[$op_key] = $option;
                    } else {
                        if ($only_has_value) {
                            unset($options[$op_key]);
                        } else {
                            $option['selected'] = 0;
                            $options[$op_key] = $option;
                        }
                    }
                }
            }
        }
        
        if ($this->hasData('value_with_options')) {
            return $this->getData('value_with_options');
        }
        $this->setData('value_with_options', $options);
        return $this->getData('value_with_options');
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
                EavAttribute\Option::schema_fields_option_id => $option->getId(),
                EavAttribute\Option::schema_fields_code => $option->getCode(),
                EavAttribute\Option::schema_fields_value => $option->getValue(),
                EavAttribute\Option::schema_fields_eav_entity_id => $option->getEavEntityId(),
                EavAttribute\Option::schema_fields_attribute_id => $option->getAttributeId(),
                EavAttribute\Option::schema_fields_swatch_image => $option->getSwatchImage(),
                EavAttribute\Option::schema_fields_swatch_color => $option->getSwatchColor(),
                EavAttribute\Option::schema_fields_swatch_text => $option->getSwatchText(),
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

    public function isSystem(bool|null $is_system = null): bool|static
    {
        if (is_bool($is_system)) {
            return $this->setData(self::schema_fields_is_system, $is_system);
        }
        return (bool)$this->getData(self::schema_fields_is_system);
    }

    public function isEnable(bool|null $is_enable = null): bool|static
    {
        if (is_bool($is_enable)) {
            return $this->setData(self::schema_fields_basic_is_enable, $is_enable);
        }
        return (bool)$this->getData(self::schema_fields_basic_is_enable);
    }

    /**
     * 是否可用于筛选
     * 
     * @param bool|null $is_filterable 如果传入布尔值则设置，否则返回当前值
     * @return bool|static
     */
    public function isFilterable(bool|null $is_filterable = null): bool|static
    {
        if (is_bool($is_filterable)) {
            return $this->setData(self::schema_fields_frontend_is_filterable, $is_filterable ? 1 : 0);
        }
        return (bool)$this->getData(self::schema_fields_frontend_is_filterable);
    }

    /**
     * 鏄惁鍙敤浜庢悳绱?
     *
     * @param bool|null $is_searchable 濡傛灉浼犲叆甯冨皵鍊煎垯璁剧疆锛屽惁鍒欒繑鍥炲綋鍓嶅€?
     * @return bool|static
     */
    public function isSearchable(bool|null $is_searchable = null): bool|static
    {
        if (is_bool($is_searchable)) {
            return $this->setData(self::schema_fields_frontend_is_searchable, $is_searchable ? 1 : 0);
        }

        return (bool)$this->getData(self::schema_fields_frontend_is_searchable);
    }

    /**
     * 是否在前端可见
     * 
     * @param bool|null $is_visible_on_front 如果传入布尔值则设置，否则返回当前值
     * @return bool|static
     */
    public function isVisibleOnFront(bool|null $is_visible_on_front = null): bool|static
    {
        if (is_bool($is_visible_on_front)) {
            return $this->setData(self::schema_fields_frontend_is_visible, $is_visible_on_front ? 1 : 0);
        }
        return (bool)$this->getData(self::schema_fields_frontend_is_visible);
    }

    public function getMultipleValued(): bool
    {
        return (bool)$this->getData(self::schema_fields_data_is_multiple);
    }

    public function setMultipleValued(bool $is_multiple_valued = false): static
    {
        return $this->setData(self::schema_fields_data_is_multiple, $is_multiple_valued ? '1' : '0');
    }

    public function getValue(string|int|null $entity_id = null, bool $return_attribute = false)
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
                ->fields(Value::schema_fields_value)
                ->where(Value::schema_fields_attribute_id, $this->getAttributeId())
                ->where(Value::schema_fields_entity_id, $entity_id);

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

    public function getValueWithOptions(string|int|null $entity_id = null, bool $return_attribute = false, string $option_key = 'value'): array|Option
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

    public function getSwatchValue(string|int|null $eav_entity_id = null, bool $object = false)
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
                ->where($attribute::schema_fields_eav_entity_id, $attribute->getEavEntityId())
                ->where($attribute::schema_fields_code, $attribute->getCode())
                ->where('v.value', null, 'IS NOT NULL')
                ->where('v.value', '', '!=');
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
            $valueModel->reset()->where(['entity_id' => $entity_id, 'attribute_id' => $this->getAttributeId()])
                ->delete()->fetch();
            $data = ['entity_id' => $entity_id, 'attribute_id' => $this->getAttributeId(), 'value' => $value];
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
                throw new Exception(__('属性值保存失败！信息：%{1}', $e->getMessage()));
            }
        } elseif (is_array($value)) {
            if (!$this->getMultipleValued() && (count($value) > 1)) {
                throw new Exception(__('单值属性只能接收一个值！当前值：%{1}', w_var_export($value, true)));
            }
            $valueModel = $this->w_getValueModel();
            $valueModel->where(['entity_id' => $entity_id, 'attribute_id' => $this->getAttributeId()])->delete()->fetch();
            $data = [];
            $bindFieldsData = [];
            foreach ($value as $item) {
                $data_tmp = ['entity_id' => $entity_id, 'value' => $item, 'attribute_id' => $this->getAttributeId()];
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
                throw new Exception(__('属性值保存失败！信息：%{1}', $e->getMessage()));
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
                    throw new Exception(__('单值属性不支持数组或者对象类型值：%{1}', w_var_export($value, true)));
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
        return clone $optionModel->reset()->clearData()->where($optionModel::schema_fields_attribute_id, $this->getAttributeId())
            ->where($optionModel::schema_fields_eav_entity_id, $this->getEavEntityId());
    }

    public function getGroupId(): int
    {
        return (int)$this->getData(self::schema_fields_group_id);
    }

    public function setGroupId(int $groupId): static
    {
        $this->setData(self::schema_fields_group_id, $groupId);
        return $this;
    }

    public function getSetId(): int
    {
        return (int)$this->getData(self::schema_fields_set_id);
    }

    public function setSetId(int $setId): static
    {
        $this->setData(self::schema_fields_set_id, $setId);
        return $this;
    }

    public function setEavEntityId(int $eav_entity_id): static
    {
        $this->setData(self::schema_fields_eav_entity_id, $eav_entity_id);
        return $this;
    }

    public function setAttributeId(int $attributeId): static
    {
        $this->setData(self::schema_fields_attribute_id, $attributeId);
        return $this;
    }

    public function existType(string $type_code = ''): Type
    {
        if ($type_code) {
            if (isset($this->exist_types[$type_code])) {
                return $this->exist_types[$type_code];
            }
            /**@var Type $typeModel */
            $typeModel = ObjectManager::getInstance(Type::class);
            $typeModel->reset()->clearData();
            $typeModel->load('code', $type_code);
            if ($typeModel->getId()) {
                $this->exist_types[$type_code] = $typeModel;
                return $typeModel;
            } else {
                throw new \Exception(__('属性类型不存在！类型：%{1}', $type_code));
            }
        } else {
            $typeModel = $this->getTypeModel();
            if ($typeModel->getId()) {
                $this->exist_types[$typeModel->getCode()] = $typeModel;
                return $typeModel;
            } else {
                throw new \Exception(__('属性类型不存在！类型：%{1}', $type_code));
            }
        }
    }


    public function getTypeModel(): Type
    {
        if ($this->type and $this->type->getId()) {
            return $this->type;
        }
        /**@var Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $this->type = clone $typeModel->reset()->clearData()->load($this->getTypeId());
        if (!$this->type->getId()) {
            throw new \Exception(__('属性类型不存在！属性：%{name}, 属性代码：%{code} 属性实体：%{entity} 属性实体代码：%{entity_code}', ['name' => $this->getName(), 'code' => $this->getCode(), 'entity' => $this->getEavEntity()->getName(), 'entity_code' => $this->getEavEntity()->getCode()]));
        }
        return $this->type;
    }

    public function resetTypeModel(): Type
    {
        /**@var Type $typeModel */
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
        if ($this->getAttributeId() > 0) {
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
        if ($this->getAttributeId() <= 0) {
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
