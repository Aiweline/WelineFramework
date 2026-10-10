<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AffiliateAfterAddBuyBoxBagContractTest extends TestCase
{
    public function testAfterAddHookReadsBuyBoxBeforeSharePolicyColdPath(): void
    {
        $hook = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml',
        );
        self::assertStringContainsString("StorefrontPdpBuyBoxBag::hasKey('after_add')", $hook);
        $bagPos = strpos($hook, "StorefrontPdpBuyBoxBag::hasKey('after_add')");
        $policyPos = strpos($hook, 'isProductShareEnabled');
        self::assertNotFalse($bagPos);
        self::assertNotFalse($policyPos);
        self::assertLessThan($policyPos, $bagPos);
        self::assertStringContainsString('$afterAddBag !== null', $hook);
    }
}
