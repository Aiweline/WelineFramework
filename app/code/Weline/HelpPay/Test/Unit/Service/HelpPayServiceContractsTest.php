<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllHelpPayServiceContractsPass().
 */
final class HelpPayServiceContractsTest extends TestCase
{
    public function testAllHelpPayServiceContractsPass(): void
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
            'HelpPayDigitalQuickPay::testOrchestratorLocksVirtualOnlyLaneForDigital' => function () {
            $this->assert_HelpPayDigitalQuickPay_testOrchestratorLocksVirtualOnlyLaneForDigital();
        },
            'HelpPayDigitalQuickPay::testQuoteServiceNeverHardDefaultsRequiresShippingTrueWithoutResolver' => function () {
            $this->assert_HelpPayDigitalQuickPay_testQuoteServiceNeverHardDefaultsRequiresShippingTrueWithoutResolver();
        },
            'HelpPayDigitalQuickPay::testShareJsSkipsAddressAndShippingForDigital' => function () {
            $this->assert_HelpPayDigitalQuickPay_testShareJsSkipsAddressAndShippingForDigital();
        },
            'HelpPayDigitalQuickPay::testQuickPayWidgetProjectsRequiresShippingDataAttr' => function () {
            $this->assert_HelpPayDigitalQuickPay_testQuickPayWidgetProjectsRequiresShippingDataAttr();
        },
        ];
    }

private function assert_HelpPayDigitalQuickPay_testOrchestratorLocksVirtualOnlyLaneForDigital(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Service/HelpPayOrchestrator.php');
    self::assertStringContainsString('offerShipping()', $src);
    self::assertStringContainsString('resolveRequiresShipping', $src);
    self::assertStringContainsString("'free_reason' => 'virtual_only'", $src);
    self::assertStringContainsString("'service_code' => 'none'", $src);
    self::assertStringContainsString('virtualOnlyShippingOption', $src);
    self::assertStringNotContainsString("product_type === 'travel_route'", $src);
    self::assertStringNotContainsString('travel_route', $src);
}

private function assert_HelpPayDigitalQuickPay_testQuoteServiceNeverHardDefaultsRequiresShippingTrueWithoutResolver(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Service/HelpPayQuickShippingQuoteService.php');
    self::assertStringContainsString('HelpPayOfferShippingResolver', $src);
    self::assertStringContainsString('resolveRequiresShipping', $src);
    self::assertStringContainsString('lineRequiresShipping', $src);
    self::assertStringNotContainsString("'requires_shipping' => true,", $src);
}

private function assert_HelpPayDigitalQuickPay_testShareJsSkipsAddressAndShippingForDigital(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/statics/frontend/js/helppay-share.js');
    self::assertStringContainsString('function readRequiresShipping', $js);
    self::assertStringContainsString('function openQuickDigitalPaymentFlow', $js);
    self::assertStringContainsString('if (!readRequiresShipping(btn))', $js);
    self::assertStringContainsString("requires_shipping: false", $js);
    self::assertStringContainsString("service_code: 'none'", $js);
    self::assertStringNotContainsString('weight_minor: 500', $js);
}

private function assert_HelpPayDigitalQuickPay_testQuickPayWidgetProjectsRequiresShippingDataAttr(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/view/templates/frontend/widgets/product-quick-pay.phtml');
    self::assertStringContainsString('data-requires-shipping', $tpl);
    self::assertStringContainsString("\$offer['requires_shipping']", $tpl);
}
}
