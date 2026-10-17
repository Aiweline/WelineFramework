<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Framework\Security\Csp\PaymentVendorsCsp;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\StripeProvider;
use Weline\Payment\Service\AmountBreakdownBuilder;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentProviderContractsPass().
 */
final class PaymentProviderContractsTest extends TestCase
{
    public function testAllPaymentProviderContractsPass(): void
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
            'PayPalExpressBreakdownRebuild::testProviderRebuildsBreakdownFromTotalsWhenStaleValueDiffers' => function () {
            $this->assert_PayPalExpressBreakdownRebuild_testProviderRebuildsBreakdownFromTotalsWhenStaleValueDiffers();
        },
            'PayPalExpressBreakdownRebuild::testFromOrderDataConservesDiscountedGrandTotal' => function () {
            $this->assert_PayPalExpressBreakdownRebuild_testFromOrderDataConservesDiscountedGrandTotal();
        },
            'PayPalExpressBreakdownRebuild::testScaleToValueDoesNotLeaveStaleComponentsWhenOnlyValueChanged' => function () {
            $this->assert_PayPalExpressBreakdownRebuild_testScaleToValueDoesNotLeaveStaleComponentsWhenOnlyValueChanged();
        },
            'PaymentProviderCspDirectives::testBuiltInProvidersDeclareCspDirectives' => function () {
            $this->assert_PaymentProviderCspDirectives_testBuiltInProvidersDeclareCspDirectives();
        },
            'PaymentProviderCspDirectives::testPaymentVendorsCspAggregatesRegistryProviders' => function () {
            $this->assert_PaymentProviderCspDirectives_testPaymentVendorsCspAggregatesRegistryProviders();
        },
            'PaymentProviderCspDirectives::testProviderInterfaceDeclaresCspDirectives' => function () {
            $this->assert_PaymentProviderCspDirectives_testProviderInterfaceDeclaresCspDirectives();
        },
        ];
    }

private function assert_PayPalExpressBreakdownRebuild_testProviderRebuildsBreakdownFromTotalsWhenStaleValueDiffers(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php');
    self::assertStringContainsString('有权威 totals 时一律按 totals 重建', $src);
    self::assertStringContainsString('$hasFreshTotals', $src);
    self::assertStringContainsString('$builder->fromOrderData($context + [', $src);
}

private function assert_PayPalExpressBreakdownRebuild_testFromOrderDataConservesDiscountedGrandTotal(): void
{
    $builder = new AmountBreakdownBuilder();
    $breakdown = $builder->fromOrderData(['amount_minor' => 68636, 'currency' => 'USD', 'totals' => ['subtotal_minor' => 36505, 'shipping_amount_minor' => 35782, 'tax_amount_minor' => 0, 'discount_amount_minor' => 3651, 'grand_total_minor' => 68636], 'discount_lines' => [['key' => 'DEMO10', 'label' => 'DEMO10', 'amount_minor' => 3651, 'source_type' => 'coupon']]]);
    self::assertSame(68636, (int) $breakdown['value_minor']);
    self::assertSame(36505, (int) $breakdown['item_total_minor']);
    self::assertSame(35782, (int) $breakdown['shipping_minor']);
    self::assertSame(3651, (int) $breakdown['discount_minor']);
    $computed = (int) $breakdown['item_total_minor'] + (int) $breakdown['tax_total_minor'] + (int) $breakdown['shipping_minor'] - (int) $breakdown['discount_minor'];
    self::assertSame(68636, $computed);
}

private function assert_PayPalExpressBreakdownRebuild_testScaleToValueDoesNotLeaveStaleComponentsWhenOnlyValueChanged(): void
{
    $builder = new AmountBreakdownBuilder();
    // Stale create-time breakdown (wrong components, conserved to 45152)
    $stale = ['currency_code' => 'USD', 'value_minor' => 45152, 'item_total_minor' => 45152, 'shipping_minor' => 12298, 'handling_minor' => 0, 'tax_total_minor' => 0, 'insurance_minor' => 0, 'shipping_discount_minor' => 0, 'discount_minor' => 12298];
    $scaled = $builder->scaleToValue($stale, 68636, 'USD');
    $computed = (int) $scaled['item_total_minor'] + (int) $scaled['tax_total_minor'] + (int) $scaled['shipping_minor'] + (int) $scaled['handling_minor'] + (int) $scaled['insurance_minor'] - (int) $scaled['shipping_discount_minor'] - (int) $scaled['discount_minor'];
    self::assertSame(68636, (int) $scaled['value_minor']);
    self::assertSame(68636, $computed);
}

private function assert_PaymentProviderCspDirectives_testBuiltInProvidersDeclareCspDirectives(): void
{
    /** @var PayPalProvider $paypal */
    $paypal = (new \ReflectionClass(PayPalProvider::class))->newInstanceWithoutConstructor();
    $p = $paypal->cspDirectives();
    self::assertContains('https://www.paypal.com', $p['script-src'] ?? []);
    self::assertContains('https://www.paypalobjects.com', $p['script-src'] ?? []);
    self::assertContains('https://www.sandbox.paypal.com', $p['frame-src'] ?? []);
    self::assertContains('https://api-m.paypal.com', $p['connect-src'] ?? []);
    self::assertContains('https://api-m.sandbox.paypal.com', $p['connect-src'] ?? []);
    /** @var StripeProvider $stripe */
    $stripe = (new \ReflectionClass(StripeProvider::class))->newInstanceWithoutConstructor();
    $s = $stripe->cspDirectives();
    self::assertContains('https://js.stripe.com', $s['script-src'] ?? []);
    self::assertContains('https://api.stripe.com', $s['connect-src'] ?? []);
    self::assertContains('https://hooks.stripe.com', $s['frame-src'] ?? []);
    self::assertContains('https://checkout.stripe.com', $s['frame-src'] ?? []);
    /** @var FakeProvider $fake */
    $fake = (new \ReflectionClass(FakeProvider::class))->newInstanceWithoutConstructor();
    self::assertSame([], $fake->cspDirectives());
}

private function assert_PaymentProviderCspDirectives_testPaymentVendorsCspAggregatesRegistryProviders(): void
{
    /** @var PayPalProvider $paypal */
    $paypal = (new \ReflectionClass(PayPalProvider::class))->newInstanceWithoutConstructor();
    /** @var StripeProvider $stripe */
    $stripe = (new \ReflectionClass(StripeProvider::class))->newInstanceWithoutConstructor();
    /** @var FakeProvider $fake */
    $fake = (new \ReflectionClass(FakeProvider::class))->newInstanceWithoutConstructor();
    $contribution = (new PaymentVendorsCsp(static fn(): array => [$paypal, $stripe, $fake]))->contribution();
    $script = $contribution->directives['script-src'] ?? [];
    $connect = $contribution->directives['connect-src'] ?? [];
    self::assertContains('https://www.paypal.com', $script);
    self::assertContains('https://www.paypalobjects.com', $script);
    self::assertContains('https://js.stripe.com', $script);
    self::assertContains('https://api-m.sandbox.paypal.com', $connect);
    self::assertContains('https://api.stripe.com', $connect);
}

private function assert_PaymentProviderCspDirectives_testProviderInterfaceDeclaresCspDirectives(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Interface/ProviderInterface.php');
    self::assertStringContainsString('function cspDirectives(): array', $src);
}
}
