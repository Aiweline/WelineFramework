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
}
