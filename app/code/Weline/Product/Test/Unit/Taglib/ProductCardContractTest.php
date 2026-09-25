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

    public function testNormalizeProductKeepsSeoPathAfterSplitForTaglib(): void
    {
        // Regression: featured/bestsellers call ProductCardUrl::splitForTaglib before
        // render, which clears url and leaves url_path=product/{slug}. normalizeProduct
        // used to ignore url_path and fall back to /product/{id}.
        $split = \Weline\Theme\Helper\ProductCardUrl::splitForTaglib(
            '/product/hua-chao-ji-zhao-zhao-gong-zhu-yuan-chuang-tang-zhi-han-fu-bb5ecc5a'
        );
        self::assertSame('', $split['url']);
        self::assertSame(
            'product/hua-chao-ji-zhao-zhao-gong-zhu-yuan-chuang-tang-zhi-han-fu-bb5ecc5a',
            $split['url_path']
        );

        $normalized = ProductCardRenderer::normalizeProduct([
            'product_id' => 236,
            'id' => 236,
            'name' => '昭昭公主',
            'url' => $split['url'],
            'url_path' => $split['url_path'],
            'image' => '/x.webp',
            'price' => 110.0,
        ]);
        self::assertStringContainsString(
            'hua-chao-ji-zhao-zhao-gong-zhu-yuan-chuang-tang-zhi-han-fu-bb5ecc5a',
            (string)$normalized['url']
        );
        self::assertStringNotContainsString('/product/236', (string)$normalized['url']);
        self::assertStringNotContainsString('product/236', (string)$normalized['url_path']);
    }

    public function testNormalizeProductPrefersSlugOverNumericIdWhenUrlEmpty(): void
    {
        $normalized = ProductCardRenderer::normalizeProduct([
            'product_id' => 543,
            'id' => 543,
            'slug' => 'qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu',
            'name' => 'Demo',
            'url' => '',
            'url_path' => '',
            'image' => '/x.webp',
            'price' => 1.0,
        ]);
        self::assertStringContainsString(
            'qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu',
            (string)$normalized['url']
        );
        self::assertStringNotContainsString('/product/543', (string)$normalized['url']);
    }

    public function testCardHrefIncludesWebsiteMountPathAndOfferQuery(): void
    {
        $renderer = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCardRenderer.php'
        );
        $partial = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/product-card.phtml'
        );
        self::assertStringContainsString('buildStorefrontCardHref', $renderer);
        self::assertStringContainsString('resolveCurrentWebsiteMountPath', $renderer);
        self::assertStringContainsString('self::buildStorefrontCardHref($path, $querySuffix)', $renderer);
        self::assertStringNotContainsString('href="@url{', $partial);
        self::assertStringContainsString('buildStorefrontCardHref', $partial);

        $prevServer = $_SERVER['WELINE_WEBSITE_URL'] ?? null;
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://shop.test:9555/daocharms';
        try {
            if (class_exists(\Weline\Framework\Env\WelineEnv::class)
                && method_exists(\Weline\Framework\Env\WelineEnv::class, 'set')
            ) {
                \Weline\Framework\Env\WelineEnv::set(
                    'website_url',
                    'https://shop.test:9555/daocharms',
                    'product-card-url-unit'
                );
            }

            $href = ProductCardRenderer::buildStorefrontCardHref(
                'product/obsidian-yinyang-pendant',
                '?offer=c044a0cb-b63b-59e7-a9c4-b26639a2aeb6'
            );
            self::assertStringContainsString('/daocharms/product/obsidian-yinyang-pendant', $href);
            self::assertStringContainsString('offer=c044a0cb-b63b-59e7-a9c4-b26639a2aeb6', $href);
        } finally {
            if ($prevServer === null) {
                unset($_SERVER['WELINE_WEBSITE_URL']);
            } else {
                $_SERVER['WELINE_WEBSITE_URL'] = $prevServer;
            }
            if (class_exists(\Weline\Framework\Env\WelineEnv::class)
                && method_exists(\Weline\Framework\Env\WelineEnv::class, 'set')
            ) {
                \Weline\Framework\Env\WelineEnv::set('website_url', '', 'product-card-url-unit-cleanup');
            }
        }
    }

    public function testZeroUnitPriceBecomesQuoteOnlyNotSellable(): void
    {
        $product = ProductCardRenderer::fromStorefrontOffer([
            'product_id' => 208,
            'name' => 'Zero',
            'sku' => 'SKU-208',
            'unit_price_minor' => 0,
            'currency' => 'USD',
            'sellable' => true,
            'global_offer_uuid' => 'offer-208',
        ]);
        self::assertSame(0.0, (float)$product['price']);
        self::assertTrue(!empty($product['quote_only']));
        self::assertFalse(!empty($product['sellable']));
        $partial = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/product-card.phtml'
        );
        self::assertStringContainsString('missingSellPrice', $partial);
        self::assertStringContainsString('联系询价', $partial);
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
        self::assertStringContainsString('20260925-rating-zero', (string)file_get_contents(
            $base . '/Service/ProductCardRenderer.php'
        ));
        $partial = (string)file_get_contents($base . '/view/templates/frontend/partials/product-card.phtml');
        self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $partial);
        self::assertStringContainsString('defer_card_css', $partial);
        $renderer = (string)file_get_contents($base . '/Service/ProductCardRenderer.php');
        self::assertStringContainsString('StorefrontProductCardFragmentCache', $renderer);
        self::assertStringContainsString("'defer_card_css' => true", $renderer);
        self::assertStringContainsString('projectFromOffers', $renderer);
        self::assertStringContainsString('bucketCardIndexForFragmentReuse', $renderer);
        self::assertStringContainsString('emitStylesheetLinkOnce() . $html', $renderer);
    }

    public function testRendererDeclaresExternalProductCardStylesheet(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCardRenderer.php'
        );
        self::assertStringContainsString('public static function emitStylesheetLinkOnce()', $src);
        self::assertStringContainsString('buildProductCardStyleTag()', $src);
        self::assertStringContainsString('product-card.css', $src);
        self::assertStringContainsString(ProductCardRenderer::CSS_LINK_MARKER, $src);
        self::assertStringNotContainsString("return '<style ", $src);
        self::assertStringContainsString('data-weline-widget-asset', $src);
        // 宿主 + 卡 partial 均可 emit；禁止旧 cssLinkOnce / body <link>
        self::assertStringNotContainsString('cssLinkOnce', $src);
        self::assertStringNotContainsString('return self::cssLinkOnce()', $src);
        self::assertStringContainsString('20260925-rating-zero', $src);
        self::assertStringContainsString('onCaptureDiscard', $src);

        ProductCardRenderer::resetProductCardCssEmission();
        $tag = ProductCardRenderer::buildProductCardStyleTag();
        self::assertStringContainsString('<link rel="stylesheet"', $tag);
        self::assertStringContainsString('data-weline-widget-asset="source"', $tag);
        self::assertStringContainsString('data-weline-source-position="head"', $tag);
        self::assertStringContainsString('product-card.css', $tag);
        self::assertStringNotContainsString('<style', $tag);

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
