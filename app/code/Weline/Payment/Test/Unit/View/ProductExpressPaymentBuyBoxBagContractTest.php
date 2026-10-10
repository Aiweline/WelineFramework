<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductExpressPaymentBuyBoxBagContractTest extends TestCase
{
    public function testExpressPaymentReadsBuyBoxBeforeListExpressMethods(): void
    {
        $widget = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/product-express-payment.phtml',
        );
        self::assertStringContainsString("StorefrontPdpBuyBoxBag::hasKey('express_methods')", $widget);
        $bagPos = strpos($widget, "StorefrontPdpBuyBoxBag::hasKey('express_methods')");
        $coldPos = strpos($widget, '$expressMethods = $facade->listExpressMethods');
        self::assertNotFalse($bagPos);
        self::assertNotFalse($coldPos);
        self::assertLessThan($coldPos, $bagPos);
    }
}
