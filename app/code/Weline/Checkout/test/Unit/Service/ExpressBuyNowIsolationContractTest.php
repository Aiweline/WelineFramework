<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * PDP PayPal/express 快捷支付必须只结当前商品，不得把浏览车其它行带进支付商金额。
 */
final class ExpressBuyNowIsolationContractTest extends TestCase
{
    private string $flowSrc;
    private string $jsSrc;
    private string $querySrc;

    protected function setUp(): void
    {
        $this->flowSrc = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
        $this->jsSrc = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Payment/view/statics/js/product-express-pay.js',
        );
        $this->querySrc = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
    }

    public function testFlowIsolatesBuyNowThenRestoresBrowseCart(): void
    {
        self::assertStringContainsString('wantsBuyNowIsolation', $this->flowSrc);
        self::assertStringContainsString('isolateBuyNowCart', $this->flowSrc);
        self::assertStringContainsString('restorePriorCartLines', $this->flowSrc);
        self::assertStringContainsString('express_buy_now_product_required', $this->flowSrc);
        self::assertStringContainsString('isConcreteShippingAddress', $this->flowSrc);
        self::assertStringContainsString('$buyNowActive', $this->flowSrc);
        $groupSrc = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php',
        );
        self::assertStringContainsString('express_deferred_shipping', $groupSrc);
        // finally 还原：成功/失败都不得永久清空浏览车。
        self::assertMatchesRegularExpression(
            '/try\s*\{\s*return \$this->startAfterCartReady[\s\S]*?\}\s*finally\s*\{\s*if \(\$buyNowActive\)/',
            $this->flowSrc,
        );
    }

    public function testProductExpressJsPassesBuyNowWithoutCartAdd(): void
    {
        self::assertStringContainsString('buy_now: true', $this->jsSrc);
        self::assertStringContainsString('product_express: true', $this->jsSrc);
        self::assertStringContainsString('global_offer_uuid: offerUuid', $this->jsSrc);
        self::assertStringNotContainsString('cartApi.add', $this->jsSrc);
    }

    public function testStartExpressCheckoutDescriptorAllowsBuyNowParams(): void
    {
        self::assertStringContainsString("'buy_now' => ['type' => 'boolean'", $this->querySrc);
        self::assertStringContainsString("'global_offer_uuid'", $this->querySrc);
        self::assertStringContainsString('buy_now isolates PDP product', $this->querySrc);
    }
}
