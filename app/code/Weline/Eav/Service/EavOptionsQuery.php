<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Options\EavOptionsQueryInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavEntity;

final class EavOptionsQuery implements EavOptionsQueryInterface
{
    public function __construct(
        private readonly EavEntity $eavEntity,
        private readonly EavAttribute $eavAttribute,
        private readonly Option $attributeOption,
    ) {
    }

    public function queryOptions(array $params = []): array
    {
        try {
            $entityCode = $params['entity_code'] ?? null;
            $attributeCode = $params['attribute_code'] ?? null;
            $attributeId = $params['attribute_id'] ?? null;
            $search = $params['search'] ?? null;
            $page = (int)($params['page'] ?? 1);
            $limit = (int)($params['limit'] ?? 100);

            if ($attributeId) {
                $this->eavAttribute->load($attributeId);
            } elseif ($entityCode && $attributeCode) {
                $this->eavEntity->reset()
                    ->where(EavEntity::schema_fields_code, $entityCode)
                    ->find()
                    ->fetch();

                if (!$this->eavEntity->getId()) {
                    return ['success' => false, 'message' => __('实体不存在: %s', $entityCode)];
                }

                $this->eavAttribute->reset()
                    ->where(EavAttribute::schema_fields_eav_entity_id, $this->eavEntity->getId())
                    ->where(EavAttribute::schema_fields_code, $attributeCode)
                    ->find()
                    ->fetch();
            } else {
                return [
                    'success' => false,
                    'message' => __('缺少必要参数：attribute_id 或 (entity_code + attribute_code)'),
                ];
            }

            if (!$this->eavAttribute->getId()) {
                return ['success' => false, 'message' => __('属性不存在')];
            }

            $query = $this->attributeOption->reset()
                ->where(Option::schema_fields_attribute_id, $this->eavAttribute->getAttributeId());
            if ($search) {
                $query->where(Option::schema_fields_value, ['like', '%' . $search . '%']);
            }

            $options = $query
                ->limit($limit, ($page - 1) * $limit)
                ->select()
                ->fetch();

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

            return [
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
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function queryAttributes(array $params = []): array
    {
        try {
            $entityCode = $params['entity_code'] ?? null;
            $setId = $params['set_id'] ?? null;
            $hasOptionOnly = (bool)($params['has_option_only'] ?? false);
            if (!$entityCode) {
                return ['success' => false, 'message' => __('缺少必要参数：entity_code')];
            }

            $this->eavEntity->reset()
                ->where(EavEntity::schema_fields_code, $entityCode)
                ->find()
                ->fetch();
            if (!$this->eavEntity->getId()) {
                return ['success' => false, 'message' => __('实体不存在: %s', $entityCode)];
            }

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

            return [
                'success' => true,
                'data' => [
                    'entity' => [
                        'id' => $this->eavEntity->getId(),
                        'code' => $this->eavEntity->getCode(),
                        'name' => $this->eavEntity->getName(),
                    ],
                    'attributes' => $formattedAttributes,
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function queryEntities(): array
    {
        try {
            $entities = $this->eavEntity->reset()->select()->fetch();
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

            return ['success' => true, 'data' => ['entities' => $formattedEntities]];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
