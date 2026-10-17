<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Extends\Module\Weline_Framework\Query\MarketingAdminQueryProvider;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingQueryContractsPass().
 */
final class MarketingQueryContractsTest extends TestCase
{
    public function testAllMarketingQueryContractsPass(): void
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
            'CustomerSignalsQueryProvider::testCustomerModuleOwnsCustomerSignalsFacts' => function () {
            $this->assert_CustomerSignalsQueryProvider_testCustomerModuleOwnsCustomerSignalsFacts();
        },
            'MarketingQueryProvider::testMarketingAdminProviderAclSource' => function () {
            $this->assert_MarketingQueryProvider_testMarketingAdminProviderAclSource();
        },
            'MarketingQueryProvider::testMarketingFrontendProviderFileDeclaresOperations' => function () {
            $this->assert_MarketingQueryProvider_testMarketingFrontendProviderFileDeclaresOperations();
        },
        ];
    }

private function assert_CustomerSignalsQueryProvider_testCustomerModuleOwnsCustomerSignalsFacts(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Customer/extends/module/Weline_Framework/Query/CustomerSignalsQueryProvider.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("return 'customer_signals'", $src);
    self::assertStringContainsString("'get_customer_facts'", $src);
    self::assertStringContainsString("'backend_acl'", $src);
    self::assertStringContainsString('Weline_Customer::customer_index', $src);
    self::assertStringNotContainsString("'matches_segment'", $src);
}

private function assert_MarketingQueryProvider_testMarketingAdminProviderAclSource(): void
{
    self::assertSame('Weline_Marketing::commerce:marketing:rules', MarketingAdminQueryProvider::ACL_SOURCE);
}

private function assert_MarketingQueryProvider_testMarketingFrontendProviderFileDeclaresOperations(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/extends/module/Weline_Framework/Query/MarketingQueryProvider.php';
    $source = (string) file_get_contents($path);
    foreach (['validateCoupon', 'quoteDiscount', 'getCoupon', 'applyCoupon', 'removeCoupon'] as $operation) {
        self::assertStringContainsString("'{$operation}'", $source);
    }
    self::assertStringContainsString("'coupon_code'", $source);
    self::assertStringContainsString("'lines'", $source);
    self::assertStringContainsString("return 'marketing';", $source);
}
}
