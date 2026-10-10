<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class SellingModeSwitcherBuyBoxBagContractTest extends TestCase
{
    public function testSellingModeReadsBuyBoxBeforeEligibilityColdPath(): void
    {
        $widget = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/selling-mode-switcher.phtml',
        );
        self::assertStringContainsString("StorefrontPdpBuyBoxBag::hasKey('selling')", $widget);
        $bagPos = strpos($widget, "StorefrontPdpBuyBoxBag::hasKey('selling')");
        $eligPos = strpos($widget, 'allowsWholesaleDisplay');
        self::assertNotFalse($bagPos);
        self::assertNotFalse($eligPos);
        self::assertLessThan($eligPos, $bagPos);
        self::assertStringContainsString('$sellingFromBag === null', $widget);
    }
}
