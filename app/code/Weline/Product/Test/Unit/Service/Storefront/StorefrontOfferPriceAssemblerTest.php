<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Storefront;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface;
use Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler;
use Weline\Product\Service\Storefront\StorefrontPriceAdjustmentProviderRegistry;

final class StorefrontOfferPriceAssemblerTest extends TestCase
{
    public function testExclusiveUnitDealPicksStrongestSavingsAndKeepsCampaignLabel(): void
    {
        $weak = new class implements StorefrontPriceAdjustmentProviderInterface {
            public function getCode(): string
            {
                return 'weak';
            }

            public function getPriority(): int
            {
                return 10;
            }

            public function collectAdjustments(StorefrontPriceContext $context): array
            {
                return [
                    new StorefrontPriceAdjustment(
                        code: 'weak-pct',
                        sourceModule: 'Test_Weak',
                        sourceType: 'campaign',
                        sourceId: '1',
                        label: '弱活动',
                        type: StorefrontPriceAdjustment::TYPE_PERCENTAGE,
                        value: 5,
                        url: '/promo/weak',
                    ),
                ];
            }
        };
        $strong = new class implements StorefrontPriceAdjustmentProviderInterface {
            public function getCode(): string
            {
                return 'strong';
            }

            public function getPriority(): int
            {
                return 20;
            }

            public function collectAdjustments(StorefrontPriceContext $context): array
            {
                return [
                    new StorefrontPriceAdjustment(
                        code: 'strong-pct',
                        sourceModule: 'Test_Strong',
                        sourceType: 'campaign',
                        sourceId: '2',
                        label: '今日特价',
                        type: StorefrontPriceAdjustment::TYPE_PERCENTAGE,
                        value: 10,
                        url: '/promotion/deals',
                        badge: '今日特价',
                    ),
                ];
            }
        };

        $assembler = new StorefrontOfferPriceAssembler(
            StorefrontPriceAdjustmentProviderRegistry::forTesting([$weak, $strong]),
        );
        $view = $assembler->assemble(StorefrontPriceContext::fromCatalogMinor(101, 4800, 'CNY'));

        self::assertTrue($view->hasDeal);
        self::assertSame(4320, $view->finalPriceMinor);
        self::assertSame(4800, $view->compareAtMinor);
        self::assertSame('今日特价', $view->campaignLabel());
        self::assertSame('/promotion/deals', $view->campaignUrl());
        self::assertCount(1, $view->appliedAdjustments);
    }

    public function testStackableAppliesAfterExclusiveWinner(): void
    {
        $exclusive = new class implements StorefrontPriceAdjustmentProviderInterface {
            public function getCode(): string
            {
                return 'exclusive';
            }

            public function getPriority(): int
            {
                return 50;
            }

            public function collectAdjustments(StorefrontPriceContext $context): array
            {
                return [
                    new StorefrontPriceAdjustment(
                        code: 'base-deal',
                        sourceModule: 'Test',
                        sourceType: 'deal',
                        sourceId: 'a',
                        label: '专场',
                        type: StorefrontPriceAdjustment::TYPE_PERCENTAGE,
                        value: 10,
                    ),
                ];
            }
        };
        $stack = new class implements StorefrontPriceAdjustmentProviderInterface {
            public function getCode(): string
            {
                return 'stack';
            }

            public function getPriority(): int
            {
                return 5;
            }

            public function collectAdjustments(StorefrontPriceContext $context): array
            {
                return [
                    new StorefrontPriceAdjustment(
                        code: 'member',
                        sourceModule: 'Test',
                        sourceType: 'member',
                        sourceId: 'b',
                        label: '会员叠加',
                        type: StorefrontPriceAdjustment::TYPE_FIXED,
                        value: 2.0,
                        stackable: true,
                    ),
                ];
            }
        };

        $assembler = new StorefrontOfferPriceAssembler(
            StorefrontPriceAdjustmentProviderRegistry::forTesting([$exclusive, $stack]),
        );
        // 100.00 → 10% = 90.00 → -2.00 = 88.00
        $view = $assembler->assemble(StorefrontPriceContext::fromCatalogMinor(1, 10000));
        self::assertSame(8800, $view->finalPriceMinor);
        self::assertCount(2, $view->appliedAdjustments);
        self::assertSame('专场', $view->campaignLabel());
    }
}
