<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Product\Taglib\ProductCard;

final class ProductCardContractTest extends TestCase
{
    public function testTagNameAndOptionalAttrs(): void
    {
        self::assertSame('product:card', ProductCard::name());
        self::assertTrue(ProductCard::tag_self_close());
        self::assertArrayHasKey('product', ProductCard::attr());
        self::assertArrayHasKey('show-price', ProductCard::attr());
        self::assertArrayHasKey('density', ProductCard::attr());
        self::assertTrue(method_exists(ProductCard::class, 'runtimeCallback'));
    }

    public function testRendererNormalizesFlagsAndSkipsEmptyProduct(): void
    {
        $flags = ProductCardRenderer::normalizeOptions([
            'show_price' => 'false',
            'show_sku' => '1',
            'density' => 'shelf',
        ]);
        self::assertFalse($flags['show_price']);
        self::assertTrue($flags['show_sku']);
        self::assertSame('shelf', $flags['density']);
        self::assertSame('', ProductCardRenderer::render([]));
    }

    public function testFromStorefrontOfferMapsDealPriceAndSku(): void
    {
        $product = ProductCardRenderer::fromStorefrontOffer([
            'product_id' => 107,
            'name' => 'Demo',
            'sku' => 'SKU-107',
            'unit_price_minor' => 8910,
            'catalog_price_minor' => 9900,
            'has_deal' => true,
            'campaign_label' => '今日精选',
            'currency' => 'CNY',
            'sellable' => true,
            'global_offer_uuid' => 'offer-107',
        ]);
        self::assertSame(107, (int)$product['id']);
        self::assertSame('SKU-107', $product['sku']);
        self::assertSame(89.1, (float)$product['price']);
        self::assertSame(99.0, (float)$product['original_price']);
        self::assertTrue(!empty($product['is_sale']));
        self::assertSame('今日精选', $product['campaign_label']);
    }

    public function testAssetsAndPartialExist(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Taglib/ProductCard.php');
        self::assertFileExists($base . '/Service/ProductCardRenderer.php');
        self::assertFileExists($base . '/view/templates/frontend/partials/product-card.phtml');
        self::assertFileExists($base . '/view/statics/css/frontend/product-card.css');
        $partial = (string)file_get_contents($base . '/view/templates/frontend/partials/product-card.phtml');
        self::assertStringContainsString('CurrencySymbol::forCode', $partial);
        self::assertStringContainsString('$currencyGlyph', $partial);
        self::assertStringNotContainsString('$esc($currency) ?> <?= number_format($price, 2)', $partial);
        $css = (string)file_get_contents($base . '/view/statics/css/frontend/product-card.css');
        self::assertStringContainsString('.weline-product-card', $css);
        self::assertStringContainsString('--wpc-link', $css);
        self::assertStringContainsString('a:any-link', $css);
        self::assertStringContainsString('color: var(--wpc-price)', $css);
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-product-card\.density-shelf \\.wpc-price-now[^}]*color:\\s*var\\(--wpc-ink\\)/s',
            $css
        );
        self::assertStringContainsString('padding-inline: var(--weline-space-4', $css);
        self::assertStringContainsString('var(--color-link', $css);
        self::assertStringContainsString('.wpc-cta .btn-buy-now', $css);
        self::assertStringContainsString('20260921-product-card-css-emission-heal', (string)file_get_contents(
            $base . '/Service/ProductCardRenderer.php'
        ));
        $partial = (string)file_get_contents($base . '/view/templates/frontend/partials/product-card.phtml');
        self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $partial);
    }

    public function testRendererDeclaresInlineProductCardStyleEmitter(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCardRenderer.php'
        );
        self::assertStringContainsString('public static function emitStylesheetLinkOnce()', $src);
        self::assertStringContainsString('buildProductCardStyleTag()', $src);
        self::assertStringContainsString('product-card.css', $src);
        self::assertStringContainsString(ProductCardRenderer::CSS_LINK_MARKER, $src);
        self::assertStringContainsString('<style ', $src);
        self::assertStringNotContainsString('<link rel="stylesheet"', $src);
        // 宿主 + 卡 partial 均可 emit；禁止旧 cssLinkOnce / body <link>
        self::assertStringNotContainsString('cssLinkOnce', $src);
        self::assertStringNotContainsString('return self::cssLinkOnce()', $src);
        self::assertStringContainsString('20260921-product-card-css-emission-heal', $src);
        self::assertStringContainsString('onCaptureDiscard', $src);

        ProductCardRenderer::resetProductCardCssEmission();
        $tag = ProductCardRenderer::buildProductCardStyleTag();
        self::assertStringContainsString('<style ' . ProductCardRenderer::CSS_LINK_MARKER . '="1"', $tag);
        self::assertStringContainsString('.weline-product-card', $tag);
        self::assertStringContainsString('a:any-link', $tag);
        self::assertStringContainsString('.product-actions .action-btn', $tag);
    }

    public function testEmitSetsFlagOnlyAfterNonEmptyStyleAndResetsOnCaptureDiscard(): void
    {
        ProductCardRenderer::resetProductCardCssEmission();
        $first = ProductCardRenderer::emitStylesheetLinkOnce();
        self::assertNotSame('', $first);
        self::assertStringContainsString(ProductCardRenderer::CSS_LINK_MARKER, $first);
        self::assertSame('', ProductCardRenderer::emitStylesheetLinkOnce());

        \Weline\Framework\Runtime\RequestContext::notifyCaptureDiscarded();
        $second = ProductCardRenderer::emitStylesheetLinkOnce();
        self::assertNotSame('', $second);
        self::assertStringContainsString(ProductCardRenderer::CSS_LINK_MARKER, $second);
    }
}
