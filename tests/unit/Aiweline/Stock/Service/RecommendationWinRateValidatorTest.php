<?php

declare(strict_types=1);

namespace Tests\Unit\Aiweline\Stock\Service;

use Aiweline\Stock\Model\StockRecommendation;
use Aiweline\Stock\Service\RecommendationWinRateValidator;
use PHPUnit\Framework\TestCase;

class RecommendationWinRateValidatorTest extends TestCase
{
    public function testEvaluatesBuySignalsAndBullishNewsAsValidationCandidates(): void
    {
        $validator = new RecommendationWinRateValidator();

        $result = $validator->evaluate(
            [
                [
                    StockRecommendation::fields_STOCK_CODE => '000001',
                    StockRecommendation::fields_STOCK_NAME => 'Buy Signal',
                    StockRecommendation::fields_STRATEGY => StockRecommendation::STRATEGY_1,
                    StockRecommendation::fields_RECOMMEND_DATE => '2026-04-01',
                    StockRecommendation::fields_RECOMMEND_PRICE => 10,
                    StockRecommendation::fields_ACTION => 'buy',
                    StockRecommendation::fields_REASON => 'technical buy',
                ],
                [
                    StockRecommendation::fields_STOCK_CODE => '000002',
                    StockRecommendation::fields_STOCK_NAME => 'Bullish News',
                    StockRecommendation::fields_STRATEGY => StockRecommendation::STRATEGY_3,
                    StockRecommendation::fields_RECOMMEND_DATE => '2026-04-01',
                    StockRecommendation::fields_RECOMMEND_PRICE => 20,
                    StockRecommendation::fields_ACTION => 'hold',
                    StockRecommendation::fields_NEWS_TITLE => '新闻推荐说会涨',
                ],
                [
                    StockRecommendation::fields_STOCK_CODE => '000003',
                    StockRecommendation::fields_STOCK_NAME => 'Neutral News',
                    StockRecommendation::fields_STRATEGY => StockRecommendation::STRATEGY_3,
                    StockRecommendation::fields_RECOMMEND_DATE => '2026-04-01',
                    StockRecommendation::fields_RECOMMEND_PRICE => 30,
                    StockRecommendation::fields_ACTION => 'hold',
                    StockRecommendation::fields_NEWS_TITLE => '新闻仅提示关注',
                ],
            ],
            [
                '000001' => [
                    ['trade_date' => '2026-04-02', 'close' => 10.2, 'high' => 10.5],
                    ['trade_date' => '2026-04-03', 'close' => 10.3, 'high' => 10.4],
                ],
                '000002' => [
                    ['trade_date' => '2026-04-02', 'close' => 19.9, 'high' => 20.1],
                    ['trade_date' => '2026-04-03', 'close' => 19.7, 'high' => 19.8],
                ],
                '000003' => [
                    ['trade_date' => '2026-04-02', 'close' => 31, 'high' => 32],
                    ['trade_date' => '2026-04-03', 'close' => 32, 'high' => 33],
                ],
            ],
            [
                'holding_days' => 2,
                'min_profit_rate' => 0.02,
            ]
        );

        self::assertSame(2, $result['summary']['total']);
        self::assertSame(2, $result['summary']['verified']);
        self::assertSame(1, $result['summary']['wins']);
        self::assertSame(1, $result['summary']['losses']);
        self::assertSame(0.5, $result['summary']['win_rate']);

        self::assertSame(1, $result['sources']['buy_signal']['total']);
        self::assertSame(1, $result['sources']['bullish_news']['total']);
        self::assertSame(['buy_signal'], $result['records'][0]['candidate_sources']);
        self::assertSame(['bullish_news'], $result['records'][1]['candidate_sources']);
    }

    public function testMarksSamplesPendingWhenFutureWindowIsIncomplete(): void
    {
        $validator = new RecommendationWinRateValidator();

        $result = $validator->evaluate(
            [
                [
                    StockRecommendation::fields_STOCK_CODE => '000001',
                    StockRecommendation::fields_STOCK_NAME => 'Recent Buy',
                    StockRecommendation::fields_STRATEGY => StockRecommendation::STRATEGY_1,
                    StockRecommendation::fields_RECOMMEND_DATE => '2026-04-20',
                    StockRecommendation::fields_RECOMMEND_PRICE => 10,
                    StockRecommendation::fields_ACTION => 'buy',
                ],
            ],
            [
                '000001' => [
                    ['trade_date' => '2026-04-21', 'close' => 11, 'high' => 11],
                ],
            ],
            [
                'holding_days' => 2,
            ]
        );

        self::assertSame(1, $result['summary']['total']);
        self::assertSame(0, $result['summary']['verified']);
        self::assertSame(1, $result['summary']['pending']);
        self::assertNull($result['summary']['win_rate']);
        self::assertSame('pending', $result['records'][0]['status']);
    }
}
