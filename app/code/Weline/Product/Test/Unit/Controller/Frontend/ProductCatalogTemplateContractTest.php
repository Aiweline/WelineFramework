<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductCatalogTemplateContractTest extends TestCase
{
    public function testQuoteOnlyProductDoesNotRenderAsZeroPricePurchase(): void
    {
        $template = BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml';
        $view = new class([
            'storefront_offers' => [[
                'product_id' => 48,
                'provider_code' => 'product',
                'global_offer_uuid' => 'offer-48',
                'name' => 'STN X3 YB300R',
                'sku' => 'STN-X3-YB300R',
                'image' => '/media/stn-x3.jpg',
                'currency' => 'USD',
                'unit_price_minor' => 0,
                'stock' => 0,
                'sellable' => false,
                'message' => '商品库存不足',
                'quote_only' => true,
            ]],
            'cart_guest_token' => '',
        ]) {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getUrl(string $path): string
            {
                return '/USD/' . ltrim($path, '/');
            }

            /** @param array<string, mixed> $data */
            public function fetch(string $template, array $data = []): string
            {
                return '';
            }

            public function render(string $template): string
            {
                ob_start();
                include $template;
                return (string)ob_get_clean();
            }
        };

        $html = $view->render($template);

        self::assertStringContainsString('联系询价', $html);
        self::assertStringNotContainsString('USD 0.00', $html);
        self::assertStringNotContainsString('商品库存不足', $html);
        self::assertMatchesRegularExpression('/<button[^>]+disabled[^>]*>\s*仅询价\s*<\/button>/s', $html);
    }
}
