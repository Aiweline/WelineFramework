<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * getData cart DTO must forward CartSummaryEnrichTaxObserver sales tax so
 * storefront-money-summary can paint the tax row (never invent rates in browser).
 */
final class CheckoutGetDataCartTaxFieldsContractTest extends TestCase
{
    public function testGetDataCartDtoForwardsTaxAmountMinorAndCombinedTaxEstimate(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );

        self::assertStringContainsString("'tax_amount_minor' => max(0, (int)(\$cart['tax_amount_minor'] ?? 0))", $src);
        self::assertStringContainsString("'sales_tax_amount_minor' => \$salesTaxMinor", $src);
        self::assertStringContainsString("\$taxEstimate['sales_tax_amount_minor'] = \$salesTaxMinor", $src);
        self::assertStringContainsString(
            "\$taxEstimate['tax_amount_minor'] = \$salesTaxMinor + \$dutyChargedMinor",
            $src
        );
        self::assertStringContainsString('resolveCheckoutDestinationSalesTaxMinor', $src);
        self::assertStringContainsString('CheckoutTaxAdvisorInterface', $src);

        $viewModel = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutPageViewModel.php'
        );
        self::assertStringContainsString("'tax_amount_minor' => \$taxAmountMinor", $viewModel);
        self::assertStringContainsString("'sales_tax_amount_minor' => \$salesTaxAmountMinor", $viewModel);
        self::assertStringContainsString('syncShippingMethodDutyDescription', $src);
    }
}
