<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 商品角标须完整包裹中文「促销」等文案：横向内边距不得偏紧；容器 class 不得泄漏到标签。
 */
final class ProductLabelWrapContractTest extends TestCase
{
    public function testFoundationProductLabelUsesRoomyInlinePadding(): void
    {
        $foundation = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/ui/css/foundation.css'
        );
        self::assertNotSame('', $foundation);
        self::assertNotFalse(strpos($foundation, '.w-product-label'));
        self::assertStringContainsString('padding-inline: var(--weline-space-3)', $foundation);
        self::assertStringContainsString('inline-size: fit-content', $foundation);
        self::assertStringContainsString('letter-spacing: 0', $foundation);
        self::assertStringContainsString('font-weight: var(--weline-font-weight-semibold, 600)', $foundation);
        self::assertStringNotContainsString(
            'padding: var(--weline-space-1) var(--weline-space-2); border-radius: var(--weline-radius-round); font-size: var(--weline-font-size-xs); font-weight: var(--weline-font-weight-semibold); line-height: 1.2; letter-spacing: 0.02em',
            $foundation
        );
    }

    public function testAmazonCardLabelMatchesFoundationWrapPolicy(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/css/widgets/amazon-product-card.css'
        );
        self::assertNotSame('', $css);
        self::assertStringContainsString('.weline-amz-card-widget .w-product-label', $css);
        self::assertStringContainsString('padding-inline: var(--weline-space-3)', $css);
        self::assertStringContainsString('inline-size: fit-content', $css);
    }

    public function testLabelsPartialClearsClassWhenRenderingChildLabel(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/product/labels.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString("'class' => ''", $src);
        self::assertStringContainsString('wpc-badges', $src);
    }
}
