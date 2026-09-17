<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * 缺重 fail-closed：不静默 0.5kg 估价；空态文案区分 missing_weight。
 */
final class CheckoutMissingWeightShippingContractTest extends TestCase
{
    public function testCheckoutQuoteLinesUseRealWeightWithoutSilentFallback(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringNotContainsString('function chargeableWeightMinor(', $src);
        self::assertStringNotContainsString('? $weightMinor : 500', $src);
        self::assertStringContainsString('quoteLineWeight()->resolveLineWeightMinor($item)', $src);
        self::assertStringContainsString('CheckoutShippingUnavailablePresenter', $src);
        self::assertStringContainsString('shipping_unavailable', $src);
        self::assertStringContainsString('$shippingEmptyReason', $src);
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/CheckoutShippingUnavailablePresenter.php');
        $presenter = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutShippingUnavailablePresenter.php',
        );
        self::assertStringContainsString('暂时无法计算运费', $presenter);
        self::assertStringContainsString("'reason_code' => 'missing_weight'", $presenter);
        self::assertStringContainsString('缺少重量', $presenter);
        self::assertStringNotContainsString('拒因码', $presenter);
        self::assertStringNotContainsString('->getLastQuoteDiagnostics()', $src);
    }

    public function testExpressPathsAlsoRefuseSilentHalfKgFallback(): void
    {
        $express = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
        $amend = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressUnpaidOrderAmend.php',
        );
        $resolver = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutQuoteLineWeightResolver.php',
        );
        self::assertStringNotContainsString('function chargeableWeightMinor(', $express);
        self::assertStringNotContainsString('? $weightMinor : 500', $express);
        self::assertStringNotContainsString(': 500, // 0.5kg', $amend);
        self::assertStringContainsString('CheckoutQuoteLineWeightResolver', $express);
        self::assertStringContainsString('quoteLineWeight()->resolveLineWeightMinor', $express);
        self::assertStringContainsString('missing_weight', $express);
        self::assertStringContainsString('lastListQuoteDiagnostics', $express);
        self::assertStringNotContainsString('getLastQuoteDiagnostics', $express);
        self::assertStringContainsString('CheckoutQuoteLineWeightResolver', $amend);
        self::assertStringNotContainsString('function catalogWeightMinor(', $amend);
        self::assertStringContainsString('Never invent 0.5kg', $resolver);
        self::assertStringNotContainsString('return 500', $resolver);
    }

    public function testLocalPricingPropagatesMissingWeightReason(): void
    {
        $pricing = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/Service/Provider/LocalTemplatePricingService.php',
        );
        $mgr = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/Service/ShippingServiceManager.php',
        );
        self::assertStringContainsString('unavailable_reasons', $pricing);
        self::assertStringContainsString("'missing_weight' =>", $mgr);
        self::assertStringContainsString("in_array('missing_weight'", $mgr);
    }
}
