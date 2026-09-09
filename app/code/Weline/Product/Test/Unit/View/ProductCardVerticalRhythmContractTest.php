<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 分类 PLP 不得把分页 margin 串到商品卡购买按钮；卡片垂直节奏单一。
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
        self::assertStringContainsString('.amz-plp .product-card-purchase-actions {', $src);
        self::assertStringNotContainsString(
            ".amz-plp .amz-card__buy-now:disabled,\n.amz-plp__pager {",
            $src
        );
        self::assertStringNotContainsString('margin: 18px 0 0;', $src);
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
    }
}
