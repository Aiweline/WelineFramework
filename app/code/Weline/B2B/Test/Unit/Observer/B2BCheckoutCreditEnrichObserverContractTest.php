<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * B2B 不再挂结账额度 Observer；仅保留类型策略 SPI。
 */
final class B2BCheckoutCreditEnrichObserverContractTest extends TestCase
{
    public function testB2bNoLongerRegistersCheckoutCreditDataObservers(): void
    {
        $xml = (string)file_get_contents(
            dirname(__DIR__, 3) . '/etc/event.xml'
        );
        self::assertStringNotContainsString(
            'Weline_Checkout::checkout::freeze_quote::enrich',
            $xml,
        );
        self::assertStringNotContainsString(
            'Weline_Checkout::checkout::asset_discount::apply',
            $xml,
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/Observer/CheckoutFreezeQuoteCreditEnrichObserver.php'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/Observer/CheckoutAssetDiscountApplyObserver.php'
        );
    }

    public function testB2bCheckoutCreditQuoteIsThinPaymentDelegate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/B2BCheckoutCreditQuote.php'
        );
        self::assertStringContainsString('AssetCheckoutDiscountQuote', $src);
        self::assertStringContainsString('薄委托', $src);
        self::assertStringNotContainsString('CustomerAssetFacadeInterface', $src);
        self::assertStringNotContainsString('getBalance', $src);
    }
}
