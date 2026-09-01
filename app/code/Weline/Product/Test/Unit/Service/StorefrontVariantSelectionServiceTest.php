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
