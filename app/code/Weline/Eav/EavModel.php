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
    public string $entity_id_field_type = '';
    public int $entity_id_field_length = 0;

    /**
     * @var \Weline\Eav\Model\EavEntity
     */
    private EavEntity $entity;
    /**
     * @var CacheInterface
     */
    private CacheInterface $eavCache;
    /**
     * @var \Weline\Eav\Model\EavAttribute
     */
    private EavAttribute $attribute;

    private array $attributes = [];
    private array $exist_entities = [];
    private array $exist_types = [];

    function __construct(
        EavEntity    $entity,
        EavCache     $eavCache,
        EavAttribute $attribute,
                     $data = [],
    )
    {
        $this->entity    = $entity;
        $this->eavCache  = $eavCache->create();
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
        if (empty($this->entity_id_field_type) && empty($this::entity_id_field_type)) {
            throw new Exception(__('Eav模型未设置实体代码entity_id_field_type常量或者未设置entity_id_field_type属性。Eav类：%1', $this::class));
        }
        if (empty($this->entity_id_field_length) && empty($this::entity_name)) {
            throw new Exception(__('Eav模型未设置实体名entity_id_field_length常量或者未设置entity_id_field_length属性。Eav类：%1', $this::class));
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

    public function getEntityId(): int
    {
        return $this->eav_Entity()->getId();
    }

    public function getEntityFieldIdType(): string
    {
        return $this->entity_id_field_type ?: $this::entity_id_field_type;
    }

    public function getEntityFieldIdLength(): int
    {
        return $this->entity_id_field_length ?: $this::entity_id_field_length;
    }


    /**
     * @inheritDoc
     */
    public function getAttribute(string $code, int|string $entity_id = null): EavAttribute|null
    {
        // 如果已经有属性则直接返回
        /**@var EavAttribute $attribute */
        $attribute = $this->attributes[$code] ?? null;
        if ($attribute) {
            if ($entity_id && !$attribute->getData($attribute::value_key)) {
                $attribute = $attribute->getValue($entity_id, true);
            }
            return $attribute;
        }
        // 如果当前实体有ID则读取属性时跟随读取属性值
        if (!$entity_id) {
            $entity_id = $this->getId();
        }
        // 查找属性
        dd($this->attribute);
        $this->attribute->clearQuery()->clearData()
                        ->where($this->attribute::fields_entity_id, $this->getEntityCode())
                        ->where($this->attribute::fields_code, $code)
                        ->find()
                        ->fetch();
        if (!$this->attribute->getId()) {
            return null;
        }
        $this->attribute->current_setEntity($this);
        if ($entity_id) {
            $this->attribute = $this->attribute->getValue($entity_id, true);
        }
        $this->attributes[$code] = clone $this->attribute;
        return $this->attributes[$code];
    }

    /**
     * @inheritDoc
     */
    public function getAttributes(): array
    {
        // 获取缓存属性
        $cache_key  = $this->getEntityCode() . '-attributes';
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
            ->where($this->attribute::fields_entity_code, $this->getEntityCode())
            ->select()
            ->fetch()
            ->getItems();
        $cache_data = [];
        foreach ($attributes as $attribute) {
            $cache_data[] = $attribute->getData();
            /**@var EavAttribute $attribute */
            $attribute->current_setEntity($this);
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
                                                 $this->attribute::fields_entity_id => $this->getEntityCode(),
                                                 $this->attribute::fields_code      => $code,
                                                 $this->attribute::fields_group_id  => $group_code,
                                                 $this->attribute::fields_set_id    => $set_code,
                                             ])
                            ->find()->fetch()->getId()) {
//            throw new Exception(__('实体（%1）已经存在属性（%2）', [$this->getEntityCode(), $code]));
            return false;
        }
        $this->existType($type);
        $this->existEntity($this->getEntityCode());
        try {
            $this->attribute->current_setEntity($this)->clear()->setData(
                [
                    $this->attribute::fields_code            => $code,
                    $this->attribute::fields_group_id        => $group_code,
                    $this->attribute::fields_set_id          => $set_code,
                    $this->attribute::fields_name            => $name,
                    $this->attribute::fields_type            => $type,
                    $this->attribute::fields_entity_id       => $this->getEntityId(),
                    $this->attribute::fields_multiple_valued => intval($multi_value),
                    $this->attribute::fields_has_option      => intval($has_option),
                    $this->attribute::fields_is_system       => intval($is_system),
                    $this->attribute::fields_is_enable       => intval($is_enable),
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
                                       $attribute->getEntity()
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
     * @param string $type 属性类型
     *
     * @return bool
     * @throws \Exception
     */
    public function existType(string $type): bool
    {
        if (isset($this->exist_types[$type])) {
            return true;
        }
        /**@var \Weline\Eav\Model\EavAttribute\Type $typeModel */
        $typeModel = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Type::class);
        $typeModel->load($type);
        if ($typeModel->getId()) {
            $this->exist_types[$type] = $type;
            return true;
        } else {
            throw new \Exception(__('属性类型不存在！类型：%1', $type));
        }
    }

    /**
     * @param string $code 实体是存在
     *
     * @return bool
     * @throws \Exception
     */
    public function existEntity(string $code): bool
    {
        if (isset($this->exist_entities[$code])) {
            return true;
        }
        /**@var EavEntity $entityModel */
        $entityModel = ObjectManager::getInstance(EavEntity::class);
        $entityModel->load($entityModel::fields_code, $code);
        if ($entityModel->getId()) {
            $this->exist_entities[$code] = $code;
            return true;
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
                $this->attribute->w_getValueModel()
                                ->where('attribute', $code)
                                ->delete();
            }
            $attribute = clone $this->attribute;
            $attribute->clear()->where($this->attribute::fields_code, $this->getEntityCode())
                      ->where($this->attribute::fields_code, $code)
                      ->delete();
            return true;
        } catch (\ReflectionException|Exception|Core $e) {
            return false;
        }
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
        $entity = $this->entity->load($this->entity::fields_code, $this->getEntityCode());
        $this->eavCache->set($this->getEntityCode(), $entity);
        return $entity;
    }

    /**
     * @DESC          # 获取实体eav属性集模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/25 22:55
     * 参数区：
     * @return  \Weline\Eav\Model\EavAttribute\Set
     */
    public function eav_AttributeSetModel(): EavAttribute\Set
    {
        /**@var Model\EavAttribute\Set $set */
        $set = ObjectManager::getInstance(EavAttribute\Set::class);
        $set->where(EavAttribute\Set::fields_entity_id, $this->eav_Entity()->getId());
        return $set;
    }

    /**
     * @DESC          # 获取实体eav属性组模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/25 22:55
     * 参数区：
     * @return  \Weline\Eav\Model\EavAttribute\Group
     */
    public function eav_AttributeGroupModel(): EavAttribute\Group
    {
        /**@var Model\EavAttribute\Group $group */
        $group = ObjectManager::getInstance(EavAttribute\Group::class);
        $group->where(EavAttribute\Group::fields_entity_id, $this->eav_Entity()->getId());
        return $group;
    }
    /**
     * @DESC          # 获取实体eav属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/25 22:55
     * 参数区：
     * @return  \Weline\Eav\Model\EavAttribute
     */
    public function eav_AttributeModel(): \Weline\Eav\Model\EavAttribute
    {
        /**@var \Weline\Eav\Model\EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute::class);
        $attribute->where(\Weline\Eav\Model\EavAttribute::fields_entity_id, $this->eav_Entity()->getId());
        return $attribute;
    }
}