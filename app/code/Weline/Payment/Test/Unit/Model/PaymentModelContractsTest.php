<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentModelContractsPass().
 */
final class PaymentModelContractsTest extends TestCase
{
    public function testAllPaymentModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PaymentMethodDiscountSupport::testFakeProviderDeclaresCommonDiscountActions' => function () {
            $this->assert_PaymentMethodDiscountSupport_testFakeProviderDeclaresCommonDiscountActions();
        },
            'PaymentMethodDiscountSupport::testPayPalProviderStillDeclaresFixedAmountDiscount' => function () {
            $this->assert_PaymentMethodDiscountSupport_testPayPalProviderStillDeclaresFixedAmountDiscount();
        },
            'PaymentMethodDiscountSupport::testSupportsDiscountActionTreatsNullProviderListAsUnrestricted' => function () {
            $this->assert_PaymentMethodDiscountSupport_testSupportsDiscountActionTreatsNullProviderListAsUnrestricted();
        },
        ];
    }

private function assert_PaymentMethodDiscountSupport_testFakeProviderDeclaresCommonDiscountActions(): void
{
    $caps = (new FakeProvider())->getCapabilities();
    $actions = $caps['supported_discount_actions'] ?? null;
    self::assertIsArray($actions);
    self::assertContains('discount_fixed_amount', $actions);
    self::assertContains('discount_percentage', $actions);
}

private function assert_PaymentMethodDiscountSupport_testPayPalProviderStillDeclaresFixedAmountDiscount(): void
{
    $caps = (new PayPalProvider())->getCapabilities();
    $actions = $caps['supported_discount_actions'] ?? null;
    self::assertIsArray($actions);
    self::assertContains('discount_fixed_amount', $actions);
}

private function assert_PaymentMethodDiscountSupport_testSupportsDiscountActionTreatsNullProviderListAsUnrestricted(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Model/PaymentMethod.php');
    self::assertStringContainsString('if ($providerSupported === null)', $source);
    self::assertStringContainsString('aligned with DiscountActionSupportService::getSupportedActions', $source);
    self::assertStringNotContainsString("return \$providerSupported !== null\n                && \$providerSupported !== []\n                && in_array(\$actionCode, \$providerSupported, true);", $source);
}
}
