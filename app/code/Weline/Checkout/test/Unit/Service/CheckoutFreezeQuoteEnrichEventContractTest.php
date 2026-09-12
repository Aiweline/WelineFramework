<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 万能结账经事件读取资产折扣；禁止 Checkout 直连 B2B 具体类。
 */
final class CheckoutFreezeQuoteEnrichEventContractTest extends TestCase
{
    public function testFreezeAndQuoteDispatchesEnrichEventWithoutB2bHardCouple(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php'
        );

        self::assertStringContainsString(
            "Weline_Checkout::checkout::freeze_quote::enrich",
            $src,
        );
        self::assertStringContainsString(
            "Weline_Checkout::checkout::asset_discount::apply",
            $src,
        );
        self::assertStringNotContainsString(
            'B2BCheckoutCreditQuote',
            $src,
        );
        self::assertStringNotContainsString(
            'B2BDepositCreditOrchestrator',
            $src,
        );
        self::assertStringNotContainsString(
            "class_exists(\\Weline\\B2B\\Service\\B2BCheckoutCreditQuote::class)",
            $src,
        );
    }

    public function testCheckoutEventCatalogDocumentsFreezeQuoteEnrich(): void
    {
        $catalog = (string)file_get_contents(
            dirname(__DIR__, 3) . '/event.php'
        );
        self::assertStringContainsString(
            "Weline_Checkout::checkout::freeze_quote::enrich",
            $catalog,
        );
        self::assertStringContainsString(
            "Weline_Checkout::checkout::asset_discount::apply",
            $catalog,
        );
    }
}
