<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\OfferEavMapper;

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
            '/^MENGHUIHANTANG-21CAF39F-S[A-F0-9]{4}-T[A-F0-9]{4}$/D',
            $sku,
        );
    }

    public function testRedistributesMisfiledColorClassificationAxis(): void
    {
        $mapper = new OfferEavMapper();
        $catalog = $mapper->catalog([
            'specifications' => [
                '颜色分类' => ['关羽', '图一', '粉色上衣＋白色裤子套装2307', '米白色'],
                '尺码' => ['S'],
            ],
            'variants' => [],
        ], 'HANFU');

        $byCode = [];
        foreach ($catalog['definitions'] as $definition) {
            $byCode[(string)$definition['code']] = $definition;
        }
        self::assertArrayHasKey('character', $byCode);
        self::assertArrayHasKey('look_ref', $byCode);
        self::assertArrayHasKey('style_type', $byCode);
        self::assertArrayHasKey('color', $byCode);
        self::assertSame(['关羽'], array_column($byCode['character']['options'], 'label'));
        self::assertSame(['图一'], array_column($byCode['look_ref']['options'], 'label'));
        self::assertContains('粉色上衣＋白色裤子套装2307', array_column($byCode['style_type']['options'], 'label'));
        self::assertSame(['米白色'], array_column($byCode['color']['options'], 'label'));
    }

    public function testKeepsColorAndStyleTypeAxesWhenSkuLabelsAreMutuallyExclusive(): void
    {
        $mapper = new OfferEavMapper();
        $catalog = $mapper->catalog([
            'specifications' => [
                '颜色' => ['蓝色印花', '肉粉上衣粉裙子', '广袖蓝裙子'],
                '尺码' => ['S', 'M'],
            ],
            'variants' => [
                [
                    'specification' => '蓝色印花>S',
                    'price' => '55.00',
                    'public_available_quantity' => 3,
                    'image_url' => 'https://example.test/blue.jpg',
                ],
                [
                    'specification' => '肉粉上衣粉裙子>M',
                    'price' => '66.00',
                    'public_available_quantity' => 2,
                    'image_url' => 'https://example.test/pink.jpg',
                ],
                [
                    'specification' => '广袖蓝裙子>S',
                    'price' => '70.00',
                    'public_available_quantity' => 1,
                ],
            ],
        ], 'XINYAO-MIX');

        $axisCodes = array_column($catalog['axes'], 'code');
        sort($axisCodes);
        // Mutually exclusive redistributed labels collapse to one variant axis.
        self::assertSame(['size', 'style_type'], $axisCodes);
        $styleAxis = null;
        foreach ($catalog['axes'] as $axis) {
            if (($axis['code'] ?? '') === 'style_type') {
                $styleAxis = $axis;
                break;
            }
        }
        self::assertNotNull($styleAxis);
        self::assertSame('颜色/类型', $styleAxis['label']);
        $labels = array_column($styleAxis['options'], 'label');
        self::assertContains('蓝色印花', $labels);
        self::assertContains('肉粉上衣粉裙子', $labels);
        self::assertContains('广袖蓝裙子', $labels);

        $keys = array_keys($catalog['sku_overrides']);
        self::assertCount(3, $keys);
        foreach ($keys as $key) {
            self::assertStringContainsString('size=', $key);
            self::assertStringContainsString('style_type=', $key);
            self::assertStringNotContainsString('color=', $key);
        }
        self::assertFalse(!empty($catalog['sparse_matrix']));
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
