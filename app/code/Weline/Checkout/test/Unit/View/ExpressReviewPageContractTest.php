<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ExpressReviewPageContractTest extends TestCase
{
    public function testExpressReviewTemplateHasBreakoutAndCopy(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml'
        );
        self::assertStringContainsString('尚未扣款', $tpl);
        self::assertStringContainsString('确认并付款', $tpl);
        self::assertStringContainsString('window.opener', $tpl);
        self::assertStringContainsString('data-testid="checkout-express-review"', $tpl);
        self::assertStringContainsString('data-weline-load="checkoutExpressReview"', $tpl);
    }

    public function testExpressReviewControllerExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/Controller/ExpressReview.php');
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/ExpressReview.php');
        self::assertStringContainsString('express-review.phtml', $src);
    }

    public function testQueryDescriptorListsExpressOps(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
        self::assertStringContainsString("'startExpressCheckout'", $src);
        self::assertStringContainsString("'getExpressReview'", $src);
        self::assertStringContainsString("'confirmExpressCheckout'", $src);
        self::assertStringContainsString("'cancelExpressCheckout'", $src);
        self::assertStringContainsString('ExpressCheckoutFlowService', $src);
    }

    public function testSinkDoesNotInventFakePhone(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutPaymentExpressAddressSink.php'
        );
        self::assertStringNotContainsString('00000000000', $src);
    }

    public function testExpressReviewJsHandlesTotalsAndCancel(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/express-review.js');
        self::assertStringContainsString('cancelExpressCheckout', $js);
        self::assertStringContainsString('majorFromTotals', $js);
        self::assertStringContainsString('shipping_methods', $js);
        self::assertStringContainsString('data-express-shipping-list', (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml'
        ));
    }

    public function testAmendServiceQuotesTaxAndShipping(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressUnpaidOrderAmend.php');
        self::assertStringContainsString('quoteTaxMinor', $src);
        self::assertStringContainsString('quoteDiscountMinor', $src);
        self::assertStringContainsString('mergeAddressReadOnlyCore', $src);
    }
}
