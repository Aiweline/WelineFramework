<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductDeliveryModeSlotContractTest extends TestCase
{
    public function testProductInfoExposesEmptyDeliveryModeSlotWithoutHardcodedBadge(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml',
        );
        $registry = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php',
        );

        self::assertStringContainsString('id="product-delivery-mode"', $template);
        self::assertStringContainsString('product-delivery-mode', $template);
        self::assertStringNotContainsString("Weline_Shipping::templates/frontend/widgets/product-delivery-mode.phtml", $template);
        self::assertStringNotContainsString("showPrimeBadge", $template);
        self::assertStringNotContainsString("__('跨境配送')", $template);
        self::assertStringNotContainsString('product-native-detail__prime-badge', $template);
        self::assertStringContainsString("'product-delivery-mode'", $registry);
        self::assertStringContainsString('layout-product-delivery-mode', $registry);
    }
}
