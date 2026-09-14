<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CheckoutSplitShippingContractTest extends TestCase
{
    public function testFreezeUsesSplitQuoteAndWhSplitKey(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/CheckoutGroupSubmitService.php');
        self::assertStringContainsString('applyFulfillmentSplitKeys', $src);
        self::assertStringContainsString('quoteSplit', $src);
        self::assertStringContainsString('shipping_request_hash', $src);
        self::assertStringContainsString('shipping_packages', $src);
        self::assertStringContainsString('SplitShippingQuoteServiceInterface', $src);
    }

    public function testExpressReviewShowsPackageBreakdown(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/express-review.js');
        self::assertStringContainsString('shipping-packages', $js);
        self::assertStringContainsString('分仓运费明细', $js);
        $phtml = (string)file_get_contents(dirname(__DIR__, 3) . '/view/frontend/checkout/express-review.phtml');
        self::assertStringContainsString('checkout-split-shipping-packages', $phtml);
    }
}
