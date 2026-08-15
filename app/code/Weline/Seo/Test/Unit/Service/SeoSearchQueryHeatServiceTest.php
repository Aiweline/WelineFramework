<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\SeoSearchQueryStat;
use Weline\Seo\Service\SeoSearchQueryHeatService;

final class SeoSearchQueryHeatServiceTest extends TestCase
{
    public function testHeatWeightsImpressionsClicksRankAndCtr(): void
    {
        $hot = SeoSearchQueryHeatService::heat(120, 8000, 3.2, 0.08);
        $cold = SeoSearchQueryHeatService::heat(1, 20, 48.0, 0.01);
        $zero = SeoSearchQueryHeatService::heat(0, 0, 0.0, 0.0);

        self::assertGreaterThan($cold, $hot);
        self::assertSame(0.0, $zero);
        self::assertGreaterThanOrEqual(0.0, $hot);
        self::assertLessThanOrEqual(100.0, $hot);
        self::assertSame(
            SeoSearchQueryHeatService::heat(10, 100, 8.0, 15.0),
            SeoSearchQueryHeatService::heat(10, 100, 8.0, 0.15),
        );
    }

    public function testMatchQueriesPrefersTermsPresentInOwnerCopy(): void
    {
        $service = new SeoSearchQueryHeatService($this->createMock(SeoSearchQueryStat::class));
        $matched = $service->matchQueries([
            ['query' => 'teen patti', 'heat' => 88.2, 'clicks' => 40, 'impressions' => 900],
            ['query' => 'rummy', 'heat' => 71.0, 'clicks' => 18, 'impressions' => 500],
            ['query' => 'unrelated casino', 'heat' => 90.0, 'clicks' => 99, 'impressions' => 2000],
            ['query' => 'a', 'heat' => 99.0, 'clicks' => 1, 'impressions' => 1],
        ], [
            'fields' => [
                'content' => [
                    'title' => 'Teen Patti Rummy Hub',
                    'body' => 'Play rummy tables online.',
                ],
            ],
        ], 8);

        self::assertSame(['teen patti', 'rummy'], \array_column($matched, 'query'));
        self::assertSame(88.2, $matched[0]['heat']);

        $compact = $service->matchQueries([
            ['query' => 'teen patti', 'heat' => 88.2],
            ['query' => 'yono games', 'heat' => 47.0],
        ], [
            'seo.heading_text' => 'TeenPatti, Rummy & Yono Games APK Downloads',
            'seo.target_keywords' => ['teenpatti apk download', 'yono games apk'],
        ], 8);
        self::assertSame(['teen patti', 'yono games'], \array_column($compact, 'query'));
    }

    public function testRankTargetsByQueryHeatPrefersHottestMatchedBlock(): void
    {
        $service = new SeoSearchQueryHeatService($this->createMock(SeoSearchQueryStat::class));
        $ranked = $service->rankTargetsByQueryHeat([
            [
                'page_type' => 'home_page',
                'block_key' => 'footer',
                'current_values' => ['fields' => ['content' => ['title' => 'About us']]],
            ],
            [
                'page_type' => 'home_page',
                'block_key' => 'hero',
                'current_values' => ['seo.heading_text' => 'TeenPatti Rummy Hub'],
            ],
            [
                'page_type' => 'home_page',
                'block_key' => 'games',
                'current_values' => ['fields' => ['content' => ['title' => 'Rummy tables']]],
            ],
        ], [
            ['query' => 'teen patti', 'heat' => 88.2],
            ['query' => 'rummy', 'heat' => 61.0],
        ], 8);

        self::assertSame(['hero', 'games'], \array_column(\array_column($ranked, 'target'), 'block_key'));
        self::assertSame('teen patti', $ranked[0]['query']);
        self::assertSame(88.2, $ranked[0]['heat']);

        $tied = $service->rankTargetsByQueryHeat([
            [
                'page_type' => 'about_page',
                'block_key' => '',
                'current_values' => ['seo.heading_text' => 'TeenPatti about'],
            ],
            [
                'page_type' => 'about_page',
                'block_key' => 'trust_proof',
                'current_values' => ['seo.heading_text' => 'TeenPatti proof'],
            ],
            [
                'page_type' => 'home_page',
                'block_key' => 'hero',
                'current_values' => ['seo.heading_text' => 'TeenPatti hero'],
            ],
        ], [
            ['query' => 'teen patti', 'heat' => 64.57],
        ], 3);
        self::assertSame(
            [['home_page', 'hero'], ['about_page', 'trust_proof'], ['about_page', '']],
            \array_map(
                static fn(array $row): array => [
                    (string)($row['target']['page_type'] ?? ''),
                    (string)($row['target']['block_key'] ?? ''),
                ],
                $tied,
            ),
        );
    }
}
