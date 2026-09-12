<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

/**
 * 信用额度数据由 Payment 经结账事件贡献；B2B 只提供类型策略。
 */
final class CheckoutAssetDiscountObserverContractTest extends TestCase
{
    public function testPaymentEventXmlRegistersCheckoutAssetObservers(): void
    {
        $xml = (string)file_get_contents(
            dirname(__DIR__, 3) . '/etc/event.xml'
        );
        self::assertStringContainsString(
            'Weline_Checkout::checkout::freeze_quote::enrich',
            $xml,
        );
        self::assertStringContainsString(
            'Weline_Checkout::checkout::asset_discount::apply',
            $xml,
        );
        self::assertStringContainsString(
            'Weline\\Payment\\Observer\\CheckoutFreezeQuoteAssetEnrichObserver',
            $xml,
        );
        self::assertStringContainsString(
            'Weline\\Payment\\Observer\\CheckoutAssetDiscountApplyObserver',
            $xml,
        );
    }

    public function testPaymentObserversOwnAssetQuoteData(): void
    {
        $freeze = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/CheckoutFreezeQuoteAssetEnrichObserver.php'
        );
        $apply = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/CheckoutAssetDiscountApplyObserver.php'
        );
        self::assertStringContainsString('AssetCheckoutDiscountQuote', $freeze);
        self::assertStringContainsString('b2b_credit', $freeze);
        self::assertStringContainsString('AssetCheckoutDiscountQuote', $apply);
        self::assertStringContainsString('typePayloadFragment', $apply);
        self::assertStringContainsString('discount_kind', $apply);
        self::assertStringNotContainsString('Weline\\B2B\\', $freeze);
        self::assertStringNotContainsString('Weline\\B2B\\', $apply);
    }

    public function testB2bEventXmlDoesNotOwnCheckoutAssetObservers(): void
    {
        $xml = (string)file_get_contents(
            dirname(__DIR__, 4) . '/B2B/etc/event.xml'
        );
        self::assertStringNotContainsString(
            'Weline_Checkout::checkout::freeze_quote::enrich',
            $xml,
        );
        self::assertStringNotContainsString(
            'Weline_Checkout::checkout::asset_discount::apply',
            $xml,
        );
    }

    public function testAssetCheckoutDiscountQuoteUsesPaymentPolicySpi(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AssetCheckoutDiscountQuote.php'
        );
        self::assertStringContainsString('getPolicyForScope', $src);
        self::assertStringContainsString('CustomerAssetFacadeInterface', $src);
        self::assertStringContainsString('ASSET_B2B_CREDIT', $src);
        self::assertStringNotContainsString('B2BPaymentAssetPolicyProvider', $src);
    }
}
