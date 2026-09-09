<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 店面商品卡：标签容器定位不得误伤 .w-product-label；胶囊须包裹中文。
 */
final class ProductCardLabelWrapContractTest extends TestCase
{
    public function testProductCardCssScopesBadgeLayoutToLabelsContainerOnly(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/frontend/product-card.css'
        );
        self::assertNotSame('', $css);
        self::assertStringContainsString('.weline-product-card .w-product-labels {', $css);
        self::assertStringContainsString('.weline-product-card .w-product-label {', $css);
        self::assertStringContainsString('padding-inline: var(--weline-space-3)', $css);
        self::assertStringContainsString('letter-spacing: 0', $css);
        self::assertStringNotContainsString(
            ".weline-product-card .wpc-badges,\n.weline-product-card .w-product-labels",
            $css
        );
    }

    public function testProductCardPassesWpcBadgesOnlyAsLabelsContainerClass(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/product-card.phtml'
        );
        self::assertNotSame('', $tpl);
        self::assertStringContainsString("'class' => 'wpc-badges'", $tpl);
    }
}
