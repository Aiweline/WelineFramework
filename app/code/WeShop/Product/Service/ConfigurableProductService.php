<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocalDescription;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocalDescription;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\Product\OptionId;

/**
 * 可配置产品服务
 * 
 * 提供获取产品规格选项的方法，支持本地化属性标签
 */
class ConfigurableProductService
{
    private Product $product;
    private OptionId $productOptionId;
    private EavAttribute $eavAttribute;
    private AttributeLocalDescription $attributeLocalDescription;
    private Option $eavAttributeOption;
    private OptionLocalDescription $optionLocalDescription;

    public function __construct(
        Product $product,
        OptionId $productOptionId,
        EavAttribute $eavAttribute,
        AttributeLocalDescription $attributeLocalDescription,
        Option $eavAttributeOption,
        OptionLocalDescription $optionLocalDescription
    ) {
        $this->product = $product;
        $this->productOptionId = $productOptionId;
        $this->eavAttribute = $eavAttribute;
        $this->attributeLocalDescription = $attributeLocalDescription;
        $this->eavAttributeOption = $eavAttributeOption;
        $this->optionLocalDescription = $optionLocalDescription;
    }

    /**
     * 检查产品是否为可配置产品（有子产品）
     * 
     * @param int $productId 产品ID
     * @return bool
     */
    public function isConfigurable(int $productId): bool
    {
        $product = clone $this->product;
        $product->reset()->clearData();
        
        // 检查是否有子产品（parent_id = $productId）
        $children = $product->where(Product::schema_fields_parent_id, $productId)
            ->where(Product::schema_fields_status, 1)
            ->select()
            ->fetchArray();
        
        return !empty($children);
    }

    /**
     * @param int[] $productIds
     * @return array<int, bool>
     */
    public function getConfigurableMap(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $map = array_fill_keys($productIds, false);
        $product = clone $this->product;
        $children = $product->reset()->clearData()
            ->where(Product::schema_fields_parent_id, $productIds, 'in')
            ->where(Product::schema_fields_status, 1)
            ->select()
            ->fetchArray();

        foreach ($children as $child) {
            $parentId = (int)($child[Product::schema_fields_parent_id] ?? 0);
            if ($parentId > 0) {
                $map[$parentId] = true;
            }
        }

        return $map;
    }

    /**
     * 获取可配置产品的所有规格选项
     * 
     * @param int $productId 产品ID（父产品ID）
     * @param string|null $localeCode 语言代码，默认使用当前语言
     * @return array 规格选项数组，格式：
     * [
     *     'attributes' => [
     *         [
     *             'attribute_id' => 1,
     *             'code' => 'color',
     *             'name' => '颜色',           // 本地化后的名称
     *             'origin_name' => 'Color',   // 原始名称
     *             'options' => [
     *                 [
     *                     'option_id' => 1,
     *                     'code' => 'red',
     *                     'value' => '红色',        // 本地化后的值
     *                     'origin_value' => 'Red', // 原始值
     *                     'swatch_type' => 'color', // color|image|text|null
     *                     'swatch_value' => '#ff0000',
     *                     'available_product_ids' => [2, 3, 5]
     *                 ],
     *                 // ...
     *             ]
     *         ],
     *         // ...
     *     ],
     *     'variants' => [
     *         [
     *             'product_id' => 2,
     *             'sku' => 'SKU-RED-S',
     *             'name' => '产品名-红色-S',
     *             'price' => 99.00,
     *             'stock' => 10,
     *             'image' => '/path/to/image.jpg',
     *             'option_ids' => [1, 5]  // 对应的选项ID组合
     *         ],
     *         // ...
     *     ]
     * ]
     */
    public function getConfigurableOptions(int $productId, ?string $localeCode = null): array
    {
        $localeCode = $localeCode ?? Cookie::getLangLocal();
        
        // 获取所有子产品
        $product = clone $this->product;
        $children = $product->reset()->clearData()
            ->where(Product::schema_fields_parent_id, $productId)
            ->where(Product::schema_fields_status, 1)
            ->select()
            ->fetchArray();
        
        if (empty($children)) {
            return ['attributes' => [], 'variants' => []];
        }

        // 获取子产品的所有选项映射
        $childProductIds = array_column($children, Product::schema_fields_ID);
        
        $optionId = clone $this->productOptionId;
        $productOptions = $optionId->reset()->clearData()
            ->where(OptionId::schema_fields_PRODUCT_ID, $childProductIds, 'in')
            ->select()
            ->fetchArray();
        
        // 收集所有属性ID和选项ID
        $attributeIds = array_unique(array_column($productOptions, OptionId::schema_fields_ATTRIBUTE_ID));
        $optionIds = array_unique(array_column($productOptions, OptionId::schema_fields_OPTION_ID));
        
        if (empty($attributeIds) || empty($optionIds)) {
            return ['attributes' => [], 'variants' => $this->formatVariants($children, [])];
        }

        // 获取属性信息（带本地化）
        $attributes = $this->getAttributesWithLocale($attributeIds, $localeCode);
        
        // 获取选项信息（带本地化）
        $options = $this->getOptionsWithLocale($optionIds, $localeCode);

        // 构建产品-选项映射
        $productOptionMap = [];
        foreach ($productOptions as $po) {
            $prodId = (int)$po[OptionId::schema_fields_PRODUCT_ID];
            if (!isset($productOptionMap[$prodId])) {
                $productOptionMap[$prodId] = [];
            }
            $productOptionMap[$prodId][] = [
                'attribute_id' => (int)$po[OptionId::schema_fields_ATTRIBUTE_ID],
                'option_id' => (int)$po[OptionId::schema_fields_OPTION_ID],
            ];
        }

        // 构建选项-产品映射（哪些产品可用于某个选项）
        $optionProductMap = [];
        $childImageMap = [];
        foreach ($children as $child) {
            $childImageMap[(int)$child[Product::schema_fields_ID]] = (string)($child[Product::schema_fields_image] ?? '');
        }
        foreach ($productOptions as $po) {
            $optId = (int)$po[OptionId::schema_fields_OPTION_ID];
            if (!isset($optionProductMap[$optId])) {
                $optionProductMap[$optId] = [];
            }
            $optionProductMap[$optId][] = (int)$po[OptionId::schema_fields_PRODUCT_ID];
        }

        // 组装结果
        $result = [
            'attributes' => [],
            'variants' => $this->formatVariants($children, $productOptionMap),
        ];

        foreach ($attributes as $attrId => $attr) {
            $attrOptions = [];
            foreach ($options as $optId => $opt) {
                if ((int)$opt['attribute_id'] === (int)$attrId) {
                    $optionImage = $this->resolveOptionImage($optionProductMap[$optId] ?? [], $childImageMap);
                    $attrOptions[] = [
                        'option_id' => (int)$optId,
                        'code' => $opt['code'] ?? '',
                        'value' => $opt['value'],
                        'origin_value' => $opt['origin_value'],
                        'swatch_type' => $opt['swatch_type'],
                        'swatch_value' => $opt['swatch_value'],
                        'option_image' => $optionImage,
                        'available_product_ids' => $optionProductMap[$optId] ?? [],
                    ];
                }
            }

            if (!empty($attrOptions)) {
                $result['attributes'][] = [
                    'attribute_id' => (int)$attrId,
                    'code' => $attr['code'] ?? '',
                    'name' => $attr['name'],
                    'origin_name' => $attr['origin_name'],
                    'options' => $attrOptions,
                ];
            }
        }

        return $result;
    }

    /**
     * @param int[] $productIds
     * @param array<int, string> $childImageMap
     */
    private function resolveOptionImage(array $productIds, array $childImageMap): string
    {
        foreach ($productIds as $productId) {
            $image = trim((string)($childImageMap[(int)$productId] ?? ''));
            if ($image !== '') {
                return $image;
            }
        }

        return '';
    }

    /**
     * 获取属性信息（带本地化）
     * 
     * @param array $attributeIds
     * @param string $localeCode
     * @return array
     */
    private function getAttributesWithLocale(array $attributeIds, string $localeCode): array
    {
        if (empty($attributeIds)) {
            return [];
        }

        $attribute = clone $this->eavAttribute;
        $attributes = $attribute->reset()->clearData()
            ->where(EavAttribute::schema_fields_ID, $attributeIds, 'in')
            ->select()
            ->fetchArray();

        // 获取本地化描述
        $localDesc = clone $this->attributeLocalDescription;
        $localDescs = $localDesc->reset()->clearData()
            ->where(AttributeLocalDescription::fields_ID, $attributeIds, 'in')
            ->where(AttributeLocalDescription::schema_fields_local_code, $localeCode)
            ->select()
            ->fetchArray();

        // 构建本地化映射
        $localMap = [];
        foreach ($localDescs as $ld) {
            $localMap[(int)$ld[AttributeLocalDescription::fields_ID]] = $ld;
        }

        // 组装结果
        $result = [];
        foreach ($attributes as $attr) {
            $attrId = (int)$attr[EavAttribute::schema_fields_ID];
            $originName = $attr[EavAttribute::schema_fields_name] ?? '';
            $localizedName = $localMap[$attrId]['name'] ?? null;

            $result[$attrId] = [
                'code' => $attr[EavAttribute::schema_fields_code] ?? '',
                'name' => $localizedName ?: $originName, // 优先使用本地化名称
                'origin_name' => $originName,
            ];
        }

        return $result;
    }

    /**
     * 获取选项信息（带本地化）
     * 
     * @param array $optionIds
     * @param string $localeCode
     * @return array
     */
    private function getOptionsWithLocale(array $optionIds, string $localeCode): array
    {
        if (empty($optionIds)) {
            return [];
        }

        $option = clone $this->eavAttributeOption;
        $options = $option->reset()->clearData()
            ->where(Option::schema_fields_ID, $optionIds, 'in')
            ->select()
            ->fetchArray();

        // 获取本地化描述
        $localDesc = clone $this->optionLocalDescription;
        $localDescs = $localDesc->reset()->clearData()
            ->where(OptionLocalDescription::fields_ID, $optionIds, 'in')
            ->where(OptionLocalDescription::schema_fields_local_code, $localeCode)
            ->select()
            ->fetchArray();

        // 构建本地化映射
        $localMap = [];
        foreach ($localDescs as $ld) {
            $localMap[(int)$ld[OptionLocalDescription::fields_ID]] = $ld;
        }

        // 组装结果
        $result = [];
        foreach ($options as $opt) {
            $optId = (int)$opt[Option::schema_fields_ID];
            $originValue = $opt[Option::schema_fields_value] ?? '';
            $localizedValue = $localMap[$optId]['value'] ?? null;

            // 确定swatch类型
            $swatchType = null;
            $swatchValue = null;
            if (!empty($opt[Option::schema_fields_swatch_image])) {
                $swatchType = 'image';
                $swatchValue = $opt[Option::schema_fields_swatch_image];
            } elseif (!empty($opt[Option::schema_fields_swatch_color])) {
                $swatchType = 'color';
                $swatchValue = $opt[Option::schema_fields_swatch_color];
            } elseif (!empty($opt[Option::schema_fields_swatch_text])) {
                $swatchType = 'text';
                $swatchValue = $opt[Option::schema_fields_swatch_text];
            }

            $result[$optId] = [
                'attribute_id' => (int)$opt[Option::schema_fields_attribute_id],
                'code' => $opt[Option::schema_fields_code] ?? '',
                'value' => $localizedValue ?: $originValue, // 优先使用本地化值
                'origin_value' => $originValue,
                'swatch_type' => $swatchType,
                'swatch_value' => $swatchValue,
            ];
        }

        return $result;
    }

    /**
     * 格式化变体产品数据
     * 
     * @param array $children 子产品数组
     * @param array $productOptionMap 产品选项映射
     * @return array
     */
    private function formatVariants(array $children, array $productOptionMap): array
    {
        $variants = [];
        foreach ($children as $child) {
            $productId = (int)$child[Product::schema_fields_ID];
            $optionIds = [];
            if (isset($productOptionMap[$productId])) {
                foreach ($productOptionMap[$productId] as $po) {
                    $optionIds[] = $po['option_id'];
                }
            }

            $variants[] = [
                'product_id' => $productId,
                'sku' => $child[Product::schema_fields_sku] ?? '',
                'name' => $child[Product::schema_fields_name] ?? '',
                'price' => (float)($child[Product::schema_fields_price] ?? 0),
                'stock' => (int)($child[Product::schema_fields_stock] ?? 0),
                'image' => $child[Product::schema_fields_image] ?? '',
                'option_ids' => $optionIds,
            ];
        }
        return $variants;
    }

    /**
     * 根据选中的选项找到对应的子产品
     * 
     * @param int $parentProductId 父产品ID
     * @param array $selectedOptionIds 选中的选项ID数组
     * @return Product|null
     */
    public function findVariantByOptions(int $parentProductId, array $selectedOptionIds): ?Product
    {
        if (empty($selectedOptionIds)) {
            return null;
        }

        // 获取所有子产品的选项映射
        $optionId = clone $this->productOptionId;
        $productOptions = $optionId->reset()->clearData()
            ->where(OptionId::schema_fields_PARENT_PRODUCT_ID, $parentProductId)
            ->select()
            ->fetchArray();

        // 构建产品-选项映射
        $productOptionMap = [];
        foreach ($productOptions as $po) {
            $prodId = (int)$po[OptionId::schema_fields_PRODUCT_ID];
            if (!isset($productOptionMap[$prodId])) {
                $productOptionMap[$prodId] = [];
            }
            $productOptionMap[$prodId][] = (int)$po[OptionId::schema_fields_OPTION_ID];
        }

        // 找到选项完全匹配的产品
        sort($selectedOptionIds);
        foreach ($productOptionMap as $productId => $optIds) {
            sort($optIds);
            if ($optIds === $selectedOptionIds) {
                $product = clone $this->product;
                $product->load($productId);
                if ($product->getId() && $product->getStatus() === 1) {
                    return $product;
                }
            }
        }

        return null;
    }
}
