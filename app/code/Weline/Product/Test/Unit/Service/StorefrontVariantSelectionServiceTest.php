<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontVariantSelectionService;

final class StorefrontVariantSelectionServiceTest extends TestCase
{
    private StorefrontVariantSelectionService $service;

    protected function setUp(): void
    {
        $this->service = new StorefrontVariantSelectionService();
    }

    public function testItParsesCanonicalCombinationKeys(): void
    {
        self::assertSame(
            ['color' => 'm-white', 'size' => 'm', 'style_type' => 'set'],
            $this->service->parseCombinationKey('color=m-white|size=m|style_type=set'),
        );
    }

    public function testItMatchesExactOfferFromAxisSelection(): void
    {
        $offers = [
            $this->offer('offer-a', 'color=m-white|size=m|style_type=set'),
            $this->offer('offer-b', 'color=red|size=m|style_type=set'),
        ];

        $matched = $this->service->matchOffer($offers, [
            'color' => 'm-white',
            'size' => 'm',
            'style_type' => 'set',
        ], true);

        self::assertSame('offer-a', $matched['global_offer_uuid'] ?? null);
    }

    public function testItResolvesSelectedOfferFromAxisQueryParams(): void
    {
        $offers = [
            $this->offer('offer-a', 'color=m-white|size=m|style_type=set'),
            $this->offer('offer-b', 'color=red|size=m|style_type=set'),
        ];

        $selected = $this->service->resolveSelectedOffer($offers, [
            'color' => 'm-white',
            'size' => 'm',
            'style_type' => 'set',
        ]);

        self::assertSame('offer-a', $selected['global_offer_uuid'] ?? null);
    }

    public function testItBuildsInteractiveVariantCatalog(): void
    {
        $offers = [
            array_merge(
                $this->offer('offer-a', 'color=m-white|size=m'),
                [
                    'variant_axes' => [[
                        'code' => 'color',
                        'label' => '颜色',
                        'options' => [
                            ['value' => 'm-white', 'label' => '米白色'],
                            ['value' => 'red', 'label' => '红色', 'swatch_color' => '#f4b4c4', 'swatch_image' => '/media/pink.jpg'],
                        ],
                    ], [
                        'code' => 'size',
                        'label' => '尺码',
                        'options' => [
                            ['value' => 'm', 'label' => 'M'],
                        ],
                    ]],
                    'images' => ['/media/a-1.jpg', '/media/a-2.jpg'],
                    'image' => '/media/a-1.jpg',
                    'catalog_price_minor' => 12000,
                    'compare_at_minor' => 12000,
                    'unit_price_minor' => 10800,
                    'has_deal' => true,
                ],
            ),
        ];

        $catalog = $this->service->buildCatalog($offers, $offers[0]);

        self::assertSame(
            ['color' => 'm-white', 'size' => 'm'],
            $catalog['selected'],
        );
        self::assertCount(2, $catalog['axes']);
        self::assertSame('#f4b4c4', $catalog['axes'][0]['options'][1]['swatch_color'] ?? null);
        self::assertSame('/media/pink.jpg', $catalog['axes'][0]['options'][1]['swatch_image'] ?? null);
        self::assertSame(['/media/a-1.jpg', '/media/a-2.jpg'], $catalog['offers'][0]['images']);
        self::assertSame(12000, $catalog['offers'][0]['catalog_price_minor'] ?? null);
        self::assertSame(10800, $catalog['offers'][0]['unit_price_minor'] ?? null);
        self::assertSame(12000, $catalog['offers'][0]['compare_at_minor'] ?? null);
        self::assertTrue((bool)($catalog['offers'][0]['has_deal'] ?? false));
    }

    public function testBuildCatalogOmitsOrphanAxesFromVariantAxesMetadata(): void
    {
        $offers = [
            array_merge(
                $this->offer('offer-a', 'color=粉色|size=s'),
                [
                    'variant_axes' => [[
                        'code' => 'color',
                        'label' => '颜色',
                        'options' => [
                            ['value' => '粉色', 'label' => '粉色'],
                            ['value' => '绿色', 'label' => '绿色'],
                        ],
                    ], [
                        'code' => 'hanfu_liu_xing_yuan_su',
                        'label' => '流行元素',
                        'options' => [
                            ['value' => '219', 'label' => '勾花'],
                        ],
                    ], [
                        'code' => 'size',
                        'label' => '尺码',
                        'options' => [
                            ['value' => 's', 'label' => 'S'],
                        ],
                    ]],
                ],
            ),
        ];

        $catalog = $this->service->buildCatalog($offers, $offers[0]);
        $axisCodes = array_map(
            static fn(array $axis): string => (string)($axis['code'] ?? ''),
            $catalog['axes'],
        );

        self::assertSame(['color', 'size'], $axisCodes);
        self::assertSame(['color', 'size'], $this->service->collectAxisCodes($offers));
        self::assertSame(['color' => '粉色', 'size' => 's'], $catalog['selected']);
        self::assertArrayNotHasKey('hanfu_liu_xing_yuan_su', $catalog['offers'][0]['combination']);
    }

    public function testItCompactsRepeatedOfferGalleriesWithoutLosingVariantImages(): void
    {
        $catalog = [
            'axes' => [['code' => 'color', 'label' => '颜色', 'options' => []]],
            'offers' => [[
                'global_offer_uuid' => 'offer-white',
                'image' => '/media/white.jpg',
                'images' => ['/media/white.jpg', '/media/base-1.jpg', '/media/base-2.jpg'],
            ], [
                'global_offer_uuid' => 'offer-red',
                'image' => '/media/red.jpg',
                'images' => ['/media/red.jpg', '/media/base-1.jpg', '/media/base-2.jpg'],
            ]],
            'selected' => ['color' => 'white'],
        ];

        $compacted = $this->service->compactCatalogMedia($catalog);

        self::assertSame(['/media/base-1.jpg', '/media/base-2.jpg'], $compacted['base_images']);
        self::assertSame([], $compacted['offers'][0]['images']);
        self::assertSame([], $compacted['offers'][1]['images']);
        self::assertSame('/media/white.jpg', $compacted['offers'][0]['image']);
        self::assertSame('/media/red.jpg', $compacted['offers'][1]['image']);
    }

    public function testItDoesNotTreatSharedVariantPrimaryImagesAsBaseGalleryMedia(): void
    {
        $catalog = [
            'offers' => [[
                'image' => '/media/white.jpg',
                'images' => ['/media/white.jpg', '/media/red.jpg', '/media/base.jpg'],
            ], [
                'image' => '/media/red.jpg',
                'images' => ['/media/white.jpg', '/media/red.jpg', '/media/base.jpg'],
            ]],
        ];

        $compacted = $this->service->compactCatalogMedia($catalog);

        self::assertSame(['/media/base.jpg'], $compacted['base_images']);
        self::assertSame([], $compacted['offers'][0]['images']);
        self::assertSame([], $compacted['offers'][1]['images']);
        self::assertSame('/media/white.jpg', $compacted['offers'][0]['image']);
        self::assertSame('/media/red.jpg', $compacted['offers'][1]['image']);
    }

    /** @return array<string, mixed> */
    private function offer(string $uuid, string $combinationKey): array
    {
        return [
            'global_offer_uuid' => $uuid,
            'combination_key' => $combinationKey,
            'sku' => strtoupper($uuid),
            'sellable' => true,
        ];
    }
}
