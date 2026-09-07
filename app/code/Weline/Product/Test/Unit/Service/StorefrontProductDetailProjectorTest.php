<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductDetailProjector;

final class StorefrontProductDetailProjectorTest extends TestCase
{
    public function testEnglishProductCopyKeepsSharedPunctuationInsteadOfFallingBackToSku(): void
    {
        $name = 'Feitian Dunhuang · Mamian Skirt and Hanfu Shirt';
        $detail = $this->projector()->project(
            ['product_id' => 94, 'sku' => 'HANFU-DUNHUANG'],
            [
                $this->attribute(0, 'name', '', '飞天敦煌马面裙'),
                $this->attribute(0, 'name', 'en_US', $name),
                $this->attribute(0, 'short_description', 'en_US', 'Hanfu · Everyday styling'),
            ],
            [],
            0,
            'en_US',
        );

        self::assertSame($name, $detail['name']);
        self::assertSame('Hanfu · Everyday styling', $detail['short_description']);
    }

    public function testItProjectsLocalizedPublicDetailsAndOrderedMedia(): void
    {
        $projector = $this->projector();

        $detail = $projector->project(
            [
                'product_id' => 9,
                'name' => 'Website name',
                'image' => '/media/primary.jpg',
            ],
            [
                $this->attribute(0, 'name', '', 'Website name'),
                $this->attribute(3, 'name', 'zh_Hans_CN', '门店商品名'),
                $this->attribute(0, 'short_description', '', 'Website summary'),
                $this->attribute(3, 'short_description', 'zh_Hans_CN', '门店简介'),
                $this->attribute(0, 'description', '', 'Website description'),
                $this->attribute(0, 'engine', '', 'LONCIN YBS300 PRO'),
                $this->attribute(3, 'engine', 'zh_Hans_CN', '隆鑫 YBS300 PRO'),
                $this->attribute(0, 'displacement', '', '294.9 ml'),
                $this->attribute(0, 'source_catalog', '', 'internal-import-source'),
                $this->attribute(0, 'source_product_id', '', '5357'),
                $this->attribute(0, 'source_images_json', '', '[{"id":5358}]'),
                $this->attribute(0, 'quote_only', '', '1'),
            ],
            [
                ['media_id' => 13, 'path' => '/media/detail.jpg', 'position' => 2],
                ['media_id' => 9, 'path' => '/media/primary.jpg', 'position' => 0],
                ['media_id' => 12, 'path' => '/media/secondary.jpg', 'position' => 1],
            ],
            3,
            'zh_Hans_CN',
        );

        self::assertSame('门店商品名', $detail['name']);
        self::assertSame('门店简介', $detail['short_description']);
        self::assertSame('Website description', $detail['description']);
        self::assertSame('', $detail['meta_name']);
        self::assertSame('', $detail['meta_description']);
        self::assertSame('', $detail['meta_keywords']);
        self::assertSame(
            [
                ['code' => 'displacement', 'label' => '', 'value' => '294.9 ml'],
                ['code' => 'engine', 'label' => '', 'value' => '隆鑫 YBS300 PRO'],
            ],
            $detail['specifications'],
        );
        self::assertSame(
            ['/media/primary.jpg', '/media/secondary.jpg', '/media/detail.jpg'],
            $detail['images'],
        );
        self::assertArrayNotHasKey('source_catalog', $detail);
        self::assertTrue($detail['quote_only']);
    }

    public function testItExposesSeoMetaAndHidesCostFromSpecifications(): void
    {
        $projector = $this->projector();

        $detail = $projector->project(
            ['product_id' => 11, 'name' => 'SEO Sample', 'image' => ''],
            [
                $this->attribute(0, 'name', '', 'SEO Sample'),
                $this->attribute(0, 'meta_name', '', '自定义 SEO 标题'),
                $this->attribute(0, 'meta_description', '', '自定义 SEO 描述'),
                $this->attribute(0, 'meta_keywords', '', '汉服,马面裙'),
                $this->attribute(0, 'cost', '', '88.5'),
                $this->attribute(0, 'brand', '', '醉欢楼'),
            ],
            [],
            0,
            '',
        );

        self::assertSame('自定义 SEO 标题', $detail['meta_name']);
        self::assertSame('自定义 SEO 描述', $detail['meta_description']);
        self::assertSame('汉服,马面裙', $detail['meta_keywords']);
        self::assertArrayNotHasKey('cost', $detail);
        self::assertSame('醉欢楼', $detail['brand']);
        self::assertSame([], $detail['specifications']);
    }

    public function testItExposesNormalizedSourceSlugForPublicUrls(): void
    {
        $projector = $this->projector();

        $detail = $projector->project(
            ['product_id' => 7, 'name' => 'Website name', 'image' => ''],
            [
                $this->attribute(0, 'name', '', 'Website name'),
                $this->attribute(0, 'slug', '', 'gasoline-atvs'),
                $this->attribute(0, 'source_slug', '', 'ztot-z7l-yb300h-gasoline-dirt-bike'),
                $this->attribute(0, 'engine', '', 'LONCIN'),
            ],
            [],
            0,
            '',
        );

        self::assertSame('ztot-z7l-yb300h-gasoline-dirt-bike', $detail['slug']);
        self::assertSame(
            [['code' => 'engine', 'label' => '', 'value' => 'LONCIN']],
            $detail['specifications'],
        );
    }

    public function testClearedStoreValueStopsWebsiteFallback(): void
    {
        $projector = $this->projector();

        $detail = $projector->project(
            ['product_id' => 9, 'image' => ''],
            [
                $this->attribute(0, 'description', '', 'Website description'),
                $this->attribute(3, 'description', 'zh_Hans_CN', null, true),
                $this->attribute(0, 'engine', '', 'Website engine'),
                $this->attribute(3, 'engine', 'zh_Hans_CN', null, true),
            ],
            [],
            3,
            'zh_Hans_CN',
        );

        self::assertSame('', $detail['description']);
        self::assertSame([], $detail['specifications']);
        self::assertSame([], $detail['images']);
    }

    public function testItHidesInternalConfigurationAndResolvesEavOptionLabels(): void
    {
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct()
            {
            }

            public function resolve(string $attributeCode, string $value): string
            {
                return match (strtolower(trim($attributeCode))) {
                    'color' => $value === 'm-white' ? '米白色' : $value,
                    'size' => $value === 'm' ? 'M' : strtoupper($value),
                    'style_type' => $value === 'set' ? '套装（上衣+马面裙）' : $value,
                    default => $value,
                };
            }

            public function attributeLabel(string $attributeCode): string
            {
                return match (strtolower(trim($attributeCode))) {
                    'color' => '颜色',
                    'size' => '尺码',
                    'style_type' => '类型',
                    'material' => '材质',
                    default => '',
                };
            }
        };
        $option = static fn(
            int $id,
            string $code,
            string $label,
        ): \Weline\Eav\Api\Metadata\AttributeOptionMetadata => new \Weline\Eav\Api\Metadata\AttributeOptionMetadata(
            id: $id,
            value: $label,
            code: $code,
            label: $label,
            sortOrder: $id,
        );
        $attribute = static fn(
            int $id,
            string $code,
            string $name,
            \Weline\Eav\Api\Metadata\AttributeOptionMetadata $attributeOption,
        ): \Weline\Eav\Api\Metadata\AttributeMetadata => new \Weline\Eav\Api\Metadata\AttributeMetadata(
            id: $id,
            entityId: 1,
            code: $code,
            name: $name,
            typeCode: 'varchar',
            fieldType: 'multiselect',
            element: 'select',
            setId: 1,
            groupId: 1,
            required: false,
            multiple: true,
            enabled: true,
            hasOption: true,
            sortOrder: $id,
            options: [$attributeOption],
        );
        $set = new \Weline\Eav\Api\Metadata\AttributeSetMetadata(
            id: 1,
            entityId: 1,
            code: 'hanfu',
            name: '汉服',
            sortOrder: 1,
            groups: [
                new \Weline\Eav\Api\Metadata\AttributeGroupMetadata(
                    id: 1,
                    entityId: 1,
                    setId: 1,
                    code: 'hanfu_variants',
                    name: '汉服规格',
                    sortOrder: 1,
                    attributes: [
                        $attribute(1, 'color', '颜色', $option(1, 'm-white', '米白色')),
                        $attribute(2, 'size', '尺码', $option(2, 'm', 'M')),
                        $attribute(3, 'style_type', '类型', $option(3, 'set', '套装（上衣+马面裙）')),
                    ],
                ),
            ],
        );
        $metadata = new class([$set]) implements \Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface {
            public function __construct(private readonly array $sets)
            {
            }

            public function catalog(\Weline\Eav\Api\Entity\EntityDefinitionInterface $entity): array
            {
                return $this->sets;
            }

            public function catalogForProduct(
                \Weline\Eav\Api\Entity\EntityDefinitionInterface $entity,
                int $productId,
                string $freeSetCode = '__product_free',
            ): array {
                return $this->sets;
            }

            public function attributeIndexByEntityCode(string $entityCode): array
            {
                return [];
            }
        };
        $entity = (new \ReflectionClass(\Weline\Product\Model\ProductCatalogAttributeEntity::class))
            ->newInstanceWithoutConstructor();
        $variantAxes = new \Weline\Product\Service\StorefrontVariantAxisResolver(
            $metadata,
            $entity,
            $labels,
        );
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(),
            $labels,
            $variantAxes,
        );

        $detail = $projector->project(
            [
                'product_id' => 26,
                'name' => '桃园清梦',
                'image' => '',
                'combination' => ['color' => 'm-white', 'size' => 'm', 'style_type' => 'set'],
                'combination_key' => 'color=m-white|size=m|style_type=set',
            ],
            [
                $this->attribute(0, 'name', '', '桃园清梦'),
                $this->attribute(0, 'brand', '', '醉欢楼'),
                $this->attribute(0, 'material', '', '聚酯纤维100%'),
                $this->attribute(0, 'product_type', '', 'configurable'),
                $this->attribute(0, 'reference_source', '', '淘宝参考价'),
                $this->attribute(0, 'color', '', ['m-white'], false, 'multiselect'),
                $this->attribute(0, 'size', '', ['m'], false, 'multiselect'),
                $this->attribute(0, 'style_type', '', ['set'], false, 'multiselect'),
            ],
            [
                [
                    'media_id' => 1,
                    'path' => '/media/m-white-set.jpg',
                    'role' => 'variant',
                    'combination_key' => 'color=m-white|size=m|style_type=set',
                    'position' => 0,
                ],
                [
                    'media_id' => 2,
                    'path' => '/media/red-set.jpg',
                    'role' => 'variant',
                    'combination_key' => 'color=red|size=m|style_type=set',
                    'position' => 0,
                ],
                [
                    'media_id' => 3,
                    'path' => '/media/base.jpg',
                    'role' => 'main',
                    'combination_key' => '',
                    'position' => 1,
                ],
            ],
            0,
            '',
        );

        self::assertSame('醉欢楼', $detail['brand']);
        self::assertSame(
            [
                ['code' => 'color', 'label' => '颜色', 'value' => '米白色'],
                ['code' => 'material', 'label' => '材质', 'value' => '聚酯纤维100%'],
                ['code' => 'size', 'label' => '尺码', 'value' => 'M'],
                ['code' => 'style_type', 'label' => '类型', 'value' => '套装（上衣+马面裙）'],
            ],
            $detail['specifications'],
        );
        self::assertSame(
            [
                [
                    'code' => 'color',
                    'label' => '颜色',
                    'value' => 'm-white',
                    'value_label' => '米白色',
                    'options' => [[
                        'value' => 'm-white',
                        'label' => '米白色',
                        'code' => 'm-white',
                        'swatch_image' => '/media/m-white-set.jpg',
                    ]],
                ],
                [
                    'code' => 'size',
                    'label' => '尺码',
                    'value' => 'm',
                    'value_label' => 'M',
                    'options' => [['value' => 'm', 'label' => 'M', 'code' => 'm']],
                ],
                [
                    'code' => 'style_type',
                    'label' => '类型',
                    'value' => 'set',
                    'value_label' => '套装（上衣+马面裙）',
                    'options' => [[
                        'value' => 'set',
                        'label' => '套装（上衣+马面裙）',
                        'code' => 'set',
                        'swatch_image' => '/media/m-white-set.jpg',
                    ]],
                ],
            ],
            $detail['variant_axes'],
        );
        self::assertSame(
            ['/media/m-white-set.jpg', '/media/base.jpg'],
            $detail['images'],
        );
        self::assertSame('/media/m-white-set.jpg', $detail['image']);
    }

    public function testEnglishLocaleKeepsHanCharacterAxisSwatchesFromVariantMedia(): void
    {
        $labels = $this->passthroughLabels();
        $option = static fn(
            int $id,
            string $code,
            string $label,
        ): \Weline\Eav\Api\Metadata\AttributeOptionMetadata => new \Weline\Eav\Api\Metadata\AttributeOptionMetadata(
            id: $id,
            value: $label,
            code: $code,
            label: $label,
            sortOrder: $id,
        );
        $attribute = static fn(
            int $id,
            string $code,
            string $name,
            \Weline\Eav\Api\Metadata\AttributeOptionMetadata $attributeOption,
        ): \Weline\Eav\Api\Metadata\AttributeMetadata => new \Weline\Eav\Api\Metadata\AttributeMetadata(
            id: $id,
            entityId: 1,
            code: $code,
            name: $name,
            typeCode: 'varchar',
            fieldType: 'multiselect',
            element: 'select',
            setId: 1,
            groupId: 1,
            required: false,
            multiple: true,
            enabled: true,
            hasOption: true,
            sortOrder: $id,
            options: [$attributeOption],
        );
        $set = new \Weline\Eav\Api\Metadata\AttributeSetMetadata(
            id: 1,
            entityId: 1,
            code: 'costume',
            name: 'Costume',
            sortOrder: 1,
            groups: [
                new \Weline\Eav\Api\Metadata\AttributeGroupMetadata(
                    id: 1,
                    entityId: 1,
                    setId: 1,
                    code: 'variants',
                    name: 'Variants',
                    sortOrder: 1,
                    attributes: [
                        $attribute(1, 'character', '角色', $option(1, '关羽', '关羽')),
                        $attribute(2, 'size', '尺码', $option(2, 'L', 'L')),
                    ],
                ),
            ],
        );
        $metadata = new class([$set]) implements \Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface {
            public function __construct(private readonly array $sets)
            {
            }

            public function catalog(\Weline\Eav\Api\Entity\EntityDefinitionInterface $entity): array
            {
                return $this->sets;
            }

            public function catalogForProduct(
                \Weline\Eav\Api\Entity\EntityDefinitionInterface $entity,
                int $productId,
                string $freeSetCode = '__product_free',
            ): array {
                return $this->sets;
            }

            public function attributeIndexByEntityCode(string $entityCode): array
            {
                return [];
            }
        };
        $entity = (new \ReflectionClass(\Weline\Product\Model\ProductCatalogAttributeEntity::class))
            ->newInstanceWithoutConstructor();
        $variantAxes = new \Weline\Product\Service\StorefrontVariantAxisResolver(
            $metadata,
            $entity,
            $labels,
        );
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(),
            $labels,
            $variantAxes,
        );

        $detail = $projector->project(
            [
                'product_id' => 125,
                'name' => 'Three Kingdoms Costume',
                'image' => '',
                'combination' => ['character' => '关羽', 'size' => 'L'],
                'combination_key' => 'character=%E5%85%B3%E7%BE%BD|size=L',
            ],
            [
                $this->attribute(0, 'name', 'en_US', 'Three Kingdoms Costume'),
                $this->attribute(0, 'character', '', ['关羽', '诸葛亮'], false, 'multiselect'),
                $this->attribute(0, 'size', '', ['L'], false, 'multiselect'),
            ],
            [
                [
                    'media_id' => 1,
                    'path' => '/media/guanyu.jpg',
                    'role' => 'variant',
                    'combination_key' => 'character=%E5%85%B3%E7%BE%BD|size=L',
                    'position' => 0,
                ],
                [
                    'media_id' => 2,
                    'path' => '/media/zhugeliang.jpg',
                    'role' => 'variant',
                    'combination_key' => 'character=%E8%AF%B8%E8%91%9B%E4%BA%AE|size=L',
                    'position' => 0,
                ],
            ],
            0,
            'en_US',
            ['zh_Hans_CN', ''],
        );

        $characterAxis = null;
        foreach ($detail['variant_axes'] as $axis) {
            if (($axis['code'] ?? '') === 'character') {
                $characterAxis = $axis;
                break;
            }
        }
        self::assertNotNull($characterAxis);
        $byValue = [];
        foreach ($characterAxis['options'] as $optionRow) {
            $byValue[(string)($optionRow['value'] ?? '')] = $optionRow;
        }
        self::assertSame('/media/guanyu.jpg', $byValue['关羽']['swatch_image'] ?? null);
        self::assertSame('/media/zhugeliang.jpg', $byValue['诸葛亮']['swatch_image'] ?? null);
    }

    public function testItKeepsDefaultChineseSpecificationValuesOnEnglishLocale(): void
    {
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct()
            {
            }

            public function resolve(string $attributeCode, string $value): string
            {
                // Simulate EAV Option LocalDescription: en for color, default/zh for others.
                return match (strtolower(trim($attributeCode))) {
                    'color' => $value === 'pink' ? 'Pink' : $value,
                    'fabric_name' => $value === 'polyester' ? '涤纶' : $value,
                    'craft' => $value === 'emboss' ? '压花' : $value,
                    default => $value,
                };
            }

            public function attributeLabel(string $attributeCode): string
            {
                return match (strtolower(trim($attributeCode))) {
                    'color' => 'Color',
                    'fabric_name' => '织物名称',
                    'craft' => '工艺',
                    default => '',
                };
            }
        };
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(),
            $labels,
        );

        $detail = $projector->project(
            ['product_id' => 91, 'name' => 'Sample Skirt', 'image' => ''],
            [
                $this->attribute(0, 'name', 'en_US', 'Sample Skirt'),
                $this->attribute(0, 'color', '', 'pink'),
                $this->attribute(0, 'fabric_name', '', 'polyester'),
                $this->attribute(0, 'craft', '', 'emboss'),
            ],
            [],
            0,
            'en_US',
        );

        self::assertSame(
            [
                ['code' => 'color', 'label' => 'Color', 'value' => 'Pink'],
                ['code' => 'craft', 'label' => '工艺', 'value' => '压花'],
                ['code' => 'fabric_name', 'label' => '织物名称', 'value' => '涤纶'],
            ],
            $detail['specifications'],
        );
    }

    public function testMetadataPrefetchFailureKeepsLabelFallbackAndRecordsPhaseError(): void
    {
        $previousTrace = \Weline\Framework\App\Env::get('wls.debug.request_trace', false);
        \Weline\Framework\App\Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace' => true]]]);
        \Weline\Framework\Runtime\Runtime::setMode(\Weline\Framework\Runtime\RuntimeInterface::MODE_WLS);
        if (\Weline\Framework\Context::hasCurrent()) {
            \Weline\Framework\Context::leave();
        }
        \Weline\Framework\Context::enter(new \Weline\Framework\Context([
            'input' => ['uri' => '/products'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => uniqid('eav-prefetch-failure-', true)]],
        ]));
        \Weline\Framework\Runtime\RequestContext::setWelineUserLang('en_US');
        try {
            $metadata = new class implements
                \Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface,
                \Weline\Eav\Api\Metadata\AttributeMetadataPrefetchInterface {
                public bool $recovered = false;
                public int $prefetchCalls = 0;
                public int $catalogCalls = 0;
                public \RuntimeException $failure;

                public function __construct()
                {
                    $this->failure = new \RuntimeException('metadata fixture unavailable');
                }

                public function prefetchForProducts(
                    \Weline\Eav\Api\Entity\EntityDefinitionInterface $entity,
                    array $productIds,
                    string $freeSetCode = '__product_free',
                ): void {
                    ++$this->prefetchCalls;
                    if (!$this->recovered) {
                        throw $this->failure;
                    }
                }

                public function catalog(\Weline\Eav\Api\Entity\EntityDefinitionInterface $entity): array
                {
                    ++$this->catalogCalls;
                    if (!$this->recovered) {
                        throw $this->failure;
                    }
                    return [new \Weline\Eav\Api\Metadata\AttributeSetMetadata(10, 4, 'base', 'Base', 10, [
                        new \Weline\Eav\Api\Metadata\AttributeGroupMetadata(20, 4, 10, 'details', 'Details', 20, [
                            new \Weline\Eav\Api\Metadata\AttributeMetadata(30, 4, 'color', 'Paint color', 'varchar', 'varchar', 'select', 10, 20, false, false, true, true, 30, [
                                new \Weline\Eav\Api\Metadata\AttributeOptionMetadata(98765, 'Red', 'red', 'English red', 98765),
                            ]),
                        ]),
                    ])];
                }

                public function catalogForProduct(
                    \Weline\Eav\Api\Entity\EntityDefinitionInterface $entity,
                    int $productId,
                    string $freeSetCode = '__product_free',
                ): array {
                    return $this->catalog($entity);
                }

                public function attributeIndexByEntityCode(string $entityCode): array
                {
                    return [];
                }
            };
            $entity = (new \ReflectionClass(\Weline\Product\Model\ProductCatalogAttributeEntity::class))
                ->newInstanceWithoutConstructor();
            // Numeric option identity takes the injected store path; no ORM or
            // business database is accessed when the ordinary fallback runs.
            $optionStore = $this->createMock(\Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface::class);
            $optionStore->method('assertUsableByInstance')->willThrowException($metadata->failure);
            $labels = new StorefrontEavLabelResolver($metadata, $entity, $optionStore);
            $offer = ['product_id' => 101, 'name' => 'Sample', 'image' => ''];
            $attributes = [$this->attribute(0, 'color', '', '98765')];
            $baseline = $this->projector($labels)->projectListing($offer, $attributes, 0, 'en_US');
            self::assertSame([['code' => 'color', 'label' => '', 'value' => '98765']], $baseline['specifications']);
            self::assertSame(2, $metadata->catalogCalls);

            $projector = $this->projector($labels);
            $projector->prefetchForProducts([101, 202]);
            self::assertSame(1, $metadata->prefetchCalls);
            $phase = \Weline\Framework\Runtime\RequestLifecycleTrace::getAggregateSummary()['phases']['product.catalog.metadata_prefetch'];
            self::assertSame(1, $phase['calls']);
            self::assertSame(1, $phase['errors']);
            self::assertSame(2, $phase['meta']['products']);
            self::assertSame($baseline, $projector->projectListing($offer, $attributes, 0, 'en_US'));

            $metadata->recovered = true;
            $projector->prefetchForProducts([101, 202]);
            self::assertSame(2, $metadata->prefetchCalls, 'Failure must not be memoized as successful empty preload.');
            $phase = \Weline\Framework\Runtime\RequestLifecycleTrace::getAggregateSummary()['phases']['product.catalog.metadata_prefetch'];
            self::assertSame(2, $phase['calls']);
            self::assertSame(1, $phase['errors']);
            $freshProduct = $projector->projectListing(array_replace($offer, ['product_id' => 202]), $attributes, 0, 'en_US');
            self::assertSame([['code' => 'color', 'label' => 'Paint color', 'value' => 'English red']], $freshProduct['specifications']);
        } finally {
            \Weline\Framework\Runtime\RequestLifecycleTrace::reset();
            \Weline\Framework\Context::leave();
            \Weline\Framework\Runtime\Runtime::resetModeCache();
            \Weline\Framework\App\Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace' => $previousTrace]]]);
        }
    }

    /** @return array<string, mixed> */
    private function attribute(
        int $storeId,
        string $code,
        string $locale,
        mixed $value,
        bool $cleared = false,
        string $valueType = 'string',
    ): array {
        return [
            'store_id' => $storeId,
            'entity_type' => 'product',
            'entity_id' => 9,
            'attribute_code' => $code,
            'locale' => $locale,
            'value_type' => $valueType,
            'value' => $value,
            'cleared' => $cleared,
            'is_required' => false,
        ];
    }
    private function projector(?StorefrontEavLabelResolver $labels = null): StorefrontProductDetailProjector
    {
        return new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(),
            $labels ?? $this->passthroughLabels(),
        );
    }

    private function passthroughLabels(): StorefrontEavLabelResolver
    {
        return new class extends StorefrontEavLabelResolver {
            public function __construct()
            {
            }

            public function resolve(string $attributeCode, string $value): string
            {
                return $value;
            }

            public function attributeLabel(string $attributeCode): string
            {
                return '';
            }
        };
    }
}
