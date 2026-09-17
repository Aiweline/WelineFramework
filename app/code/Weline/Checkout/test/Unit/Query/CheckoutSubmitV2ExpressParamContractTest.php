<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Traditional checkout must not trip FrontendQueryGateway with undeclared
 * express_checkout / b2b_credit_apply_minor on submitV2.
 */
final class CheckoutSubmitV2ExpressParamContractTest extends TestCase
{
    public function testSubmitV2DescriptorAllowsExpressAndB2bCreditParams(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString("'name' => 'submitV2'", $src);
        self::assertMatchesRegularExpression(
            "/'name' => 'submitV2'[\s\S]*?'express_checkout' => \['type' => 'boolean'[\s\S]*?'b2b_credit_apply_minor' => \['type' => 'integer'/",
            $src,
        );
    }

    public function testTraditionalSubmitOmitsExpressCheckoutUnlessOptedIn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/view/frontend/checkout/index.phtml',
        );
        self::assertStringContainsString('if (opts.express_checkout)', $src);
        self::assertStringContainsString('submitPayload.express_checkout = true', $src);
        self::assertStringNotContainsString(
            'express_checkout: !!opts.express_checkout',
            $src,
        );
    }
}
