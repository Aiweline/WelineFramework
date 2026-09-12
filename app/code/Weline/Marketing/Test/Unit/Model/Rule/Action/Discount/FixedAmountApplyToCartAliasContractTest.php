<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Model\Rule\Action\Discount;

use PHPUnit\Framework\TestCase;

/**
 * Random coupon upsert historically wrote apply_to=cart; discount actions only
 * resolved subtotal|shipping|matched_products, so fixed min(value, 0) wiped gift discounts.
 */
final class FixedAmountApplyToCartAliasContractTest extends TestCase
{
    public function testProviderUpsertWritesSubtotalApplyTo(): void
    {
        $service = dirname(__DIR__, 6) . '/Service/RandomCouponCampaignProvider.php';
        self::assertFileExists($service);
        $src = (string)file_get_contents($service);
        self::assertStringContainsString("'apply_to' => 'subtotal'", $src);
        self::assertStringNotContainsString("'apply_to' => 'cart'", $src);
    }

    public function testFixedAmountAliasesCartToSubtotal(): void
    {
        $path = dirname(__DIR__, 6) . '/Model/Rule/Action/Discount/FixedAmount.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("\$applyTo === 'cart'", $src);
        self::assertStringContainsString("\$applyTo = 'subtotal'", $src);
    }

    public function testPercentageAliasesCartToSubtotal(): void
    {
        $path = dirname(__DIR__, 6) . '/Model/Rule/Action/Discount/Percentage.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("\$applyTo === 'cart'", $src);
        self::assertStringContainsString("\$applyTo = 'subtotal'", $src);
    }
}
