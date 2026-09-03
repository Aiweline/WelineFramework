<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\CompareMode;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Placement;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Ensures storefront product EAV attribute sets, groups and attribute metadata exist.
 */
final class ProductCatalogEavBootstrap
{
    /** 系统保留：承载商品实例级自由属性组/属性的属性集 code */
    public const PRODUCT_FREE_SET_CODE = '__product_free';

    /**
     * Dedicated product basics (brand catalog / brand_id wizard field).
     * Kept as writable value codes but must not sit in attribute-set editor groups.
     *
     * @var list<string>
     */
    public const DEDICATED_IDENTITY_ATTRIBUTE_CODES = ['brand', 'brand_code'];

    /** @var array<string, list<array{code:string,name:string}>> */
    private const GROUP_ATTRIBUTES = [
        'basic' => [
            ['code' => 'attribute_set', 'name' => '属性集'],
            ['code' => 'model', 'name' => '型号'],
            ['code' => 'color', 'name' => '颜色'],
        ],
        'specs' => [
            ['code' => 'storage', 'name' => '存储容量'],
            ['code' => 'chipset', 'name' => '处理器'],
            ['code' => 'battery', 'name' => '电池容量'],
            ['code' => 'charging', 'name' => '充电规格'],
            ['code' => 'network', 'name' => '网络制式'],
            ['code' => 'coverage_area', 'name' => '适用面积'],
            ['code' => 'filter_type', 'name' => '滤芯类型'],
            ['code' => 'power', 'name' => '功率'],
            ['code' => 'noise_level', 'name' => '噪音'],
            ['code' => 'smart_control', 'name' => '智能控制'],
            ['code' => 'connectivity', 'name' => '连接方式'],
            ['code' => 'ports', 'name' => '接口数量'],
            ['code' => 'power_delivery', 'name' => 'PD 供电'],
            ['code' => 'compatibility', 'name' => '兼容设备'],
            ['code' => 'dpi', 'name' => 'DPI'],
            ['code' => 'runtime', 'name' => '续航时间'],
            ['code' => 'suction', 'name' => '吸力'],
            ['code' => 'weight_kg', 'name' => '重量'],
            ['code' => 'volume', 'name' => '容量'],
            ['code' => 'scent', 'name' => '香型'],
            ['code' => 'material', 'name' => '材质'],
            ['code' => 'dimensions', 'name' => '尺寸'],
            ['code' => 'formaldehyde_cadr', 'name' => '甲醛 CADR'],
            ['code' => 'unlock_methods', 'name' => '开锁方式'],
            ['code' => 'accessories', 'name' => '配件'],
            ['code' => 'suitable_for', 'name' => '适用面料'],
            ['code' => 'shelf_life', 'name' => '保质期'],
            ['code' => 'care_instructions', 'name' => '保养说明'],
        ],
        'service' => [
            ['code' => 'warranty_months', 'name' => '质保(月)'],
        ],
    ];

    /** @var list<array{code:string,label:string}> */
    private const WARRANTY_MONTHS_OPTIONS = [
        ['code' => '12', 'label' => '12 个月'],
        ['code' => '24', 'label' => '24 个月'],
        ['code' => '36', 'label' => '36 个月'],
    ];

    /**
     * System storefront attribute metadata defaults (bootstrap + one-time sync).
     *
     * @var array<string, array{compare_mode:string,frontend_is_filterable:bool,frontend_is_searchable:bool}>
     */
    private const ATTRIBUTE_PROFILES = [
        'attribute_set' => ['compare_mode' => CompareMode::NONE, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'brand' => ['compare_mode' => CompareMode::NONE, 'frontend_is_filterable' => true, 'frontend_is_searchable' => true],
        'model' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => true],
        'color' => ['compare_mode' => CompareMode::NONE, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'storage' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'chipset' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'battery' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'charging' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'network' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'coverage_area' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'filter_type' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'power' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'noise_level' => ['compare_mode' => CompareMode::LOWER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'smart_control' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'connectivity' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'ports' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'power_delivery' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'compatibility' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'dpi' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'runtime' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'suction' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'weight_kg' => ['compare_mode' => CompareMode::LOWER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'volume' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'scent' => ['compare_mode' => CompareMode::NONE, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'material' => ['compare_mode' => CompareMode::NONE, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'dimensions' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'formaldehyde_cadr' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'unlock_methods' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'accessories' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'suitable_for' => ['compare_mode' => CompareMode::DIFF, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false],
        'shelf_life' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'care_instructions' => ['compare_mode' => CompareMode::NONE, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
        'warranty_months' => ['compare_mode' => CompareMode::HIGHER_BETTER, 'frontend_is_filterable' => false, 'frontend_is_searchable' => false],
    ];

    /**
     * @return array{entity_id:int,set_id:int,groups:array<string,int>,attributes:int}
     */
    public function ensureStorefrontSchema(): array
    {
        $entityId = $this->resolveEntityId();
        if ($entityId <= 0) {
            return [
                'entity_id' => 0,
                'set_id' => 0,
                'groups' => [],
                'attributes' => 0,
            ];
        }

        $setId = $this->ensureSet($entityId, 'storefront', '前台商品');
        $groupIds = [];
        $attributeCount = 0;
        $typeId = $this->resolveVarcharTypeId();

        foreach ([
            'basic' => '基本信息',
            'specs' => '规格参数',
            'service' => '售后保障',
        ] as $groupCode => $groupName) {
            $groupIds[$groupCode] = $this->ensureGroup($entityId, $setId, $groupCode, $groupName);
            foreach (self::GROUP_ATTRIBUTES[$groupCode] as $attribute) {
                if ($this->ensureAttribute(
                    $entityId,
                    $setId,
                    $groupIds[$groupCode],
                    $typeId,
                    $attribute['code'],
                    $attribute['name'],
                )) {
                    ++$attributeCount;
                }
            }
        }

        $warranty = $this->ensureWarrantyMonthsSelect($entityId, $setId, $groupIds['service'], $typeId);
        if ($warranty['attribute_created']) {
            ++$attributeCount;
        }

        $this->syncSystemAttributeMetadata($entityId);
        $detached = $this->detachDedicatedIdentityAttributesFromSets($entityId);

        return [
            'entity_id' => $entityId,
            'set_id' => $setId,
            'groups' => $groupIds,
            'attributes' => $attributeCount,
            'warranty_options_added' => $warranty['options_added'],
            'detached_identity_attributes' => $detached,
        ];
    }

    /**
     * Remove brand/brand_code from attribute-set groups and placements.
     * Values remain writable via dedicated create basics (brand_id → brand/brand_code).
     */
    public function detachDedicatedIdentityAttributesFromSets(int $entityId = 0): int
    {
        $entityId = $entityId > 0 ? $entityId : $this->resolveEntityId();
        if ($entityId <= 0) {
            return 0;
        }

        $detached = 0;
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        /** @var Placement $placementModel */
        $placementModel = ObjectManager::getInstance(Placement::class);

        foreach (self::DEDICATED_IDENTITY_ATTRIBUTE_CODES as $code) {
            $attribute = clone $attributeModel;
            $attribute->clearData()
                ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
                ->where(EavAttribute::schema_fields_code, $code)
                ->find()
                ->fetch();
            $attributeId = (int)$attribute->getAttributeId();
            if ($attributeId <= 0) {
                continue;
            }

            foreach ($placementModel->clearData()
                ->where(Placement::schema_fields_attribute_id, $attributeId)
                ->select()
                ->fetchArray() as $row) {
                $placementId = (int)($row[Placement::schema_fields_placement_id] ?? 0);
                if ($placementId <= 0) {
                    continue;
                }
                (clone $placementModel)->load($placementId)->delete();
                ++$detached;
            }

            if ($attribute->getSetId() !== 0 || $attribute->getGroupId() !== 0) {
                $attribute->setSetId(0)->setGroupId(0)->save(true);
                ++$detached;
            }
        }

        return $detached;
    }

    /**
     * 汉服属性集：规格轴（尺码/颜色/类型）走 EAV 选项定义，供 configurable 与前台筛选复用。
     *
     * @return array{entity_id:int,set_id:int,groups:array<string,int>,attributes:int,options:int}
     */
    public function ensureHanfuSchema(): array
    {
        $entityId = $this->resolveEntityId();
        if ($entityId <= 0) {
            return [
                'entity_id' => 0,
                'set_id' => 0,
                'groups' => [],
                'attributes' => 0,
                'options' => 0,
            ];
        }

        $setId = $this->ensureSet($entityId, 'hanfu', '汉服');
        $basicGroupId = $this->ensureGroup($entityId, $setId, 'hanfu_basic', '基本信息');
        $variantGroupId = $this->ensureGroup($entityId, $setId, 'hanfu_variants', '规格维度');
        $specsGroupId = $this->ensureGroup($entityId, $setId, 'hanfu_specs', '规格参数');
        $sourceGroupId = $this->ensureGroup($entityId, $setId, 'hanfu_source', '1688 来源');
        $serviceGroupId = $this->ensureGroup($entityId, $setId, 'hanfu_service', '售后保障');

        $typeId = $this->resolveVarcharTypeId();
        $textTypeId = $this->resolveTypeId('textarea_text');
        $attributeCount = 0;
        $optionCount = 0;

        foreach ([
            ['code' => 'attribute_set', 'name' => '属性集', 'group' => $basicGroupId, 'type' => $typeId],
            ['code' => 'material', 'name' => '材质', 'group' => $specsGroupId, 'type' => $typeId],
            ['code' => 'source_platform', 'name' => '来源平台', 'group' => $sourceGroupId, 'type' => $typeId],
            ['code' => 'source_offer_id', 'name' => '1688 商品 ID', 'group' => $sourceGroupId, 'type' => $typeId],
            ['code' => 'source_url', 'name' => '1688 商品地址', 'group' => $sourceGroupId, 'type' => $textTypeId],
            ['code' => 'source_shop_url', 'name' => '1688 店铺地址', 'group' => $sourceGroupId, 'type' => $textTypeId],
            ['code' => 'source_factory_url', 'name' => '1688 厂档地址', 'group' => $sourceGroupId, 'type' => $textTypeId],
            ['code' => 'source_company_name', 'name' => '1688 企业名称', 'group' => $sourceGroupId, 'type' => $typeId],
            ['code' => 'source_minimum_order_quantity', 'name' => '1688 起订量', 'group' => $sourceGroupId, 'type' => $typeId],
            ['code' => 'source_snapshot_digest', 'name' => '来源快照校验值', 'group' => $sourceGroupId, 'type' => $typeId],
            ['code' => 'care_instructions', 'name' => '保养说明', 'group' => $serviceGroupId, 'type' => $typeId],
        ] as $attribute) {
            if ($this->ensureAttribute(
                $entityId,
                $setId,
                $attribute['group'],
                $attribute['type'],
                $attribute['code'],
                $attribute['name'],
            )) {
                ++$attributeCount;
            } elseif ($this->ensurePlacementByCode($entityId, $attribute['code'], $setId, $attribute['group'])) {
                ++$attributeCount;
            }
        }

        $variantDefinitions = [
            [
                'code' => 'color',
                'name' => '颜色',
                'options' => [
                    ['code' => 'm-white', 'label' => '米白色', 'swatch' => '#f5f0e6'],
                    ['code' => 'pink', 'label' => '粉色', 'swatch' => '#f4b4c4'],
                    ['code' => 'black', 'label' => '黑色', 'swatch' => '#1a1a1a'],
                    ['code' => 'red', 'label' => '红色', 'swatch' => '#b83232'],
                    ['code' => 'white', 'label' => '白色', 'swatch' => '#ffffff'],
                    ['code' => 'xianhe-black', 'label' => '仙鹤黑色', 'swatch' => '#101010'],
                    ['code' => 'xianhe-red', 'label' => '仙鹤红色', 'swatch' => '#8c1d1d'],
                    ['code' => 'qingzhu-white', 'label' => '幻彩卿竹白色', 'swatch' => '#eef2f0'],
                    ['code' => 'light-sea', 'label' => '浅海青', 'swatch' => '#7eb8b8'],
                    ['code' => 'phantom-black', 'label' => '幻夜黑', 'swatch' => '#222222'],
                    ['code' => 'star-gray', 'label' => '星岩灰', 'swatch' => '#8a8f98'],
                ],
            ],
            [
                'code' => 'size',
                'name' => '尺码',
                'options' => [
                    ['code' => 's', 'label' => 'S'],
                    ['code' => 'm', 'label' => 'M'],
                    ['code' => 'l', 'label' => 'L'],
                    ['code' => 'xl', 'label' => 'XL'],
                    ['code' => 'xxl', 'label' => 'XXL'],
                    ['code' => 'xxxl', 'label' => 'XXXL'],
                    ['code' => '2xl', 'label' => '2XL'],
                    ['code' => '3xl', 'label' => '3XL'],
                ],
            ],
            [
                'code' => 'style_type',
                'name' => '类型',
                'options' => [
                    ['code' => 'set', 'label' => '套装（上衣+下装）'],
                    ['code' => 'skirt', 'label' => '马面裙单件'],
                    ['code' => 'top', 'label' => '上衣单件'],
                    ['code' => 'skirt-black', 'label' => '黑色妆花马面裙'],
                    ['code' => 'skirt-red', 'label' => '红色妆花马面裙'],
                    ['code' => 'top-white', 'label' => '白色飞机袖'],
                ],
            ],
        ];

        $warranty = $this->ensureWarrantyMonthsSelect($entityId, $setId, $serviceGroupId, $typeId);
        if ($warranty['attribute_created']) {
            ++$attributeCount;
        } elseif ($this->ensurePlacementByCode($entityId, 'warranty_months', $setId, $serviceGroupId)) {
            ++$attributeCount;
        }
        $optionCount += $warranty['options_added'];

        foreach ($variantDefinitions as $definition) {
            $created = $this->ensureSelectAttribute(
                $entityId,
                $setId,
                $variantGroupId,
                $typeId,
                $definition['code'],
                $definition['name'],
                $definition['options'],
                true,
            );
            if ($created['attribute_created']) {
                ++$attributeCount;
            } elseif ($this->ensurePlacementByCode($entityId, $definition['code'], $setId, $variantGroupId)) {
                ++$attributeCount;
            }
            $optionCount += $created['options_added'];
        }

        return [
            'entity_id' => $entityId,
            'set_id' => $setId,
            'groups' => [
                'basic' => $basicGroupId,
                'variants' => $variantGroupId,
                'specs' => $specsGroupId,
                'source' => $sourceGroupId,
                'service' => $serviceGroupId,
            ],
            'attributes' => $attributeCount,
            'options' => $optionCount,
            'legacy_attributes_detached' => $this->detachAttributeCodesFromSet(
                $entityId,
                $setId,
                [
                    'available_colors',
                    'available_sizes',
                    'source_public_specs',
                    'source_variant_combinations',
                ],
            ),
            'cleared_global_swatch_images' => $this->clearVariantOptionSwatchImages($entityId),
        ];
    }

    /**
     * Add source-discovered Hanfu specification options to the formal EAV attribute set.
     *
     * @param list<array<string,mixed>> $definitions
     * @return array{attributes:int,options:int}
     */
    public function ensureHanfuAttributeOptions(array $definitions): array
    {
        $schema = $this->ensureHanfuSchema();
        $entityId = (int)($schema['entity_id'] ?? 0);
        $setId = (int)($schema['set_id'] ?? 0);
        $groups = is_array($schema['groups'] ?? null) ? $schema['groups'] : [];
        if ($entityId <= 0 || $setId <= 0) {
            return ['attributes' => 0, 'options' => 0];
        }

        $attributes = 0;
        $options = 0;
        $typeId = $this->resolveVarcharTypeId();
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $code = strtolower(trim((string)($definition['code'] ?? '')));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
                throw new \InvalidArgumentException('hanfu_eav_attribute_code_invalid');
            }
            $name = trim((string)($definition['name'] ?? $definition['label'] ?? $code));
            $variant = !empty($definition['variant']);
            $multiple = $variant || !empty($definition['multiple']);
            $groupId = (int)($groups[$variant ? 'variants' : 'specs'] ?? 0);
            $rawOptions = is_array($definition['options'] ?? null) ? $definition['options'] : [];
            $normalizedOptions = [];
            foreach ($rawOptions as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionCode = strtolower(trim((string)($option['code'] ?? $option['value'] ?? '')));
                $label = trim((string)($option['label'] ?? $option['name'] ?? $optionCode));
                if ($optionCode !== '' && $label !== '') {
                    $normalizedOptions[] = [
                        'code' => $optionCode,
                        'label' => $label,
                        'swatch' => (string)($option['swatch'] ?? ''),
                    ];
                }
            }
            if ($name === '' || $groupId <= 0 || $normalizedOptions === []) {
                continue;
            }
            $created = $this->ensureSelectAttribute(
                $entityId,
                $setId,
                $groupId,
                $typeId,
                $code,
                $name,
                $normalizedOptions,
                $multiple,
            );
            if ($created['attribute_created']
                || $this->ensurePlacementByCode($entityId, $code, $setId, $groupId)
            ) {
                ++$attributes;
            }
            $options += (int)$created['options_added'];
        }

        return ['attributes' => $attributes, 'options' => $options];
    }

    /** @param list<string> $codes */
    private function detachAttributeCodesFromSet(int $entityId, int $setId, array $codes): int
    {
        $detached = 0;
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        /** @var Placement $placementModel */
        $placementModel = ObjectManager::getInstance(Placement::class);
        foreach ($codes as $code) {
            $attribute = clone $attributeModel;
            $attribute->clearData()
                ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
                ->where(EavAttribute::schema_fields_code, $code)
                ->find()
                ->fetch();
            $attributeId = (int)$attribute->getAttributeId();
            if ($attributeId <= 0) {
                continue;
            }
            foreach ((clone $placementModel)->clearData()
                ->where(Placement::schema_fields_attribute_id, $attributeId)
                ->where(Placement::schema_fields_set_id, $setId)
                ->select()
                ->fetchArray() as $row) {
                $placementId = (int)($row[Placement::schema_fields_placement_id] ?? 0);
                if ($placementId > 0) {
                    (clone $placementModel)->load($placementId)->delete();
                    ++$detached;
                }
            }
            if ((int)$attribute->getSetId() === $setId) {
                $attribute->setSetId(0)->setGroupId(0)->save(true);
                ++$detached;
            }
        }
        return $detached;
    }

    /**
     * 全局 EAV 选项只保留抽象色值；商品样本图仅通过 Product/Offer 媒体关联维护。
     */
    public function clearVariantOptionSwatchImages(int $entityId): int
    {
        if ($entityId <= 0) {
            return 0;
        }

        $cleared = 0;
        foreach (['color', 'style_type', 'size'] as $attributeCode) {
            /** @var EavAttribute $attribute */
            $attribute = ObjectManager::getInstance(EavAttribute::class);
            $attribute->clearData()
                ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
                ->where(EavAttribute::schema_fields_code, $attributeCode)
                ->find()
                ->fetch();
            $attributeId = (int)$attribute->getAttributeId();
            if ($attributeId <= 0) {
                continue;
            }

            /** @var Option $optionModel */
            $optionModel = ObjectManager::getInstance(Option::class);
            foreach ($optionModel->clearData()
                ->where(Option::schema_fields_eav_entity_id, $entityId)
                ->where(Option::schema_fields_attribute_id, $attributeId)
                ->select()
                ->fetchArray() as $row) {
                $optionId = (int)($row[Option::schema_fields_ID] ?? 0);
                if ($optionId <= 0 || trim((string)($row[Option::schema_fields_swatch_image] ?? '')) === '') {
                    continue;
                }
                $optionModel->clearData()
                    ->where(Option::schema_fields_ID, $optionId)
                    ->find()
                    ->fetch();
                if ((int)$optionModel->getOptionId() <= 0) {
                    continue;
                }
                $optionModel->addData([Option::schema_fields_swatch_image => ''])->save();
                ++$cleared;
            }
        }

        return $cleared;
    }

    public function ensureProductFreeSet(int $entityId): int
    {
        return $this->ensureSet($entityId, self::PRODUCT_FREE_SET_CODE, '商品自由属性');
    }

    public function productFreeSetId(int $entityId): int
    {
        return $this->ensureProductFreeSet($entityId);
    }

    private function resolveEntityId(): int
    {
        /** @var EavEntity $entity */
        $entity = ObjectManager::getInstance(EavEntity::class);
        $entity->clearData()
            ->where(EavEntity::schema_fields_code, ProductCatalogAttributeEntity::entity_code)
            ->find()
            ->fetch();

        return (int)$entity->getId();
    }

    private function resolveVarcharTypeId(): int
    {
        return $this->resolveTypeId('input_string_255');
    }

    private function resolveTypeId(string $code): int
    {
        /** @var Type $type */
        $type = ObjectManager::getInstance(Type::class);
        $type->clearData()
            ->where(Type::schema_fields_code, $code)
            ->find()
            ->fetch();
        $typeId = (int)$type->getId();
        if ($typeId <= 0) {
            throw new \RuntimeException('EAV attribute type is missing: ' . $code);
        }

        return $typeId;
    }

    private function ensureSet(int $entityId, string $code, string $name): int
    {
        /** @var Set $set */
        $set = ObjectManager::getInstance(Set::class);
        $set->clearData()
            ->where(Set::schema_fields_eav_entity_id, $entityId)
            ->where(Set::schema_fields_code, $code)
            ->find()
            ->fetch();
        if ((int)$set->getId() > 0) {
            return (int)$set->getId();
        }

        $set->clearData()->insert([
            Set::schema_fields_eav_entity_id => $entityId,
            Set::schema_fields_code => $code,
            Set::schema_fields_name => $name,
        ])->fetch();

        return (int)$set->getId();
    }

    private function ensureGroup(int $entityId, int $setId, string $code, string $name): int
    {
        /** @var Group $group */
        $group = ObjectManager::getInstance(Group::class);
        $group->clearData()
            ->where(Group::schema_fields_eav_entity_id, $entityId)
            ->where(Group::schema_fields_code, $code)
            ->find()
            ->fetch();
        if ((int)$group->getId() > 0) {
            return (int)$group->getId();
        }

        $group->clearData()->insert([
            Group::schema_fields_eav_entity_id => $entityId,
            Group::schema_fields_set_id => $setId,
            Group::schema_fields_code => $code,
            Group::schema_fields_name => $name,
        ])->fetch();

        return (int)$group->getId();
    }

    private function ensureAttribute(
        int $entityId,
        int $setId,
        int $groupId,
        int $typeId,
        string $code,
        string $name,
    ): bool {
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->clearData()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, $code)
            ->find()
            ->fetch();
        if ((int)$attribute->getAttributeId() > 0) {
            return false;
        }

        $profile = self::ATTRIBUTE_PROFILES[$code] ?? [
            'compare_mode' => CompareMode::NONE,
            'frontend_is_filterable' => true,
            'frontend_is_searchable' => false,
        ];

        $attribute->clearData()->insert([
            EavAttribute::schema_fields_eav_entity_id => $entityId,
            EavAttribute::schema_fields_code => $code,
            EavAttribute::schema_fields_name => $name,
            EavAttribute::schema_fields_type_id => $typeId,
            EavAttribute::schema_fields_set_id => $setId,
            EavAttribute::schema_fields_group_id => $groupId,
            EavAttribute::schema_fields_is_system => 1,
            EavAttribute::schema_fields_basic_is_enable => 1,
            EavAttribute::schema_fields_frontend_is_visible => 1,
            EavAttribute::schema_fields_frontend_is_filterable => $profile['frontend_is_filterable'] ? 1 : 0,
            EavAttribute::schema_fields_frontend_is_searchable => $profile['frontend_is_searchable'] ? 1 : 0,
            EavAttribute::schema_fields_compare_mode => $profile['compare_mode'],
            EavAttribute::schema_fields_data_is_multiple => 0,
            EavAttribute::schema_fields_data_has_option => 0,
        ])->fetch();

        return true;
    }

    private function ensurePlacementByCode(int $entityId, string $code, int $setId, int $groupId): bool
    {
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->clearData()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, $code)
            ->find()
            ->fetch();
        $attributeId = (int)$attribute->getAttributeId();
        if ($attributeId <= 0) {
            return false;
        }
        if ((int)$attribute->getSetId() === $setId && (int)$attribute->getGroupId() === $groupId) {
            return false;
        }

        return $this->ensurePlacement($entityId, $attributeId, $setId, $groupId);
    }

    private function ensurePlacement(int $entityId, int $attributeId, int $setId, int $groupId): bool
    {
        /** @var Placement $placement */
        $placement = ObjectManager::getInstance(Placement::class);
        $placement->clearData()
            ->where(Placement::schema_fields_attribute_id, $attributeId)
            ->where(Placement::schema_fields_set_id, $setId)
            ->find()
            ->fetch();
        if ((int)$placement->getId() > 0) {
            if ((int)$placement->getData(Placement::schema_fields_group_id) === $groupId) {
                return false;
            }
            $placement->setData(Placement::schema_fields_group_id, $groupId)->save();

            return true;
        }

        $placement->clearData()->insert([
            Placement::schema_fields_attribute_id => $attributeId,
            Placement::schema_fields_eav_entity_id => $entityId,
            Placement::schema_fields_set_id => $setId,
            Placement::schema_fields_group_id => $groupId,
        ])->fetch();

        return (int)$placement->getId() > 0;
    }

    public function syncSystemAttributeMetadata(int $entityId = 0): int
    {
        $entityId = $entityId > 0 ? $entityId : $this->resolveEntityId();
        if ($entityId <= 0) {
            return 0;
        }

        $updated = 0;
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        foreach (self::ATTRIBUTE_PROFILES as $code => $profile) {
            $attribute = clone $attributeModel;
            $attribute->clearData()
                ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
                ->where(EavAttribute::schema_fields_code, $code)
                ->where(EavAttribute::schema_fields_is_system, 1)
                ->find()
                ->fetch();
            if ((int)$attribute->getAttributeId() <= 0) {
                continue;
            }

            $changes = [];
            $currentMode = CompareMode::normalize((string)$attribute->getData(EavAttribute::schema_fields_compare_mode));
            if ($currentMode === CompareMode::NONE && $profile['compare_mode'] !== CompareMode::NONE) {
                $changes[EavAttribute::schema_fields_compare_mode] = $profile['compare_mode'];
            }
            if (!(bool)$attribute->getData(EavAttribute::schema_fields_frontend_is_searchable)
                && $profile['frontend_is_searchable']
            ) {
                $changes[EavAttribute::schema_fields_frontend_is_searchable] = 1;
            }
            if (!(bool)$attribute->getData(EavAttribute::schema_fields_frontend_is_filterable)
                && $profile['frontend_is_filterable']
            ) {
                $changes[EavAttribute::schema_fields_frontend_is_filterable] = 1;
            }
            if ($changes === []) {
                continue;
            }
            $attribute->addData($changes)->save();
            ++$updated;
        }

        return $updated;
    }

    /**
     * @return array{attribute_created:bool,options_added:int}
     */
    private function ensureWarrantyMonthsSelect(int $entityId, int $setId, int $groupId, int $typeId): array
    {
        $created = $this->ensureSelectAttribute(
            $entityId,
            $setId,
            $groupId,
            $typeId,
            'warranty_months',
            '质保(月)',
            self::WARRANTY_MONTHS_OPTIONS,
        );
        if (!$created['attribute_created']) {
            $this->ensurePlacementByCode($entityId, 'warranty_months', $setId, $groupId);
        }

        return $created;
    }

    /**
     * @param list<array{code:string,label:string,swatch?:string,swatch_image?:string}> $options
     * @return array{attribute_created:bool,options_added:int}
     */
    private function ensureSelectAttribute(
        int $entityId,
        int $setId,
        int $groupId,
        int $typeId,
        string $code,
        string $name,
        array $options,
        bool $multiple = false,
    ): array {
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $attribute->clearData()
            ->where(EavAttribute::schema_fields_eav_entity_id, $entityId)
            ->where(EavAttribute::schema_fields_code, $code)
            ->find()
            ->fetch();

        $attributeCreated = false;
        $profile = self::ATTRIBUTE_PROFILES[$code] ?? [
            'compare_mode' => CompareMode::NONE,
            'frontend_is_filterable' => true,
            'frontend_is_searchable' => false,
        ];
        if ((int)$attribute->getAttributeId() <= 0) {
            $attribute->clearData()->insert([
                EavAttribute::schema_fields_eav_entity_id => $entityId,
                EavAttribute::schema_fields_code => $code,
                EavAttribute::schema_fields_name => $name,
                EavAttribute::schema_fields_type_id => $typeId,
                EavAttribute::schema_fields_set_id => $setId,
                EavAttribute::schema_fields_group_id => $groupId,
                EavAttribute::schema_fields_is_system => 1,
                EavAttribute::schema_fields_basic_is_enable => 1,
                EavAttribute::schema_fields_frontend_is_visible => 1,
                EavAttribute::schema_fields_frontend_is_filterable => $profile['frontend_is_filterable'] ? 1 : 0,
                EavAttribute::schema_fields_frontend_is_searchable => $profile['frontend_is_searchable'] ? 1 : 0,
                EavAttribute::schema_fields_compare_mode => $profile['compare_mode'],
                EavAttribute::schema_fields_data_is_multiple => $multiple ? 1 : 0,
                EavAttribute::schema_fields_data_has_option => 1,
            ])->fetch();
            $attributeCreated = true;
        } else {
            $attribute->setData(EavAttribute::schema_fields_name, $name)
                ->setData(EavAttribute::schema_fields_data_has_option, 1)
                ->setData(EavAttribute::schema_fields_data_is_multiple, $multiple ? 1 : 0)
                ->setData(EavAttribute::schema_fields_frontend_is_filterable, 1)
                ->save(true);
        }

        $attributeId = (int)$attribute->getAttributeId();
        $optionsAdded = 0;
        foreach ($options as $option) {
            if ($this->ensureOption(
                $entityId,
                $attributeId,
                $option['code'],
                $option['label'],
                (string)($option['swatch'] ?? ''),
            )) {
                ++$optionsAdded;
            }
        }

        return [
            'attribute_created' => $attributeCreated,
            'options_added' => $optionsAdded,
        ];
    }

    private function ensureOption(
        int $entityId,
        int $attributeId,
        string $code,
        string $label,
        string $swatchColor = '',
    ): bool {
        $label = $this->normalizeOptionLabel($label);
        $swatchColor = trim($swatchColor);

        /** @var Option $option */
        $option = ObjectManager::getInstance(Option::class);
        $existingOptions = $option->clearData()
            ->where(Option::schema_fields_eav_entity_id, $entityId)
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->order('main_table.' . Option::schema_fields_option_id)
            ->select()
            ->fetchArray();

        $existingOptionId = 0;
        foreach (is_array($existingOptions) ? $existingOptions : [] as $existingOption) {
            if (!is_array($existingOption)) {
                continue;
            }
            if ($this->normalizeOptionLabel(
                (string)($existingOption[Option::schema_fields_value] ?? ''),
            ) !== $label) {
                continue;
            }
            $existingOptionId = (int)($existingOption[Option::schema_fields_option_id] ?? 0);
            if ($existingOptionId > 0) {
                break;
            }
        }

        $option->clearData();
        if ($existingOptionId > 0) {
            $option->where(Option::schema_fields_option_id, $existingOptionId)
                ->find()
                ->fetch();
        } else {
            $option->where(Option::schema_fields_eav_entity_id, $entityId)
                ->where(Option::schema_fields_attribute_id, $attributeId)
                ->where(Option::schema_fields_code, $code)
                ->find()
                ->fetch();
        }

        if ((int)$option->getOptionId() > 0) {
            $changes = [];
            if ((string)$option->getData(Option::schema_fields_value) !== $label) {
                $changes[Option::schema_fields_value] = $label;
            }
            if ($swatchColor !== ''
                && (string)$option->getData(Option::schema_fields_swatch_color) !== $swatchColor
            ) {
                $changes[Option::schema_fields_swatch_color] = $swatchColor;
            }
            if ($changes === []) {
                return false;
            }
            $option->addData($changes)->save();

            return true;
        }

        $row = [
            Option::schema_fields_eav_entity_id => $entityId,
            Option::schema_fields_attribute_id => $attributeId,
            Option::schema_fields_code => $code,
            Option::schema_fields_value => $label,
        ];
        if ($swatchColor !== '') {
            $row[Option::schema_fields_swatch_color] = $swatchColor;
        }
        $option->clearData()->insert($row)->fetch();

        return true;
    }

    private function normalizeOptionLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', $label);

        return trim(is_string($normalized) ? $normalized : $label);
    }
}
