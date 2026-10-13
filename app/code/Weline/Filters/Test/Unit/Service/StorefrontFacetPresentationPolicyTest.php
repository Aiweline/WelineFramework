<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Filters\Service\StorefrontFacetPresentationPolicy;

final class StorefrontFacetPresentationPolicyTest extends TestCase
{
    public function testCuratesShopperFacetsInStableOrderAndRemovesBackOfficeFields(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $names = [
            'hanfu_li_liao_cheng_fen' => '里料成分',
            'hanfu_aql_chou_jian_biao_zhun' => 'AQL 抽检标准',
            'hanfu_zhi_wu' => '面料',
            'source_offer_id' => '1688 Offer ID',
            'color' => '颜色',
            'brand' => '品牌',
            'hanfu_xing_zhi' => '形制',
            'hanfu_chao_dai' => '朝代',
        ];
        $counts = [];
        foreach ($names as $code => $name) {
            $counts[$code] = ['示例' => 3];
        }

        $groups = $policy->curateFacetCounts($counts, $names);

        self::assertSame(
            ['hanfu_chao_dai', 'hanfu_xing_zhi', 'color', 'hanfu_zhi_wu', 'brand'],
            array_keys($groups),
        );
        self::assertArrayNotHasKey('source_offer_id', $groups);
        self::assertArrayNotHasKey('hanfu_aql_chou_jian_biao_zhun', $groups);
        self::assertArrayNotHasKey('hanfu_li_liao_cheng_fen', $groups);
    }

    public function testKeepsSelectedZeroCountAndDropsUnselectedZeros(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $names = ['hanfu_chao_dai' => '朝代'];
        $counts = [
            'hanfu_chao_dai' => [
                '唐制' => 0,
                '明制' => 2,
                '清制' => 0,
            ],
        ];

        $groups = $policy->curateFacetCounts($counts, $names, ['hanfu_chao_dai' => '唐制']);

        self::assertArrayHasKey('hanfu_chao_dai', $groups);
        self::assertSame(0, $groups['hanfu_chao_dai']['counts']['唐制']);
        self::assertSame(2, $groups['hanfu_chao_dai']['counts']['明制']);
        self::assertArrayNotHasKey('清制', $groups['hanfu_chao_dai']['counts']);
    }

    public function testUsesPopulatedAliasAndKeepsSelectedAliasVisible(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $names = [
            'brand' => '品牌',
            'hanfu_pin_pai' => '品牌',
            'hanfu_shi_yong_ji_jie' => '适用季节',
            'hanfu_shi_he_ji_jie' => '适合季节',
        ];

        $groups = $policy->curateFacetCounts(
            [
                'hanfu_pin_pai' => ['其他品牌' => 4],
                'hanfu_shi_yong_ji_jie' => ['春季' => 5],
                'hanfu_shi_he_ji_jie' => ['秋季' => 1],
            ],
            $names,
            ['hanfu_shi_he_ji_jie' => '秋季'],
        );

        self::assertSame(['hanfu_shi_he_ji_jie', 'hanfu_pin_pai'], array_keys($groups));
        self::assertSame(['秋季' => 1], $groups['hanfu_shi_he_ji_jie']['counts']);
    }

    public function testCandidateMetadataSkipsNonMerchandisingAttributesBeforeOfferCounting(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();

        $candidates = $policy->candidateCodeNames([
            'hanfu_chao_dai' => '朝代',
            'hanfu_shi_fou_kua_jing_chu_kou_zhuan_gong_huo_yuan' => '跨境出口专供货源',
            'hanfu_li_liao_cheng_fen_han_liang' => '里料成分含量',
            'source_snapshot_digest' => 'Source Snapshot Digest',
        ]);

        self::assertSame(['hanfu_chao_dai' => '朝代'], $candidates);
    }

    public function testColorFamilyStripsApparelSizeTokensButKeepsRealColors(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $groups = $policy->curateFacetCounts(
            [
                'color' => [
                    'L' => 9,
                    'M' => 8,
                    'S' => 7,
                    'XL' => 6,
                    '红色' => 4,
                    '白色' => 3,
                    '【大明贵女】白' => 2,
                ],
            ],
            ['color' => '颜色'],
        );

        self::assertArrayHasKey('color', $groups);
        $counts = $groups['color']['counts'];
        self::assertArrayHasKey('红色', $counts);
        self::assertArrayHasKey('白色', $counts);
        self::assertArrayNotHasKey('L', $counts);
        self::assertArrayNotHasKey('M', $counts);
        self::assertArrayNotHasKey('S', $counts);
        self::assertArrayNotHasKey('XL', $counts);
        self::assertTrue($policy->isColorSizeToken('xxl'));
        self::assertTrue($policy->isColorSizeToken('36'));
        self::assertFalse($policy->isColorSizeToken('红色'));
    }

    public function testColorFamilyKeepsSelectedSizeTokenWhenCurrentlySelected(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $groups = $policy->curateFacetCounts(
            [
                'color' => [
                    'L' => 0,
                    '红色' => 4,
                ],
            ],
            ['color' => '颜色'],
            ['color' => 'L'],
        );

        self::assertSame(0, $groups['color']['counts']['L']);
        self::assertSame(4, $groups['color']['counts']['红色']);
    }

    public function testCompareFacetOptionsOrdersSelectedThenCountDescThenLabel(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $options = [
            ['label' => '白色', 'value' => '白色', 'count' => 3, 'selected' => false],
            ['label' => 'L', 'value' => 'L', 'count' => 9, 'selected' => false],
            ['label' => '红色', 'value' => '红色', 'count' => 4, 'selected' => true],
            ['label' => '蓝色', 'value' => '蓝色', 'count' => 4, 'selected' => false],
        ];
        usort($options, [$policy, 'compareFacetOptions']);

        // selected 红色 → then L(9) → 蓝色(4) → 白色(3)
        self::assertSame(['红色', 'L', '蓝色', '白色'], array_column($options, 'label'));
    }

    /**
     * Static assert for UC-C1: after strip + sort, primary 6 must prefer real colors
     * (红色/白色) over apparel size junk — mirrors category-filters visibleOptionLimit.
     */
    public function testColorPrimarySlotsPreferHighCountColorsNotSizeJunk(): void
    {
        $policy = new StorefrontFacetPresentationPolicy();
        $groups = $policy->curateFacetCounts(
            [
                'color' => [
                    'L' => 9,
                    'M' => 8,
                    'S' => 7,
                    'XL' => 6,
                    'XXL' => 5,
                    '【大明贵女】白' => 2,
                    '【大明贵女】紫' => 1,
                    '白色' => 3,
                    '红色' => 4,
                    '黑色' => 2,
                    '蓝色' => 1,
                ],
            ],
            ['color' => '颜色'],
        );
        $options = [];
        foreach ($groups['color']['counts'] as $value => $count) {
            $options[] = [
                'label' => (string)$value,
                'value' => (string)$value,
                'count' => (int)$count,
                'selected' => false,
            ];
        }
        usort($options, [$policy, 'compareFacetOptions']);
        $primary = array_slice(array_column($options, 'label'), 0, 6);

        self::assertContains('红色', $primary);
        self::assertContains('白色', $primary);
        foreach (['L', 'M', 'S', 'XL', 'XXL'] as $size) {
            self::assertNotContains($size, $primary);
            self::assertNotContains($size, array_column($options, 'label'));
        }
    }
}
