<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductDeliveryModeBuyBoxBagContractTest extends TestCase
{
    public function testDeliveryModeReadsBuyBoxThenDefaultOriginOnly(): void
    {
        $widget = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-delivery-mode.phtml',
        );
        self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'origin_country\')', $widget);
        self::assertStringContainsString('resolveDefaultOriginCountry', $widget);
        self::assertStringNotContainsString('resolveForOffer(', $widget);
        self::assertStringContainsString('@widget.cache {300}', $widget);
    }
}
