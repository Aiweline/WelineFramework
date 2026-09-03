<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\Hanfu1688\OfferEavMapper;

final class OfferEavMapperTest extends TestCase
{
    public function testMapsPublicSourceSpecificationsAndVariantsToPlainEavRows(): void
    {
        $offer = [
            'offer_id' => '604560496347',
            'detail_url' => 'https://detail.1688.com/offer/604560496347.html',
            'minimum_order_quantity' => 2,
            'specifications' => [
                '颜色' => ['红色', '青色'],
                '尺码' => ['S', 'M'],
                '适用性别' => ['女'],
            ],
            'variants' => [
                [
                    'source_sku_id' => '9001',
                    'specification' => '红色&gt;S',
                    'price' => '88.00',
                    'public_available_quantity' => 12,
                ],
            ],
        ];
        $mapper = new OfferEavMapper();
        $catalog = $mapper->catalog($offer, 'ZHIZAO-HANFU');
        $rows = $mapper->map(
            $offer,
            [
                'shop_url' => 'https://shop123.1688.com',
                'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
                'company_name' => '曹县汉服厂',
            ],
            str_repeat('a', 64),
            $catalog,
        );

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['attribute_code']] = $row;
            self::assertSame('explicit', $row['scope_state']);
        }

        self::assertSame(['color', 'size'], array_column($catalog['axes'], 'code'));
        self::assertSame('string', $byCode['source_offer_id']['value_type']);
        self::assertSame('2', $byCode['source_minimum_order_quantity']['value']);
        self::assertSame('multiselect', $byCode['color']['value_type']);
        self::assertSame($catalog['product_values']['color'], $byCode['color']['value']);
        self::assertSame('multiselect', $byCode['size']['value_type']);
        self::assertSame($catalog['product_values']['size'], $byCode['size']['value']);
        self::assertCount(1, $catalog['sku_overrides']);
        self::assertCount(1, $catalog['prices']);
        self::assertCount(1, $catalog['inventory']);

        foreach ([
            'available_colors',
            'available_sizes',
            'source_public_specs',
            'source_variant_combinations',
            'type_configuration',
        ] as $legacyCode) {
            self::assertArrayNotHasKey($legacyCode, $byCode);
        }
    }

    public function testGeneratesCompactAxisAwareVariantSku(): void
    {
        $mapper = new OfferEavMapper();
        $catalog = $mapper->catalog([
            'specifications' => [
                '颜色分类' => ['粉色上衣+白色裤子套装2307'],
                '尺码' => ['XXXL130-140斤'],
            ],
            'variants' => [[
                'specification' => '粉色上衣+白色裤子套装2307;XXXL130-140斤',
            ]],
        ], 'MENGHUIHANTANG-21CAF39F');

        $sku = (string)(array_values($catalog['sku_overrides'])[0] ?? '');
        self::assertLessThanOrEqual(36, strlen($sku), $sku);
        self::assertMatchesRegularExpression(
            '/^MENGHUIHANTANG-21CAF39F-C[A-F0-9]{4}-S[A-F0-9]{4}$/D',
            $sku,
        );
    }

    public function testDoesNotInferColorOrSizeFromUnrelatedAxes(): void
    {
        $offer = [
            'offer_id' => '1',
            'source_url' => 'https://m.1688.com/offer/1.html',
            'specifications' => ['服装款式细节' => ['绣花']],
            'variants' => [],
        ];
        $mapper = new OfferEavMapper();
        $catalog = $mapper->catalog($offer, 'HANFU');
        $rows = $mapper->map(
            $offer,
            [
                'shop_url' => 'https://shop123.1688.com',
                'factory_url' => 'https://www.1688.com/factory/b2b-123.html',
                'company_name' => '厂家',
            ],
            str_repeat('b', 64),
            $catalog,
        );

        $codes = array_column($rows, 'attribute_code');
        self::assertNotContains('color', $codes);
        self::assertNotContains('size', $codes);
        self::assertNotContains('available_colors', $codes);
        self::assertNotContains('available_sizes', $codes);
        self::assertNotContains('source_public_specs', $codes);
        self::assertNotContains('source_variant_combinations', $codes);
    }
}
