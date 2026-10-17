<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Product\Taglib\OfferSelect;
use Weline\Product\Taglib\ProductCard;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllProductTaglibContractsPass().
 */
final class ProductTaglibContractsTest extends TestCase
{
    public function testAllProductTaglibContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AdminProductPicker::testPickerUsesProductAdminResourceAndThemeTokens' => function () {
            $this->assert_AdminProductPicker_testPickerUsesProductAdminResourceAndThemeTokens();
        },
            'OfferSelect::testOfferSelectTagContract' => function () {
            $this->assert_OfferSelect_testOfferSelectTagContract();
        },
            'ProductCard::testTagNameAndOptionalAttrs' => function () {
            $this->assert_ProductCard_testTagNameAndOptionalAttrs();
        },
            'ProductCard::testRendererNormalizesFlagsAndSkipsEmptyProduct' => function () {
            $this->assert_ProductCard_testRendererNormalizesFlagsAndSkipsEmptyProduct();
        },
            'ProductCard::testFromStorefrontOfferMapsDealPriceAndSku' => function () {
            $this->assert_ProductCard_testFromStorefrontOfferMapsDealPriceAndSku();
        },
            'ProductCard::testNormalizeProductSourceEmitsCurrencyGlyphFields' => function () {
            $this->assert_ProductCard_testNormalizeProductSourceEmitsCurrencyGlyphFields();
        },
            'ProductCard::testNormalizeProductKeepsSeoPathAfterSplitForTaglib' => function () {
            $this->assert_ProductCard_testNormalizeProductKeepsSeoPathAfterSplitForTaglib();
        },
            'ProductCard::testNormalizeProductPrefersSlugOverNumericIdWhenUrlEmpty' => function () {
            $this->assert_ProductCard_testNormalizeProductPrefersSlugOverNumericIdWhenUrlEmpty();
        },
            'ProductCard::testCardHrefIncludesWebsiteMountPathAndOfferQuery' => function () {
            $this->assert_ProductCard_testCardHrefIncludesWebsiteMountPathAndOfferQuery();
        },
            'ProductCard::testZeroUnitPriceBecomesQuoteOnlyNotSellable' => function () {
            $this->assert_ProductCard_testZeroUnitPriceBecomesQuoteOnlyNotSellable();
        },
            'ProductCard::testAssetsAndPartialExist' => function () {
            $this->assert_ProductCard_testAssetsAndPartialExist();
        },
            'ProductCard::testRendererDeclaresExternalProductCardStylesheet' => function () {
            $this->assert_ProductCard_testRendererDeclaresExternalProductCardStylesheet();
        },
            'ProductCard::testEmitSetsFlagOnlyAfterNonEmptyStyleAndResetsOnCaptureDiscard' => function () {
            $this->assert_ProductCard_testEmitSetsFlagOnlyAfterNonEmptyStyleAndResetsOnCaptureDiscard();
        },
        ];
    }

private function assert_AdminProductPicker_testPickerUsesProductAdminResourceAndThemeTokens(): void
{
    $taglibPath = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Taglib/AdminProductPicker.php';
    $scriptPath = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/js/backend/product-admin-picker.js';
    self::assertFileExists($taglibPath);
    self::assertFileExists($scriptPath);
    $taglib = (string) file_get_contents($taglibPath);
    $script = (string) file_get_contents($scriptPath);
    self::assertSame('product:admin:picker', \Weline\Product\Taglib\AdminProductPicker::name());
    self::assertTrue(method_exists(\Weline\Product\Taglib\AdminProductPicker::class, 'runtimeCallback'));
    self::assertStringContainsString('filters.keyword', $script);
    self::assertStringNotContainsString('filters.name = kw', $script);
    self::assertStringNotContainsString('filters.sku = kw', $script);
    self::assertStringContainsString('data-product-admin-picker', $taglib);
    self::assertStringContainsString('data-product-admin-picker-open', $taglib);
    self::assertStringContainsString('w-dialog', $taglib);
    self::assertStringContainsString('data-product-admin-picker-dialog', $taglib);
    self::assertStringContainsString('w-product-admin-picker__thumb', $script);
    self::assertStringContainsString('resolvePickerLocale', $script);
    self::assertStringContainsString('filters.locale', $script);
    self::assertStringContainsString('formatSelectedLabel', $script);
    self::assertStringContainsString('enrichSelectedLabels', $script);
    self::assertStringContainsString('product_ids', $script);
    self::assertStringContainsString('ui.dialog.open', $script);
    self::assertStringContainsString('runSearch()', $script);
    self::assertStringContainsString('正在加载已发布商品', $taglib);
    self::assertStringContainsString('data-product-admin-picker-open', $script);
    self::assertStringContainsString('extractPrice', $script);
    self::assertStringContainsString('price_label', $script);
    self::assertStringContainsString('w-product-admin-picker__item-price', $script);
    self::assertStringContainsString('w-product-admin-picker__col-price', $script);
    self::assertStringContainsString("'price' =>", $taglib);
    self::assertStringContainsString('resolveModuleStaticUrl', $taglib);
    self::assertStringContainsString('fetchTagSource', $taglib);
    self::assertStringNotContainsString('@static(Weline_Product::css/backend/product-admin-picker.css)', $taglib);
    self::assertStringNotContainsString('@static(Weline_Product::js/backend/product-admin-picker.js)', $taglib);
    self::assertStringContainsString('--weline-theme-border', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/statics/css/backend/product-admin-picker.css'));
}

private function assert_OfferSelect_testOfferSelectTagContract(): void
{
    self::assertSame('product:offer:select', OfferSelect::name());
    self::assertTrue(OfferSelect::attr()['id']);
    self::assertFalse(OfferSelect::attr()['name']);
    self::assertTrue(OfferSelect::tag_self_close());
    self::assertStringContainsString('offer-search/search', OfferSelect::document());
}

private function assert_ProductCard_testTagNameAndOptionalAttrs(): void
{
    self::assertSame('product:card', ProductCard::name());
    self::assertTrue(ProductCard::tag_self_close());
    self::assertArrayHasKey('product', ProductCard::attr());
    self::assertArrayHasKey('ids', ProductCard::attr());
    self::assertArrayHasKey('show-price', ProductCard::attr());
    self::assertArrayHasKey('density', ProductCard::attr());
    self::assertTrue(method_exists(ProductCard::class, 'runtimeCallback'));
    $callback = ProductCard::callback();
    $compiled = $callback('tag-self-close-with-attrs', [], [], ['ids' => 'relatedProductIds', 'density' => 'compact']);
    self::assertStringContainsString('ProductCardRenderer::projectFromIds(', $compiled);
    self::assertStringContainsString('$relatedProductIds', $compiled);
    self::assertStringNotContainsString('renderFromTaglib(', $compiled);
}

private function assert_ProductCard_testRendererNormalizesFlagsAndSkipsEmptyProduct(): void
{
    $flags = ProductCardRenderer::normalizeOptions(['show_price' => 'false', 'show_sku' => '1', 'density' => 'shelf']);
    self::assertFalse($flags['show_price']);
    self::assertTrue($flags['show_sku']);
    self::assertSame('shelf', $flags['density']);
    self::assertSame('', ProductCardRenderer::render([]));
}

private function assert_ProductCard_testFromStorefrontOfferMapsDealPriceAndSku(): void
{
    $product = ProductCardRenderer::fromStorefrontOffer(['product_id' => 107, 'name' => 'Demo', 'sku' => 'SKU-107', 'unit_price_minor' => 8910, 'catalog_price_minor' => 9900, 'has_deal' => true, 'campaign_label' => '今日精选', 'currency' => 'CNY', 'sellable' => true, 'global_offer_uuid' => 'offer-107']);
    self::assertSame(107, (int) $product['id']);
    self::assertSame('SKU-107', $product['sku']);
    self::assertSame(89.09999999999999, (float) $product['price']);
    self::assertSame(99.0, (float) $product['original_price']);
    self::assertTrue(!empty($product['is_sale']));
    self::assertSame('今日精选', $product['campaign_label']);
    // Runtime glyph assertions need full app bootstrap (w_env); source contract below
    // pins the projection so hydrate cards can reuse CurrencySymbol formatting.
}

private function assert_ProductCard_testNormalizeProductSourceEmitsCurrencyGlyphFields(): void
{
    $renderer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCardRenderer.php');
    self::assertStringContainsString("CurrencySymbol::forCode", $renderer);
    self::assertStringContainsString("CurrencySymbol::formatAmount", $renderer);
    self::assertStringContainsString("\$product['currency_symbol']", $renderer);
    self::assertStringContainsString("\$product['formatted_price']", $renderer);
}

private function assert_ProductCard_testNormalizeProductKeepsSeoPathAfterSplitForTaglib(): void
{
    // Regression: featured/bestsellers call ProductCardUrl::splitForTaglib before
    // render, which clears url and leaves url_path=product/{slug}. normalizeProduct
    // used to ignore url_path and fall back to /product/{id}.
    $split = \Weline\Theme\Helper\ProductCardUrl::splitForTaglib('/product/hua-chao-ji-zhao-zhao-gong-zhu-yuan-chuang-tang-zhi-han-fu-bb5ecc5a');
    self::assertSame('', $split['url']);
    self::assertSame('product/hua-chao-ji-zhao-zhao-gong-zhu-yuan-chuang-tang-zhi-han-fu-bb5ecc5a', $split['url_path']);
    $normalized = ProductCardRenderer::normalizeProduct(['product_id' => 236, 'id' => 236, 'name' => '昭昭公主', 'url' => $split['url'], 'url_path' => $split['url_path'], 'image' => '/x.webp', 'price' => 110.0]);
    self::assertStringContainsString('hua-chao-ji-zhao-zhao-gong-zhu-yuan-chuang-tang-zhi-han-fu-bb5ecc5a', (string) $normalized['url']);
    self::assertStringNotContainsString('/product/236', (string) $normalized['url']);
    self::assertStringNotContainsString('product/236', (string) $normalized['url_path']);
}

private function assert_ProductCard_testNormalizeProductPrefersSlugOverNumericIdWhenUrlEmpty(): void
{
    $normalized = ProductCardRenderer::normalizeProduct(['product_id' => 543, 'id' => 543, 'slug' => 'qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu', 'name' => 'Demo', 'url' => '', 'url_path' => '', 'image' => '/x.webp', 'price' => 1.0]);
    self::assertStringContainsString('qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu', (string) $normalized['url']);
    self::assertStringNotContainsString('/product/543', (string) $normalized['url']);
}

private function assert_ProductCard_testCardHrefIncludesWebsiteMountPathAndOfferQuery(): void
{
    $renderer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCardRenderer.php');
    $partial = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString('buildStorefrontCardHref', $renderer);
    self::assertStringContainsString('resolveCurrentWebsiteMountPath', $renderer);
    self::assertStringContainsString('self::buildStorefrontCardHref($path, $querySuffix)', $renderer);
    self::assertStringNotContainsString('href="@url{', $partial);
    self::assertStringContainsString('buildStorefrontCardHref', $partial);
    $prevServer = $_SERVER['WELINE_WEBSITE_URL'] ?? null;
    $_SERVER['WELINE_WEBSITE_URL'] = 'https://shop.test:9555/daocharms';
    try {
        if (class_exists(\Weline\Framework\Env\WelineEnv::class) && method_exists(\Weline\Framework\Env\WelineEnv::class, 'set')) {
            \Weline\Framework\Env\WelineEnv::set('website_url', 'https://shop.test:9555/daocharms', 'product-card-url-unit');
        }
        $href = ProductCardRenderer::buildStorefrontCardHref('product/obsidian-yinyang-pendant', '');
        self::assertStringContainsString('product/obsidian-yinyang-pendant', $href);
        self::assertStringNotContainsString('offer=', $href);
        // Idempotent when callers already mounted (catalog → widget → card).
        $again = ProductCardRenderer::buildStorefrontCardHref($href);
        self::assertStringContainsString('product/obsidian-yinyang-pendant', $again);
        self::assertStringNotContainsString('/daocharms/daocharms/', $again);
    } finally {
        if ($prevServer === null) {
            unset($_SERVER['WELINE_WEBSITE_URL']);
        } else {
            $_SERVER['WELINE_WEBSITE_URL'] = $prevServer;
        }
        if (class_exists(\Weline\Framework\Env\WelineEnv::class) && method_exists(\Weline\Framework\Env\WelineEnv::class, 'set')) {
            \Weline\Framework\Env\WelineEnv::set('website_url', '', 'product-card-url-unit-cleanup');
        }
    }
}

private function assert_ProductCard_testZeroUnitPriceBecomesQuoteOnlyNotSellable(): void
{
    $product = ProductCardRenderer::fromStorefrontOffer(['product_id' => 208, 'name' => 'Zero', 'sku' => 'SKU-208', 'unit_price_minor' => 0, 'currency' => 'USD', 'sellable' => true, 'global_offer_uuid' => 'offer-208']);
    self::assertSame(0.0, (float) $product['price']);
    self::assertTrue(!empty($product['quote_only']));
    self::assertFalse(!empty($product['sellable']));
    $partial = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString('missingSellPrice', $partial);
    self::assertStringContainsString('联系询价', $partial);
}

private function assert_ProductCard_testAssetsAndPartialExist(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    self::assertFileExists($base . '/Taglib/ProductCard.php');
    self::assertFileExists($base . '/Service/ProductCardRenderer.php');
    self::assertFileExists($base . '/view/templates/frontend/partials/product-card.phtml');
    self::assertFileExists($base . '/view/statics/css/frontend/product-card.css');
    $partial = (string) file_get_contents($base . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString('CurrencySymbol::forCode', $partial);
    self::assertStringContainsString('$currencyGlyph', $partial);
    self::assertStringNotContainsString('$esc($currency) ?> <?= number_format($price, 2)', $partial);
    $css = (string) file_get_contents($base . '/view/statics/css/frontend/product-card.css');
    self::assertStringContainsString('.weline-product-card', $css);
    self::assertStringContainsString('--wpc-link', $css);
    self::assertStringContainsString('a:any-link', $css);
    self::assertStringContainsString('color: var(--wpc-price)', $css);
    self::assertDoesNotMatchRegularExpression('/\.weline-product-card\.density-shelf \.wpc-price-now[^}]*color:\s*var\(--wpc-ink\)/s', $css);
    self::assertStringContainsString('padding-inline: var(--weline-space-4', $css);
    self::assertStringContainsString('var(--color-link', $css);
    self::assertStringContainsString('.wpc-cta .btn-buy-now', $css);
    self::assertStringContainsString('20260925-rating-zero', (string) file_get_contents($base . '/Service/ProductCardRenderer.php'));
    $partial = (string) file_get_contents($base . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $partial);
    self::assertStringContainsString('defer_card_css', $partial);
    $renderer = (string) file_get_contents($base . '/Service/ProductCardRenderer.php');
    self::assertStringContainsString('StorefrontProductCardFragmentCache', $renderer);
    self::assertStringContainsString("'defer_card_css' => true", $renderer);
    self::assertStringContainsString('projectFromOffers', $renderer);
    self::assertStringContainsString('projectFromIds', $renderer);
    self::assertStringContainsString('projectFromProducts', $renderer);
    self::assertStringContainsString('projectHtmlByProductId', $renderer);
    self::assertStringContainsString('stampImageLoadingAttributes', $renderer);
    self::assertStringContainsString('peekCardHtml', $renderer);
    self::assertStringContainsString('emitStylesheetLinkOnce() . $html', $renderer);
}

private function assert_ProductCard_testRendererDeclaresExternalProductCardStylesheet(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCardRenderer.php');
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

private function assert_ProductCard_testEmitSetsFlagOnlyAfterNonEmptyStyleAndResetsOnCaptureDiscard(): void
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
