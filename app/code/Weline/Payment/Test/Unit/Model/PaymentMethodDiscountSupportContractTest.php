<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;

final class PaymentMethodDiscountSupportContractTest extends TestCase
{
    public function testFakeProviderDeclaresCommonDiscountActions(): void
    {
        $caps = (new FakeProvider())->getCapabilities();
        $actions = $caps['supported_discount_actions'] ?? null;
        self::assertIsArray($actions);
        self::assertContains('discount_fixed_amount', $actions);
        self::assertContains('discount_percentage', $actions);
    }

    public function testPayPalProviderStillDeclaresFixedAmountDiscount(): void
    {
        $caps = (new PayPalProvider())->getCapabilities();
        $actions = $caps['supported_discount_actions'] ?? null;
        self::assertIsArray($actions);
        self::assertContains('discount_fixed_amount', $actions);
    }

    public function testSupportsDiscountActionTreatsNullProviderListAsUnrestricted(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/PaymentMethod.php');
        self::assertStringContainsString('if ($providerSupported === null)', $source);
        self::assertStringContainsString('aligned with DiscountActionSupportService::getSupportedActions', $source);
        self::assertStringNotContainsString(
            "return \$providerSupported !== null\n                && \$providerSupported !== []\n                && in_array(\$actionCode, \$providerSupported, true);",
            $source,
        );
    }
}
