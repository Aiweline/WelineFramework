<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Product\Model\Product;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;

/**
 * 产品 EAV 属性服务
 * 
 * 提供统一的接口获取产品属性数据：
 * - 按属性集/属性组组织的属性列表
 * - 属性值（含选项/色板）
 * - 用于前端展示的格式化数据
 */
class ProductEavService
{
    private EavEntity $eavEntity;
    private EavAttribute $eavAttribute;
    private Set $attributeSet;
    private Group $attributeGroup;
    private Option $attributeOption;

    /**
     * 产品实体缓存
     */
    private ?EavEntity $productEntity = null;

    public function __construct(
        EavEntity $eavEntity,
        EavAttribute $eavAttribute,
        Set $attributeSet,
        Group $attributeGroup,
        Option $attributeOption
    ) {
        $this->eavEntity = $eavEntity;
        $this->eavAttribute = $eavAttribute;
        $this->attributeSet = $attributeSet;
        $this->attributeGroup = $attributeGroup;
        $this->attributeOption = $attributeOption;
    }

    /**
     * 获取产品 EAV 实体
     * 
     * @return EavEntity|null
     */
    public function getProductEntity(): ?EavEntity
    {
        if ($this->productEntity === null) {
            $this->productEntity = $this->eavEntity->reset()
                ->where(EavEntity::schema_fields_code, Product::entity_code)
                ->find()
                ->fetch();
        }

        return $this->productEntity->getId() ? $this->productEntity : null;
    }

    /**
     * 获取产品的所有属性（按属性组组织）
     * 
     * @param int $productId 产品ID
     * @param int|null $setId 属性集ID，null 则从产品获取
     * @return array 按组组织的属性数据
     */
    public function getProductAttributes(int $productId, ?int $setId = null): array
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return [];
        }

        // 获取产品的属性集ID
        if ($setId === null) {
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            $setId = (int)($product->getData(Product::schema_fields_set_id) ?? 0);
        }

        if (!$setId) {
            return [];
        }

        // 获取属性集下的所有属性组
        $groups = $this->getAttributeGroups($setId);

        $result = [];
        foreach ($groups as $group) {
            $groupId = (int)($group[Group::schema_fields_ID] ?? $group['group_id'] ?? 0);
            $groupName = $group[Group::schema_fields_name] ?? $group['name'] ?? '';

            // 获取组内的属性
            $attributes = $this->getGroupAttributes($productEntity->getId(), $setId, $groupId);

            if (empty($attributes)) {
                continue;
            }

            // 加载每个属性的值
            $attributesWithValues = [];
            foreach ($attributes as $attribute) {
                $attributeData = $this->loadAttributeValue($attribute, $productId);
                if ($attributeData !== null) {
                    $attributesWithValues[] = $attributeData;
                }
            }

            if (!empty($attributesWithValues)) {
                $result[] = [
                    'group_id' => $groupId,
                    'group_name' => $groupName,
                    'attributes' => $attributesWithValues,
                ];
            }
        }

        return $result;
    }

    /**
     * 获取产品的可筛选属性（用于列表页筛选）
     * 
     * @param int|null $setId 属性集ID，null 则获取所有可筛选属性
     * @return array
     */
    public function getFilterableAttributes(?int $setId = null): array
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return [];
        }

        $query = $this->eavAttribute->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $productEntity->getId())
            ->where(EavAttribute::schema_fields_is_enable, 1)
            ->where(EavAttribute::schema_fields_has_option, 1); // 只有有选项的属性才能筛选

        if ($setId) {
            $query->where(EavAttribute::schema_fields_set_id, $setId);
        }

        $attributes = $query->select()->fetch();

        if (!is_array($attributes)) {
            return [];
        }

        $result = [];
        foreach ($attributes as $attribute) {
            $attributeId = (int)($attribute[EavAttribute::schema_fields_attribute_id] ?? 0);
            $options = $this->getAttributeOptions($attributeId);

            $result[] = [
                'attribute_id' => $attributeId,
                'code' => $attribute[EavAttribute::schema_fields_code] ?? '',
                'name' => $attribute[EavAttribute::schema_fields_name] ?? '',
                'type_id' => (int)($attribute[EavAttribute::schema_fields_type_id] ?? 0),
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * 获取属性的所有选项
     * 
     * @param int $attributeId 属性ID
     * @return array
     */
    public function getAttributeOptions(int $attributeId): array
    {
        $options = $this->attributeOption->reset()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->select()
            ->fetch();

        if (!is_array($options)) {
            return [];
        }

        return array_map(function ($option) {
            return [
                'option_id' => (int)($option[Option::schema_fields_option_id] ?? 0),
                'code' => $option[Option::schema_fields_code] ?? '',
                'value' => $option[Option::schema_fields_value] ?? '',
                'swatch_image' => $option[Option::schema_fields_swatch_image] ?? null,
                'swatch_color' => $option[Option::schema_fields_swatch_color] ?? null,
                'swatch_text' => $option[Option::schema_fields_swatch_text] ?? null,
                'is_swatch' => !empty($option[Option::schema_fields_swatch_image]) 
                    || !empty($option[Option::schema_fields_swatch_color]) 
                    || !empty($option[Option::schema_fields_swatch_text]),
            ];
        }, $options);
    }

    /**
     * 获取属性集列表
     * 
     * @return array
     */
    public function getAttributeSets(): array
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return [];
        }

        $sets = $this->attributeSet->reset()
            ->where(Set::schema_fields_eav_entity_id, $productEntity->getId())
            ->select()
            ->fetch();

        return is_array($sets) ? $sets : [];
    }

    /**
     * 获取属性组列表
     * 
     * @param int $setId 属性集ID
     * @return array
     */
    public function getAttributeGroups(int $setId): array
    {
        $groups = $this->attributeGroup->reset()
            ->where(Group::schema_fields_set_id, $setId)
            ->order('sort_order')
            ->select()
            ->fetch();

        return is_array($groups) ? $groups : [];
    }

    /**
     * 获取属性组内的属性
     * 
     * @param int $entityId EAV 实体ID
     * @param int $setId 属性集ID
     * @param int $groupId 属性组ID
     * @return array
     */
    private function getGroupAttributes(int $entityId, int $setId, int $groupId): array
    {
        $attributes = $this->eavAttribute->reset()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_set_id, $setId)
            ->where(EavAttribute::schema_fields_group_id, $groupId)
            ->where(EavAttribute::schema_fields_is_enable, 1)
            ->select()
            ->fetch();

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * 加载属性值
     * 
     * @param array $attributeData 属性数据
     * @param int $productId 产品ID
     * @return array|null
     */
    private function loadAttributeValue(array $attributeData, int $productId): ?array
    {
        $attributeId = (int)($attributeData[EavAttribute::schema_fields_attribute_id] ?? 0);
        $hasOption = (bool)($attributeData[EavAttribute::schema_fields_has_option] ?? false);
        $multipleValued = (bool)($attributeData[EavAttribute::schema_fields_multiple_valued] ?? false);
        $typeId = (int)($attributeData[EavAttribute::schema_fields_type_id] ?? 0);

        // 创建属性实例获取值
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->load($attributeId);

        if (!$attribute->getId()) {
            return null;
        }

        try {
            // 设置产品实体用于获取值
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            $attribute->current_setEntity($product);

            $value = $attribute->getValue($productId);

            // 如果有选项，获取选项显示值
            $displayValue = $value;
            $options = [];

            if ($hasOption && $value !== null && $value !== '') {
                $options = $attribute->getOptionsWithValue(true);
                
                // 构建显示值
                if (is_array($options)) {
                    $displayValues = [];
                    foreach ($options as $option) {
                        if (isset($option['selected']) && $option['selected']) {
                            $displayValues[] = $option[Option::schema_fields_value] ?? $option['value'] ?? '';
                        }
                    }
                    $displayValue = implode(', ', $displayValues);
                }
            }

            return [
                'attribute_id' => $attributeId,
                'code' => $attributeData[EavAttribute::schema_fields_code] ?? '',
                'name' => $attributeData[EavAttribute::schema_fields_name] ?? '',
                'type_id' => $typeId,
                'has_option' => $hasOption,
                'multiple_valued' => $multipleValued,
                'value' => $value,
                'display_value' => $displayValue,
                'options' => $hasOption ? $attribute->getOptions() : [],
                'selected_options' => $options,
            ];
        } catch (\Exception $e) {
            // 获取值失败，返回基本信息
            return [
                'attribute_id' => $attributeId,
                'code' => $attributeData[EavAttribute::schema_fields_code] ?? '',
                'name' => $attributeData[EavAttribute::schema_fields_name] ?? '',
                'type_id' => $typeId,
                'has_option' => $hasOption,
                'multiple_valued' => $multipleValued,
                'value' => null,
                'display_value' => '',
                'options' => [],
                'selected_options' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取产品属性的视图模型（用于前端模板）
     * 
     * 返回格式化的数据，可直接用于模板渲染
     * 
     * @param int $productId 产品ID
     * @param int|null $setId 属性集ID
     * @param bool $includeEmptyValues 是否包含空值属性
     * @return array
     */
    public function getProductAttributesViewModel(int $productId, ?int $setId = null, bool $includeEmptyValues = false): array
    {
        $attributes = $this->getProductAttributes($productId, $setId);

        $viewModel = [];
        foreach ($attributes as $group) {
            $groupAttributes = [];

            foreach ($group['attributes'] as $attribute) {
                // 跳过空值（如果不包含）
                if (!$includeEmptyValues && ($attribute['value'] === null || $attribute['value'] === '')) {
                    continue;
                }

                $groupAttributes[] = [
                    'label' => $attribute['name'],
                    'value' => $attribute['display_value'] ?: $attribute['value'],
                    'code' => $attribute['code'],
                    'is_swatch' => $this->hasSwatchOptions($attribute['selected_options']),
                    'swatch_data' => $this->getSwatchData($attribute['selected_options']),
                ];
            }

            if (!empty($groupAttributes)) {
                $viewModel[] = [
                    'group_name' => $group['group_name'],
                    'group_id' => $group['group_id'],
                    'items' => $groupAttributes,
                ];
            }
        }

        return $viewModel;
    }

    /**
     * 检查选项是否包含色板数据
     */
    private function hasSwatchOptions(array $options): bool
    {
        foreach ($options as $option) {
            if (!empty($option['swatch_image']) || !empty($option['swatch_color']) || !empty($option['swatch_text'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取色板数据
     */
    private function getSwatchData(array $options): array
    {
        $swatchData = [];
        foreach ($options as $option) {
            if (isset($option['selected']) && $option['selected']) {
                $swatchData[] = [
                    'type' => !empty($option['swatch_image']) ? 'image' : (!empty($option['swatch_color']) ? 'color' : 'text'),
                    'value' => $option['swatch_image'] ?? $option['swatch_color'] ?? $option['swatch_text'] ?? '',
                    'label' => $option[Option::schema_fields_value] ?? $option['value'] ?? '',
                ];
            }
        }
        return $swatchData;
    }

    /**
     * 获取单个属性的值
     * 
     * @param int $productId 产品ID
     * @param string $attributeCode 属性代码
     * @return mixed
     */
    public function getProductAttributeValue(int $productId, string $attributeCode)
    {
        $productEntity = $this->getProductEntity();
        if (!$productEntity) {
            return null;
        }

        try {
            $attribute = $this->eavAttribute->reset()
                ->where(EavAttribute::schema_fields_eav_entity_id, $productEntity->getId())
                ->where(EavAttribute::schema_fields_code, $attributeCode)
                ->where(EavAttribute::schema_fields_is_enable, 1)
                ->find()
                ->fetch();

            if (!$attribute->getId()) {
                return null;
            }

            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            $attribute->current_setEntity($product);

            return $attribute->getValue($productId);
        } catch (\Exception $e) {
            return null;
        }
    }
}
