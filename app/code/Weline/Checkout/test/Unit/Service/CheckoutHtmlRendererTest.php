<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutHtmlRenderer;

/**
 * TEST-P2E-09（单元层）：商品 DOM 服务端生成 + XSS 转义；禁止依赖客户端拼装。
 */
final class CheckoutHtmlRendererTest extends TestCase
{
    private function renderer(): CheckoutHtmlRenderer
    {
        return new CheckoutHtmlRenderer(static fn (string $path): string => 'https://url.test/' . $path);
    }

    public function testRenderItemsEscapesAndFormats(): void
    {
        $r = $this->renderer();
        $html = $r->renderItems([
            [
                'name' => '<script>alert(1)</script>',
                'qty' => 2,
                'price' => 10.5,
                'row_total' => 21.0,
                'sku' => 'SKU<script>',
                'image' => '/media/product.jpg" onerror="alert(1)',
                'options' => [
                    [
                        'code' => 'color',
                        'label' => '颜色',
                        'value' => 'red',
                        'value_label' => '红色<script>',
                        'swatch_image' => '/media/swatch-red.jpg',
                    ],
                ],
            ],
        ], 'CNY');
        self::assertStringContainsString('weline-checkout__item', $html);
        self::assertStringContainsString('weline-checkout__item-thumb', $html);
        self::assertStringContainsString('weline-checkout__item-main', $html);
        self::assertStringContainsString('data-storefront-img="1"', $html);
        self::assertStringContainsString('/media/product.jpg&quot; onerror=&quot;alert(1)', $html);
        self::assertStringNotContainsString('onerror="alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('CNY 21.00', $html);
        self::assertStringContainsString('x2', $html);
        self::assertStringContainsString('weline-checkout__item-sku', $html);
        self::assertStringContainsString('SKU: SKU&lt;script&gt;', $html);
        self::assertStringContainsString('weline-checkout__item-options', $html);
        self::assertStringContainsString('weline-checkout__item-option-swatch', $html);
        self::assertStringContainsString('data-checkout-swatch-trigger', $html);
        self::assertStringContainsString('width="16"', $html);
        self::assertStringContainsString('weline-checkout__item-option-label', $html);
        self::assertStringContainsString('weline-checkout__item-option-value-wrap', $html);
        self::assertStringContainsString('weline-checkout__item-option-value', $html);
        self::assertMatchesRegularExpression(
            '/item-option-label">颜色<\/span>.*data-checkout-swatch-trigger.*item-option-value">红色&lt;script&gt;<\/span>/s',
            $html
        );
        self::assertStringContainsString('红色&lt;script&gt;', $html);
        self::assertStringContainsString('src="/media/swatch-red.jpg"', $html);
    }

    public function testRenderItemsIncludesDealChromeWhenCompareAtPresent(): void
    {
        $r = $this->renderer();
        $html = $r->renderItems([
            [
                'name' => 'Student Hanfu',
                'qty' => 1,
                'price' => 80.10,
                'row_total' => 80.10,
                'unit_price_minor' => 8010,
                'compare_at_minor' => 8900,
                'original_price' => 89.0,
                'has_deal' => true,
                'campaign_label' => "Today's Picks",
                'campaign_url' => '/promotion/deals',
            ],
        ], 'CNY');
        self::assertStringContainsString('weline-checkout__item-title', $html);
        self::assertStringContainsString('weline-checkout__item-price-row', $html);
        self::assertStringContainsString('weline-checkout__item-price-now', $html);
        self::assertStringContainsString('weline-checkout__item-price-was', $html);
        self::assertStringContainsString('weline-checkout__item-price-campaign', $html);
        // Price is floated before the title inside item-main.
        self::assertLessThan(
            (int)strpos($html, 'weline-checkout__item-title'),
            (int)strpos($html, 'weline-checkout__item-price')
        );
        self::assertStringContainsString('CNY 80.10', $html);
        self::assertStringContainsString('CNY 89.00', $html);
        self::assertStringContainsString('Today&#039;s Picks', $html);
        self::assertStringContainsString('href="https://url.test/promotion/deals"', $html);
    }

    public function testRenderItemsUsesPlaceholderWhenImageMissing(): void
    {
        $r = $this->renderer();
        $html = $r->renderItems([
            [
                'name' => 'Hanfu',
                'qty' => 1,
                'row_total' => 40.0,
                'product_id' => 42,
            ],
        ], 'CNY');
        self::assertStringContainsString('weline-checkout__item-thumb', $html);
        self::assertStringContainsString('data-storefront-img="1"', $html);
        self::assertStringContainsString('storefront-placeholder', $html);
    }

    public function testEmptyItemsMessage(): void
    {
        $r = $this->renderer();
        $html = $r->renderItems([], 'CNY', 'EMPTY');
        self::assertStringContainsString('EMPTY', $html);
        self::assertStringContainsString('weline-checkout__empty', $html);
    }

    public function testMethodOptionsServerHtml(): void
    {
        $r = $this->renderer();
        $html = $r->renderMethodOptions([
            ['code' => 'std', 'label' => 'Standard', 'amount' => 12.3],
        ], 'shipping_method', 'CNY', '', true);
        self::assertStringContainsString('name="shipping_method"', $html);
        self::assertStringContainsString('value="std"', $html);
        self::assertStringContainsString('CNY 12.30', $html);
        self::assertStringContainsString('checked', $html);
    }

    public function testEmptyShippingMethodsRenderBlockingAlert(): void
    {
        $r = $this->renderer();
        $html = $r->renderMethodOptions(
            [],
            'shipping_method',
            'CNY',
            '购物车中有商品缺少重量，国际运费需按重量计算，因此目前无法报价。请联系客服协助补全商品重量后再结账。',
            true,
            '暂时无法计算运费',
            'missing_weight',
        );
        self::assertStringContainsString('w-alert', $html);
        self::assertStringContainsString('data-tone="warning"', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('data-checkout-method-empty="shipping_method"', $html);
        self::assertStringContainsString('data-reason-code="missing_weight"', $html);
        self::assertStringContainsString('暂时无法计算运费', $html);
        self::assertStringContainsString('缺少重量', $html);
        self::assertStringNotContainsString('拒因码', $html);
        self::assertStringContainsString('dir="auto"', $html);
        self::assertStringContainsString('weline-checkout__method-alert-body', $html);
        self::assertStringNotContainsString('weline-checkout__empty', $html);
        self::assertStringNotContainsString('请调整收货地址或商品', $html);
    }

    public function testEmptyPaymentMethodsRenderBlockingAlert(): void
    {
        $r = $this->renderer();
        $html = $r->renderPaymentMethodOptions([], 'payment_method', '暂无可用支付方式。');
        self::assertStringContainsString('data-checkout-method-empty="payment_method"', $html);
        self::assertStringContainsString('暂无可用支付方式', $html);
        self::assertStringContainsString('data-tone="warning"', $html);
    }

    public function testPaymentMethodOptionsIncludeLogoIntroAndGuideLink(): void
    {
        $r = $this->renderer();
        $html = $r->renderPaymentMethodOptions([
            [
                'code' => 'paypal',
                'label' => 'PayPal',
                'description' => '使用 PayPal 账户或卡完成支付。<script>alert(1)</script>',
                'icon_url' => '/Weline/Payment/view/statics/img/payment/paypal.svg',
                'guide_url' => '/guide/payment/paypal',
                'has_guide' => true,
            ],
        ]);
        self::assertStringContainsString('weline-checkout__option--payment', $html);
        self::assertStringContainsString('weline-checkout__payment-logo', $html);
        self::assertStringContainsString('/Weline/Payment/view/statics/img/payment/paypal.svg', $html);
        self::assertStringContainsString('data-payment-intro-toggle', $html);
        self::assertStringContainsString('dir="auto"', $html);
        self::assertStringContainsString('weline-checkout__payment-intro-text', $html);
        self::assertStringContainsString('data-payment-details', $html);
        self::assertStringContainsString('href="https://url.test/guide/payment/paypal"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('name="payment_method"', $html);
        self::assertStringContainsString('value="paypal"', $html);
    }

    public function testPaymentMethodOptionsSupportSelectedIndexAndHelpPayAttrs(): void
    {
        $r = $this->renderer();
        $html = $r->renderPaymentMethodOptions(
            [
                [
                    'code' => 'fake_card',
                    'label' => '本地测试支付',
                    'icon_url' => '/Weline/Payment/view/statics/img/payment/fake-card.svg',
                    'requires_billing' => true,
                ],
                [
                    'code' => 'paypal',
                    'label' => 'PayPal',
                    'icon_url' => '/Weline/Payment/view/statics/img/payment/paypal.svg',
                    'requires_billing' => false,
                ],
            ],
            'helppay_payment_method',
            '',
            [
                'selected_index' => 1,
                'radio_boolean_attrs' => ['data-helppay-payment-method'],
                'testid_prefix' => 'help-pay-method-',
            ]
        );
        self::assertStringContainsString('weline-checkout__payment-logo', $html);
        self::assertStringContainsString('data-helppay-payment-method', $html);
        self::assertStringContainsString('data-requires-billing="0"', $html);
        self::assertStringContainsString('data-requires-billing="1"', $html);
        self::assertStringContainsString('data-testid="help-pay-method-paypal"', $html);
        self::assertMatchesRegularExpression(
            '/value="paypal"[^>]*checked|checked[^>]*value="paypal"/',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/value="fake_card"[^>]*checked|checked[^>]*value="fake_card"/',
            $html
        );
    }

    public function testCheckoutIndexPhtmlDoesNotCreateElementForItems(): void
    {
        $path = dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('applyServerHtml', $src);
        self::assertStringContainsString('enhancePaymentMethodIntros', $src);
        self::assertMatchesRegularExpression(
            '/payment-intro-text\s*\{[^}]*unicode-bidi:\s*isolate/s',
            $src
        );
        self::assertStringContainsString('data-checkout-swatch-preview', $src);
        self::assertStringContainsString('data-checkout-swatch-trigger', $src);
        self::assertStringContainsString('frontend/checkout/partials/items.phtml', $src);
        self::assertStringContainsString('frontend::partials::checkout::cart-items', $src);
        self::assertStringContainsString('data-checkout-items-hook', $src);
        self::assertStringContainsString('Weline.Api.resource', $src);
        self::assertStringNotContainsString('function renderItems', $src);
        // Type switcher may use createElement; cart items / payment options stay server HTML.
        self::assertDoesNotMatchRegularExpression(
            '/paymentBox\.innerHTML\s*=\s*[\'"]<label[^>]*weline-checkout__method/',
            $src
        );
        self::assertStringNotContainsString('window.fetch', $src);
        self::assertStringNotContainsString("fetch('/", $src);
        self::assertStringNotContainsString('fetch("/', $src);
        self::assertStringNotContainsString('XMLHttpRequest', $src);
        self::assertStringNotContainsString('axios', $src);
        self::assertStringNotContainsString('/api/framework/query-bin', $src);
    }

    public function testCheckoutQueryProviderRendersRichPaymentMethodHtml(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('renderPaymentMethodOptions', $src);
        self::assertStringContainsString('CheckoutPaymentMethodsProvider', $src);

        $providerPath = dirname(__DIR__, 3) . '/Service/CheckoutPaymentMethodsProvider.php';
        self::assertFileExists($providerPath);
        $providerSrc = (string)file_get_contents($providerPath);
        self::assertStringContainsString("'icon_url'", $providerSrc);
        self::assertStringContainsString("'guide_url'", $providerSrc);
        self::assertStringContainsString('storefrontUrl', $providerSrc);
        self::assertStringContainsString('getUrl($route)', $providerSrc);
        self::assertStringNotContainsString("'/guide/payment/' . rawurlencode", $providerSrc);
        self::assertStringContainsString('getCheckoutPaymentMethods', $providerSrc);
        self::assertStringContainsString('requires_billing', $providerSrc);
        self::assertStringContainsString('function findMethod', $providerSrc);
        self::assertStringContainsString('function ensureMethodPresent', $providerSrc);
        self::assertStringContainsString('本地测试支付', $providerSrc);
        self::assertStringContainsString('staticMethodChrome($code)', $providerSrc);
    }

    public function testCheckoutQueryProviderEnsuresBoundPaymentMethodForContinuePay(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('ensurePaymentMethodListed', $src);
        self::assertStringContainsString('$continuePayRequest', $src);
        self::assertStringContainsString('if ($continuePayRequest && $selectedPayment !== \'\')', $src);
        self::assertStringNotContainsString(
            "if (\$selectedPayment !== '' && !\$checkoutBlocked)",
            $src
        );
        self::assertStringContainsString("'selected_code' => \$selectedPayment", $src);
        self::assertStringContainsString("params['payment_method']", $src);
        // Frontend worker must declare payment_method or getData loops with Unknown param toast.
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'getData'[\\s\\S]*?'payment_method'\\s*=>\\s*\\[/",
            $src
        );
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'getData'[\\s\\S]*?'continue_pay'\\s*=>\\s*\\[/",
            $src
        );
    }

    public function testRendererBuildsStorefrontHrefsViaUrlHelper(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/CheckoutHtmlRenderer.php');
        self::assertStringContainsString('function storefrontHref', $src);
        self::assertStringContainsString('getUrl($path)', $src);
        self::assertStringNotContainsString("ltrim(\$guideUrl, '/')", $src);
    }
}
