<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 22:41:28
 */

namespace Weline\Eav;

use Weline\Eav\Cache\EavCache;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

abstract class EavModel extends Model implements EavInterface
{
    public string $entity_code = '';
    public string $entity_name = '';
    public string $eav_entity_id_field_type = '';
    public int $eav_entity_id_field_length = 0;

    /**
     * @var \Weline\Eav\Model\EavEntity
     */
    private EavEntity $entity;
    /**
     * @var CacheInterface
     */
    private CacheInterface $eavCache;
    /**
     * @var EavAttribute
     */
    private EavAttribute $attribute;

    private array $attributes = [];
    private array $exist_entities = [];
    private array $exist_types = [];
    private ?Type $type = null;

    function __construct(
        EavEntity    $entity,
        EavCache     $eavCache,
        EavAttribute $attribute,
                     $data = [],
    )
    {
        $this->entity = $entity;
        $this->eavCache = $eavCache->create();
        $this->attribute = $attribute;
        parent::__construct($data);
    }

    function __init()
    {
        parent::__init();
        if (empty($this->entity_code) && empty($this::entity_code)) {
            throw new Exception(__('Eav模型未设置实体代码entity_code常量或者未设置entity_code属性。Eav类：%1', $this::class));
        }
        if (empty($this->entity_name) && empty($this::entity_name)) {
            throw new Exception(__('Eav模型未设置实体名entity_name常量或者未设置entity_name属性。Eav类：%1', $this::class));
        }
        if (empty($this->eav_entity_id_field_type) && empty($this::eav_entity_id_field_type)) {
            throw new Exception(__('Eav模型未设置实体代码eav_entity_id_field_type常量或者未设置eav_entity_id_field_type属性。Eav类：%1', $this::class));
        }
        if (empty($this->eav_entity_id_field_length) && empty($this::entity_name)) {
            throw new Exception(__('Eav模型未设置实体名eav_entity_id_field_length常量或者未设置eav_entity_id_field_length属性。Eav类：%1', $this::class));
        }
    }

    public function getEntityName(): string
    {
        return $this->entity_name ?: $this::entity_name;
    }

    public function getEntityCode(): string
    {
        return $this->entity_code ?: $this::entity_code;
    }

    public function getEavEntityId(): int
    {
        return $this->eav_Entity()->getId();
    }

    public function getEntityFieldIdType(): string
    {
        return $this->eav_entity_id_field_type ?: $this::eav_entity_id_field_type;
    }

    public function getEntityFieldIdLength(): int
    {
        return $this->eav_entity_id_field_length ?: $this::eav_entity_id_field_length;
    }


    /**
     * @inheritDoc
     */
    public function getAttribute(string $code, int|string $entity_id = 0): EavAttribute|null
    {
        // 如果已经有属性则直接返回
        /**@var EavAttribute $attribute */
        $attribute = $this->attributes[$code] ?? null;
        if ($attribute) {
            return $attribute;
        }
        # 特殊的实体ID
        if ($entity_id) {
            $entity = clone $this;
            $entity = $entity->load($entity_id);
            if (!$entity->getId()) {
                return null;
            }
            $this->attribute->current_setEntity($entity);
        } else {
            $this->attribute->current_setEntity($this);
        }
        // 查找属性
        $attribute = clone $this->attribute;
        $attribute->reset()->clearData()
            ->where($this->attribute::fields_eav_entity_id, $this->getEavEntityId())
            ->where($this->attribute::fields_code, $code)
            ->find()
            ->fetch();
        if (!$attribute->getId()) {
            return null;
        }
        $attribute->resetTypeModel();
        $this->attributes[$code] = $attribute;
        return $this->attributes[$code];
    }

    /**
     * @inheritDoc
     */
    public function getAttributes(): array
    {
        // 获取缓存属性
        $cache_key = $this->getEntityCode() . '_' . $this->getEavEntityId() . '-attributes';
        $attributes = $this->eavCache->get($cache_key);
        if ($attributes) {
            foreach ($attributes as &$attribute) {
                /**@var EavAttribute $attribute */
                $attribute = ObjectManager::make(EavAttribute::class, ['data' => $attribute]);
                $attribute->current_setEntity($this);
            }
            return $attributes;
        }
        // 数据库读取属性
        $attributes = $this->attribute
            ->where($this->attribute::fields_eav_entity_id, $this->getEavEntityId())
            ->select()
            ->fetch()
            ->getItems();
        $cache_data = [];
        foreach ($attributes as $attribute) {
            /**@var EavAttribute $attribute */
            $attribute->current_setEntity($this);
            $cache_data[] = $attribute->getData();
        }
        // 缓存属性
        $this->eavCache->set($cache_key, $cache_data, 300);
        return $attributes;
    }

    /**
     * @inheritDoc
     * @throws null
     */
    public function addAttribute(string $code, string $name, string $type, bool $multi_value = false, bool $has_option = false, bool $is_system = false,
                                 bool   $is_enable = true, string $group_code = 'default', string $set_code = 'default'): bool
    {
        if ($this->attribute->clear()->where([
            $this->attribute::fields_eav_entity_id => $this->getEntityCode(),
            $this->attribute::fields_code => $code,
            $this->attribute::fields_group_id => $group_code,
            $this->attribute::fields_set_id => $set_code,
        ])
            ->find()->fetch()->getId()) {
//            throw new Exception(__('实体（%1）已经存在属性（%2）', [$this->getEntityCode(), $code]));
            return false;
        }
        $type = $this->existType($type);
        $eavEntity = $this->existEavEntity($this->getEntityCode());
        try {
            $this->attribute->current_setEntity($this)->clear()->setData(
                [
                    $this->attribute::fields_code => $code,
                    $this->attribute::fields_group_id => $group_code,
                    $this->attribute::fields_set_id => $set_code,
                    $this->attribute::fields_name => $name,
                    $this->attribute::fields_type_id => $type->getId(),
                    $this->attribute::fields_eav_entity_id => $eavEntity->getId(),
                    $this->attribute::fields_multiple_valued => intval($multi_value),
                    $this->attribute::fields_has_option => intval($has_option),
                    $this->attribute::fields_is_system => intval($is_system),
                    $this->attribute::fields_is_enable => intval($is_enable),
                ]
            )->forceCheck(true, $this->attribute->_unit_primary_keys)->save();
            return true;
        } catch (\Exception $exception) {
            p($exception->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(EavAttribute $attribute): bool
    {
        if ($attribute->current_getEntity()->getEntityCode() !== $this->getEntityCode()) {
            throw new Exception(__('警告：属性不属于当前Eav实体！当前实体：%1，当前属性：%2，当前属性所属实体：%3',
                    [
                        $this->getEntityCode(),
                        $attribute->getCode() . ':' . $attribute->getName(),
                        $attribute->current_getEntity()->getEntityName() . '(' . $attribute->current_getEntity()->getEntityCode() . ')'
                    ]
                )
            );
        }
        /**
         * 卸载值信息
         */
        $attribute->unsetData($attribute::value_key);
        $attribute->unsetModelData($attribute::value_keys);
        return $attribute->save(true);
    }

    /**
     * @param string $type_code
     * @return Type
     * @throws \Exception
     */
    public function existType(string $type_code): Type
    {
        if (empty($type_code)) {
            throw new \Exception(__('请传入类型编码！'));
        }
        if (isset($this->exist_types[$type_code])) {
            return $this->exist_types[$type_code];
        }
        /**@var Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $typeModel->load('code', $type_code);
        if ($typeModel->getId()) {
            $this->exist_types[$type_code] = $typeModel;
            return $typeModel;
        } else {
            throw new \Exception(__('属性类型不存在！类型：%1', $type_code));
        }
    }


    public function getTypeModel(): Type
    {
        if ($this->type) {
            return $this->type;
        }
        $this->type = ObjectManager::getInstance(Type::class)->load($this->getTypeId());
        return $this->type;
    }

    public function delete_before(): void
    {
        $attributes = $this->getAttributes();
        foreach ($attributes as $attribute) {
            $this->unsetAttributeValues($attribute->getCode());
        }
    }

    /**
     * @param string $code 实体是存在
     *
     * @return EavEntity
     * @throws \Exception
     */
    public function existEavEntity(string $code): EavEntity
    {
        if (isset($this->exist_entities[$code])) {
            return $this->exist_entities[$code];
        }
        /**@var EavEntity $entityModel */
        $entityModel = ObjectManager::getInstance(EavEntity::class);
        $entityModel->load($entityModel::fields_code, $code);
        if ($entityModel->getId()) {
            $this->exist_entities[$code] = $entityModel;
            return $entityModel;
        } else {
            throw new \Exception(__('属性所属实体不存在！实体：%1', $code));
        }
    }

    /**
     * @inheritDoc
     */
    public function unsetAttribute(string $code, bool $remove_value = false): bool
    {
        unset($this->attributes[$code]);
        try {
            if ($remove_value) {
                $this->unsetAttributeValues($code);
            }
            $attribute = clone $this->attribute;
            $attribute->clear()->where($this->attribute::fields_eav_entity_id, $this->getEavEntityId())
                ->where($this->attribute::fields_code, $code)
                ->delete()->fetch();
            return true;
        } catch (\ReflectionException|Exception|Core $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function unsetAttributeValues(string $code): bool
    {
        try {
            $attribute = clone $this->attribute->current_setEntity($this);
            $attribute->load($attribute::fields_code, $code);
            $valueModel = clone $attribute->w_getValueModel();
            $valueModel->where('attribute_id', $attribute->getId())
                ->where('entity_id', $this->getId())
                ->delete()->fetch();
            return true;
        } catch (\ReflectionException|Exception|Core $e) {
            return false;
        }
    }

    public function getAttributeSets(): array
    {
        /**@var Set $attributeSetsModel */
        $attributeSetsModel = ObjectManager::getInstance(Set::class);
        return $attributeSetsModel->getEavEntitySet($this);
    }

    public function getAttributeSet(string $code): Set
    {
        /**@var Set $attributeSetsModel */
        $attributeSetsModel = ObjectManager::getInstance(Set::class);
        return $attributeSetsModel->where('code', $code)->where('eav_entity_id', $this->getEavEntityId())->find()->fetch();
    }

    public function getAttributeGroups(): array
    {
        /**@var Group $attributeSetsModel */
        $attributeGroupsModel = ObjectManager::getInstance(Group::class);
        return $attributeGroupsModel->getEavEntityGroup($this);
    }

    /**
     * @DESC          # Eav: 获取实体
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/15 22:43
     * 参数区：
     * @return \Weline\Eav\Model\EavEntity
     */
    public function eav_Entity(): \Weline\Eav\Model\EavEntity
    {
        if ($entity = $this->eavCache->get($this->getEntityCode())) {
            return $this->entity->addData($entity);
        }
        $entity = $this->entity->load($this->entity::fields_code, $this->getEntityCode());
        $this->eavCache->set($this->getEntityCode(), $entity->getData());
        return $entity;
    }

    /**
     * @DESC          # 获取实体eav属性集模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/25 22:55
     * 参数区：
     * @return  Set
     */
    public function eav_AttributeSetModel(): Set
    {
        /**@var Set $set */
        $set = ObjectManager::getInstance(Set::class);
        $set->where('main_table.' . Set::fields_eav_entity_id, $this->eav_Entity()->getId());
        return $set;
    }

    /**
     * @DESC          # 获取实体eav属性组模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/25 22:55
     * 参数区：
     * @return  Group
     */
    public function eav_AttributeGroupModel(): Group
    {
        /**@var Group $group */
        $group = ObjectManager::getInstance(Group::class);
        $group->where('main_table.' . Group::fields_eav_entity_id, $this->eav_Entity()->getId());
        return $group;
    }

    /**
     * @DESC          # 获取实体eav属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/25 22:55
     * 参数区：
     * @return  EavAttribute
     */
    public function eav_AttributeModel(): EavAttribute
    {
        /**@var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->where('main_table.' . EavAttribute::fields_eav_entity_id, $this->eav_Entity()->getId());
        $attribute->current_setEntity($this);
        return $attribute;
    }

}