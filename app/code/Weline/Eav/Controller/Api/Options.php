<?php

declare(strict_types=1);

namespace Weline\Eav\Controller\Api;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * EAV 选项 API 控制器
 * 
 * 提供 EAV 属性选项的 API 接口，用于可视化编辑器的属性选择器
 */
class Options extends BackendController
{
    private EavEntity $eavEntity;
    private EavAttribute $eavAttribute;
    private Option $attributeOption;

    public function __construct(
        EavEntity $eavEntity,
        EavAttribute $eavAttribute,
        Option $attributeOption
    ) {
        parent::__construct();
        $this->eavEntity = $eavEntity;
        $this->eavAttribute = $eavAttribute;
        $this->attributeOption = $attributeOption;
    }

    /**
     * 获取指定属性的选项列表
     * 
     * GET /weline/eav/api/options?entity_code=product&attribute_code=color
     * GET /weline/eav/api/options?attribute_id=123
     * 
     * @return string JSON
     */
    public function getIndex(): string
    {
        try {
            $entityCode = $this->request->getParam('entity_code');
            $attributeCode = $this->request->getParam('attribute_code');
            $attributeId = $this->request->getParam('attribute_id');
            $search = $this->request->getParam('search');
            $page = (int)($this->request->getParam('page') ?? 1);
            $limit = (int)($this->request->getParam('limit') ?? 100);

            // 获取属性
            if ($attributeId) {
                $this->eavAttribute->load($attributeId);
            } elseif ($entityCode && $attributeCode) {
                // 获取实体ID
                $this->eavEntity->reset()
                    ->where(EavEntity::schema_fields_code, $entityCode)
                    ->find()
                    ->fetch();

                if (!$this->eavEntity->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('实体不存在: %s', $entityCode),
                    ]);
                }

                $this->eavAttribute->reset()
                    ->where(EavAttribute::schema_fields_eav_entity_id, $this->eavEntity->getId())
                    ->where(EavAttribute::schema_fields_code, $attributeCode)
                    ->find()
                    ->fetch();
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('缺少必要参数：attribute_id 或 (entity_code + attribute_code)'),
                ]);
            }

            if (!$this->eavAttribute->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('属性不存在'),
                ]);
            }

            // 查询选项
            $query = $this->attributeOption->reset()
                ->where(Option::schema_fields_attribute_id, $this->eavAttribute->getAttributeId());

            if ($search) {
                $query->where(Option::schema_fields_value, ['like', '%' . $search . '%']);
            }

            // 分页
            $offset = ($page - 1) * $limit;
            $options = $query
                ->limit($limit, $offset)
                ->select()
                ->fetch();

            // 格式化结果
            $formattedOptions = [];
            if (is_array($options)) {
                foreach ($options as $option) {
                    $formattedOptions[] = [
                        'id' => (int)($option[Option::schema_fields_option_id] ?? 0),
                        'code' => $option[Option::schema_fields_code] ?? '',
                        'value' => $option[Option::schema_fields_value] ?? '',
                        'swatch_image' => $option[Option::schema_fields_swatch_image] ?? null,
                        'swatch_color' => $option[Option::schema_fields_swatch_color] ?? null,
                        'swatch_text' => $option[Option::schema_fields_swatch_text] ?? null,
                    ];
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'attribute' => [
                        'id' => $this->eavAttribute->getAttributeId(),
                        'code' => $this->eavAttribute->getCode(),
                        'name' => $this->eavAttribute->getName(),
                        'has_option' => $this->eavAttribute->hasOption(),
                    ],
                    'options' => $formattedOptions,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => count($formattedOptions),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取实体的所有属性列表
     * 
     * GET /weline/eav/api/options/attributes?entity_code=product
     * 
     * @return string JSON
     */
    public function getAttributes(): string
    {
        try {
            $entityCode = $this->request->getParam('entity_code');
            $setId = $this->request->getParam('set_id');
            $hasOptionOnly = (bool)$this->request->getParam('has_option_only');

            if (!$entityCode) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('缺少必要参数：entity_code'),
                ]);
            }

            // 获取实体
            $this->eavEntity->reset()
                ->where(EavEntity::schema_fields_code, $entityCode)
                ->find()
                ->fetch();

            if (!$this->eavEntity->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('实体不存在: %s', $entityCode),
                ]);
            }

            // 查询属性
            $query = $this->eavAttribute->reset()
                ->where(EavAttribute::schema_fields_eav_entity_id, $this->eavEntity->getId())
                ->where(EavAttribute::schema_fields_is_enable, 1);

            if ($setId) {
                $query->where(EavAttribute::schema_fields_set_id, $setId);
            }

            if ($hasOptionOnly) {
                $query->where(EavAttribute::schema_fields_has_option, 1);
            }

            $attributes = $query->select()->fetch();

            // 格式化结果
            $formattedAttributes = [];
            if (is_array($attributes)) {
                foreach ($attributes as $attribute) {
                    $formattedAttributes[] = [
                        'id' => (int)($attribute[EavAttribute::schema_fields_attribute_id] ?? 0),
                        'code' => $attribute[EavAttribute::schema_fields_code] ?? '',
                        'name' => $attribute[EavAttribute::schema_fields_name] ?? '',
                        'type_id' => (int)($attribute[EavAttribute::schema_fields_type_id] ?? 0),
                        'set_id' => (int)($attribute[EavAttribute::schema_fields_set_id] ?? 0),
                        'group_id' => (int)($attribute[EavAttribute::schema_fields_group_id] ?? 0),
                        'has_option' => (bool)($attribute[EavAttribute::schema_fields_has_option] ?? false),
                        'multiple_valued' => (bool)($attribute[EavAttribute::schema_fields_multiple_valued] ?? false),
                    ];
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'entity' => [
                        'id' => $this->eavEntity->getId(),
                        'code' => $this->eavEntity->getCode(),
                        'name' => $this->eavEntity->getName(),
                    ],
                    'attributes' => $formattedAttributes,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取所有 EAV 实体列表
     * 
     * GET /weline/eav/api/options/entities
     * 
     * @return string JSON
     */
    public function getEntities(): string
    {
        try {
            $entities = $this->eavEntity->reset()
                ->select()
                ->fetch();

            $formattedEntities = [];
            if (is_array($entities)) {
                foreach ($entities as $entity) {
                    $formattedEntities[] = [
                        'id' => (int)($entity[EavEntity::schema_fields_ID] ?? 0),
                        'code' => $entity[EavEntity::schema_fields_code] ?? '',
                        'name' => $entity[EavEntity::schema_fields_name] ?? '',
                    ];
                }
            }

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'entities' => $formattedEntities,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
