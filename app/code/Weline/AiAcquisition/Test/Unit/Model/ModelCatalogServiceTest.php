<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;

/**
 * 模型目录价格纯函数测试：不依赖真实 DB / 网络。
 */
class ModelCatalogServiceTest extends TestCase
{
    private ModelCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // 纯计算方法不触发模型查询，因此无需引导框架数据库。
        $this->service = new ModelCatalogService();
    }

    public function testQuoteChargesPerThousandTokens(): void
    {
        $quote = $this->service->quoteFromPrices(0.002, 0.008, 1000, 500, 1, 'demo-model');

        self::assertSame('demo-model', $quote['model_code']);
        self::assertSame(0.002, $quote['input_cost']);
        self::assertSame(0.004, $quote['output_cost']);
        self::assertSame(0.006, $quote['total_cost']);
        self::assertSame('CNY', $quote['currency']);
        self::assertTrue($quote['priced']);
        self::assertSame(1000, $quote['input_tokens']);
        self::assertSame(500, $quote['output_tokens']);
        self::assertSame(1, $quote['iterations']);
    }

    public function testQuoteMultipliesIterations(): void
    {
        $quote = $this->service->quoteFromPrices(0.01, 0.02, 1500, 2500, 2);

        self::assertSame(0.03, $quote['input_cost']);
        self::assertSame(0.1, $quote['output_cost']);
        self::assertSame(0.13, $quote['total_cost']);
        self::assertSame(2, $quote['iterations']);
    }

    public function testQuoteWithoutPricesIsZeroAndUnpriced(): void
    {
        $quote = $this->service->quoteFromPrices(0.0, 0.0, 4000, 1200);

        self::assertSame(0.0, $quote['input_cost']);
        self::assertSame(0.0, $quote['output_cost']);
        self::assertSame(0.0, $quote['total_cost']);
        self::assertFalse($quote['priced']);
    }

    public function testQuoteRoundsToSixDecimals(): void
    {
        $quote = $this->service->quoteFromPrices(0.000123, 0.000456, 777, 111);

        self::assertSame(0.000096, $quote['input_cost']);
        self::assertSame(0.000051, $quote['output_cost']);
        self::assertSame(0.000146, $quote['total_cost']);
    }

    public function testQuoteClampsNegativeInputs(): void
    {
        $quote = $this->service->quoteFromPrices(-1.0, -1.0, -5, -5, 0);

        self::assertSame(0.0, $quote['total_cost']);
        self::assertFalse($quote['priced']);
        self::assertSame(0, $quote['input_tokens']);
        self::assertSame(0, $quote['output_tokens']);
        self::assertSame(1, $quote['iterations']);
    }

    public function testEstimateRunReturnsThreeTiers(): void
    {
        $estimate = $this->service->estimateRunFromPrices(0.002, 0.008, 4, 4000, 1000, 'demo-model');

        self::assertSame('demo-model', $estimate['model_code']);
        self::assertSame(4, $estimate['iterations']);
        self::assertSame(0.016, $estimate['per_iteration']['total_cost']);
        self::assertSame(0.032, $estimate['min']['total_cost']);
        self::assertSame(0.064, $estimate['typical']['total_cost']);
        self::assertSame(0.128, $estimate['max']['total_cost']);
        self::assertSame(0.032, $estimate['min_cost']);
        self::assertSame(0.064, $estimate['typical_cost']);
        self::assertSame(0.128, $estimate['max_cost']);
        self::assertSame('CNY', $estimate['currency']);
        self::assertTrue($estimate['priced']);
    }

    public function testEstimateRunUsesDefaultIterations(): void
    {
        $estimate = $this->service->estimateRunFromPrices(0.002, 0.008);

        self::assertSame(8, $estimate['iterations']);
        self::assertSame(0.0176, $estimate['per_iteration']['total_cost']);
        self::assertSame(0.1408, $estimate['typical_cost']);
    }

    public function testEstimateRunWithoutPricesIsUnpriced(): void
    {
        $estimate = $this->service->estimateRunFromPrices(0.0, 0.0);

        self::assertFalse($estimate['priced']);
        self::assertSame(0.0, $estimate['min_cost']);
        self::assertSame(0.0, $estimate['typical_cost']);
        self::assertSame(0.0, $estimate['max_cost']);
    }
}
