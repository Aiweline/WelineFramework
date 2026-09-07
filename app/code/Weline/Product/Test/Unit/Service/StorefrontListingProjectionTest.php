<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontProductDetailProjector;
use Weline\Product\Service\StorefrontVariantAxisResolver;

final class StorefrontListingProjectionTest extends TestCase
{
    public function testListingPreservesCardAndFilterFactsWithoutLoadingVariantChoices(): void
    {
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->expects(self::never())->method('catalog');
        $metadata->expects(self::never())->method('catalogForProduct');
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct() {}
            public function resolve(string $attributeCode, string $value): string { return $value; }
            public function attributeLabel(string $attributeCode): string { return strtoupper($attributeCode); }
        };
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(), $labels, new StorefrontVariantAxisResolver($metadata, $entity, $labels),
        );
        $offer = ['product_id' => 9, 'sku' => 'HANFU-9', 'image' => '/primary.jpg',
            'unit_price_minor' => 15900, 'currency' => 'CNY', 'sellable' => true,
            'global_offer_uuid' => 'offer-9', 'combination_key' => 'size=M'];
        $rows = [
            $this->row('name', '', '网站商品'), $this->row('name', 'en_US', 'Website name'),
            $this->row('name', 'en_US', 'Store name', 3),
            $this->row('short_description', 'en_US', 'Summary'),
            $this->row('description', 'en_US', 'Searchable product description'),
            $this->row('source_slug', '', 'hanfu-nine'), $this->row('brand', 'en_US', 'Brand'),
            $this->row('quote_only', '', '1'), $this->row('fabric', 'en_US', 'Silk'),
            $this->row('size', '', ['S', 'M', 'L']),
        ];
        $listing = $projector->projectListing($offer, $rows, 3, 'en_US');
        self::assertSame('Store name', $listing['name']);
        self::assertSame('hanfu-nine', $listing['slug']);
        self::assertSame('Summary', $listing['short_description']);
        self::assertSame('Searchable product description', $listing['description']);
        self::assertSame('Brand', $listing['brand']);
        self::assertTrue($listing['quote_only']);
        self::assertSame(['size' => 'M'], $listing['combination']);
        self::assertSame([
            ['code' => 'fabric', 'label' => 'FABRIC', 'value' => 'Silk'],
            ['code' => 'size', 'label' => 'SIZE', 'value' => 'M'],
        ], $listing['specifications']);
        self::assertSame([], $listing['variant_axes']);
        self::assertSame(['/primary.jpg'], $listing['images']);
        foreach ($offer as $key => $value) { self::assertSame($value, $listing[$key]); }

        $otherStore = $projector->projectListing($offer, $rows, 8, 'en_US');
        self::assertSame('Website name', $otherStore['name']);

        $facets = new \Weline\Filters\Service\StorefrontAttributeListingFilter();
        self::assertSame([$listing], $facets->apply([$listing], ['fabric' => 'Silk', 'brand' => 'Brand']));
        self::assertSame([], $facets->apply([$listing], ['fabric' => 'Cotton']));
        self::assertSame(['Silk' => 1], $facets->countOfferAttributeValues([$listing], ['fabric' => 'Fabric'])['fabric']['counts']);
        $prices = new \Weline\Product\Service\StorefrontCategoryListingFilter();
        $priced = array_replace($listing, ['quote_only' => false]);
        $cheaper = array_replace($priced, ['product_id' => 10, 'unit_price_minor' => 12000]);
        $filtered = $prices->apply([$priced, $cheaper, $listing], '100-299', 'price_asc');
        self::assertSame([10, 9], array_column($filtered, 'product_id'));
        self::assertSame([9], array_column($prices->paginate($filtered, 2, 1)['items'], 'product_id'));
    }

    public function testListingUsesExistingLocaleFallbackAndClearedValueRules(): void
    {
        $projector = new StorefrontProductDetailProjector();
        $rows = [
            $this->row('name', 'zh_Hans_CN', '中文商品'),
            $this->row('name', 'en_US', 'English product'),
            $this->row('short_description', '', 'Website summary'),
            $this->row('short_description', 'fr_FR', null, 3, true),
        ];
        $result = $projector->projectListing(['sku' => 'SKU-9'], $rows, 3, 'fr_FR', ['en_US', 'zh_Hans_CN', '']);
        self::assertSame('English product', $result['name']);
        self::assertSame('', $result['short_description']);
        self::assertSame([], $result['specifications']);
        self::assertSame([], $result['variant_axes']);
    }

    public function testListingSummaryKeepsCardFactsWithoutResolvingEavMetadata(): void
    {
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->expects(self::never())->method('catalog');
        $metadata->expects(self::never())->method('catalogForProduct');
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct() {}
            public function resolve(string $attributeCode, string $value): string { return $value; }
            public function attributeLabel(string $attributeCode): string { return strtoupper($attributeCode); }
        };
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(), $labels, new StorefrontVariantAxisResolver($metadata, $entity, $labels),
        );
        $offer = [
            'product_id' => 9,
            'name' => 'Website name',
            'sku' => 'HANFU-9',
            'slug' => 'hanfu-nine',
            'image' => '/primary.jpg',
            'unit_price_minor' => 15900,
            'currency' => 'CNY',
            'sellable' => true,
            'quote_only' => false,
            'global_offer_uuid' => 'offer-9',
            'combination_key' => 'size=M',
        ];

        $summary = $projector->projectListingSummary(
            $offer,
            [
                $this->row('name', '', '网站商品'),
                $this->row('name', 'en_US', 'Localized name'),
                $this->row('source_slug', '', 'hanfu-nine'),
                $this->row('fabric', 'en_US', 'Silk'),
            ],
            3,
            'en_US',
            ['zh_Hans_CN', ''],
        );

        self::assertSame('Localized name', $summary['name']);
        self::assertSame('hanfu-nine', $summary['slug']);
        self::assertSame('HANFU-9', $summary['sku']);
        self::assertSame(['/primary.jpg'], $summary['images']);
        self::assertSame(['size' => 'M'], $summary['combination']);
        self::assertSame([], $summary['specifications']);
        self::assertSame([], $summary['variant_axes']);
    }

    public function testListingSummaryFastPathUsesSnapshotFactsWhenNoEavRowsExist(): void
    {
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->expects(self::never())->method('catalog');
        $metadata->expects(self::never())->method('catalogForProduct');
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct() {}
            public function resolve(string $attributeCode, string $value): string { return $value; }
            public function attributeLabel(string $attributeCode): string { return strtoupper($attributeCode); }
        };
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(), $labels, new StorefrontVariantAxisResolver($metadata, $entity, $labels),
        );
        $offer = [
            'product_id' => 12,
            'name' => 'Snapshot card',
            'sku' => 'SKU-12',
            'slug' => 'snapshot-card',
            'image' => '/snapshot.jpg',
            'combination_key' => 'size=L',
            'quote_only' => false,
        ];

        $summary = $projector->projectListingSummary($offer, [], 3, 'en_US');

        self::assertSame('Snapshot card', $summary['name']);
        self::assertSame('snapshot-card', $summary['slug']);
        self::assertSame('SKU-12', $summary['sku']);
        self::assertSame(['/snapshot.jpg'], $summary['images']);
        self::assertSame(['size' => 'L'], $summary['combination']);
        self::assertSame('size=L', $summary['combination_key']);
        self::assertSame([], $summary['specifications']);
        self::assertSame([], $summary['variant_axes']);
    }

    public function testProjectManyPreservesPerOfferImagesWhileSharingProductContext(): void
    {
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->expects(self::never())->method('catalog');
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct() {}
            public function resolve(string $attributeCode, string $value): string { return $value; }
            public function attributeLabel(string $attributeCode): string { return strtoupper($attributeCode); }
        };
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(), $labels, new StorefrontVariantAxisResolver($metadata, $entity, $labels),
        );
        $offers = [
            [
                'product_id' => 9,
                'sku' => 'SKU-RED',
                'name' => 'Product',
                'slug' => 'product',
                'image' => '/red.jpg',
                'combination_key' => 'color=red',
            ],
            [
                'product_id' => 9,
                'sku' => 'SKU-BLUE',
                'name' => 'Product',
                'slug' => 'product',
                'image' => '/blue.jpg',
                'combination_key' => 'color=blue',
            ],
        ];

        $projected = $projector->projectMany($offers, [], [], 3, 'en_US');

        self::assertCount(2, $projected);
        self::assertSame(['/red.jpg'], $projected[0]['images']);
        self::assertSame(['/blue.jpg'], $projected[1]['images']);
        self::assertSame('color=red', $projected[0]['combination_key']);
        self::assertSame('color=blue', $projected[1]['combination_key']);
    }

    public function testProjectManyReusesBaseSpecificationsAndKeepsCombinationValuesPerOffer(): void
    {
        $metadata = $this->createMock(AttributeMetadataCatalogInterface::class);
        $metadata->expects(self::never())->method('catalog');
        $labels = new class extends StorefrontEavLabelResolver {
            public function __construct() {}
            public function resolve(string $attributeCode, string $value): string
            {
                return strtoupper($attributeCode) . ':' . $value;
            }
            public function attributeLabel(string $attributeCode): string
            {
                return 'label-' . strtolower($attributeCode);
            }
        };
        $entity = (new \ReflectionClass(ProductCatalogAttributeEntity::class))->newInstanceWithoutConstructor();
        $projector = new StorefrontProductDetailProjector(
            new CatalogOverlayResolver(), $labels, new StorefrontVariantAxisResolver($metadata, $entity, $labels),
        );
        $offers = [
            ['product_id' => 9, 'name' => 'Product', 'combination_key' => 'color=red'],
            ['product_id' => 9, 'name' => 'Product', 'combination_key' => 'color=blue'],
        ];
        $rows = [
            array_replace($this->row('material', 'en_US', 'silk'), ['entity_type' => 'offer']),
            array_replace($this->row('color', 'en_US', 'default'), ['entity_type' => 'offer']),
        ];

        $individual = array_map(
            fn(array $offer): array => $projector->project($offer, $rows, [], 3, 'en_US'),
            $offers,
        );
        $bulk = $projector->projectMany($offers, $rows, [], 3, 'en_US');

        self::assertSame(
            array_column($individual, 'specifications'),
            array_column($bulk, 'specifications'),
        );
        self::assertSame('COLOR:red', $bulk[0]['specifications'][0]['value']);
        self::assertSame('COLOR:blue', $bulk[1]['specifications'][0]['value']);
    }

    private function row(string $code, string $locale, mixed $value, int $store = 0, bool $cleared = false): array
    {
        return ['entity_type' => 'product', 'entity_id' => 9, 'attribute_code' => $code,
            'store_id' => $store, 'locale' => $locale, 'value' => $value,
            'value_type' => is_array($value) ? 'multiselect' : 'string', 'cleared' => $cleared];
    }
}
