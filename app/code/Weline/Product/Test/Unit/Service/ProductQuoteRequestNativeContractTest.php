<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Product-owned quote request surface (no Inquiry coupling).
 */
final class ProductQuoteRequestNativeContractTest extends TestCase
{
    public function testModelAndServiceSurfaceExist(): void
    {
        $model = BP . 'app/code/Weline/Product/Model/ProductQuoteRequest.php';
        $service = BP . 'app/code/Weline/Product/Service/ProductQuoteRequestService.php';
        $api = BP . 'app/code/Weline/Product/Api/ProductQuoteRequestSubmitInterface.php';
        $frontend = BP . 'app/code/Weline/Product/Controller/Frontend/Api/QuoteRequest.php';
        $backend = BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php';

        foreach ([$model, $service, $api, $frontend, $backend] as $path) {
            self::assertFileExists($path);
        }

        $modelSrc = (string)file_get_contents($model);
        self::assertStringContainsString('weline_product_quote_request', $modelSrc);
        self::assertStringContainsString('idempotency_key', $modelSrc);
        self::assertStringContainsString('address_json', $modelSrc);

        $serviceSrc = (string)file_get_contents($service);
        self::assertStringContainsString('function submit(', $serviceSrc);
        self::assertStringContainsString('idempotency_key', $serviceSrc);
        self::assertStringContainsString('publishedOffersForProduct', $serviceSrc);
        self::assertStringContainsString('仅询价', $serviceSrc);
        self::assertStringContainsString('company_website', $serviceSrc);
        self::assertStringNotContainsString('Weline\\Inquiry', $serviceSrc);
        self::assertStringNotContainsString('Weline_Inquiry', $serviceSrc);
    }

    public function testStorefrontQueryExposesSubmitQuoteRequest(): void
    {
        $path = BP . 'app/code/Weline/Product/extends/module/Weline_Framework/Query/ProductStorefrontQueryProvider.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'submitQuoteRequest'", $src);
        self::assertStringContainsString('ProductQuoteRequestSubmitInterface', $src);
        self::assertStringContainsString("'frontend' => true", $src);
        self::assertStringContainsString("'mode' => 'write'", $src);
    }

    public function testAdminMenuAndAclExist(): void
    {
        $menu = (string)file_get_contents(BP . 'app/code/Weline/Product/etc/backend/menu.xml');
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/QuoteRequest.php');
        $template = BP . 'app/code/Weline/Product/view/templates/backend/quote-request/index.phtml';

        self::assertFileExists($template);
        self::assertStringContainsString('Weline_Product::commerce:catalog:quote-requests', $menu);
        self::assertStringContainsString('weline_product/backend/quote-request/index', $menu);
        self::assertStringContainsString("Acl('Weline_Product::commerce:catalog:quote-requests'", $controller);
        self::assertStringContainsString('function markProcessed', $controller);
        self::assertStringContainsString('标记已处理', (string)file_get_contents($template));
    }

    public function testPdpQuoteOnlyIsSelectableAndOwnsSubmitModal(): void
    {
        $pdp = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml'
        );
        self::assertStringContainsString('data-action="open-product-quote"', $pdp);
        self::assertStringContainsString('data-product-quote-modal', $pdp);
        self::assertStringContainsString('data-testid="product-submit-quote"', $pdp);
        self::assertStringContainsString("params.get('quote') === '1'", $pdp);
        self::assertStringContainsString("=== '#quote'", $pdp);
        self::assertStringContainsString("params.set('quote', '1')", $pdp);
        self::assertStringContainsString('function isOfferPurchasable', $pdp);
        self::assertStringContainsString('is-disabled\', !exists)', $pdp);
        self::assertStringContainsString('is-out-of-stock\', exists && !purchasable && !quoteOnlyOption)', $pdp);
        self::assertStringContainsString('syncQuoteButtonContext', $pdp);
        self::assertStringContainsString('data-quote-submit-url', $pdp);
        self::assertStringContainsString('idempotency_key', $pdp);
        self::assertStringContainsString('<w:theme:address', $pdp);
        self::assertStringContainsString('postal-lookup="true"', $pdp);
        self::assertStringContainsString('code="product-quote-address"', $pdp);
        self::assertStringNotContainsString('w:inquiry', $pdp);
        self::assertStringNotContainsString('Weline_Inquiry', $pdp);
        self::assertStringNotContainsString('<w:inquiry', $pdp);
    }

    public function testCartAddToCartSkipsQuoteOnly(): void
    {
        $cart = (string)file_get_contents(
            BP . 'app/code/Weline/Cart/view/templates/frontend/widgets/product-add-to-cart.phtml'
        );
        self::assertStringContainsString('$quoteOnly = !empty($offer[\'quote_only\'])', $cart);
        self::assertStringContainsString('// Product owns the quote CTA', $cart);
        self::assertMatchesRegularExpression(
            '/if \(\$quoteOnly\) \{\s*\/\/ Product owns the quote CTA[^\n]*\s*return;/s',
            $cart
        );
    }

    public function testModuleProvidesSubmitInterfaceAndOptionalCaptcha(): void
    {
        $module = include BP . 'app/code/Weline/Product/etc/module.php';
        self::assertSame('1.0.164', $module['version'] ?? null);
        self::assertArrayHasKey(
            \Weline\Product\Api\ProductQuoteRequestSubmitInterface::class,
            $module['provides'] ?? []
        );
        self::assertSame('*', $module['optional']['Weline_Captcha'] ?? null);
        self::assertFileExists(BP . 'app/code/Weline/Product/doc/product-quote-request.md');
    }
}
