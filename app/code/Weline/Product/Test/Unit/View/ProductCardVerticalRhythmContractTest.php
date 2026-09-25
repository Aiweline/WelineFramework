<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 分类 PLP 不得把分页 margin 串到商品卡购买按钮；卡片垂直节奏单一。
 * CTA 视觉嵌入 canonical product-card.css，PLP 禁止再挂第二套 purchase-actions 皮。
 */
final class ProductCardVerticalRhythmContractTest extends TestCase
{
    public function testCategoryPlpDoesNotLeakPagerMarginOntoPurchaseButtons(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/category/index.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('.amz-plp__pager {', $src);
        // CTA 布局/色由 .weline-product-card .wpc-cta 拥有；PLP 不得再写 purchase-actions 方言
        self::assertStringNotContainsString('.amz-plp .product-card-purchase-actions {', $src);
        self::assertStringNotContainsString(
            ".amz-plp .amz-card__buy-now:disabled,\n.amz-plp__pager {",
            $src
        );
        self::assertStringNotContainsString('margin: 18px 0 0;', $src);
        self::assertStringContainsString('.weline-product-card .wpc-cta', $src);
    }

    public function testProductCardCssUsesSingleCtaAutoMargin(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/frontend/product-card.css'
        );
        self::assertNotSame('', $css);
        self::assertMatchesRegularExpression(
            '/\.weline-product-card \\.wpc-cta \\{[^}]*margin-top:\\s*auto;/s',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-product-card \\.wpc-price \\{[^}]*margin-top:\\s*auto;/s',
            $css
        );
        self::assertStringContainsString('padding-top: 100%', $css);
        self::assertStringContainsString('height: 100%', $css);
        self::assertStringContainsString('object-fit: cover', $css);
        self::assertStringContainsString('padding-inline: var(--weline-space-4', $css);
        // 卡内 CTA 自包含：禁用态保持品牌色透明度，禁止灰底第二方言
        self::assertStringContainsString('.wpc-cta .btn-buy-now:disabled', $css);
        self::assertStringContainsString('opacity: 0.72', $css);
        self::assertStringContainsString('appearance: none', $css);
        self::assertStringContainsString('gap: var(--weline-space-2', $css);
        self::assertDoesNotMatchRegularExpression(
            '/\.wpc-cta [^\{]*:disabled[^\}]*surface-muted/s',
            $css
        );
    }

    public function testProductCardPartialOwnsPurchaseCssFlag(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/product-card.phtml'
        );
        self::assertStringContainsString("'css_owned_by_card' => true", $tpl);
        // 无评也必须渲染评分行，否则网格价签/CTA 上下错位
        self::assertStringContainsString('if ($showRating):', $tpl);
        self::assertStringNotContainsString('if ($showRating && $rating > 0):', $tpl);
        self::assertStringContainsString('$reviewCountSafe', $tpl);

        $partial = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/theme/frontend/partials/product/add-to-cart.phtml'
        );
        self::assertStringContainsString('card_css_owned_by_product_card', $partial);
        self::assertStringContainsString('if (!$cssOwnedByProductCard)', $partial);
    }
}
