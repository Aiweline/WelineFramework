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
        self::assertStringContainsString('id="checkout-shipping-address"', $tpl);
        self::assertStringContainsString('请确认收货地址；不对可更换或新增', $tpl);
        self::assertStringNotContainsString('支付商带回，只读', $tpl);
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
        // refreshReview 会带顶层 contact_phone/email；未声明则 Unknown frontend worker param
        self::assertMatchesRegularExpression(
            "/'name' => 'getExpressReview'[\s\S]*?'contact_phone' => \['type' => 'string'/",
            $src,
        );
        self::assertMatchesRegularExpression(
            "/'name' => 'getExpressReview'[\s\S]*?'email' => \['type' => 'string'/",
            $src,
        );
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
        self::assertStringContainsString('weline:checkout:address-updated', $js);
        self::assertStringContainsString('shipping_address', $js);
        self::assertStringContainsString('collectShippingAddress', $js);
        self::assertStringContainsString('method.title || method.label || method.service_name', $js);
        self::assertStringContainsString('trackReviewEnter', $js);
        self::assertStringContainsString("trackPixel('begin_checkout'", $js);
        self::assertStringContainsString('express_review_enter', $js);
        // 支付方式徽章禁止硬编码中文（非中文 locale 漏译）
        self::assertStringContainsString("translatePhrase('支付方式')", $js);
        self::assertStringNotContainsString("methodLabel = '支付方式：'", $js);
        self::assertStringContainsString('data-express-shipping-list', (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml'
        ));
    }

    public function testExpressReviewDtoExposesPixelItems(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php');
        self::assertStringContainsString('orderItemsToPixelItems', $src);
        self::assertStringContainsString("'items' => \$pixelItems", $src);
        self::assertStringContainsString("'item_id'", $src);
        self::assertStringContainsString("'item_name'", $src);
    }

    public function testExpressFlowMapsShippingLabelNotRawCodeOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php');
        self::assertStringContainsString("\$option['label'] ?? \$option['service_name']", $src);
        self::assertStringContainsString("'label' => \$label", $src);
        self::assertStringContainsString("'title' => \$label", $src);
    }

    public function testAmendServiceQuotesTaxAndShipping(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressUnpaidOrderAmend.php');
        self::assertStringContainsString('quoteTaxMinor', $src);
        self::assertStringContainsString('quoteDiscountMinor', $src);
        self::assertStringContainsString('mergeAddressReadOnlyCore', $src);
        self::assertStringContainsString('Incoming (user-selected', $src);
    }

    public function testFlowServiceAcceptsSelectedAddressOverride(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php');
        self::assertStringContainsString('extractAddressFromParams', $src);
        self::assertStringContainsString("'address_readonly' => false", $src);
        self::assertStringContainsString('请确认收货地址；不对可更换或新增', $src);
        self::assertStringContainsString('resolvePaymentTransactionNo', $src);
        self::assertStringContainsString('buildAlreadyPaidResult', $src);
    }

    public function testExpressReviewJsResolvesGroupUuid(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/express-review.js');
        self::assertStringContainsString('checkout_group_uuid', $js);
        self::assertStringContainsString('reviewPayload', $js);
        self::assertStringContainsString('rememberTransactionNo', $js);
        self::assertStringContainsString('handleAlreadyPaid', $js);
        self::assertStringContainsString('already_paid', $js);
        self::assertStringContainsString('data-checkout-group-uuid', (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml'
        ));
    }

    public function testPaymentSessionHasUpdateBrowserLanding(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Payment/Service/PaymentCheckoutSessionPersistenceService.php'
        );
        self::assertStringContainsString('function updateBrowserLanding', $src);
    }

    public function testExpressReviewControllerRedirectsAlreadyPaid(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/ExpressReview.php');
        self::assertStringContainsString('buildAlreadyPaidResult', $src);
        self::assertStringContainsString('checkout/success', $src);
    }

    public function testSuccessDoesNotCartDumpPaidOrders(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Success.php');
        self::assertStringContainsString('renderPaidAcknowledgement', $src);
        self::assertStringContainsString('checkout_paid_ack', $src);
        self::assertStringContainsString('must NOT dump to /cart', $src);
    }

    public function testExpressReviewTemplateShowsAlreadyPaidCopy(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml'
        );
        self::assertStringContainsString('订单已支付', $tpl);
        self::assertStringContainsString('data-already-paid', $tpl);
        self::assertStringContainsString('该笔订单已支付，无需再次确认', $tpl);
    }

    public function testSuccessTemplateHasPaidAckCopy(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/success.phtml'
        );
        self::assertStringContainsString('paidAck', $tpl);
        self::assertStringContainsString('该笔订单已完成扣款，无需再次付款', $tpl);
    }
}
